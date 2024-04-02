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
$merchant_order_id   = (isset($_POST['merchant_order_id']) === true) ? $_POST['merchant_order_id'] : $_GET['merchant_order_id'];
$razorpay_payment_id = $_POST['razorpay_payment_id'];

// Validate Callback Invoice ID.
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $gatewayParams['name']);

/**
* Fetch amount to verify transaction
*/
# Fetch invoice to get the amount and userid
$result = mysql_fetch_assoc(select_query('tblinvoices', '*', array("id"=>$merchant_order_id)));

#check whether order is already paid or not, if paid then redirect to complete page
if($result['status'] === 'Paid')
{
    header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id); // nosemgrep : php.lang.security.non-literal-header.non-literal-header
    
    exit;
}

$amount = $result['total'];

$error = "";

try
{
    verifySignature($merchant_order_id, $_POST, $gatewayParams);

    # Successful
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($merchant_order_id, $razorpay_payment_id, $amount, 0, $gatewayParams["name"]);

    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
}
catch (Errors\SignatureVerificationError $e)
{
    $error = 'WHMCS_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();

    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}

header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id); // nosemgrep : php.lang.security.non-literal-header.non-literal-header

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
    $razorpayOrderId = "";

    if (isset($_SESSION[$sessionKey]) === true)
    {
        $razorpayOrderId = $_SESSION[$sessionKey];
    }
    else
    {
        logTransaction($gatewayParams['name'], $sessionKey, "Session not found");
        try
        {
            if (isset($order_no) === true)
            {
                $rzpOrderMapping = new RZPOrderMapping($gatewayParams['name']);
                $razorpayOrderId = $rzpOrderMapping->getRazorpayOrderID($order_no);
            }
            else
            {
                $error = "merchant_order_id is not set";
                logTransaction($gatewayParams['name'], $error, "Validation Failure");
            }
        }
        catch (Exception $e)
        {
            logTransaction($gatewayParams['name'], $e->getMessage(), "Unsuccessful - Fetch Order");
        }
    }

    $attributes[RAZORPAY_ORDER_ID] = $razorpayOrderId;
    $api->utility->verifyPaymentSignature($attributes);
}
