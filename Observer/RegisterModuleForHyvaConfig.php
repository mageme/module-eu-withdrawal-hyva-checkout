<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace Hyva\MageMeEUWithdrawalCheckout\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Adds this module to `app/etc/hyva-themes.json` on `bin/magento hyva:config:generate`,
 * so the Hyva Tailwind build reads the content globs in
 * `view/frontend/tailwind/tailwind.config.js` and keeps the utility classes used by
 * the checkout templates in the theme bundle.
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    private const MODULE_NAME = 'Hyva_MageMeEUWithdrawalCheckout';

    /**
     * Constructor.
     *
     * @param ComponentRegistrarInterface $componentRegistrar
     */
    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        $base = rtrim(BP, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($path === null || !str_starts_with($path, $base)) {
            return;
        }

        $config = $observer->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];
        $extensions[] = ['src' => substr($path, strlen($base))];
        $config->setData('extensions', $extensions);
    }
}
