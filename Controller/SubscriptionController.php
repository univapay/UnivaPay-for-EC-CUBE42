<?php
namespace Plugin\UnivaPay\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\MailService;
use Eccube\Service\OrderHelper;
use Eccube\Service\PurchaseFlow\Processor\AddPointProcessor;
use Eccube\Service\PurchaseFlow\Processor\OrderNoProcessor;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Plugin\UnivaPay\Repository\ConfigRepository;
use Plugin\UnivaPay\Util\SDK;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

class SubscriptionController extends AbstractController
{
    /** @var ConfigRepository */
    protected $Config;

    /** @var OrderRepository */
    protected $Order;

    /**
     * @var OrderStatusRepository
     */
    private $orderStatusRepository;

    /**
     * @var PurchaseFlow
     */
    private $purchaseFlow;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var OrderNoProcessor
     */
    private $orderNoProcessor;

    /**
     * @var AddPointProcessor
     */
    private $addPointProcessor;

    /**
     * @var MailService
     */
    protected $mailService;

    /**
     * OrderController constructor.
     *
     * @param ConfigRepository $configRepository
     * @param OrderRepository $orderRepository
     * @param OrderStatusRepository $orderStatusRepository
     * @param PurchaseFlow $shoppingPurchaseFlow
     * @param OrderHelper $orderHelper
     * @param OrderNoProcessor $orderNoProcessor
     * @param AddPointProcessor $addPointProcessor
     * @param MailService $mailService
     */
    public function __construct(
        ConfigRepository $configRepository,
        OrderRepository $orderRepository,
        OrderStatusRepository $orderStatusRepository,
        PurchaseFlow $shoppingPurchaseFlow,
        OrderHelper $orderHelper,
        OrderNoProcessor $orderNoProcessor,
        AddPointProcessor $addPointProcessor,
        MailService $mailService
    ) {
        $this->Config = $configRepository;
        $this->Order = $orderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->purchaseFlow = $shoppingPurchaseFlow;
        $this->orderHelper = $orderHelper;
        $this->orderNoProcessor = $orderNoProcessor;
        $this->addPointProcessor = $addPointProcessor;
        $this->mailService = $mailService;
    }

