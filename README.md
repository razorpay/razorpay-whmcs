## Razorpay Payment Extension for WHMCS

Allows you to use Razorpay payment gateway with the WHMCS Store.

> This fork is modified and maintained by [Ucartz](https://www.ucartz.com) with reliability and PHP 8.2+/WHMCS 9 compatibility fixes on top of the upstream [razorpay/razorpay-whmcs](https://github.com/razorpay/razorpay-whmcs) module.

## Description

​This is the Razorpay payment gateway plugin for WHMCS. Allows merchants to accept credit cards, debit cards, netbanking, wallets, UPI and international payment methods with the WHMCS store. It uses a seamless integration, allowing the customer to pay on your website without being redirected away from your WHMCS website.

## Downloads: [whmcs-6 / whmcs-7 / whmcs-8 / whmcs-9][6] [whmcs-5][5]

## Bugs Fixed

The official module had a number of serious, long-standing bugs. Here's everything that was wrong and how it was fixed.

### Payments failing outright on modern PHP / WHMCS

- **Fatal error on PHP 7+**: the callback and webhook handlers called `mysql_fetch_assoc()` / `select_query()` — `mysql_fetch_assoc()` is a native PHP function that was removed from PHP core in PHP 7.0. WHMCS 9 requires PHP 8.2+, so this call would fatal-error on any modern install, breaking every payment. Replaced with proper database queries (Capsule ORM).
- **TLS 1.1 forced on every API call to Razorpay**: the SDK explicitly pinned the connection to TLS 1.1. TLS 1.1 is rejected by most gateways today — PCI-DSS v4.0.1 requires TLS 1.2+ — so this silently broke the underlying connection to Razorpay's API. Fixed to use TLS 1.2.
- **Outdated vendored SDK**: the bundled Razorpay SDK was version 2.8.1 with a HTTP library from 2015. Updated to the latest official release (2.9.3), keeping every other fix intact on top of it.

### Wrong amounts being credited to invoices

- **The most serious bug**: the payment callback credited invoices using the invoice's stored *total*, not the amount actually captured by Razorpay for that specific transaction. If a customer paid only a late fee, or made a partial payment, Razorpay correctly charged the smaller amount — but WHMCS then credited the invoice's full original total regardless, silently over- or under-crediting the account. Fixed to always fetch and credit the exact amount Razorpay actually captured for that payment.
- **Stale Razorpay order reuse**: if a customer opened an invoice, left, and returned after a late fee or credit was applied, the module could reuse a cached Razorpay order created for the old (wrong) amount — including silently reusing it if the API call to re-verify that order failed. Fixed to always create a fresh order whenever the current invoice balance no longer matches the existing order, or whenever that order can't be re-verified.
- **Inconsistent amount rounding**: order creation and the checkout form each rounded the same amount differently, causing floating-point "Amount Mismatch" errors. Both paths now round identically.
- **`receipt` field type rejection**: Razorpay's API requires the `receipt` field to be a string; WHMCS passes invoice IDs as integers, which Razorpay's API rejected outright with `expected string but provided ...`. Fixed by explicitly casting `receipt` and `currency` to string.

### Checkout and signature verification

- **Checkout button not working**: the module used an old, auto-embedding `<script data-*>` checkout pattern that Razorpay's own current documentation says is unreliable — Razorpay requires `checkout.open()` to be triggered by a direct user click. Rebuilt using Razorpay's current recommended Standard Checkout pattern (explicit button + click handler), which is the likely root cause of long-standing "Pay Now button does nothing" reports.
- **Missing response fields**: the checkout form only captured the payment ID from Razorpay's response, never the order ID or signature, so server-side signature verification had nothing to actually verify and would always fail. Fixed by capturing and submitting all three fields.
- **Unreliable session-based verification**: signature verification originally depended on a PHP session set when the invoice page was rendered. Under AJAX-driven "Make Payment" tabs, expired sessions, or 3DS/OTP delays, that session could be lost by the time the callback ran, breaking verification for an otherwise legitimate payment. Verification now prioritizes the order ID Razorpay's own checkout handler returns directly, falling back to the database and then session only if that's missing.
- **Null-pointer risk**: the database-backed order lookup could crash when no matching row existed instead of failing safely.
- **No duplicate-transaction protection**: neither the callback nor the webhook guarded against the same transaction being processed twice.
- **Double slashes in redirect URLs**: a trailing slash on the configured system URL produced broken `//viewinvoice.php` redirect links.
- **Stored-XSS-shaped gap**: client name/email and other dynamic values were concatenated directly into HTML/JS without escaping. Fixed with proper JSON/HTML escaping.

### Missing functionality

- **No refund support**: refunds initiated from the WHMCS admin area only updated the local WHMCS record — they never actually reached Razorpay. Added real refund support via the SDK, with a configurable refund speed (normal / instant).
- **Gateway fee never recorded**: every transaction showed a hardcoded $0 fee, making it impossible to reconcile actual Razorpay settlement amounts. Now records the real fee (including tax) Razorpay deducts, matching the Razorpay Dashboard.
- **Chargebacks/disputes silently ignored**: Razorpay's dispute webhooks were never handled at all — a lost chargeback left the invoice marked Paid and the service running with no reversal and no admin notification. Added full dispute webhook handling: disputes are logged for admin review while open, and a lost dispute automatically reverses the payment in WHMCS (invoice returns to unpaid, standard WHMCS overdue handling takes over).
- **Wrong internal gateway name recorded**: the module passed its human-readable display name ("Razorpay") instead of its internal system name to WHMCS's payment-recording function, causing the "Payment Method" column to show blank on the Transactions list and breaking other internal lookups keyed on the module name.
- **Missing company name and other PHP 8 warnings**: the checkout modal never showed the merchant's company name, and several `$_GET`/`$_POST` accesses and SDK-level deprecation notices were left unguarded on PHP 8.1+. All fixed.

## Installation

1. Ensure you have latest version of WHMCS installed.
2. Download the zip of this repo.
3. Upload the contents of the repo to your WHMCS Installation directory (content of module folder goes in module folder).

## Branches

 - Use the `master` branch if you are on WHMCS 6, WHMCS 7, WHMCS 8 or WHMCS 9
 - Use the `whmcs-5` branch if you are on WHMCS 5

## Configuration

1. Log into WHMCS as administrator (http://whmcs_installation/admin).
2. Navigate to Setup->Payments->Payment Gateways.
3. Choose Razorpay in the Activate dropdown and Activate it
4. Fill the Key Id and Key Secret.
5. Choose Convert for Processing to INR if your store has a different default currency. Make sure you update the exchange rate in that case in your currency management.
6. Click 'Save Changes'

### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email <integrations@razorpay.com>.

### License

This is licensed under the [MIT License][mit]

[mit]: https://opensource.org/licenses/MIT
[8]: https://github.com/razorpay/razorpay-whmcs/releases/tag/2.2.2
[7]: https://github.com/razorpay/razorpay-whmcs/releases/tag/2.2.1
[6]: https://github.com/razorpay/razorpay-whmcs/releases/tag/2.2.0
[5]: https://github.com/razorpay/razorpay-whmcs/releases/tag/v1.0.3
