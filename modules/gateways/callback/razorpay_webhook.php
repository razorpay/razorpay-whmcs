<?php
/**
 * WHMCS Razorpay Payment Webhook File
 *
 * Continuing a delayed transaction that has been authorized but not captured,
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

$webhookBody = file_get_contents("php://input");
$data = json_decode($webhookBody, true);
$webhookSecret = "<YOUR-SECRET-HERE>";

$api = new Api($keyId, $keySecret);
try {
    $api->utility->verifyWebhookSignature($webhookBody, $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'], $webhookSecret);
} catch (\Razorpay\Api\Errors\SignatureVerificationError $e){

}

if(!empty($data['event']) && $data['event'] == "payment.authorized"){
    $rzpay = $data['payload']['payment']['entity'];
    $invoice = Capsule::table('tblinvoices')->find($rzpay['notes']['whmcs_invoice_id']);

    //Check validity of the invoice
    $invoiceid = checkCbInvoiceID($invoice->id, $gatewayParams['name']);

    //Check whether transaction exists in database or not
    checkCbTransID($rzpay['id']);

    $inr_currency = Capsule::table('tblcurrencies')->where('code','INR')->first();

    $user_currency = (object)getCurrency($invoice->userid);

    if($user_currency->id !== $inr_currency->id){
        $amount = convertCurrency($invoice->amount, $user_currency->id, $inr_currency->id);
    } else {
        $amount = $invoice->total;
    }

    $amount = $amount*100;

    if($rzpay['status'] == 'authorized'){
        $payment = $api->payment->fetch($rzpay['id'])->capture(['amount' => $amount]);
    }

    $payment = $api->payment->fetch($rzpay['id']);

    if($payment->status == "captured"){

        addInvoicePayment($invoice->id, $payment->id, $amount, $payment->fee, $gatewayParams["name"]);

        logTransaction($gatewayParams["name"], file_get_contents("php://input"), "Successful"); # Save to Gateway Log: name, data array, status
    }
}

