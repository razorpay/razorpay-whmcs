<?php
/**
 * Modified and maintained by Ucartz (https://www.ucartz.com) for
 * compatibility with the latest WHMCS releases.
 */

require_once __DIR__.'/razorpay/razorpay-sdk/Razorpay.php';
require_once __DIR__.'/razorpay/rzpordermapping.php';


use Razorpay\Api\Api;
use Razorpay\Api\Errors;

const RAZORPAY_WHMCS_VERSION= '3.0.1';
const RAZORPAY_PAYMENT_ID   = 'razorpay_payment_id';
const RAZORPAY_ORDER_ID     = 'razorpay_order_id';
const RAZORPAY_SIGNATURE    = 'razorpay_signature';

const CAPTURE            = 'capture';
const AUTHORIZE          = 'authorize';
const WHMCS_ORDER_ID     = 'whmcs_order_id';
/**
 * WHMCS Razorpay Payment Gateway Module
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
/**
 * Define module related meta data.
 * @return array
 */
function razorpay_MetaData()
{
    return array(
        'DisplayName' => 'Razorpay',
        'APIVersion' => '1.1',
    );
}
/**
 * Define gateway configuration options.
 * @return array
 */
function razorpay_config()
{
    global $CONFIG;

    $webhookUrl = $CONFIG['SystemURL'].'/modules/gateways/razorpay/razorpay-webhook.php';
    $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);

    try
    {
        $rzpOrderMapping->createTable();
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Create Table");
    }

    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Razorpay',
        ),
        'signUp' => array(
            'FriendlyName' => '',
            'Type' => 'comment',
            'Size' => '50',
            'Description' => 'First <a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=whmcs" target="_blank">Signup</a> for a Razorpay account OR <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=whmcs" target="_blank">Login</a> if you have an existing account.',
        ),
        'ucartzModified' => array(
            'FriendlyName' => '',
            'Type' => 'comment',
            'Size' => '50',
            'Description' => 'Developed and maintained by <a href="https://www.ucartz.com" target="_blank">Ucartz</a>.',
        ),
        'keyId' => array(
            'FriendlyName' => 'Key Id',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Razorpay "Key Id". Available <a href="https://dashboard.razorpay.com/#/app/keys" target="_blank">HERE</a>',
        ),
        'keySecret' => array(
            'FriendlyName' => 'Key Secret',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Razorpay "Key Secret" shared during activation API Key',
        ),
        'paymentAction' => array(
            'FriendlyName' => 'Payment Action',
            'Type' => 'dropdown',
            'Default' => 'Authorize and Capture',
            'Options' => array(
                CAPTURE   => 'Authorize and Capture',
                AUTHORIZE => 'Authorize',
            ),
            'Description' => 'Payment action on order complete. "Authorize" payments are NOT captured automatically by WHMCS — they must be captured manually from the Razorpay Dashboard within the authorization window, or they will be auto-refunded by Razorpay.',
        ),
        'refundSpeed' => array(
            'FriendlyName' => 'Refund Speed',
            'Type' => 'dropdown',
            'Default' => 'normal',
            'Options' => array(
                'normal'  => 'Normal',
                'optimum' => 'Instant (where eligible)',
            ),
            'Description' => 'Speed used when refunds are issued from WHMCS. <a href="https://razorpay.com/docs/refunds/instant/" target="_blank">About instant refunds</a>.',
        ),
        'enableWebhook' => array(
            'FriendlyName' => 'Enable Webhook',
            'Type' => 'yesno',
            'Default' => false,
            'Description' => 'Enable Razorpay Webhook <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a> with the URL listed below. <br/><br><span>'.$webhookUrl.'</span><br/>',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '<br/> Webhook secret is used for webhook signature verification. This has to match the one added <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a>',
        )
    );
}

/**
* @codeCoverageIgnore
*/
function getRazorpayApiInstance($params)
{
    $key    = $params['keyId'];
    $secret = $params['keySecret'];

    return new Api($key, $secret);
}

/**
 * Create the session key name
 * @param  int $order_no
 * @return
 */
function getOrderSessionKey($order_no)
{
    return RAZORPAY_ORDER_ID . $order_no;
}

/**
 * Create razorpay order id
 * @param  array  $params
 * @return string
 */
