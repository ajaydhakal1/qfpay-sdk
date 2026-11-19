# QFPay SDK for Laravel

Accept payments through QFPay Hosted Checkout, Element, or OpenAPI. The SDK signs requests server-side, verifies webhooks, and supports transaction enquiry, refunds, recurring billing, and Haojin ECR terminals.

## Installation

Requires PHP 8.4 or newer within the PHP 8 series and OpenSSL. The SDK has been tested on Laravel 12 and 13. Composer also accepts Laravel 11, but upstream security advisories blocked its test installation.

```sh
composer require ajaydhakal/qfpay-sdk
```

Laravel discovers the service provider and facade. To customize the package configuration, run `php artisan vendor:publish --tag=qfpay-sdk-config`. [Packagist](https://packagist.org/packages/ajaydhakal/qfpay-sdk)

## Configure

Set the following values in the Laravel application's environment. Start in `sandbox` with matching credentials, and use your merchant's currency, timezone, and HTTPS URLs.

```dotenv
QFPAY_ENVIRONMENT=sandbox
QFPAY_APP_CODE=
QFPAY_CLIENT_KEY=
QFPAY_CURRENCY=HKD
QFPAY_TIMEZONE=Asia/Hong_Kong
QFPAY_RETURN_URL=https://shop.example/checkout/return
QFPAY_FAILED_URL=https://shop.example/checkout/failed
QFPAY_NOTIFY_URL=https://shop.example/api/webhooks/qfpay
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
| `QFPAY_VERIFY_RESPONSE_SIGNATURES` | Optional global verification for signed object responses. Defaults to `false`. Enable only if every OpenAPI endpoint you use returns a compatible signature and object shape. Enquiry responses with a list in `data` are incompatible with this option. Webhook verification is independent. |

Keep the client key in your server's secret store. Rebuild Laravel's cached configuration after deploying environment changes. QFPay assigns channels and credentials per merchant and environment. [Environments](https://sdk.qfapi.com/integration/preparation/environments) · [Request signing](https://sdk.qfapi.com/integration/preparation/authentication-and-signature)

## Hosted Checkout

In an authenticated checkout action, load and authorize the order, then persist a unique payment-attempt reference before redirecting. Use the amount from your database as an integer in the currency's minor units.

```php
use AjayDhakal\QFPay\Facades\QFPay;

return redirect()->away(QFPay::checkoutUrl([
    'out_trade_no' => $attempt->reference,
    'txamt' => $attempt->amount_in_minor_units,
    'txcurrcd' => $attempt->currency,
]));
```

Use the browser return routes to display the current order status. Before fulfillment, query QFPay with `QFPay::query(['out_trade_no' => $attempt->reference])`. Match the payment entry's reference, amount, currency, and final status against your stored attempt. The top-level `respcd` describes the enquiry request. Check the matched transaction's result and cancellation status separately. [Hosted Checkout](https://sdk.qfapi.com/integration/online-shop/checkout-integration/checkout) · [Transaction enquiry](https://sdk.qfapi.com/integration/common-api/transaction-enquiry)

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

Register your public HTTPS callback with QFPay. Add the example to `routes/api.php` in an application that has API routing enabled with the default `/api` prefix. Implement `App\Jobs\ProcessQFPayNotification` as a queued job and configure a durable queue before using this route.

```php
use AjayDhakal\QFPay\Facades\QFPay;
use AjayDhakal\QFPay\QFPayException;
use App\Jobs\ProcessQFPayNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/qfpay', function (Request $request) {
    try {
        $notification = QFPay::parseWebhook(
            $request->getContent(),
            $request->header('X-QF-SIGN'),
        );
    } catch (QFPayException) {
        abort(400);
    }

    ProcessQFPayNotification::dispatch($notification);

    return response('SUCCESS', 200, ['Content-Type' => 'text/plain']);
});
```

The SDK verifies `X-QF-SIGN` over the original request body and client key. Acknowledge delivery after the event is durably queued or recorded. In the job, use `notify_type` and `syssn` to identify the transaction, verify it through enquiry, and make order updates idempotent so repeated deliveries cannot trigger duplicate fulfillment or refunds. Confirm the callback registration, signature scheme, and acknowledgement separately for token and subscription events. [Payment and refund notifications](https://sdk.qfapi.com/integration/common-api/async-notifications) · [Token notifications](https://sdk.qfapi.com/integration/online-shop/integration-by-use-case/saved-payment-methods/token-notification) · [Subscription notifications](https://sdk.qfapi.com/integration/online-shop/integration-by-use-case/recurring-payments/recurring-webhook)

## Before going live

1. Confirm production credentials, payment channels, the regional API host, and callback URLs with QFPay. Set `QFPAY_ENVIRONMENT=production` when switching over, refresh Laravel's configuration cache, and restart queue workers.
2. Exercise success, cancellation, pending results, duplicate callbacks, and refunds in the QFPay test environment available to your merchant. Confirm that browser returns and repeated callbacks cannot trigger duplicate fulfillment.
3. Keep the original `out_trade_no` when handling a timeout or ambiguous result. Query that attempt before deciding whether to submit another payment or refund. QFPay can report in-progress results with `1143` or `1145`.
4. Log the merchant reference, QFPay transaction ID, and response code for reconciliation. Keep credentials, signatures, card data, and full callback payloads out of application logs.

[QFPay integration prerequisites](https://sdk.qfapi.com/integration/preparation/before-you-begin) · [Transaction status codes](https://sdk.qfapi.com/integration/api-reference/status-codes)

## API methods

Import the facade or inject `AjayDhakal\QFPay\QFPay`. OpenAPI methods take QFPay's documented field names and return decoded response arrays. Connection failures, non-2xx HTTP responses, and invalid response bodies throw `QFPayException`. Check provider response codes separately. The SDK sends one request per invocation.

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

[MIT license](LICENSE.md)
