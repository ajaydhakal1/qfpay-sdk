# QFPay SDK for Laravel

A Laravel client for QFPay OpenAPI, Hosted Checkout, and the server side of QFPay Element. It also includes an HTTP(S) client for Haojin ECR terminals.

## Installation

Requires PHP 8.4 or newer within the PHP 8 series, OpenSSL, and Laravel 11, 12, or 13.

To use the local checkout from a Laravel application before publishing a release, run these commands in the application directory.

```sh
composer config repositories.qfpay path /path/to/qfpay-sdk
composer require ajaydhakal/qfpay-sdk:@dev
```

For a published release, use the standard Composer install command.

```sh
composer require ajaydhakal/qfpay-sdk
```

Laravel discovers the service provider and facade. You can publish the optional config with `php artisan vendor:publish --tag=qfpay-sdk-config`. Set these values in the application's `.env` file.

```dotenv
QFPAY_ENVIRONMENT=sandbox
QFPAY_APP_CODE=
QFPAY_CLIENT_KEY=
QFPAY_CURRENCY=HKD
QFPAY_TIMEZONE=Asia/Hong_Kong
QFPAY_RETURN_URL=https://shop.example/checkout/return
QFPAY_FAILED_URL=https://shop.example/checkout/failed
QFPAY_NOTIFY_URL=https://shop.example/webhooks/qfpay
```

| Variable | Purpose |
| --- | --- |
| `QFPAY_ENVIRONMENT` | `sandbox`, `live_testing`, or `production`. Use the environment assigned to your credentials. |
| `QFPAY_BASE_URL` | Optional HTTPS API base URL for a different region or QFPay-assigned host. |
| `QFPAY_MERCHANT_ID` | Include only when QFPay issues a merchant ID. The SDK supplies `mchid` for trade, payment-intent, and WeChat auth requests and `mchntid` for Hosted Checkout. |
| `QFPAY_SIGNATURE_TYPE` | OpenAPI signature algorithm. Defaults to `MD5` for compatibility with the Postman collection. Select `SHA256` if your channel supports it. |
| `QFPAY_CHECKOUT_SIGNATURE_TYPE` | Hosted Checkout signature algorithm. Defaults to `SHA256`. |
| `QFPAY_CHECKOUT_PAYSOURCE` | Defaults to `remotepay_checkout`. Custom values must end in `_checkout`. |
| `QFPAY_CANCEL_URL` | Optional Hosted Checkout cancellation URL. |
| `QFPAY_CURRENCY` | Optional default currency. Required by Hosted Checkout and direct payment unless supplied per order. |
| `QFPAY_TIMEZONE` | Timezone for generated `txdtm` values. Defaults to the application's PHP timezone. |
| `QFPAY_CONNECT_TIMEOUT`, `QFPAY_TIMEOUT` | OpenAPI timeouts in seconds. Defaults are 5 and 30. |
| `QFPAY_VERIFY_RESPONSE_SIGNATURES` | Require the signed response-field format when your QFPay channel provides it. Defaults to false because response signatures vary by endpoint. Webhook verification is independent. |

Rebuild Laravel's cached configuration after changing environment variables if your deployment uses `php artisan config:cache`. QFPay assigns channel availability and credentials per merchant and environment. See [environments](https://sdk.qfapi.com/integration/preparation/environments) and [request signing](https://sdk.qfapi.com/integration/preparation/authentication-and-signature).

## Hosted Checkout

Call this from your checkout action after loading the authenticated customer's order. Use a persisted, unique merchant reference and the amount from your server in minor units.

```php
use AjayDhakal\QFPay\Facades\QFPay;

return redirect()->away(QFPay::checkoutUrl([
    'out_trade_no' => $order->payment_reference,
    'txamt' => $order->amount_in_minor_units,
    'txcurrcd' => $order->currency,
    'goods_name' => 'Online order',
]));
```

