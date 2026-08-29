# Changelog

All notable changes to this module are documented here.

## 3.0.1 — 2026-08-29

### Fixed
- **Critical: payment could not be completed when paying immediately after placing a new order.** The checkout button previously rendered inside a real `<form>` element present on the page from initial load. Some client-area storefronts auto-submit any payment-looking form shortly after page load, without user interaction (this codebase's own theme has a documented pattern for this, used for a different, 3D-Secure flow) — if that logic also caught this gateway's form, it submitted it empty, before the customer ever saw the Pay Now button or Razorpay's checkout ever opened, landing on a blank page with no popup and no payment collected. Fixed by never rendering a `<form>` element at all — the button is now a plain `<button>`, and the POST to the callback is constructed and sent only from inside Razorpay's own success handler, which fires only once a real payment has actually completed.
- **TLS 1.1 was still being forced on Razorpay API calls.** Razorpay's own API rejects TLS 1.1 outright (PCI-DSS v4.0.1 requires TLS 1.2+). An earlier fix for this was only half-applied when the vendored SDK was updated, leaving one `curl_setopt()` call still pinned to the old, rejected TLS version — silently breaking the specific API call used to verify a payment's captured amount.
- Hardened the callback's fallback error handling from `catch (Exception $e)` to `catch (Throwable $e)`, so a failure in that verification call always lands in a logged, redirecting branch instead of risking an unhandled failure.
- Added a short bounded wait (up to 2 seconds) for a brand-new invoice to become visible before validating it in both the callback and the `order.paid` webhook, closing a race condition between order placement and an unusually fast payment completion.

## 3.0.0 — 2026-08-29

First fully versioned Ucartz release. `RAZORPAY_WHMCS_VERSION` had been left at Razorpay's original `2.2.2` through the entire rebuild described below; this release establishes a proper baseline going forward.

### Fixed
- Removed `mysql_fetch_assoc()`/`select_query()` calls in the callback and webhook handlers — `mysql_fetch_assoc()` was removed from PHP core in PHP 7.0, so these would fatal-error outright on PHP 8.2+ (required by WHMCS 9), breaking every payment. Replaced with the Capsule ORM already used elsewhere in the module.
- **Invoices could be credited with the wrong amount.** The callback applied an invoice's stored total instead of the amount Razorpay actually captured for a given transaction — e.g. a customer paying only an outstanding late fee had the full original invoice total credited instead, silently over- or under-collecting revenue. Fixed to always credit exactly what Razorpay reports as captured for that specific payment.
- Fixed stale Razorpay order reuse: if a late fee or credit changed an invoice's balance after an order was created, or if the API call to re-verify an existing order failed, the module could silently reuse an order created for the wrong amount.
- Fixed the checkout form never submitting `razorpay_order_id` or `razorpay_signature` back to the callback, which broke server-side signature verification.
- Fixed unreliable session-based signature verification (PHP session loss under AJAX-driven "Make Payment" tabs, or expiry during 3DS/OTP delays) by prioritizing the order ID Razorpay's own checkout handler returns directly, falling back to the database and then session only if that's missing.
- Fixed a null-pointer risk in the database-backed order lookup when no matching row exists.
- Fixed the module passing its display name ("Razorpay") instead of its internal system name ("razorpay") when recording payments, which caused a blank "Payment Method" column on the Transactions list and broke other internal lookups keyed on the module name.
- Fixed inconsistent amount rounding between order creation and the checkout form, which caused floating-point "Amount Mismatch" errors.
- Fixed the Razorpay API rejecting the `receipt` field when passed as an integer instead of a string.
- Fixed a double-slash in redirect URLs when the configured system URL had a trailing slash.
- Added duplicate-transaction protection to both the callback and the webhook handler.
- Hardened `$_GET`/`$_POST` access with `isset()` checks (PHP 8 warnings) and fixed several PHP 8.1+ deprecation notices in the vendored SDK.
- Fixed a stored-XSS-shaped gap where client-supplied name/email values were concatenated directly into HTML/JS without escaping.

### Added
- Rebuilt the checkout flow using Razorpay's current recommended Standard Checkout integration pattern (explicit button + click handler), replacing the old auto-embedding `<script data-*>` form.
- Added refund support (`razorpay_refund()`) using the official SDK, with a configurable refund speed (normal / instant) — refunds initiated from the WHMCS admin area now actually reach Razorpay.
- Added chargeback/dispute webhook handling (`payment.dispute.*`) — a lost dispute now automatically reverses the payment in WHMCS via `paymentReversed()`.
- Added real gateway fee recording on each transaction (previously hardcoded to 0), matching the "Fee" column in the Razorpay Dashboard.
- Added the merchant's company name to the checkout modal (previously missing).
- Updated the vendored Razorpay SDK from 2.8.1 to the latest release (2.9.3).

### Compatibility
- Full compatibility with WHMCS 9 and PHP 8.2–8.4.
