<?php
/**
 * Ucartz: checks shared by the Razorpay callback (razorpay.php) and webhook (razorpay-webhook.php). Owner approved,
 * 21 Oct 2026, after a code review found:
 *   - the callback credited a payment to whatever invoice was posted, without checking that the Razorpay order and
 *     payment belong to that invoice or are in its currency;
 *   - the fee of non-INR payments was recorded in INR (Razorpay charges and reports fees in INR): a $14.00 payment
 *     got a fee of 38.85;
 *   - the callback and the webhook could record the same payment twice if they arrived together.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use Illuminate\Database\Capsule\Manager as Capsule;

if (!function_exists('ucartz_rzp_expected_currency')) {
    /** Currency code the invoice is charged in: the gateway's "convert to" currency if set, else the client's. */
    function ucartz_rzp_expected_currency($invoiceId, $gatewayParams)
    {
        $currencyId = (int) ($gatewayParams['convertto'] ?? 0);
        if ($currencyId <= 0) {
            $currencyId = (int) Capsule::table('tblinvoices')->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
                ->where('tblinvoices.id', (int) $invoiceId)->value('tblclients.currency');
        }
        $code = $currencyId > 0 ? Capsule::table('tblcurrencies')->where('id', $currencyId)->value('code') : null;
        if (!$code) {
            $code = Capsule::table('tblcurrencies')->where('default', 1)->value('code');
        }
        return strtoupper((string) $code);
    }

    /**
     * Razorpay's fee for a payment, in the payment's currency. Razorpay reports `fee` in paise of INR (its settlement
     * currency) for every payment, so a USD/GBP/CAD/... fee is converted with WHMCS's exchange rates (tblcurrencies,
     * updated daily). 0 when there is no fee yet or no rate.
     */
    function ucartz_rzp_fee($payment)
    {
        $feeInr = round(((float) ($payment['fee'] ?? 0)) / 100, 2);
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        if ($feeInr <= 0 || $currency === 'INR') {
            return $feeInr;
        }
        $rates = Capsule::table('tblcurrencies')->whereIn('code', ['INR', $currency])->pluck('rate', 'code')->all();
        if (empty($rates['INR']) || empty($rates[$currency]) || (float) $rates['INR'] <= 0) {
            return 0.0;
        }
        return round($feeInr / (float) $rates['INR'] * (float) $rates[$currency], 2);
    }

    /** True if WHMCS already holds a transaction with this Razorpay payment ID. */
    function ucartz_rzp_recorded($paymentId)
    {
        return Capsule::table('tblaccounts')->where('transid', (string) $paymentId)->exists();
    }

    /** One process at a time per payment (callback and webhook can arrive within the same second). */
    function ucartz_rzp_lock($paymentId)
    {
        $row = Capsule::selectOne('SELECT GET_LOCK(?, 15) AS l', ['ucartz_rzp_' . substr((string) $paymentId, 0, 40)]);
        return isset($row->l) && (int) $row->l === 1;
    }

    function ucartz_rzp_unlock($paymentId)
    {
        Capsule::select('SELECT RELEASE_LOCK(?)', ['ucartz_rzp_' . substr((string) $paymentId, 0, 40)]);
    }

    /**
     * Checks a Razorpay payment (and its order) against the invoice it is about to be credited to. Returns '' when
     * everything matches, else the reason. $order may be null when the caller has the invoice from the signed order
     * notes already (webhook).
     */
    function ucartz_rzp_mismatch($invoiceId, $payment, $order, $verifiedOrderId, $gatewayParams)
    {
        $invoiceId = (string) (int) $invoiceId;
        if ($verifiedOrderId !== null && (string) ($payment['order_id'] ?? '') !== (string) $verifiedOrderId) {
            return 'payment belongs to order ' . ($payment['order_id'] ?? 'none') . ', not the verified order ' . $verifiedOrderId;
        }
        if ($order !== null) {
            $notes = $order['notes'] ?? [];
            $noteInvoice = (is_array($notes) || $notes instanceof \ArrayAccess) ? ($notes['whmcs_order_id'] ?? '') : (is_object($notes) ? ($notes->whmcs_order_id ?? '') : '');
            if ((string) ($order['receipt'] ?? '') !== $invoiceId && (string) $noteInvoice !== $invoiceId) {
                return 'Razorpay order ' . $verifiedOrderId . ' was created for invoice ' . ($order['receipt'] ?? '?') . ', not ' . $invoiceId;
            }
        }
        $expected = ucartz_rzp_expected_currency($invoiceId, $gatewayParams);
        if (strtoupper((string) ($payment['currency'] ?? '')) !== $expected) {
            return 'payment currency ' . ($payment['currency'] ?? '?') . ' does not match the invoice currency ' . $expected;
        }
        if (($payment['status'] ?? '') !== 'captured') {
            return 'payment status is ' . ($payment['status'] ?? '?') . ', not captured';
        }
        return '';
    }
}
