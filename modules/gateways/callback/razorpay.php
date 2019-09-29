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
if(!class_exists("Razorpay\Api")){
    require_once '../razorpay/vendor/autoload.php';
}
use WHMCS\Database\Capsule;
use Razorpay\Api\Api;

// Detect module name from filename.
$gatewayModuleName = 'razorpay';
// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);
// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
$keyId = $gatewayParams["keyId"];
$keySecret = $gatewayParams["keySecret"];

$invoice_id = $_POST["merchant_order_id"];
$razorpay_payment_id = $_POST["razorpay_payment_id"];

checkCbInvoiceID($invoice_id, $gatewayParams['name']);

checkCbTransID($razorpay_payment_id);

/**
 * Fetch amount to verify transaction
 */
# Fetch invoice details
$invoice = Capsule::table('tblinvoices')->find($invoice_id);
$amount = $invoice->total;

$user_currency = (object)getCurrency($invoice->userid);
$inr_currency = Capsule::table('tblcurrencies')->where('code','INR')->first();

if($user_currency->id != $inr_currency->id) {
    $converted_amount = convertCurrency($amount, $user_currency->id, $inr_currency->id);
} else {
    $converted_amount = $amount;
}


$converted_amount = 100*$converted_amount;
$success = true;
$error = "";
$api = new Api($keyId, $keySecret);
try {
    $api->payment->fetch($razorpay_payment_id)->capture(['amount' => $converted_amount]);
    $payment = $api->payment->fetch($razorpay_payment_id);
    if($payment->status == "captured"){
        $success = true;
    }
} catch (\Exception $e) {
    $success = false;
    $error = "WHMCS_ERROR: Request to Razorpay Failed";
}

if ($success === true) {
    addInvoicePayment($invoice_id, $razorpay_payment_id, $amount, $payment->fee, $gatewayParams["name"]);
    logTransaction($gatewayParams["name"], $_POST, "Successful");
} else {
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}
header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $invoice_id);
