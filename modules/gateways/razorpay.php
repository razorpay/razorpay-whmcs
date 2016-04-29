<?php

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
        'DisplayName' => 'Razorpay by KDC',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
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
            //'Default' => 'rzp_',
            'Description' => 'Razorpay "Key Id". Available <a href="https://dashboard.razorpay.com/#/app/keys" target="_blank" style="bottom-border:1px dotted;">HERE</a>',
        ),
        'keySecret' => array(
            'FriendlyName' => 'Key Secret',
            'Type' => 'text',
            'Size' => '50',
            //'Default' => '',
            'Description' => 'Razorpay "Key Secret" shared during activation API Key',
        ),
        'themeLogo' => array(
            'FriendlyName' => 'Logo URL',
            'Type' => 'text',
            'Size' => '50',
            //'Default' => 'http://',
            'Description' => 'ONLY "http<strong>s</strong>://"; else leave blank.<br/><small>Size: 128px X 128px (or higher) | File Type: png/jpg/gif/ico</small>',
        ),
        'themeColor' => array(
            'FriendlyName' => 'Theme Color',
            'Type' => 'text',
            'Size' => '15',
            'Default' => '#15A4D3',
            'Description' => 'The colour of checkout form elements',
        ),
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
    $themeLogo = $params['themeLogo'];
    $themeColor = $params['themeColor'];
    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount']*100; // Required to be converted to Paisa.
    $currencyCode = $params['currency'];
    // Client Parameters
    $client_name = $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname'];
    $client_email = $params['clientdetails']['email'];
    $client_phone = $params['clientdetails']['phonenumber'];
    // System Parameters
    $companyName = $params['companyname'];
    $whmcsVersion = $params['whmcsVersion'];
    $callbackUrl = $params['systemurl'] . '/modules/gateways/callback/razorpay.php';
    $checkoutUrl = 'https://checkout.razorpay.com/v1/checkout.js';
    
    $html = '<form name="razorpay-form" id="razorpay-form" action="'.$callbackUrl.'" method="POST" onSubmit="if(!razorpay_open) razorpaySubmit(); return razorpay_submit;">
                <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id" />
                <input type="hidden" name="merchant_order_id" id="merchant_order_id" value="'.$invoiceId.'"/>
                <input type="button" value="Click Here to Pay" onClick="razorpaySubmit()"/>
            </form>';
    
    $js = '<script src="'.$checkoutUrl.'"></script>';
    $js .= "<script>
            var razorpay_open = false;
            var razorpay_submit = false;
            var razorpay_options = {
                'key': '".$keyId."',
                'amount': '".$amount."',
                'currency': '".$currencyCode."',
                'name': '".$companyName."',
                'description': 'Inv#".$invoiceId."',";
    
    if (isset($themeLogo)&&$themeLogo!="") {
        if (strpos($theme_logo, 'https://')!== false) {
            $js .= "
                'image': '".$theme_logo."',";
        }
    }
    if (isset($themeColor)&&$themeColor!="") {
        $js .= "
                'theme': {
                    'color': '".$themeColor."'
                },";
    }
    
    $js .= "
                'handler': function (transaction) {
                    razorpay_submit = true;
                    document.getElementById('razorpay_payment_id').value = transaction.razorpay_payment_id;
                    document.getElementById('razorpay-form').submit();
                },
                'prefill': {
                    'name': '".$client_name."',
                    'email': '".$client_email."',
                    'contact': '".$client_phone."'
                },
                notes: {
                    'whmcs_invoice_id': '".$invoiceId."',
                    'whmcs_version': '".$whmcsVersion."'
                },
                netbanking: true
            };
            
            function razorpaySubmit(){                  
                var rzp1 = new Razorpay(razorpay_options);
                rzp1.open();
                razorpay_open = true;
                rzp1.modal.options.backdropClose = false;
            }    
            </script>";
    return $html.$js;
}