The SDK signs on the server. The customer returns through the configured success, failure, or cancellation URL. Use those routes to display order status and use the verified webhook and transaction enquiry to confirm the payment. Match the transaction reference, amount, currency, and final status against your stored order before fulfillment. Use a new merchant reference for a new payment attempt. See [QFPay Hosted Checkout](https://sdk.qfapi.com/integration/online-shop/checkout-integration/checkout) and [Transaction Enquiry](https://sdk.qfapi.com/integration/common-api/transaction-enquiry).

## Direct payments and Element

QFPay uses one direct payment endpoint for Merchant Present Mode, Consumer Present Mode, web, mobile web, and native app flows. Pass the documented `pay_type` and any channel-specific fields. For example, `800201` requests a WeChat QR payment.

```php
$payment = QFPay::payment([
    'out_trade_no' => $order->payment_reference,
    'txamt' => $order->amount_in_minor_units,
    'txcurrcd' => $order->currency,
    'pay_type' => '800201',
]);

$enquiry = QFPay::query(['out_trade_no' => $order->payment_reference]);
```

For Element, create the payment intent on the backend. Provide the browser with the intent and public options for QFPay's JavaScript SDK. You can override the script URL and region with `QFPAY_ELEMENT_SCRIPT_URL` and `QFPAY_ELEMENT_REGION`.

```php
$intent = QFPay::createPaymentIntent([
    'out_trade_no' => $order->payment_reference,
    'txamt' => $order->amount_in_minor_units,
    'txcurrcd' => $order->currency,
    'pay_type' => '802801', // Online Visa / Mastercard
]);

if (($intent['respcd'] ?? null) !== '0000' || empty($intent['payment_intent'])) {
    // Handle the provider error before rendering the payment form.
    abort(502, 'Could not start checkout.');
}

$element = QFPay::elementOptions();
// Pass $intent['payment_intent'] and $element to your checkout view.
```

Load the script at `$element['script_url']` and use the returned `region` and `env` values with `QFpay.config()`. Use QFPay's browser SDK to render the form and handle wallet handoff, card confirmation, Apple Pay, and the pre-authorisation flow. Collect card details through the QFPay UI. For saved cards, use `createCustomer()` first and pass its customer ID to the eligible Element card flow. Pass `promotion_tag` in the payment request for a campaign registered with QFPay. See [Element SDK](https://sdk.qfapi.com/integration/online-shop/checkout-integration/payment-element), [payment types](https://sdk.qfapi.com/integration/api-reference/paytypes), and [saved payment methods](https://sdk.qfapi.com/integration/online-shop/integration-by-use-case/saved-payment-methods/customer-token-setup).

## Webhooks

Register a public callback URL with QFPay for the relevant event family. In Laravel, use a route that can accept external POSTs. Read the original request body and verify `X-QF-SIGN` before processing it.

```php
use AjayDhakal\QFPay\Facades\QFPay;
use AjayDhakal\QFPay\QFPayException;
use Illuminate\Http\Request;

public function __invoke(Request $request)
{
    try {
        $notification = QFPay::parseWebhook(
            $request->getContent(),
            $request->header('X-QF-SIGN'),
        );
    } catch (QFPayException) {
        abort(400);
    }

    // Application-defined handler. Persist or enqueue processing idempotently.
    $this->recordNotification($notification);

    return response('SUCCESS', 200)->header('Content-Type', 'text/plain');
}
```

Payment and refund callbacks use MD5 over the exact body and client key. QFPay documents separate callback registration for token creation and subscription events. Confirm their signature and acknowledgement format for your account before reusing the same parser. For events using the same raw-body signature scheme, the parser preserves fields for `payment_token`, `subscription`, and `subscription_payment` as received. See [payment and refund notifications](https://sdk.qfapi.com/integration/common-api/async-notifications), [token notifications](https://sdk.qfapi.com/integration/online-shop/integration-by-use-case/saved-payment-methods/token-notification), and [subscription notifications](https://sdk.qfapi.com/integration/online-shop/integration-by-use-case/recurring-payments/recurring-webhook).

## API methods

Import the facade or inject `AjayDhakal\QFPay\QFPay`. API methods accept QFPay's documented field names and return the decoded response array, including provider status and channel-specific fields. Transport failures, non-2xx responses, and invalid JSON throw `QFPayException`. Check the operation's `respcd` and transaction status before changing your order. For example, `1143` and `1145` can indicate an in-progress result. Monetary calls make one request per invocation. After a timeout, query the original attempt before resubmitting it. See [transaction status codes](https://sdk.qfapi.com/integration/api-reference/status-codes).

| API | SDK methods |
| --- | --- |
| Checkout and Element | `checkoutUrl`, `createPaymentIntent`, `elementOptions` |
| Direct payment and enquiry | `payment`, `query` |
| Refund, reversal, close, and notes | `refund`, `reversal`, `close`, `addTransactionNote` |
| Pre-authorisation | `createPaymentIntent`, `capture`, `unfreeze`, `refund` |
| Customs declaration | `declareCustoms`, `queryCustoms`, `redeclareCustoms`. Pass `'GET'` as the second argument to `queryCustoms` when required. |
| Customer management and saved tokens | `createCustomer`, `updateCustomer`, `queryCustomers`, `deleteCustomer`, `queryTokens` |
| Recurring products | `createProduct`, `updateProduct`, `queryProducts`, `deleteProduct` |
| Subscriptions and billing | `createSubscription`, `updateSubscription`, `querySubscriptions`, `cancelSubscription`, `listBillingOrders`, `chargeSubscription` |
| WeChat Official Account | `wechatOAuthUrl`, `wechatOpenId`, `payment`, `wechatJsapiRedirectUrl` |
| Webhook verification | `verifyWebhook`, `parseWebhook` |

For form endpoints, pass structured fields such as `products`, `billing_address`, and `extend_info` as PHP arrays or as JSON strings. Arrays are encoded as single JSON-valued form fields before signing. For additional QFPay OpenAPI routes, use `request('POST', '/relative/path', $parameters)` or the equivalent signed GET.

For WeChat JSAPI, open `wechatOAuthUrl()` inside WeChat, exchange the code with `wechatOpenId()`, and submit the payment with `pay_type` `800207`. Build the browser handoff using `wechatJsapiRedirectUrl()` and the fields from `pay_params` and your order. Native wallets, Mini Programs, FPS app handoffs, and Haojin Android app-to-app integrations use their respective client SDKs. See [WeChat JSAPI](https://sdk.qfapi.com/integration/online-shop/integration-by-payment-type/wechat/wechat-jsapi-payment), [FPS app-to-app](https://sdk.qfapi.com/integration/online-shop/integration-by-payment-type/fps/fps-app-call-app), and [Haojin Android](https://sdk.qfapi.com/integration/in-store/pos-api/app-call-app).

## ECR terminals

Configure `QFPAY_ECR_URL` with the terminal's origin, for example `https://terminal.example:9001`, and `QFPAY_ECR_KEY` with its 16-byte POS key. Trust the terminal CA on the Laravel host or set `QFPAY_ECR_CA_BUNDLE` to a path to the trusted CA certificate. The default connect and operation timeouts are 5 and 150 seconds respectively, configurable via `QFPAY_ECR_CONNECT_TIMEOUT` and `QFPAY_ECR_TIMEOUT`.

```php
use AjayDhakal\QFPay\EcrTerminal;

$result = app(EcrTerminal::class)->request('payment', [
    'amt' => 10.50, // ECR uses major currency units.
    'channel' => 'card_payment',
    'out_trade_no' => $order->payment_reference,
]);
```

Operations include `payment`, `refund`, `print_receipt`, `print_summary`, `query`, and `cancel`. The HTTP(S) client uses the encrypted Haojin request format and needs network access to the terminal. USB and TCP require their own device-side transport. See [ECR specification](https://sdk.qfapi.com/integration/in-store/pos-api/ecr) and [ECR HTTPS setup](https://sdk.qfapi.com/integration/in-store/pos-api/ecr-https).

## Development

```sh
composer install
composer test
composer analyse
```

The automated tests use fake QFPay HTTP responses. Merchant and terminal end-to-end testing requires QFPay credentials and enabled channels.

## License

MIT.
