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
// Retrieve data returned in payment gateway callback
$merchant_order_id = $_POST["merchant_order_id"];
$razorpay_payment_id = $_POST["razorpay_payment_id"];
// Validate Callback Invoice ID.
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $gatewayParams['name']);
// Check Callback Transaction ID.
checkCbTransID($razorpay_payment_id);
/**
 * Fetch amount to verify transaction
 */
# Fetch invoice to get the amount
$result = mysql_fetch_assoc(select_query('tblinvoices', 'total', array("id"=>$merchant_order_id)));
$amount = $result['total'];
# Check if amount is INR, convert if not.
$currency = getCurrency();
if ($currency['code'] !== 'INR') {
    $result = mysql_fetch_array(select_query("tblcurrencies", "id", array("code"=>'INR')));
    $inr_id = $result['id'];
    $converted_amount = convertCurrency($amount, $currency['id'], $inr_id);
} else {
    $converted_amount = $amount;
}
# Amount in Paisa
$converted_amount = 100*$converted_amount;
$success = true;
$error = "";
try {
    $url = 'https://api.razorpay.com/v1/payments/'.$razorpay_payment_id.'/capture';
    $fields_string="amount=$converted_amount";
    //cURL Request
    $ch = curl_init();
    //set the url, number of POST vars, POST data
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, $keyId . ":" . $keySecret);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //execute post
    $result = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($result === false) {
        $success = false;
        $error = 'Curl error: ' . curl_error($ch);
    } else {
        $response_array = json_decode($result, true);
        //Check success response
        if ($http_status === 200 and isset($response_array['error']) === false) {
            $success = true;
        } else {
            $success = false;
            if (!empty($response_array['error']['code'])) {
                $error = $response_array['error']['code'].":".$response_array['error']['description'];
            } else {
                $error = "RAZORPAY_ERROR: Invalid Response <br/>".$result;
            }
        }
    }
        
    //close connection
    curl_close($ch);
} catch (Exception $e) {
    $success = false;
    $error ="WHMCS_ERROR: Request to Razorpay Failed";
}
if ($success === true) {
    # Successful
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($merchant_order_id, $razorpay_payment_id, $amount, 0, $gatewayParams["name"]);
    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
} else {
    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}
header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id);
