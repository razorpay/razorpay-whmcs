<?php
/**
 * WHMCS Razorpay Payment Callback File
 *
 * Verifying that the payment gateway module is active,
 * Validating an Invoice ID, Checking for the existence of a Transaction ID,
 * Logging the Transaction for debugging and Adding Payment to an Invoice.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/razorpay-sdk/Razorpay.php';
require_once __DIR__ . '/rzpordermapping.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Illuminate\Database\Capsule\Manager as Capsule;

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type'])
{
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
$merchant_order_id   = (isset($_POST['merchant_order_id']) === true) ? $_POST['merchant_order_id'] : (isset($_GET['merchant_order_id']) === true ? $_GET['merchant_order_id'] : null);
$razorpay_payment_id = (isset($_POST['razorpay_payment_id']) === true) ? $_POST['razorpay_payment_id'] : null;

// Guard against a race between order/invoice creation and this callback.
// When a customer pays immediately after placing a new order, Razorpay's
// checkout can complete and call back within seconds - sometimes before the
// brand-new invoice is visible to checkCbInvoiceID() below (confirmed via
// direct testing: an old, settled invoice always verifies fine; a
// just-created or nonexistent one produces a blank, silent failure with no
// error output at all - checkCbInvoiceID() gives us no way to retry after
// the fact, since it die()s immediately). Waiting briefly for our own
// direct visibility of the row is a reasonable proxy: if we can see it,
// checkCbInvoiceID() finding it too becomes overwhelmingly likely.
for ($invoiceVisibilityAttempt = 0; $invoiceVisibilityAttempt < 5; $invoiceVisibilityAttempt++)
{
    if (Capsule::table('tblinvoices')->where('id', $merchant_order_id)->exists() === true)
    {
        break;
    }

    usleep(400000); // 400ms
}

// Validate Callback Invoice ID.
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $gatewayParams['name']);

/**
* Fetch amount to verify transaction
*/
# Fetch invoice to get the amount and userid
$result = Capsule::table('tblinvoices')->where('id', $merchant_order_id)->first();

#check whether order is already paid or not, if paid then redirect to complete page
if (isset($result) === true and $result->status === 'Paid')
{
    header("Location: " . rtrim($gatewayParams['systemurl'], '/') . "/viewinvoice.php?id=" . $merchant_order_id); // nosemgrep : php.lang.security.non-literal-header.non-literal-header

    exit;
}

$error = "";

try
{
    // Check Callback Transaction ID.
    checkCbTransID($razorpay_payment_id);

    verifySignature($merchant_order_id, $_POST, $gatewayParams);

    // Credit the amount Razorpay actually captured for THIS payment - never
    // a value re-derived from the invoice (e.g. its `total` column, as this
    // callback used to do). An invoice's total does not reflect what a
    // given transaction actually collected: e.g. when a customer pays off
    // only an outstanding late fee, Razorpay correctly charges just that
    // smaller amount, but the invoice's total column still holds the full
    // original amount. Crediting that instead of the real captured amount
    // wrongly marks the invoice as fully paid despite Razorpay only having
    // collected a fraction of it - silently under-collecting revenue.
    $api = getApiInstance($gatewayParams['keyId'], $gatewayParams['keySecret']);
    $razorpayPayment = $api->payment->fetch($razorpay_payment_id);
    $amountPaid = ((float) $razorpayPayment['amount']) / 100;

    // Razorpay returns fee (total gateway charge including GST) in paise.
    // Only populated after capture; null for authorize-only payments — fall
    // back to 0 so the invoice is still credited correctly in that case.
    // This value is what Razorpay deducts from your settlement and matches
    // the "Fee" column in your Razorpay Dashboard.
    $gatewayFee = round(((float) ($razorpayPayment['fee'] ?? 0)) / 100, 2);

    # Successful
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($merchant_order_id, $razorpay_payment_id, $amountPaid, $gatewayFee, $gatewayModuleName);

    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
}
catch (Errors\SignatureVerificationError $e)
{
    $error = 'WHMCS_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();

    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$razorpay_payment_id);
}
catch (Throwable $e)
{
    // Signature was valid (the payment is genuine) but we could not confirm
    // the actual captured amount from Razorpay - e.g. the network call to
    // fetch the payment failed or threw something unexpected. Catching
    // Throwable (not just Exception) here is deliberate: a TLS/connection
    // failure talking to Razorpay's API turned out to be reachable through
    // this exact call in the past and produced a blank, uncaught failure
    // that never reached this log line at all. Whatever the failure type,
    // it must land here and produce a normal redirect + log entry, not a
    // blank page. Deliberately do NOT apply any payment here - guessing an
    // amount is exactly the dangerous behaviour being fixed. This needs
    // manual reconciliation instead.
    $error = 'WHMCS_ERROR: Payment to Razorpay succeeded but the captured amount could not be verified: ' . $e->getMessage();

    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error." Payment id: ".$razorpay_payment_id." was NOT applied to invoice ".$merchant_order_id.". Verify the actual amount captured on the Razorpay Dashboard and apply it to the invoice manually.");
}

header("Location: " . rtrim($gatewayParams['systemurl'], '/') . "/viewinvoice.php?id=" . $merchant_order_id); // nosemgrep : php.lang.security.non-literal-header.non-literal-header

/**
* @codeCoverageIgnore
*/
function getApiInstance($key,$keySecret)
{
    return new Api($key, $keySecret);
}

/**
 * Verify the signature on payment success
 * @param  int $order_no
 * @param  array $response
 * @param  array $gatewayParams
 * @return
 */
function verifySignature(int $order_no, array $response, $gatewayParams)
{
    $api = getApiInstance($gatewayParams['keyId'], $gatewayParams['keySecret']);

    $attributes = array(
        RAZORPAY_PAYMENT_ID => (isset($response[RAZORPAY_PAYMENT_ID]) === true) ? $response[RAZORPAY_PAYMENT_ID] : "",
        RAZORPAY_SIGNATURE  => (isset($response[RAZORPAY_SIGNATURE]) === true) ? $response[RAZORPAY_SIGNATURE] : "",
    );

    $sessionKey = getOrderSessionKey($order_no);
    $razorpayOrderId = null;

    // 1. Direct POST from Razorpay Checkout handler
    if (empty($response[RAZORPAY_ORDER_ID]) === false)
    {
        $razorpayOrderId = $response[RAZORPAY_ORDER_ID];
    }

    // 2. DB fallback (tblrzpordermapping)
    if (empty($razorpayOrderId) === true)
    {
        try
        {
            $rzpOrderMapping = new RZPOrderMapping($gatewayParams['name']);
            $razorpayOrderId = $rzpOrderMapping->getRazorpayOrderID($order_no);
        }
        catch (Exception $e)
        {
            logTransaction($gatewayParams['name'], $e->getMessage(), "Unsuccessful - Fetch Order from DB");
        }
    }

    // 3. Session fallback
    if (empty($razorpayOrderId) === true)
    {
        if (isset($_SESSION[$sessionKey]) === true)
        {
            $razorpayOrderId = $_SESSION[$sessionKey];
        }
        else
        {
            logTransaction($gatewayParams['name'], "Order ID not found in POST, DB or Session", "Unsuccessful - Verification");
            throw new Exception("Razorpay Order ID not found.");
        }
    }

    $attributes[RAZORPAY_ORDER_ID] = $razorpayOrderId;
    $api->utility->verifyPaymentSignature($attributes);
}
