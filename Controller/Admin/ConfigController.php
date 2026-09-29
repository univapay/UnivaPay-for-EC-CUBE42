<?php
namespace Plugin\UnivaPay\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Service\Composer\ComposerServiceInterface;
use Plugin\UnivaPay\Form\Type\Admin\ConfigType;
use Plugin\UnivaPay\Repository\ConfigRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var ComposerServiceInterface
     */
    protected $composerService;

    /**
     * ConfigController constructor.
     *
     * @param ConfigRepository $configRepository
     * @param ComposerServiceInterface $composerService
     */
    public function __construct(
        ConfigRepository $configRepository,
        ComposerServiceInterface $composerService
    ) {
        $this->configRepository = $configRepository;
        $this->composerService = $composerService;
    }

    /**
     * @Route("/%eccube_admin_route%/univapay/config", name="univa_pay_admin_config")
     * @Template("@UnivaPay/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Config = $form->getData();
            $this->entityManager->persist($Config);
            $this->entityManager->flush($Config);

            $this->addSuccess('univa_pay.admin.save.success', 'admin');

            return $this->redirectToRoute('univa_pay_admin_config');
        }

        return [
            'form' => $form->createView(),
        ];
    }
    /**
     * @Route("/%eccube_admin_route%/univapay/sdk", name="univa_pay_admin_sdk")\
     */
    public function sdk(Request $request) {
        try {
            $log = $this->composerService->execRequire('univapay/php-sdk:6.5.0');
            return $this->redirectToRoute('univa_pay_admin_config', ['error' => 0]);
        } catch (Exception $e) {
            return $this->redirectToRoute('univa_pay_admin_config', ['error' => 1]);
        }
    }
}
