## Razorpay Payment Extension for WHMCS

Allows you to use Razorpay payment gateway with the WHMCS Store.

## Description

​This is the Razorpay payment gateway plugin for WHMCS. Allows Indian merchants to accept credit cards, debit cards, netbanking and wallet payments with the WHMCS store. It uses a seamless integration, allowing the customer to pay on your website without being redirected away from your WHMCS website.

## Branches

 - Use the `whmcs-6` branch if you are on WHMCS 6
 - Use the `master` branch if you are on WHMCS 5

## Installation

1. Ensure you have latest version of WHMCS installed.
2. Download the zip of this repo.
3. Upload the contents of the repo to your WHMCS Installation directory (content of module folder goes in module folder).

## Configuration

1. Log into WHMCS as administrator (http://cscart_installation/admin). 
2. Navigate to Setup->Payments->Payment Gateways.
3. Choose Razorpay in the Activate dropdown and Activate it
4. Fill the Key Id and Key Secret. 
5. Choose Convert for Processing to INR if your store has a different default currency. Make sure you update the exchange rate in that case in your currency management.
6. Click 'Save Changes'

### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email <integrations@razorpay.com>.

### License

This is licensed under the [MIT License][mit]

[mit]: https://opensource.org/licenses/MIT
