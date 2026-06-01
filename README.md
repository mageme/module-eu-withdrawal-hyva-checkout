# MageMe EU Withdrawal — Hyva Checkout Companion

> Hyva Checkout compatibility for the pre-contract Annex I block and the digital-content waiver step — Magewire, Alpine.js and Tailwind.

[![Latest Version](https://img.shields.io/packagist/v/mageme/module-eu-withdrawal-hyva-checkout.svg?style=flat-square)](https://packagist.org/packages/mageme/module-eu-withdrawal-hyva-checkout)
[![Downloads](https://img.shields.io/packagist/dt/mageme/module-eu-withdrawal-hyva-checkout.svg?style=flat-square)](https://packagist.org/packages/mageme/module-eu-withdrawal-hyva-checkout)
[![Hyva Checkout](https://img.shields.io/badge/Hyva%20Checkout-1.3%2B-21CFD5.svg?style=flat-square)](https://www.hyva.io)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-MageMe%20EULA-blue.svg?style=flat-square)](https://mageme.com/license/)

Hyva Checkout companion for [`mageme/module-eu-withdrawal`](https://github.com/mageme/module-eu-withdrawal). Re-implements the two checkout-side compliance touchpoints for Hyva Checkout's Magewire runtime. Install it when you run **Hyva theme with Hyva Checkout**.

**[Documentation](https://docs.mageme.com)** · **[Base module](https://github.com/mageme/module-eu-withdrawal)** · **[Hyva theme companion](https://github.com/mageme/module-eu-withdrawal-hyva)**

---

## What it does

- **Pre-contract Annex I block** (Art. 6(1)(h)) — the consumer-information block, rendered in the Hyva Checkout payment step as a collapsed accordion with the Annex I(A) text and Annex I(B) model-form download.
- **Digital-content waiver step** (Art. 16(m)) — the express-consent step, inserted between Shipping and Payment when the cart contains digital items, blocking advance until both consent boxes are ticked.

It reuses the base module's data layer; only the rendering is Hyva-native. **No compliance behaviour is added or removed.** The module auto-disables when Hyva Checkout is not installed.

For the storefront withdrawal pages, customer account and order view, install the sibling [Hyva theme companion](https://github.com/mageme/module-eu-withdrawal-hyva).

## Requirements

- **EU Withdrawal** base module (pulled automatically)
- `hyva-themes/magento2-hyva-checkout` **≥ 1.3**
- Magento **2.4.4–2.4.9**, **PHP 8.1–8.5**

## Install

```bash
composer require mageme/module-eu-withdrawal-hyva-checkout
bin/magento module:enable Hyva_MageMeEUWithdrawalCheckout
bin/magento setup:upgrade
bin/magento cache:flush
```

Recompile the Hyva Tailwind bundle so the classes used by this module are picked up (substitute your child theme's `web/tailwind/` path if applicable):

```bash
cd vendor/hyva-themes/magento2-default-theme/web/tailwind
npm install && npm run build
```

## Custom Magento development

Need a feature an extension doesn't cover, or a bespoke Magento build? MageMe takes on custom extension development and integration work.

→ **[Custom Magento development](https://mageme.com/magento-services/custom-development)**

## Support

- Documentation: [docs.mageme.com](https://docs.mageme.com)
- Bug reports and feature requests: [GitHub Issues](https://github.com/mageme/module-eu-withdrawal-hyva-checkout/issues)

## Disclaimer

This is a **technical theme-compatibility shim** — it re-implements the checkout-side integration in Hyva Checkout's runtime and adds or removes no compliance functionality. The merchant is responsible for verifying the pre-contract display and waiver capture work correctly under their Hyva Checkout version and configuration before going to production. See the base module's [full disclaimer](https://docs.mageme.com).

## License

Distributed **free of charge** as a free-tier companion, governed by the **MageMe End User License Agreement** ([mageme.com/license](https://mageme.com/license/)).

---

**MageMe** builds Magento 2 and Adobe Commerce extensions for B2B merchants — form building, quoting, catalog control, and EU compliance.