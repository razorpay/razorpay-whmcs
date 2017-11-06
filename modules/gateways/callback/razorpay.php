<?php
/**
 * WHMCS Razorpay Payment Callback File
 *
 * Verifying that the payment gateway module is active,
 * Validating an Invoice ID, Checking for the existence of a Transaction ID,
 * Logging the Transaction for debugging and Adding Payment to an Invoice.
 */

require_once('razorpay-php/razorpay.php');
use Razorpay\Api\Api;

// Require libraries needed for gateway module functions.
include('../../../init.php');
include("../../../includes/functions.php");
include('../../../includes/gatewayfunctions.php');
include('../../../includes/invoicefunctions.php');

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

// Validate Callback Invoice ID.
//echo "i am here";
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $gatewayParams['name']);

// Check Callback Transaction ID.
//echo "i am before check transactionID";
checkCbTransID($razorpay_payment_id);

/**
 * Fetch amount to verify transaction
 */
//echo "i am before fetch invoice";
# Fetch invoice to get the amount
$result = mysql_fetch_assoc(select_query('tblinvoices', 'total', array("id"=>$merchant_order_id)));
$amount = $result['total'];

# Check if amount is INR, convert if not.
$currency = getCurrency();

if ($currency['code'] !== 'INR')
{
    $result = mysql_fetch_array(select_query("tblcurrencies", "id", array("code"=>'INR')));
    $inr_id = $result['id'];
    $converted_amount = convertCurrency($amount, $currency['id'], $inr_id);
}
else
{
    $converted_amount = $amount;
}

# Amount in Paisa
$converted_amount = 100 * $converted_amount;
$success = true;
$error = "";
//echo "i am before try";

// Retrieve data returned in payment gateway callback
$merchant_order_id   = $_POST["merchant_order_id"];
$razorpay_payment_id = $_POST["razorpay_payment_id"];
$razorpay_order_id   = $_SESSION['razorpay_order_id'];
$razorpay_signature  = $_POST['razorpay_signature'];

$api = new Api($keyId, $keySecret);
$payment = $api->payment->fetch($razorpay_payment_id);

try
{
    $signature = hash_hmac('sha256', $razorpay_order_id . '|' . $razorpay_payment_id, $keySecret);

    if (hash_equals($signature , $razorpay_signature))
    {
        $success = true;
    }
    else
    {
        $success = false;
        $error = "PAYMENT_ERROR: Payment failed";
    }
}
catch (Exception $e)
{
    $success = false;
    $error ="WHMCS_ERROR: Request to Razorpay Failed";
}

if ($success === true)
{
    # Successful
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($merchant_order_id, $razorpay_payment_id, $amount, 0, $gatewayModuleName);
    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
}
else
{
    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}

header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id);
