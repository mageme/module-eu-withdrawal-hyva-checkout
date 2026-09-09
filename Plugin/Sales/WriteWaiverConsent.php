<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace Hyva\MageMeEUWithdrawalCheckout\Plugin\Sales;

use MageMe\EUWithdrawal\Model\ModuleConfig;
use MageMe\EUWithdrawal\Model\Scope\WithdrawalScope;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventWriter;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextHasher;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextResolver;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Quote\Api\CartManagementInterface as Subject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Promotes the customer's genuine digital-content waiver affirmation into the
 * permanent consent record at place-order, binding it to the committed billing
 * jurisdiction. It does NOT originate consent: the storefront waiver step
 * (`Magewire\Checkout\WaiverStep`) records the UI affirmation (EVT_AFFIRM) when
 * the customer actually ticks both boxes; this plugin only writes EVT_CONSENT +
 * EVT_LOSS for items that carry a matching affirmation. Items without a genuine,
 * matching affirmation are left untouched, so the base `OrderPlacementGuard`
 * blocks them.
 */
class WriteWaiverConsent
{
    private const XML_DIGITAL_ENABLED = 'mageme_eu_withdrawal/digital_waiver/enabled';

    /**
     * Constructor.
     *
     * @param DigitalContentDetector $detector
     * @param WaiverTextResolver $resolver
     * @param WaiverTextHasher $hasher
     * @param WaiverEventWriter $writer
     * @param CartRepositoryInterface $cartRepo
     * @param StoreManagerInterface $storeManager
     * @param HttpRequest $request
     * @param LoggerInterface $logger
     * @param WaiverEventReader $reader
     * @param ScopeConfigInterface $scopeConfig
     * @param WithdrawalScope $withdrawalScope
     * @param ModuleConfig $moduleConfig
     */
    public function __construct(
        private readonly DigitalContentDetector $detector,
        private readonly WaiverTextResolver $resolver,
        private readonly WaiverTextHasher $hasher,
        private readonly WaiverEventWriter $writer,
        private readonly CartRepositoryInterface $cartRepo,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request,
        private readonly LoggerInterface $logger,
        private readonly WaiverEventReader $reader,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WithdrawalScope $withdrawalScope,
        private readonly ModuleConfig $moduleConfig,
    ) {
    }

    /**
     * Before place order.
     *
     * @param Subject $subject
     * @param int $cartId
     * @param mixed $paymentMethod
     * @return array
     */
    public function beforePlaceOrder(Subject $subject, int $cartId, $paymentMethod = null): array
    {
        try {
            $this->promoteConsentForDigital($cartId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva Checkout WriteWaiverConsent: ' . $e->getMessage(),
                ['exception' => $e],
            );
        }
        return [$cartId, $paymentMethod];
    }

    /**
     * Promote consent for digital.
     *
     * @param int $cartId
     * @return void
     */
    private function promoteConsentForDigital(int $cartId): void
    {
        $quote = $this->cartRepo->get($cartId);
        $storeId = (int) $quote->getStoreId();
        if (!$this->moduleConfig->isEnabled($storeId)) {
            return;
        }
        if (!$this->scopeConfig->isSetFlag(self::XML_DIGITAL_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return;
        }
        if (!$this->withdrawalScope->quoteInScope($quote)) {
            return;
        }
        $digital = $this->detector->filterDigitalItems($quote->getAllVisibleItems());
        if ($digital === []) {
            return;
        }
        $store = $this->storeManager->getStore($storeId);
        $locale = (string) $store->getConfig('general/locale/code');
        $jurisdiction = strtoupper(substr((string) ($quote->getBillingAddress()?->getCountryId() ?? ''), 0, 2));
        $jurisdictionKey = $jurisdiction !== '' ? $jurisdiction : '__eu_generic__';
        $texts = $this->resolver->resolve($locale, $jurisdictionKey);
        $hash = $this->hasher->hash($texts['consent'], $texts['acknowledgment'], $locale, $jurisdictionKey);
        $snapshotPayload = $texts['consent'] . "\n\n---\n\n" . $texts['acknowledgment'];

        foreach ($digital as $qi) {
            $quoteItemId = (int) $qi->getItemId();
            if (!$this->hasMatchingAffirmation($quoteItemId, $hash)) {
                continue;
            }
            $base = [
                'order_id' => 0,
                'quote_item_id' => $quoteItemId,
                'consent_value' => 1,
                'waiver_text_snapshot' => $snapshotPayload,
                'waiver_text_hash' => $hash,
                'locale' => $locale,
                'jurisdiction' => $jurisdiction ?: null,
                'ip' => (string) $this->request->getClientIp(),
                'user_agent' => (string) $this->request->getServerValue('HTTP_USER_AGENT', ''),
                'product_sku' => (string) $qi->getSku(),
            ];
            $this->writer->write($base + ['event_type' => WaiverEventReader::EVT_CONSENT]);
            $this->writer->write($base + ['event_type' => WaiverEventReader::EVT_LOSS]);
        }
    }

    /**
     * Whether a genuine UI affirmation for the committed-jurisdiction text exists.
     *
     * @param int $quoteItemId
     * @param string $expectedHash
     * @return bool
     */
    private function hasMatchingAffirmation(int $quoteItemId, string $expectedHash): bool
    {
        foreach ($this->reader->findQuoteEvents($quoteItemId) as $event) {
            if (($event['event_type'] ?? '') === WaiverEventReader::EVT_AFFIRM
                && (int) ($event['consent_value'] ?? 0) === 1
                && hash_equals($expectedHash, (string) ($event['waiver_text_hash'] ?? ''))
            ) {
                return true;
            }
        }
        return false;
    }
}
