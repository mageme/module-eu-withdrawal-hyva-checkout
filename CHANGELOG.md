## 1.1.0

+ New: Works with Hyvä Checkout 3 as a native Magewire 3 component, with or without Hyvä's Magewire 1 backward-compatibility module. Hyvä Checkout 1.3 stays supported.
+ New: Ships its own translations into 22 EU languages, so its texts no longer depend on the base module's dictionary.
- Fix: Digital-content consent is recorded only for the digital items in the customer's cart and only for the consent text they were shown, never from checkout data the browser can change.
- Fix: Changing the billing country after giving consent on the one-page checkout no longer ends in a "please reload checkout" error at Place Order.
* Change: If the address, the cart or the consent text changes after the customer ticked the consent boxes, both boxes are cleared and the current text is shown, so consent is given again for what is actually being bought. Changing the quantity alone keeps the consent.

## 1.0.5

- Fix: The digital-content consent step on Hyvä Checkout keeps its styling — its styles were being left out when the theme's Tailwind bundle was built.

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
