<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

/**
 * Event constants
 */
const ORDER_PAID  = 'order.paid';

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

/**
 * Process a Razorpay Webhook. We exit in the following cases:
 * - Successful processed
 * - Exception while fetching the payment
 *
 * It passes on the webhook in the following cases:
 * - invoice_id set in payment.authorized
 * - order refunded
 * - Invalid JSON
 * - Signature mismatch
 * - Secret isn't setup
 * - Event not recognized
 *
 * @return void|WP_Error
 * @throws Exception
 */

$post = file_get_contents('php://input');

$data = json_decode($post, true);

if (json_last_error() !== 0)
{
    return;
}

$enabled = $gatewayParams['enableWebhook'];

if ($enabled === 'on' and
    (empty($data['event']) === false))
{
    if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
    {
        $razorpayWebhookSecret = $gatewayParams['webhookSecret'];

        //
        // If the webhook secret isn't set on wordpress, return
        //
        if (empty($razorpayWebhookSecret) === true)
        {
            return;
        }

        try
        {
            $this->api->utility->verifyWebhookSignature($post,
                                                        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                                                        $razorpayWebhookSecret);
        }
        catch (Errors\SignatureVerificationError $e)
        {
            $log = array(
                'message'   => $e->getMessage(),
                'data'      => $data,
                'event'     => 'razorpay.whmcs.signature.verify_failed'
            );

            logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$e->getMessage());
            return;
        }

        switch ($data['event'])
        {
            case ORDER_PAID:
                return orderPaid($data, $gatewayParams);

            default:
                return;
        }
    }
}


/**
 * Order Paid webhook
 *
 * @param array $data
 */
function orderPaid(array $data, $gatewayParams)
{
    // We don't process subscription/invoice payments here
    if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
    {
        return;
    }

    //
    // Order entity should be sent as part of the webhook payload
    //
    $orderId = $data['payload']['order']['entity']['notes']['whmcs_order_id'];

    $command = 'GetOrders';

    $postData = array(
        'id' => $orderId,
    );

    $order = localAPI($command, $postData);

    // If it is already marked as paid or failed ignore the event
    if ($order['orders']['order'][0]['paymentstatus'] === 'Paid')
    {
        return;
    }

    $success = false;
    $error = "";
    $errorMessage = 'The payment has failed.';

    $razorpayPaymentId = $data['payload']['payment']['entity']['id'];


    $payment = getPaymentEntity($razorpayPaymentId, $data, $gatewayParams);

    $amount = getOrderAmountAsInteger($order);

    if($payment['amount'] === $amount)
    {
        $success = true;
    }
    else
    {
        $error = 'WHMCS_ERROR: Payment to Razorpay Failed. Amount mismatch.';
    }

	$log = [
		'merchant_order_id'   => $orderId,
		'razorpay_payment_id' => $razorpayPaymentId
	];

    if ($success === true)
	{
	    # Successful
	    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
	    addInvoicePayment($orderId, $razorpayPaymentId, $amount, 0, $gatewayParams["name"]);
	    logTransaction($gatewayParams["name"], $log, "Successful"); # Save to Gateway Log: name, data array, status
	}
	else
	{
	    # Unsuccessful
	    # Save to Gateway Log: name, data array, status
	    logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$razorpayPaymentId);
	}

    // Graceful exit since payment is now processed.
    exit;
}

function getPaymentEntity($razorpayPaymentId, $data, $gatewayParams)
{
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);

    try
    {
        $payment = $api->payment->fetch($razorpayPaymentId);
    }
    catch (Exception $e)
    {
    	$log = array(
            'message'         => $e->getMessage(),
            'payment_id'      => $razorpayPaymentId,
            'event'           => $data['event']
        );

        logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$e->getMessage() . ". Please check razorpay dashboard for Payment id: ".$razorpayPaymentId);

        exit;
    }

    return $payment;
}

/**
 * Returns the order amount, rounded as integer
 * @param WHMCS_Order $order WHMCS Order instance
 * @return int Order Amount
 */
function getOrderAmountAsInteger($order)
{
    return (int) round($order['orders']['order'][0]['amount'] * 100);
}

?>