## Razorpay Payment Extension for WHMCS

Allows you to use Razorpay payment gateway with the WHMCS Store.

## Description

​This is the Razorpay payment gateway plugin for WHMCS. Allows Indian merchants to accept credit cards, debit cards, netbanking and wallet payments with the WHMCS store. It uses a seamless integration, allowing the customer to pay on your website without being redirected away from your WHMCS website.

## Installation

1. Ensure you have latest version of WHMCS installed.
2. Download the zip of this repo.
3. Upload the contents of the repo to your WHMCS Installation directory (content of module folder goes in module folder).

## Branches
 - Use the `master` branch

## Configuration

1. Log into WHMCS as administrator (http://whmcs_installation/admin).
2. Navigate to Setup->Payments->Payment Gateways.
3. Choose Razorpay in the Activate dropdown and Activate it
4. Fill the Key Id and Key Secret.
5. Choose Convert for Processing to INR if your store has a different default currency. Make sure you update the exchange rate in that case in your currency management.
6. Click 'Save Changes'

### Webhook Support

Added webhook support for delayed authorized payments.
1. Create a new webhook with payment.authorized event and point it to https://yourwhmcs/modules/gateways/callback/razorpay_webhook.php
2. Enter the secret mentioned in razorpay dashboard in the modules/gateways/callback/razorpay_webhook.php file on Line 34.

### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email <integrations@razorpay.com>.

### License

This is licensed under the [MIT License][mit]

[mit]: https://opensource.org/licenses/MIT
[6]: https://github.com/razorpay/razorpay-whmcs/releases/tag/1.1.0
[5]: https://github.com/razorpay/razorpay-whmcs/releases/tag/v1.0.3
