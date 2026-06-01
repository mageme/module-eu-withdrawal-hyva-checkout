<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace Hyva\MageMeEUWithdrawalCheckout\Model\CustomCondition;

use Hyva\Checkout\Model\CustomConditionInterface;
use MageMe\EUWithdrawal\Block\Checkout\WaiverStep;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Hyva Checkout custom condition: shows the digital-content waiver step
 * (Art. 16(m) Directive 2011/83/EU) only when the cart contains items the
 * `MageMe\EUWithdrawal\Service\DigitalContentDetector` recognises as digital
 * AND the waiver feature is enabled in admin config.
 */
class HasDigitalContent implements CustomConditionInterface
{
    /**
     * Constructor.
     *
     * @param CheckoutSession $checkoutSession
     * @param DigitalContentDetector $detector
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly DigitalContentDetector $detector,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Validate.
     *
     * @return bool
     */
    public function validate(): bool
    {
        $waiverEnabled = (bool) $this->scopeConfig->getValue(
            WaiverStep::XML_ENABLED,
            ScopeInterface::SCOPE_STORE,
        );
        if (!$waiverEnabled) {
            return false;
        }
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Throwable) {
            return false;
        }
        $digital = $this->detector->filterDigitalItems($quote->getAllVisibleItems());
        return $digital !== [];
    }
}
