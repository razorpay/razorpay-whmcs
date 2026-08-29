<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/razorpay-sdk/Razorpay.php';
require_once __DIR__ . '/rzpordermapping.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Event constants
 */
const ORDER_PAID                      = 'order.paid';
const PAYMENT_DISPUTE_CREATED         = 'payment.dispute.created';
const PAYMENT_DISPUTE_WON             = 'payment.dispute.won';
const PAYMENT_DISPUTE_LOST            = 'payment.dispute.lost';
const PAYMENT_DISPUTE_CLOSED          = 'payment.dispute.closed';
const PAYMENT_DISPUTE_UNDER_REVIEW    = 'payment.dispute.under_review';
const PAYMENT_DISPUTE_ACTION_REQUIRED = 'payment.dispute.action_required';

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

$api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);

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
            $api->utility->verifyWebhookSignature($post,
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

            header('HTTP/1.1 401 Unauthorized', true, 401);

            return;
        }

        switch ($data['event'])
        {
            case ORDER_PAID:
                return orderPaid($data, $gatewayParams, $gatewayModuleName);

            // Dispute opened — log and alert admin but do NOT reverse yet.
            // The dispute may still be won; reversing here would cancel the
            // customer's service before the bank has made a ruling.
            case PAYMENT_DISPUTE_CREATED:
            case PAYMENT_DISPUTE_UNDER_REVIEW:
            case PAYMENT_DISPUTE_ACTION_REQUIRED:
                return disputeOpened($data, $gatewayParams);

            // Bank ruled against us — money is gone. Reverse payment in WHMCS
            // so the invoice goes back to unpaid and service suspension kicks
            // in automatically via WHMCS overdue handling.
            case PAYMENT_DISPUTE_LOST:
                return disputeLost($data, $gatewayParams);

            // Dispute resolved in our favour — no action needed, payment stands.
            case PAYMENT_DISPUTE_WON:
            case PAYMENT_DISPUTE_CLOSED:
                return disputeResolved($data, $gatewayParams);

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
function orderPaid(array $data, $gatewayParams, $gatewayModuleName)
{
    // We don't process subscription/invoice payments here
    if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
    {
        logTransaction($gatewayParams['name'], "returning order.paid webhook", "Invoice ID exists");
        return;
    }

    //
    // Order entity should be sent as part of the webhook payload
    //
    $orderId = $data['payload']['order']['entity']['notes']['whmcs_order_id'];
    $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

    // Guard against a race between order/invoice creation and this webhook -
    // see the matching comment in razorpay.php for the confirmed failure
    // mode this avoids.
    for ($invoiceVisibilityAttempt = 0; $invoiceVisibilityAttempt < 5; $invoiceVisibilityAttempt++)
    {
        if (Capsule::table('tblinvoices')->where('id', $orderId)->exists() === true)
        {
            break;
        }

        usleep(400000); // 400ms
    }

    // Validate Callback Invoice ID.
    $merchant_order_id = checkCbInvoiceID($orderId, $gatewayParams['name']);

    // Check Callback Transaction ID.
    checkCbTransID($razorpayPaymentId);

    $orderTableRow = Capsule::table('tblorders')->select('id')->where('invoiceid', $orderId)->first();

    if (isset($orderTableRow) === false)
    {
        logTransaction($gatewayParams['name'], "order not found for invoice ".$orderId, "INFO");
        return;
    }

    $command = 'GetOrders';

    $postData = array(
        'id' => $orderTableRow->id,
    );

    $order = localAPI($command, $postData);

    // If order detail not found then ignore.
    // If it is already marked as paid or failed ignore the event
    if($order['totalresults'] == 0 or $order['orders']['order'][0]['paymentstatus'] === 'Paid')
    {
        logTransaction($gatewayParams['name'], "order detail not found or already paid or failed", "INFO");
        return;
    }

    $success = false;
    $error = "";
    $error = 'The payment has failed.';

    $amount = getOrderAmountAsInteger($order);

    if($data['payload']['payment']['entity']['amount'] === $amount)
    {
        $success = true;
    }
    else
    {
        $error = 'WHMCS_ERROR: Payment to Razorpay Failed. Amount mismatch.';
    }

    $log = [
        'merchant_order_id'   => $orderId,
        'razorpay_payment_id' => $razorpayPaymentId,
        'webhook' => true
    ];

    if ($success === true)
    {
        # Successful
        # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
        $orderAmount=$order['orders']['order'][0]['amount'];
        
        addInvoicePayment($orderId, $razorpayPaymentId, $orderAmount, 0, $gatewayModuleName);
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

/**
 * Handle dispute opened / under review / action required.
 * Logs the event and notifies admin without reversing the payment — the
 * dispute may still be won. Only disputeLost() actually reverses.
 *
 * Payload path for payment ID : payload.payment.entity.id
 * Payload path for invoice ID : payload.payment.entity.notes.whmcs_order_id
 *
 * @param array $data          Decoded webhook payload
 * @param array $gatewayParams WHMCS gateway configuration
 */
function disputeOpened(array $data, $gatewayParams)
{
    $paymentEntity = $data['payload']['payment']['entity'] ?? [];
    $disputeEntity = $data['payload']['dispute']['entity']  ?? [];

    $razorpayPaymentId = $paymentEntity['id']                          ?? 'unknown';
    $whmcsInvoiceId    = $paymentEntity['notes']['whmcs_order_id']     ?? null;
    $disputeId         = $disputeEntity['id']                          ?? 'unknown';
    $reasonCode        = $disputeEntity['reason_code']                 ?? 'unknown';
    $reasonDescription = $disputeEntity['reason_description']          ?? '';
    $disputeAmount     = isset($disputeEntity['amount'])
                         ? (((float) $disputeEntity['amount']) / 100)
                         : 'unknown';
    $event             = $data['event'];

    $log = [
        'event'              => $event,
        'dispute_id'         => $disputeId,
        'razorpay_payment_id'=> $razorpayPaymentId,
        'whmcs_invoice_id'   => $whmcsInvoiceId,
        'dispute_amount'     => $disputeAmount,
        'reason_code'        => $reasonCode,
        'reason_description' => $reasonDescription,
        'action'             => 'No automatic reversal — dispute may still be won. Review in Razorpay Dashboard.',
    ];

    logTransaction($gatewayParams['name'], $log, "Dispute Opened - Action Required");

    // Return 200 OK so Razorpay does not retry the webhook.
    http_response_code(200);
    exit;
}

/**
 * Handle payment.dispute.lost — bank ruled against us, money is gone.
 * Calls WHMCS paymentReversed() which:
 *   - Marks the invoice as unpaid / Collections
 *   - Reverts any next-due-date increment for the associated service
 *   - Sends admin notification email
 *   - Records the reversal in transaction history
 *
 * Requires WHMCS 7.2+
 *
 * @param array $data          Decoded webhook payload
 * @param array $gatewayParams WHMCS gateway configuration
 */
function disputeLost(array $data, $gatewayParams)
{
    $paymentEntity = $data['payload']['payment']['entity'] ?? [];
    $disputeEntity = $data['payload']['dispute']['entity']  ?? [];

    $razorpayPaymentId = $paymentEntity['id']                      ?? null;
    $whmcsInvoiceId    = $paymentEntity['notes']['whmcs_order_id'] ?? null;
    $disputeId         = $disputeEntity['id']                      ?? null;
    $reasonCode        = $disputeEntity['reason_code']             ?? 'unknown';

    if (empty($razorpayPaymentId) || empty($whmcsInvoiceId) || empty($disputeId))
    {
        logTransaction(
            $gatewayParams['name'],
            [
                'event'   => 'payment.dispute.lost',
                'payload' => $data['payload'] ?? [],
                'error'   => 'Missing payment_id, whmcs_order_id, or dispute_id in payload — cannot reverse automatically.',
            ],
            "Dispute Lost - Reversal Failed"
        );

        http_response_code(200);
        exit;
    }

    $log = [
        'event'               => 'payment.dispute.lost',
        'dispute_id'          => $disputeId,
        'razorpay_payment_id' => $razorpayPaymentId,
        'whmcs_invoice_id'    => $whmcsInvoiceId,
        'reason_code'         => $reasonCode,
    ];

    try
    {
        // paymentReversed($reverseTransactionId, $originalTransactionId)
        // - $reverseTransactionId : the dispute ID (unique ID for this reversal event)
        // - $originalTransactionId: the original Razorpay payment ID recorded in tblaccounts
        // WHMCS matches the original transaction by searching tblaccounts.transid
        paymentReversed($disputeId, $razorpayPaymentId);

        $log['action'] = 'Payment reversed in WHMCS. Invoice marked unpaid. Service suspension will follow WHMCS overdue settings.';

        logTransaction($gatewayParams['name'], $log, "Dispute Lost - Payment Reversed");
    }
    catch (Exception $e)
    {
        // Common causes:
        // - Original payment was never recorded in WHMCS (webhook processed the payment)
        // - Transaction already reversed (duplicate webhook delivery)
        // - Multiple transactions matched the payment ID
        $log['error']  = $e->getMessage();
        $log['action'] = 'Automatic reversal failed — manual review required in WHMCS admin > Billing > Transactions.';

        logTransaction($gatewayParams['name'], $log, "Dispute Lost - Reversal Failed");
    }

    http_response_code(200);
    exit;
}

/**
 * Handle payment.dispute.won / payment.dispute.closed.
 * Dispute resolved in our favour — log it, no payment action needed.
 *
 * @param array $data          Decoded webhook payload
 * @param array $gatewayParams WHMCS gateway configuration
 */
function disputeResolved(array $data, $gatewayParams)
{
    $paymentEntity = $data['payload']['payment']['entity'] ?? [];
    $disputeEntity = $data['payload']['dispute']['entity']  ?? [];

    $log = [
        'event'               => $data['event'],
        'dispute_id'          => $disputeEntity['id']                      ?? 'unknown',
        'razorpay_payment_id' => $paymentEntity['id']                      ?? 'unknown',
        'whmcs_invoice_id'    => $paymentEntity['notes']['whmcs_order_id'] ?? 'unknown',
        'action'              => 'No action taken — dispute resolved in our favour. Payment stands.',
    ];

    logTransaction($gatewayParams['name'], $log, "Dispute Resolved - No Action Needed");

    http_response_code(200);
    exit;
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