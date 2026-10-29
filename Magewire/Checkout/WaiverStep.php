<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace Hyva\MageMeEUWithdrawalCheckout\Magewire\Checkout;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use MageMe\EUWithdrawal\Block\Checkout\WaiverStep as WaiverStepBlock;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventWriter;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextHasher;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextResolver;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magewirephp\Magewire\Component;
use Magewirephp\Magewire\Features\SupportMagewireBackwardsCompatibility\HandleBackwardsCompatibility;
use Psr\Log\LoggerInterface;

/**
 * Hyva Checkout Magewire component for the digital-content waiver step
 * (Art. 16(m) Directive 2011/83/EU).
 *
 * Hyva's checkout navigator calls `evaluateCompletion()` before allowing the
 * step to advance — returning `createErrorMessageEvent()->asBlocking()` keeps
 * the customer on this step until both consent boxes are checked. On success it
 * records a genuine UI affirmation (EVT_AFFIRM) per digital quote item; the
 * place-order plugin `Plugin\Sales\WriteWaiverConsent` later promotes that
 * affirmation into the permanent consent record bound to the committed billing
 * jurisdiction.
 *
 * NOTE: Service deps use prefixed property names (`euDetector`, `euResolver`,
 * etc.) to avoid colliding with Magewire `Component` base-class properties
 * (`$request`, `$response`, `$resolver`, `$loader`, `$redirect`, etc.).
 * Magewire's hydrators iterate every public property; we use protected to
 * keep injected services out of the wire-state payload.
 *
 * Public properties are client-writable, so `$items` is display state only:
 * the affirmation is always built from the current quote on the server.
 *
 * Runs as a native Magewire 3 component on Hyva Checkout 3, outside the v1bc layer.
 */
#[HandleBackwardsCompatibility(enabled: false)]
class WaiverStep extends Component implements EvaluationInterface
{
    /** @var bool Magewire wire:model state — both must be true to allow navigation. */
    public bool $consentExpress = false;

    /** @var bool Magewire wire:model state — both must be true to allow navigation. */
    public bool $lossAck = false;

    /** @var array<int, array{quote_item_id:int,sku:string,name:string,qty:float,consent_text:string,acknowledgment_text:string,waiver_text_hash:string,locale:string,jurisdiction:?string}> */
    public array $items = [];

    public string $locale = '';

    public ?string $jurisdiction = null;

    /** @var bool True when context hydration failed — renders a recoverable notice instead of a blank step. */
    public bool $loadError = false;

    /** @var array<string, string> An address change can switch the jurisdiction and with it the legal text. */
    protected $listeners = [
        'shipping_address_saved' => 'syncContext',
        'shipping_address_activated' => 'syncContext',
        'billing_address_saved' => 'syncContext',
        'billing_address_activated' => 'syncContext',
    ];

    /** @var bool Set once this request replaced the displayed context; a tick in the same request stays cleared. */
    protected bool $contextReplaced = false;

    protected CheckoutSession $euCheckoutSession;
    protected DigitalContentDetector $euDetector;
    protected WaiverTextResolver $euResolver;
    protected WaiverTextHasher $euHasher;
    protected StoreManagerInterface $euStoreManager;
    protected LoggerInterface $euLogger;
    protected WaiverEventWriter $euWriter;
    protected HttpRequest $euRequest;
    protected ModuleConfig $euModuleConfig;
    protected ScopeConfigInterface $euScopeConfig;

    /**
     * Constructor.
     *
     * @param CheckoutSession $checkoutSession
     * @param DigitalContentDetector $detector
     * @param WaiverTextResolver $resolver
     * @param WaiverTextHasher $hasher
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param WaiverEventWriter $writer
     * @param HttpRequest $request
     * @param ModuleConfig $moduleConfig
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        DigitalContentDetector $detector,
        WaiverTextResolver $resolver,
        WaiverTextHasher $hasher,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        WaiverEventWriter $writer,
        HttpRequest $request,
        ModuleConfig $moduleConfig,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->euCheckoutSession = $checkoutSession;
        $this->euDetector = $detector;
        $this->euResolver = $resolver;
        $this->euHasher = $hasher;
        $this->euStoreManager = $storeManager;
        $this->euLogger = $logger;
        $this->euWriter = $writer;
        $this->euRequest = $request;
        $this->euModuleConfig = $moduleConfig;
        $this->euScopeConfig = $scopeConfig;
    }

    /**
     * Mount: hydrate items + locale/jurisdiction context. Called by Magewire
     * once on initial render.
     *
     * @return void
     */
    public function mount(): void
    {
        try {
            $this->hydrateContext();
        } catch (\Throwable $e) {
            $this->loadError = true;
            $this->euLogger->warning(
                'Hyva Checkout WaiverStep mount failed: ' . $e->getMessage(),
                ['exception' => $e],
            );
        }
    }

