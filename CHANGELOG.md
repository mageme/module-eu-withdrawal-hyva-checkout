## 1.0.4

- Fix: The digital-content waiver step no longer shows in Hyvä Checkout while the module is disabled.
- Fix: Waiver consent is no longer recorded while the module or the checkout waiver step is disabled.
- Fix: Checkout can no longer get stuck on the digital-content waiver step after the module or the waiver step is switched off mid-order.

## 1.0.3

- Fix: The checkout pre-contractual information now shows your configured withdrawal period instead of a fixed 14 days.

## 1.0.2

- Fix: The digital-content waiver step is now reliably hidden for orders outside the merchant's configured withdrawal scope (country and customer group).
- Fix: A failure while recording the checkout waiver consent no longer surfaces as an error; it fails safely and can be retried.

## 1.0.1

* Other: The digital-content waiver step is skipped for orders outside the merchant's configured withdrawal scope (country and customer group).

## 1.0.0

+ New: First public release. Hyva Checkout compatibility for the pre-contractual Annex I block and the digital-content waiver step, rebuilt for Hyva Checkout's runtime.
