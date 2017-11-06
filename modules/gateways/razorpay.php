<?php

require_once __DIR__ . '/callback/razorpay-php/Razorpay.php';
use Razorpay\Api\Api;

/**
 * WHMCS Razorpay Payment Gateway Module
 */
if (!defined("WHMCS"))
{
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
    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Razorpay',
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
        )
    );
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
    $keySecret = $params['keySecret'];
    
    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'] * 100; // Required to be converted to Paisa.
    $currencyCode = $params['currency'];
    
    // Client Parameters
    $name = $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $contact = $params['clientdetails']['phonenumber'];

    // create Razorpay order
    $api = new Api($keyId, $keySecret);

    $razorpayOrder = $api->order->create([
        'receipt'         => $invoiceId,
        'amount'          => $amount,
        'currency'        => 'INR',
        'payment_capture' => 1,
    ]);

    $_SESSION['razorpay_order_id'] = $razorpayOrder['id'];
    
    // System Parameters
    $whmcsVersion = $params['whmcsVersion'];
    $callbackUrl = $params['systemurl'] . '/modules/gateways/callback/razorpay.php';
    $checkoutUrl = 'https://checkout.razorpay.com/v1/checkout.js';
    $orderId = $razorpayOrder['id'];

    return <<<EOT
<form name="razorpay-form" id="razorpay-form" action="$callbackUrl" method="POST">
    <input type="hidden" name="merchant_order_id" id="merchant_order_id" value="$invoiceId"/>
    <script src="$checkoutUrl"
        data-key            = "$keyId"
        data-amount         = "$amount"
        data-currency       = "$currencyCode"
        data-description    = "Inv#$invoiceId"

        data-prefill.name   = "$name"
        data-prefill.email  = "$email"
        data-prefill.contact= "$contact"

        data-notes.whmcs_invoice_id = "$invoiceId"
        data-notes.whmcs_version = "$whmcsVersion"

        data-order_id = "$orderId"
    ></script>
</form>
EOT;
}
