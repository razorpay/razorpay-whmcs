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
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

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
$merchant_order_id   = $_POST["merchant_order_id"];
$razorpay_payment_id = $_POST["razorpay_payment_id"];

$success = false;
$error = "";

try
{
    verifySignature($merchant_order_id, $_POST, $gatewayParams);
    $success = true;
}
catch (Errors\SignatureVerificationError $e)
{
    $error = 'WHMCS_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();
}

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
        RAZORPAY_PAYMENT_ID => $response[RAZORPAY_PAYMENT_ID],
        RAZORPAY_SIGNATURE  => $response[RAZORPAY_SIGNATURE],
    );

    $sessionKey = getOrderSessionKey($order_no);

    $attributes[RAZORPAY_ORDER_ID] = $_SESSION[$sessionKey];

    $api->utility->verifyPaymentSignature($attributes);
}

// Validate Callback Invoice ID.
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $gatewayParams['name']);

// Check Callback Transaction ID.
checkCbTransID($razorpay_payment_id);

/**
 * Fetch amount to verify transaction
 */
# Fetch invoice to get the amount and userid
$result = mysql_fetch_assoc(select_query('tblinvoices', 'total, userid', array("id"=>$merchant_order_id)));

$amount = $result['total'];

# Check if amount is INR, convert if not.
//$currency = getCurrency();
$result = mysql_fetch_assoc(select_query('tblclients', 'currency', array("id"=>$result['userid'])));

$currency_id = $result['currency'];

$result = mysql_fetch_array(select_query("tblcurrencies", "id", array("code"=>'INR')));

$inr_id = $result['id'];

if($currency_id != $inr_id)
{
    $converted_amount = convertCurrency($amount, $currency_id, $inr_id);
}
else
{
    $converted_amount = $amount;
}

# Amount in Paisa
$converted_amount = 100*$converted_amount;

if ($success === true)
{
    # Successful
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($merchant_order_id, $razorpay_payment_id, $amount, 0, $gatewayParams["name"]);
    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
}
else
{
    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}
header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id);