function createRazorpayOrderId(array $params)
{
    $api = getRazorpayApiInstance($params);

    $data = array(
        'receipt'         => (string) $params['invoiceid'],
        'amount'          => (int) round($params['amount'] * 100),
        'currency'        => (string) $params['currency'],
        'payment_capture' => ($params['paymentAction'] === AUTHORIZE) ? 0 : 1,
        'notes'           => array(
            WHMCS_ORDER_ID  => (string) $params['invoiceid'],
        ),
    );

    try
    {
        $razorpayOrder = $api->order->create($data);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Create Order");
        return null;
    }

    if (empty($razorpayOrder['id']) === true)
    {
        return null;
    }

    $razorpayOrderId = $razorpayOrder['id'];

    $sessionKey = getOrderSessionKey($params['invoiceid']);

    $_SESSION[$sessionKey] = $razorpayOrderId;

    $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);

    if ((isset($params['invoiceid']) === false) or
        (isset($razorpayOrderId) === false))
    {
        $error = [
            "invoice_id" => $params['invoiceid'],
            "razorpay_order_id" => $razorpayOrderId
        ];
        logTransaction(razorpay_MetaData()['DisplayName'], $error, "Validation Failure");
        return null;
    }

    try
    {
        $rzpOrderMapping->insertOrder($params['invoiceid'], $razorpayOrderId);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Insert Order");
    }

    return $razorpayOrderId;
}

/**
 * Refund transaction.
 * Called when a refund is requested for a previously successful transaction
 * from the WHMCS admin area.
 * @param array $params Payment Gateway Module Parameters
 * @return array Transaction response status
 */
function razorpay_refund($params)
{
    $api = getRazorpayApiInstance($params);

    $transactionIdToRefund = $params['transid'];
    $refundAmount = (int) round($params['amount'] * 100); // Required to be converted to Paisa.
    $refundSpeed = (empty($params['refundSpeed']) === false) ? $params['refundSpeed'] : 'normal';

    try
    {
        $payment = $api->payment->fetch($transactionIdToRefund);

        $refund = $payment->refund(array(
            'amount' => $refundAmount,
            'speed'  => $refundSpeed,
        ));

        $refundData = $refund->toArray();

        logTransaction(razorpay_MetaData()['DisplayName'], $refundData, "Refund Successful");

        return array(
            'status'  => 'success',
            'rawdata' => $refundData,
            'transid' => $refund['id'],
            'fees'    => 0,
        );
    }
    catch (Exception $e)
    {
        logTransaction($params['name'], $e->getMessage(), "Unsuccessful - Refund Failed for Payment id: ".$transactionIdToRefund);

        return array(
            'status'        => 'error',
            'rawdata'       => $e->getMessage(),
            'declinereason' => $e->getMessage(),
        );
    }
}