    /**
     * subscription webhook action
     *
     * @Method("POST")
     * @Route("/univapay/hook", name="univa_pay_hook")
     */
    public function hook(Request $request)
    {
        $data = json_decode($request->getContent());
        $existOrder = $this->Order->findOneBy(["order_no" => $data->data->metadata->orderNo]);
        if (is_null($existOrder))
            return new Response();

        $config = $this->Config->findOneById(1);
        $util = new SDK($config);
        $charge = null;
        if ($data->event === 'subscription_payment' || $data->event === 'subscription_failure') {
            // SubscriptionIdからChargeを取得
            $charge = $util->getChargeBySubscriptionId($data->data->id);
        } elseif ( $data->event === 'charge_finished') {
            // 支払い確定したら注文ステータスを変更する
            // セキュリティのために一様UUIDを確認する
            if ($data->data->id !== $existOrder->getUnivapayChargeId())
                return new Response();
            $OrderStatus = $this->orderStatusRepository->find(OrderStatus::PAID);
            $existOrder->setOrderStatus($OrderStatus);
            $existOrder->setPaymentDate(new \DateTime());
            $this->entityManager->persist($existOrder);
            $this->entityManager->flush();
            return new Response();
        }
        if (is_null($charge))
            return new Response();
        // 再課金待ちもしくは初回課金の場合は何もしない
        if ($data->data->status === 'unpaid' || $charge->id === $existOrder->getUnivapayChargeId())
            return new Response();
        // cloneで注文を複製してもidが変更できないため一から作成
        $newOrder = new Order;
        // 今回での決済の課金ID取得
        $newOrder->setUnivapayChargeId($charge->id);
        $newOrder->setUnivapaySubscriptionId($existOrder->getUnivapaySubscriptionId());
        $newOrder->setMessage($existOrder->getMessage());
        $newOrder->setName01($existOrder->getName01());
        $newOrder->setName02($existOrder->getName02());
        $newOrder->setKana01($existOrder->getKana01());
        $newOrder->setKana02($existOrder->getKana02());
        $newOrder->setCompanyName($existOrder->getCompanyName());
        $newOrder->setEmail($existOrder->getEmail());
        $newOrder->setPhoneNumber($existOrder->getPhoneNumber());
        $newOrder->setPostalCode($existOrder->getPostalCode());
        $newOrder->setAddr01($existOrder->getAddr01());
        $newOrder->setAddr02($existOrder->getAddr02());
        $newOrder->setBirth($existOrder->getBirth());
        // 今回決済金額から小計を逆算
        $newSubtotal = $existOrder->getSubtotal() - $existOrder->getTotal() + $data->data->amount - $existOrder->getDiscount();
        $newOrder->setSubtotal($newSubtotal);
        // 割引無効化
        $newOrder->setDiscount(0);
        $newOrder->setDeliveryFeeTotal($existOrder->getDeliveryFeeTotal());
        $newOrder->setCharge($existOrder->getCharge());
        $newOrder->setTax($existOrder->getTax());
        // 二回目以降の決済金額が違う場合があるため変更
        $newOrder->setTotal($data->data->amount);
        $newOrder->setPaymentTotal($data->data->amount);
        $newOrder->setPaymentMethod($existOrder->getPaymentMethod());
        $newOrder->setNote($existOrder->getNote());
        $newOrder->setCurrencyCode($existOrder->getCurrencyCode());
        $newOrder->setCompleteMessage($existOrder->getCompleteMessage());
        $newOrder->setCompleteMailMessage($existOrder->getCompleteMailMessage());
        // 決済日を今日に変更
        $newOrder->setPaymentDate(new \DateTime());
        $newOrder->setCustomer($existOrder->getCustomer());
        $newOrder->setCountry($existOrder->getCountry());
        $newOrder->setPref($existOrder->getPref());
        $newOrder->setSex($existOrder->getSex());
        $newOrder->setJob($existOrder->getJob());
        $newOrder->setPayment($existOrder->getPayment());
        $newOrder->setDeviceType($existOrder->getDeviceType());
        $newOrder->setCustomerOrderStatus($existOrder->getCustomerOrderStatus());
        $newOrder->setOrderStatusColor($existOrder->getOrderStatusColor());
        // 商品ごとの今回課金金額を取得
        $amount = json_decode($existOrder->getUnivapayChargeAmount());
        // 商品金額が存在しなかったらその時点のデータを作成する
        if(!$amount) {
            $items = [];
            foreach($existOrder->getOrderItems() as $value) {
                // 商品単位で金額を取得
                if($value->isProduct()) {
                    $class = $value->getProductClass();
                    $items[$class->getId()] = ['price' => $class->getPrice01(), 'tax' => $class->getPrice01IncTax() - $class->getPrice01()];
                }
            }
            $newOrder->setUnivapayChargeAmount(json_encode($items));
            $amount = json_decode($newOrder->getUnivapayChargeAmount());
        }
        $amount = get_object_vars($amount);
        foreach($existOrder->getOrderItems() as $value) {
            $newOrderItem = clone $value;
            // 値引きは引き継がない
            if($newOrderItem->isDiscount() || $newOrderItem->isPoint())
                continue;
            // OrderItemごとの金額を修正する
            if($newOrderItem->isProduct()) {
                // 二回目は通常金額を取得する
                $class = $newOrderItem->getProductClass();
                $newOrderItem->setPrice($amount[$class->getId()]->price);
                $newOrderItem->setTax($amount[$class->getId()]->tax);
            }
            $newOrderItem->setOrder($newOrder);
            $newOrder->addOrderItem($newOrderItem);
        }
        foreach($existOrder->getShippings() as $value) {
            $newShipping = clone $value;
            $newShipping->setShippingDeliveryDate(NULL);
            $newShipping->setShippingDeliveryTime(NULL);
            $newShipping->setTimeId(NULL);
            $newShipping->setShippingDate(NULL);
            $newShipping->setTrackingNumber(NULL);
            $newShipping->setOrder($newOrder);
            // 循環参照してしまい正常に発送データがセットできないため
            foreach($newShipping->getOrderItems() as $v) {
                $newShipping->removeOrderItem($v);
            }
            foreach($newOrder->getOrderItems() as $v) {
                if($v->getShipping() && $v->getShipping()->getId() == $value->getId()) {
                    $v->setShipping($newShipping);
                    $newShipping->addOrderItem($v);
                }
            }
            $newOrder->addShipping($newShipping);
        }
        $purchaseContext = new PurchaseContext($newOrder, $newOrder->getCustomer());
        // ポイントを再計算4.0以前のバージョンの場合、先にポイントを再計算を行わないと正常に動かない
        $this->addPointProcessor->validate($newOrder, $purchaseContext);
        // 注文番号変更
        $preOrderId = $this->orderHelper->createPreOrderId();
        $newOrder->setPreOrderId($preOrderId);
        // 購入処理を完了
        $this->purchaseFlow->prepare($newOrder, $purchaseContext);
        $this->purchaseFlow->commit($newOrder, $purchaseContext);
        $this->entityManager->persist($newOrder);
        // 注文番号が重複しないように再採番
        $this->entityManager->flush();
        $this->orderNoProcessor->process($newOrder, $purchaseContext);
        $this->entityManager->flush();
        // 定期課金に失敗した場合はキャンセル済み注文に変更
        $OrderStatus = $this->orderStatusRepository->find($data->data->status === 'suspended' ? OrderStatus::CANCEL : OrderStatus::PAID);
        $newOrder->setOrderStatus($OrderStatus);
        if($config->getMail())
            $this->mailService->sendOrderMail($newOrder);
        $this->entityManager->flush();
        return new Response();
    }