    /**
     * Re-fetch context on every navigation hit so the per-jurisdiction hash
     * tracks the current billing country.
     *
     * @return void
     */
    public function refresh(): void
    {
        try {
            $this->hydrateContext();
        } catch (\Throwable $e) {
            $this->loadError = true;
            $this->euLogger->warning(
                'Hyva Checkout WaiverStep refresh failed: ' . $e->getMessage(),
                ['exception' => $e],
            );
        }
    }

    /**
     * Hyva navigator gate. Returns blocking error when consent is incomplete;
     * on success records the UI affirmation for each digital item.
     *
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if (!$this->isWaiverEnabled()) {
            return $resultFactory->createSuccess([], 'eu-withdrawal-waiver:consent:success');
        }
        if ($this->loadError) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('eu-withdrawal-waiver:consent:error')
                ->withMessage((string) __('The digital-content consent step could not load. Please refresh and try again, or contact support.'))
                ->asBlocking();
        }
        if ($this->items === [] && !$this->hasDigitalItems()) {
            return $resultFactory->createSuccess([], 'eu-withdrawal-waiver:consent:success');
        }
        if (!$this->consentExpress || !$this->lossAck) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('eu-withdrawal-waiver:consent:error')
                ->withMessage((string) __('Please confirm both consent boxes to proceed with digital content delivery.'))
                ->asBlocking();
        }
        try {
            $items = $this->buildContext()['items'];
            if (!$this->hasConsentTexts($items)) {
                return $resultFactory->createErrorMessageEvent()
                    ->withCustomEvent('eu-withdrawal-waiver:consent:error')
                    ->withMessage((string) __('The digital-content consent step could not load. Please refresh and try again, or contact support.'))
                    ->asBlocking();
            }
            if (!$this->matchesDisplayedItems($items)) {
                // waiver.phtml answers this event with syncContext(), which shows the current text.
                return $resultFactory->createErrorMessageEvent()
                    ->withCustomEvent('eu-withdrawal-waiver:context-changed')
                    ->withMessage((string) __('The products or the consent text have changed. Please review the text and confirm both boxes again.'))
                    ->asBlocking();
            }
            $this->recordAffirmation($items);
        } catch (\Throwable $e) {
            $this->euLogger->warning(
                'Hyva Checkout WaiverStep affirmation write failed: ' . $e->getMessage(),
                ['exception' => $e],
            );
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('eu-withdrawal-waiver:consent:error')
                ->withMessage((string) __('We could not record your consent just now. Please try again.'))
                ->asBlocking();
        }
        return $resultFactory->createSuccess([], 'eu-withdrawal-waiver:consent:success');
    }

    /**
     * Magewire hook: the express consent box changed. Magewire 1 assigns the
     * returned value to the property.
     *
     * @param mixed $value
     * @return bool
     */
    public function updatedConsentExpress($value): bool
    {
        return !$this->contextReplaced && $this->syncDisplayedContext() && (bool) $value;
    }

    /**
     * Magewire hook: the loss acknowledgment box changed. Magewire 1 assigns
     * the returned value to the property.
     *
     * @param mixed $value
     * @return bool
     */
    public function updatedLossAck($value): bool
    {
        return !$this->contextReplaced && $this->syncDisplayedContext() && (bool) $value;
    }

    /**
     * Magewire listener for the checkout address events.
     *
     * @return void
     */
    public function syncContext(): void
    {
        $this->syncDisplayedContext();
    }

    /**
     * When the cart or the legal text no longer matches what the customer is
     * looking at, show the current items and text with both boxes cleared.
     *
     * @return bool False when the displayed context was replaced
     */
    protected function syncDisplayedContext(): bool
    {
        try {
            $context = $this->buildContext();
        } catch (\Throwable $e) {
            $this->loadError = true;
            $this->euLogger->warning(
                'Hyva Checkout WaiverStep update failed: ' . $e->getMessage(),
                ['exception' => $e],
            );
            return true;
        }
        $this->loadError = false;
        if ($this->matchesDisplayedItems($context['items'])) {
            return true;
        }
        $this->consentExpress = false;
        $this->lossAck = false;
        $this->items = $context['items'];
        $this->locale = $context['locale'];
        $this->jurisdiction = $context['jurisdiction'];
        $this->contextReplaced = true;
        return false;
    }

    /**
     * Whether the server-built items are the ones the customer is looking at.
     *
     * @param array<int, array<string, mixed>> $items
     * @return bool
     */
    protected function matchesDisplayedItems(array $items): bool
    {
        return $this->fingerprint($items) === $this->fingerprint($this->items);
    }

