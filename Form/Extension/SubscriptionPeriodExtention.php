<?php
    namespace Plugin\UnivaPay\Form\Extension;

    use Eccube\Entity\ProductClass;
    use Eccube\Form\Type\Admin\ProductClassType;
    use Symfony\Component\Form\AbstractTypeExtension;
    use Plugin\UnivaPay\Form\Type\SubscriptionPeriodType;
    use Plugin\UnivaPay\Entity\SubscriptionPeriod;
    use Symfony\Component\Validator\Constraints as Assert;
    use Symfony\Component\Form\FormBuilderInterface;
    use Symfony\Component\Form\FormEvent;
    use Symfony\Component\Form\FormEvents;

    /**
     * サブスク周期を保存する
     */
    class SubscriptionPeriodExtention extends AbstractTypeExtension
    {
        public function buildForm(FormBuilderInterface $builder, array $options)
        {
            $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
                /** @var ProductClass $data */
                $form = $event->getForm();

                $form->add('subscription_period', SubscriptionPeriodType::class, [
                    'multiple' => false,
                    'expanded' => false,
                    'placeholder' => 'デフォルト(毎月)',
                ]);
            });
        }

        /**
         * {@inheritdoc}
         */
        public function getExtendedType()
        {
            return ProductClassType::class;
        }
    }