    /**
     * subscription cancel action
     *
     * @Method("POST")
     * @Route("/univapay/subscription/cancel/{id}", requirements={"id" = "\d+"}, name="univa_pay_cancel_subscription")
     */
    public function cancelSubscription(Request $request, Order $Order)
    {
        if ($request->isXmlHttpRequest() && $this->isTokenValid()) {
            $util = new SDK($this->Config->findOneById(1));
            $subscription = $util->getSubscriptionByChargeId($Order->getUnivapayChargeId());
            $subscription = $subscription->cancel()->awaitResult();

            return $this->json($subscription->status);
        }

        throw new BadRequestHttpException();
    }

    /**
     * subscription get action
     *
     * @Method("GET")
     * @Route("/univapay/subscription/get/{id}", requirements={"id" = "\d+"}, name="univa_pay_get_subscription")
     */
    public function getSubscription(Request $request, Order $Order)
    {
        if ($request->isXmlHttpRequest() && $this->isTokenValid()) {
            $util = new SDK($this->Config->findOneById(1));
            $subscription = $util->getSubscriptionByChargeId($Order->getUnivapayChargeId());

            return $this->json(['status' => $subscription->status, 'id' => $subscription->id]);
        }

        throw new BadRequestHttpException();
    }

    /**
     * subscription update action
     *
     * @Method("POST")
     * @Route("/univapay/subscription/update/{id}", requirements={"id" = "\d+"}, name="univa_pay_update_subscription")
     */
    public function updateSubscription(Request $request, Order $Order)
    {
        if ($request->isXmlHttpRequest() && $this->isTokenValid()) {
            $util = new SDK($this->Config->findOneById(1));
            $subscription = $util->getSubscriptionByChargeId($Order->getUnivapayChargeId());
            $subscription->patch($request->getContent());

            return $this->json($subscription->status);
        }

        throw new BadRequestHttpException();
    }
}