function getExistingOrderDetails($params, $razorpayOrderId)
{
    try
    {
        $api = getRazorpayApiInstance($params);
        return $api->order->fetch($razorpayOrderId);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Fetch existing order failed");
    }

}
/**
 * Payment link.
 * Required by third party payment gateway modules only.
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function razorpay_link($params)
{
    // Gateway Configuration Parameters
    $keyId = $params['keyId'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = (int) round($params['amount'] * 100); // Required to be converted to Paisa.
    $currencyCode = $params['currency'];

    // Client Parameters
    $name = trim($params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname']);
    $email = $params['clientdetails']['email'];
    $contact = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $whmcsVersion = $params['whmcsVersion'];
    $razorpayWHMCSVersion = RAZORPAY_WHMCS_VERSION;
    $checkoutUrl = 'https://checkout.razorpay.com/v1/checkout.js';
    $callbackUrl = (substr($params['systemurl'], -1) === '/') ? $params['systemurl'] . 'modules/gateways/razorpay/razorpay.php?merchant_order_id=' . $invoiceId : $params['systemurl'] . '/modules/gateways/razorpay/razorpay.php?merchant_order_id=' . $invoiceId;

    $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);

    try
    {
        $existingRazorpayOrderId = $rzpOrderMapping->getRazorpayOrderID($invoiceId);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Fetch Order");
    }

    if (isset($existingRazorpayOrderId) === false)
    {
        $razorpayOrderId = createRazorpayOrderId($params);
    }
    else
    {
        $existingOrder = getExistingOrderDetails($params, $existingRazorpayOrderId);

        // Create a new order if:
        // (a) the existing order could not be fetched from Razorpay (API
        //     failure / timeout) — we cannot verify the amount, so we must
        //     not reuse a potentially stale order, or
        // (b) the stored order amount no longer matches the current invoice
        //     balance (e.g. a late fee was added or a credit was applied
        //     after the original order was created).
        // In all other cases (fetch succeeded and amounts match) it is safe
        // to reuse the existing order.
        if (isset($existingOrder) === false ||
            ((int)$existingOrder['amount']) !== ((int)$amount))
        {
            $razorpayOrderId = createRazorpayOrderId($params);
        }
        else
        {
            $razorpayOrderId = $existingRazorpayOrderId;
        }
    }

    // Razorpay's Standard Checkout is meant to be opened from an explicit
    // user action (rzp.open() must be called from a click handler, browsers
    // block programmatic popups otherwise). The old auto-embedding
    // <script data-*> form this module used previously does not reliably
    // initialise when the invoice's payment HTML is injected into the page
    // after initial load (e.g. an AJAX-loaded "Make Payment" tab in some
    // client area templates), which is the likely cause of long-standing
    // "Pay Now button does nothing" reports. This uses Razorpay's currently
    // documented explicit button + handler pattern instead.
    $elementId = 'razorpay-'.preg_replace('/[^a-zA-Z0-9]/', '', (string) $invoiceId);

    $options = array(
        'key'         => $keyId,
        'amount'      => $amount,
        'currency'    => $currencyCode,
        'name'        => $companyName,
        'description' => 'Inv#'.$invoiceId,
        'order_id'    => $razorpayOrderId,
        'prefill'     => array(
            'name'    => $name,
            'email'   => $email,
            'contact' => $contact,
        ),
        'notes' => array(
            WHMCS_ORDER_ID                 => (string) $invoiceId,
            'whmcs_version'                 => $whmcsVersion,
            '_integration'                  => 'whmcs',
            '_integration_version'          => $razorpayWHMCSVersion,
            '_integration_parent_version'   => $whmcsVersion,
            '_integration_type'             => 'plugin',
        ),
    );

    // HEX_* flags prevent client-supplied name/email values (which may
    // contain characters like </script> or quotes) from breaking out of
    // the inline <script> block below.
    $optionsJson = json_encode($options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $invoiceIdAttr = htmlspecialchars((string) $invoiceId, ENT_QUOTES, 'UTF-8');

    return <<<EOT
<button type="button" class="btn btn-success" id="$elementId-button">Pay Now</button>
<script src="$checkoutUrl"></script>
<script>
(function () {
    var options = $optionsJson;
    var callbackUrl = "$callbackUrl";
    var invoiceId = "$invoiceIdAttr";

    // Deliberately no <form> element anywhere in this output. Some client
    // area templates auto-submit any payment-looking <form> they find on
    // an order/checkout page shortly after load (a real, findable pattern
    // in this codebase's own theme, used for 3D-Secure remote-input
    // processing) - if that logic also catches this gateway's form, it
    // submits it with empty hidden fields before the customer has even
    // seen the Razorpay button, let alone paid. That produces exactly the
    // observed bug: no popup, no payment collected, an immediate POST with
    // nothing in it, landing on a blank page. With no <form> present at
    // all, there's nothing for any such script to find or trigger early -
    // the POST is only ever constructed here, at the moment a real payment
    // has actually completed.
    options.handler = function (response) {
        var body = 'razorpay_payment_id=' + encodeURIComponent(response.razorpay_payment_id || '') +
            '&razorpay_order_id=' + encodeURIComponent(response.razorpay_order_id || '') +
            '&razorpay_signature=' + encodeURIComponent(response.razorpay_signature || '') +
            '&merchant_order_id=' + encodeURIComponent(invoiceId);

        fetch(callbackUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            credentials: 'same-origin',
        }).then(function (res) {
            (window.top || window).location.href = res.url;
        }).catch(function () {
            // fetch() itself failed outright (e.g. network error) - build
            // and submit a form only now, with the real response data
            // already in hand, rather than ever having one sitting on the
            // page beforehand.
            var fallbackForm = document.createElement('form');
            fallbackForm.method = 'POST';
            fallbackForm.action = callbackUrl;
            fallbackForm.target = '_top';
            fallbackForm.style.display = 'none';

            var fields = {
                razorpay_payment_id: response.razorpay_payment_id || '',
                razorpay_order_id: response.razorpay_order_id || '',
                razorpay_signature: response.razorpay_signature || '',
                merchant_order_id: invoiceId,
            };

            Object.keys(fields).forEach(function (name) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = fields[name];
                fallbackForm.appendChild(input);
            });

            document.body.appendChild(fallbackForm);
            fallbackForm.submit();
        });
    };
    var rzp = new Razorpay(options);
    document.getElementById('$elementId-button').addEventListener('click', function (e) {
        e.preventDefault();
        rzp.open();
    });
})();
</script>
EOT;
}