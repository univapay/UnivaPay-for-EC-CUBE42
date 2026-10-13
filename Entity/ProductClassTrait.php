<?php
    namespace Plugin\UnivaPay\Entity;

    use Doctrine\ORM\Mapping as ORM;
    use Eccube\Annotation\EntityExtension;

    /**
     * @EntityExtension("Eccube\Entity\ProductClass")
     */
    trait ProductClassTrait
    {
        /**
         * 支払周期を保存するカラム
         *
         * @var \Plugin\UnivaPay\Entity\SubscriptionPeriod
         *
         * @ORM\ManyToOne(targetEntity="Plugin\UnivaPay\Entity\SubscriptionPeriod")
         * @ORM\JoinColumns({
         *   @ORM\JoinColumn(name="subscription_period_id", referencedColumnName="id")
         * })
         */
        private $SubscriptionPeriod;

        /**
         * Set subscriptionPeriod.
         *
         * @param \Plugin\UnivaPay\Entity\SubscriptionPeriod|null $subscriptionPeriod
         *
         * @return Order
         */
        public function setSubscriptionPeriod(?\Plugin\UnivaPay\Entity\SubscriptionPeriod $subscriptionPeriod = null)
        {
            $this->SubscriptionPeriod = $subscriptionPeriod;

            return $this;
        }

        /**
         * Get subscriptionPeriod
         *
         * @return \Plugin\UnivaPay\Entity\SubscriptionPeriod|null
         */
        public function getSubscriptionPeriod()
        {
            return $this->SubscriptionPeriod;
        }
    }
