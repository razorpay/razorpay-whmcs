<?php

/**
 * WHMCS Razorpay Payment Callback File
 *
 * Verifying that the payment gateway module is active,
 * Validating an Invoice ID, Checking for the existence of a Transaction ID,
 * Logging the Transaction for debugging and Adding Payment to an Invoice.
 */

require_once __DIR__ . '/razorpay-php/Razorpay.php';
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = 'razorpay';
// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type'])
{
    die("Module Not Activated");
}

$keyId     = $gatewayParams["keyId"];
$keySecret = $gatewayParams["keySecret"];

// Retrieve data returned in payment gateway callback
$merchantOrderId   = $_POST["merchant_order_id"];

// Validate Callback Invoice ID.
$merchantOrderId = checkCbInvoiceID($merchantOrderId, $gatewayParams['name']);

$razorpayPaymentId = $_POST["razorpay_payment_id"];

// Check Callback Transaction ID.
checkCbTransID($razorpayPaymentId);

/**
 * Fetch amount to verify transaction
 */
// Fetch invoice to get the amount
$result = mysql_fetch_assoc(select_query('tblinvoices', 'total', array("id"=>$merchantOrderId)));
$amount = $result['total'];

// Check if amount is INR, convert if not.
$currency = getCurrency();

if ($currency['code'] !== 'INR')
{
    $result = mysql_fetch_array(select_query("tblcurrencies", "id", array("code"=>'INR')));
    $inrId = $result['id'];
    $convertedAmount = convertCurrency($amount, $currency['id'], $inrId);
}
else
{
    $convertedAmount = $amount;
}

// Amount in Paisa
$convertedAmount = 100 * $convertedAmount;
$success = true;
$error = "";

$attributes = array(
    'razorpay_payment_id' => $_POST["razorpay_payment_id"],
    'razorpay_order_id'   => $_SESSION['razorpay_order_id'],
    'razorpay_signature'  => $_POST['razorpay_signature'],
);

$api = new Api($keyId, $keySecret);

$success = true;

try
{
    // We verify payment signature
    $api->utility->verifyPaymentSignature($attributes);
}
catch (SignatureVerificationError $e)
{
    $success = false;
    $error ="WHMCS_ERROR: Request to Razorpay Failed. Error Message: " . $e->getMessage();
}

if ($success === true)
{
    //
    // Successful
    // Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    //
    addInvoicePayment($merchantOrderId, $razorpayPaymentId, $amount, 0, $gatewayModuleName);
    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
}
else
{
    //
    // Unsuccessful
    // Save to Gateway Log: name, data array, status
    //
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$razorpayPaymentId);
}

header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchantOrderId);
