<?php
function razorpay_config() {

    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "Razorpay (Credit Card/Debit Card/Net Banking)"),
        "KeyId" => array("FriendlyName" => "Key Id", "Type" => "text", "Size" => "50", "Description" => "Enter your Razorpay Key Id here",),
        "KeySecret" => array("FriendlyName" => "Key Secret", "Type" => "text", "Size" => "50", "Description" => "Enter your Razorpay Key Secret here",),
    );
    return $configarray;
}

function razorpay_link($params) {
    # Gateway Specific Variables
    $key_id = $params['KeyId'];
    $key_secret = $params['KeySecret'];  

    # Invoice Variables
    $order_id = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount']*100;
    $currency = $params['currency'];
    
    # Client Variables
    $customer_name = $params['clientdetails']['firstname']." ".$params['clientdetails']['lastname'];
    $customer_email = $params['clientdetails']['email'];
    $customer_phone = $params['clientdetails']['phonenumber'];
    
    # System Variables
    $name = $params['companyname'];
    $companyname = 'razorpay';
    $checkoutURL = 'https://checkout.razorpay.com/v1/checkout.js';
    $callbackURL = $params['systemurl'].'/modules/gateways/callback/razorpay.php';


    $html = '<form name="razorpay-form" id="razorpay-form" action="'.$callbackURL.'" method="POST" onSubmit="if(!razorpay_open) razorpaySubmit(); return razorpay_submit;">
                <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id" />
                <input type="hidden" name="merchant_order_id" id="order_id" value="'.$order_id.'"/>
                <input type="button" value="Click Here to Pay" onClick="razorpaySubmit()"/>
            </form>';
    
    $js = '<script src="'.$checkoutURL.'"></script>';

    $js .= "<script>
            var razorpay_open = false;
            var razorpay_submit = false;
            var razorpay_options = {
                'key': '".$key_id."',
                'amount': '".$amount."',
                'currency': '".$currency."',
                'name': '".$name."',
                'description': '".$description."',
                'handler': function (transaction) {
                    razorpay_submit = true;
                    document.getElementById('razorpay_payment_id').value = transaction.razorpay_payment_id;
                    document.getElementById('razorpay-form').submit();
                },
                'prefill': {
                    'name': '".$customer_name."',
                    'email': '".$customer_email."',
                    'contact': '".$customer_phone."'
                },
                notes: {
                    'whmcs_order_id': '".$order_id."'
                },
                netbanking: true
            };
            
            function razorpaySubmit(){                  
                var rzp1 = new Razorpay(razorpay_options);
                rzp1.open();
                razorpay_open = true;
            }    

            </script>";

    return $html.$js;

}
?>