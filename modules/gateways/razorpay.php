<?php

require_once __DIR__.'/razorpay/razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

const RAZORPAY_WHMCS_VERSION= '2.1.1';
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
            'Description' => 'Payment action on order compelete.',
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
        'receipt'         => $params['invoiceid'],
        'amount'          => (int) round($params['amount'] * 100),
        'currency'        => $params['currency'],
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
        return $e;
    }

    $razorpayOrderId = $razorpayOrder['id'];

    $sessionKey = getOrderSessionKey($params['invoiceid']);

    $_SESSION[$sessionKey] = $razorpayOrderId;

    return $razorpayOrderId;
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
    $description = $params["description"];
    $amount = $params['amount'] * 100; // Required to be converted to Paisa.
    $currencyCode = $params['currency'];

    // Client Parameters
    $name = $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $contact = $params['clientdetails']['phonenumber'];

    // System Parameters
    $whmcsVersion = $params['whmcsVersion'];
    $razorpayWHMCSVersion = RAZORPAY_WHMCS_VERSION;
    $checkoutUrl = 'https://checkout.razorpay.com/v1/checkout.js';
    $callbackUrl = (substr($params['systemurl'], -1) === '/') ? $params['systemurl'] . 'modules/gateways/razorpay/razorpay.php' : $params['systemurl'] . '/modules/gateways/razorpay/razorpay.php';

    $razorpayOrderId = createRazorpayOrderId($params);

    return <<<EOT
<form name="razorpay-form" id="razorpay-form" action="$callbackUrl" method="POST">
    <input type="hidden" name="merchant_order_id" id="merchant_order_id" value="$invoiceId"/>
    <script src="$checkoutUrl"
        data-key            = "$keyId"
        data-amount         = "$amount"
        data-currency       = "$currencyCode"
        data-order_id       = "$razorpayOrderId"
        data-description    = "Inv#$invoiceId"

        data-prefill.name   = "$name"
        data-prefill.email  = "$email"
        data-prefill.contact= "$contact"

        data-notes.whmcs_order_id = "$invoiceId"
        data-notes.whmcs_version  = "$whmcsVersion"

        data-_.integration                = "whmcs"
        data-_.integration_version        = "$razorpayWHMCSVersion"
        data-_.integration_parent_version = "$whmcsVersion"
        data-_.integration_type           = "plugin"
    ></script>
</form>
EOT;
}