    /**
     * Item identity and the hash of the consent text, locale and jurisdiction.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<int, mixed>>
     */
    protected function fingerprint(array $items): array
    {
        return array_map(static fn ($item): array => [
            (int) ($item['quote_item_id'] ?? 0),
            (string) ($item['waiver_text_hash'] ?? ''),
            (string) ($item['locale'] ?? ''),
            (string) ($item['jurisdiction'] ?? ''),
        ], array_values($items));
    }

    /**
     * Whether the current quote holds digital items; a failed lookup counts as
     * yes, so the consent path below blocks and logs it.
     *
     * @return bool
     */
    protected function hasDigitalItems(): bool
    {
        try {
            return $this->buildContext()['items'] !== [];
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Whether every item carries both legal texts.
     *
     * @param array<int, array<string, mixed>> $items
     * @return bool
     */
    protected function hasConsentTexts(array $items): bool
    {
        foreach ($items as $item) {
            if ((string) ($item['consent_text'] ?? '') === '' || (string) ($item['acknowledgment_text'] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Same gate as the base OrderPlacementGuard, at the quote store scope:
     * both the module master switch and the checkout waiver switch must be on
     * before a consent affirmation is written.
     *
     * @return bool
     */
    protected function isWaiverEnabled(): bool
    {
        $storeId = (int) $this->euCheckoutSession->getQuote()->getStoreId();

        return $this->euModuleConfig->isEnabled($storeId)
            && $this->euScopeConfig->isSetFlag(WaiverStepBlock::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Persist the genuine UI consent affirmation (EVT_AFFIRM) for each digital
     * item of the current quote, bound to the server-resolved text.
     *
     * @param array<int, array<string, mixed>> $items
     * @return void
     */
    protected function recordAffirmation(array $items): void
    {
        $ip = (string) $this->euRequest->getClientIp();
        $ua = (string) $this->euRequest->getServerValue('HTTP_USER_AGENT', '');
        foreach ($items as $item) {
            $this->euWriter->upsert([
                'order_id' => 0,
                'quote_item_id' => (int) $item['quote_item_id'],
                'event_type' => WaiverEventReader::EVT_AFFIRM,
                'consent_value' => 1,
                'waiver_text_snapshot' => (string) ($item['consent_text'] ?? '')
                    . "\n\n---\n\n" . (string) ($item['acknowledgment_text'] ?? ''),
                'waiver_text_hash' => (string) ($item['waiver_text_hash'] ?? ''),
                'locale' => (string) ($item['locale'] ?? ''),
                'jurisdiction' => $item['jurisdiction'] ?? null,
                'product_sku' => (string) ($item['sku'] ?? ''),
                'ip' => $ip,
                'user_agent' => $ua,
            ]);
        }
    }

    /**
     * Hydrate context.
     *
     * @return void
     */
    protected function hydrateContext(): void
    {
        $this->loadError = false;
        $this->consentExpress = false;
        $this->lossAck = false;

        $context = $this->buildContext();
        $this->items = $context['items'];
        $this->locale = $context['locale'];
        $this->jurisdiction = $context['jurisdiction'];
    }

    /**
     * Digital items of the current quote with the consent texts and hash for
     * the store locale and billing jurisdiction.
     *
     * @return array{items: array<int, array<string, mixed>>, locale: string, jurisdiction: ?string}
     */
    protected function buildContext(): array
    {
        $quote = $this->euCheckoutSession->getQuote();
        $digital = $this->euDetector->filterDigitalItems($quote->getAllVisibleItems());
        $locale = (string) $this->euStoreManager->getStore()->getConfig('general/locale/code');
        $jurisdiction = strtoupper(substr((string) ($quote->getBillingAddress()?->getCountryId() ?? ''), 0, 2));
        $jurisdictionKey = $jurisdiction !== '' ? $jurisdiction : '__eu_generic__';

        $texts = $this->euResolver->resolve($locale, $jurisdictionKey);
        $hash = $this->euHasher->hash($texts['consent'], $texts['acknowledgment'], $locale, $jurisdictionKey);

        $items = [];
        foreach ($digital as $qi) {
            $items[] = [
                'quote_item_id' => (int) $qi->getItemId(),
                'sku' => (string) $qi->getSku(),
                'name' => (string) $qi->getName(),
                'qty' => (float) $qi->getQty(),
                'consent_text' => $texts['consent'],
                'acknowledgment_text' => $texts['acknowledgment'],
                'waiver_text_hash' => $hash,
                'locale' => $locale,
                // Persist the same jurisdiction key that fed the text + hash
                // (the '__eu_generic__' fallback when no billing country), so the
                // stored event matches the verifiable hash instead of being NULL.
                'jurisdiction' => $jurisdictionKey,
            ];
        }

        return ['items' => $items, 'locale' => $locale, 'jurisdiction' => $jurisdiction ?: null];
    }
}
