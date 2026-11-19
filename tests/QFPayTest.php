<?php

use AjayDhakal\QFPay\EcrTerminal;
use AjayDhakal\QFPay\Facades\QFPay;
use AjayDhakal\QFPay\QFPay as Client;
use AjayDhakal\QFPay\QFPayException;
use AjayDhakal\QFPay\QFPayServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

beforeEach(function () {
    config()->set([
        'qfpay-sdk.app_code' => 'app-test',
        'qfpay-sdk.client_key' => 'test-key',
        'qfpay-sdk.merchant_id' => 'store-test',
        'qfpay-sdk.environment' => 'live_testing',
        'qfpay-sdk.currency' => 'HKD',
        'qfpay-sdk.notify_url' => 'https://shop.example/webhooks/qfpay',
        'qfpay-sdk.checkout.return_url' => 'https://shop.example/return?a=1&b=two words',
        'qfpay-sdk.checkout.failed_url' => 'https://shop.example/failed',
    ]);
    Http::preventStrayRequests();
});

it('publishes the configuration and resolves the same client through the facade', function () {
    expect(app(Client::class))->toBe(QFPay::getFacadeRoot());
    expect(config('qfpay-sdk'))->toHaveKeys(['checkout', 'element', 'signature_type', 'ecr']);
    expect(ServiceProvider::pathsToPublish(QFPayServiceProvider::class, 'qfpay-sdk-config'))
        ->toContain(config_path('qfpay-sdk.php'));
});

it('signs raw values and sends the same values URL encoded using the Postman protocol', function () {
    Http::fake(['https://test-openapi-hk.qfapi.com/trade/v1/payment' => Http::response(['respcd' => '1143'])]);

    $parameters = [
        'txamt' => 250,
        'out_trade_no' => 'order-1',
        'pay_type' => '800008',
        'auth_code' => '00123',
        'txdtm' => '2026-01-02 03:04:05',
        'goods_name' => 'Tea & coffee',
        'expired_time' => '',
        'optional' => null,
        'zero' => 0,
    ];
    $signed = 'auth_code=00123&goods_name=Tea & coffee&mchid=store-test&notify_url=https://shop.example/webhooks/qfpay'
        .'&out_trade_no=order-1&pay_type=800008&txamt=250&txcurrcd=HKD&txdtm=2026-01-02 03:04:05&zero=0';

    expect(QFPay::payment($parameters))->toBe(['respcd' => '1143']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->hasHeader('X-QF-APPCODE', 'app-test')
        && $request->hasHeader('X-QF-SIGN', strtoupper(md5($signed.'test-key')))
        && $request['goods_name'] === 'Tea & coffee'
        && $request['auth_code'] === '00123'
        && $request['zero'] === '0'
        && ! isset($request['expired_time'])
        && ! isset($request['optional']));
});

it('uses an explicit SHA256 signature and encodes subscription products as JSON', function () {
    config()->set('qfpay-sdk.signature_type', 'SHA256');
    Http::fake(['https://test-openapi-hk.qfapi.com/subscription/v1/create' => Http::response(['respcd' => '0000'])]);

    QFPay::createSubscription([
        'token_id' => 'tk_1',
        'products' => [['product_id' => 'prod_1', 'quantity' => 2]],
        'customer_id' => 'cust_1',
    ]);

    $products = '[{"product_id":"prod_1","quantity":2}]';
    $signed = 'customer_id=cust_1&products='.$products.'&token_id=tk_1';

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-QF-SIGNTYPE', 'SHA256')
        && $request->hasHeader('X-QF-SIGN', strtoupper(hash('sha256', $signed.'test-key')))
        && $request['products'] === $products);
});

it('uses the documented route for each OpenAPI operation', function (string $operation, string $path) {
    Http::fake(['https://test-openapi-hk.qfapi.com'.$path => Http::response(['respcd' => '0000'])]);

    expect(QFPay::{$operation}(['txdtm' => '2026-01-02 03:04:05']))->toBe(['respcd' => '0000']);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === $path);
})->with([
    ['query', '/trade/v1/query'],
    ['refund', '/trade/v1/refund'],
    ['reversal', '/trade/v1/reversal'],
    ['close', '/trade/v1/close'],
    ['addTransactionNote', '/trade/v1/add_note'],
    ['capture', '/trade/v1/authtrade'],
    ['unfreeze', '/trade/v1/unfreeze'],
    ['createPaymentIntent', '/payment_element/v1/create_payment_intent'],
    ['declareCustoms', '/custom/v1/declare'],
    ['queryCustoms', '/custom/v1/query'],
    ['redeclareCustoms', '/custom/v1/redeclare'],
    ['createCustomer', '/customer/v1/create'],
    ['updateCustomer', '/customer/v1/update'],
    ['queryCustomers', '/customer/v1/query'],
    ['deleteCustomer', '/customer/v1/delete'],
    ['queryTokens', '/token/v1/query'],
    ['createProduct', '/product/v1/create'],
    ['updateProduct', '/product/v1/update'],
    ['queryProducts', '/product/v1/query'],
    ['deleteProduct', '/product/v1/delete'],
    ['updateSubscription', '/subscription/v1/update'],
    ['querySubscriptions', '/subscription/v1/query'],
    ['cancelSubscription', '/subscription/v1/cancel'],
    ['listBillingOrders', '/subscription/billing_order/v1/list'],
    ['chargeSubscription', '/subscription/v1/charge'],
]);

it('builds a signed hosted checkout redirect without a server request', function () {
    config()->set('qfpay-sdk.checkout.return_url', 'https://shop.example/return?a=1&b=two%20words');
    Http::fake();

    $url = QFPay::checkoutUrl([
        'out_trade_no' => 'order-2',
        'txamt' => 1099,
        'txdtm' => '2026-01-02 03:04:05',
        'sign' => 'invalid',
    ]);

    parse_str(substr(parse_url($url, PHP_URL_FRAGMENT), 2), $parameters);
    $signed = 'appcode=app-test&failed_url=https://shop.example/failed&mchntid=store-test'
        .'&notify_url=https://shop.example/webhooks/qfpay&out_trade_no=order-2&paysource=remotepay_checkout'
        .'&return_url=https://shop.example/return?a=1&b=two%20words&sign_type=SHA256&txamt=1099'
        .'&txcurrcd=HKD&txdtm=2026-01-02 03:04:05';

    expect($url)->toStartWith('https://test-openapi-hk.qfapi.com/checkstand/#/?')
        ->and($parameters['return_url'])->toBe('https://shop.example/return?a=1&b=two%20words')
        ->and($parameters['mchntid'])->toBe('store-test')
        ->and($parameters['sign'])->toBe(strtoupper(hash('sha256', $signed.'test-key')))
        ->and($url)->not->toContain('test-key');

    Http::assertNothingSent();
});

it('provides the configured Element client settings', function () {
    expect(QFPay::elementOptions())->toBe([
        'script_url' => 'https://test-cdn-hk.qfapi.com/qfpay_element/qfpay.js',
        'region' => 'hk',
        'env' => 'test',
    ]);
});

it('uses query credentials for WeChat OAuth and header credentials for the OpenID exchange', function () {
    $uri = 'https://shop.example/wechat?state=a%2Bb';
    $url = QFPay::wechatOAuthUrl($uri);
    parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

    expect($parameters)->toMatchArray([
        'app_code' => 'app-test',
        'mchid' => 'store-test',
        'redirect_uri' => $uri,
        'sign' => strtoupper(md5('app_code=app-test&mchid=store-test&redirect_uri='.$uri.'test-key')),
    ]);

    Http::fake(['https://test-openapi-hk.qfapi.com/tool/v1/get_weixin_openid*' => Http::response(['openid' => 'open-1'])]);
    expect(QFPay::wechatOpenId('code+one'))->toBe(['openid' => 'open-1']);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->body() === ''
        && $request->hasHeader('X-QF-SIGN', strtoupper(md5('code=code+one&mchid=store-testtest-key')))
        && str_contains($request->url(), 'code=code%2Bone'));
});

it('authenticates raw webhook bodies before parsing', function () {
    $body = '{"respcd": "0000", "syssn": 2026101012345678901234}';
    $signature = md5($body.'test-key');

    expect(QFPay::parseWebhook($body, $signature))->toBe([
        'respcd' => '0000',
        'syssn' => '2026101012345678901234',
    ]);
    expect(QFPay::verifyWebhook($body."\n", $signature))->toBeFalse();
    expect(fn () => QFPay::parseWebhook($body, null))->toThrow(QFPayException::class);
});

it('verifies signed response fields when signature verification is enabled', function () {
    config()->set('qfpay-sdk.verify_response_signatures', true);
    $data = ['syssn' => '12345'];
    Http::fake([
        'https://test-openapi-hk.qfapi.com/trade/v1/query' => Http::sequence()
            ->push(['respcd' => '0000', 'data' => $data], 200, ['X-QF-SIGN' => md5('syssn=12345test-key')])
            ->push(['respcd' => '0000', 'data' => $data]),
    ]);

    expect(QFPay::query(['syssn' => '12345'])['data'])->toBe($data);
    expect(fn () => QFPay::query(['syssn' => '12345']))->toThrow(QFPayException::class);
});

it('returns business failures and does not retry failing monetary requests', function () {
    Http::fake(['https://test-openapi-hk.qfapi.com/trade/v1/refund' => Http::sequence()
        ->push(['respcd' => '1101'])
        ->push('error', 503)]);

    expect(QFPay::refund([]))->toBe(['respcd' => '1101']);
    expect(fn () => QFPay::refund([]))->toThrow(QFPayException::class, 'QFPay returned HTTP 503.');
    Http::assertSentCount(2);
});

it('sends a locally encrypted ECR command to the terminal', function () {
    config()->set([
        'qfpay-sdk.ecr.url' => 'https://terminal.example:9001',
        'qfpay-sdk.ecr.key' => '0123456789ABCDEF',
    ]);
    Http::fake(['https://terminal.example:9001/api/pos/trade' => Http::response([
        'respcd' => '6000',
        'data' => '{"orderId":"12345"}',
    ])]);

    expect(app(EcrTerminal::class)->request('payment', ['channel' => 'card_payment', 'amt' => 10.5]))
        ->toBe(['respcd' => '6000', 'data' => ['orderId' => '12345']]);

    $content = "{amt=10.5,channel='card_payment',func_type=1001}";
    $digest = strtoupper(md5($content.'0123456789ABCDEF'));
    Http::assertSent(fn (Request $request) => $request->hasHeader('Content-Type', 'application/json')
        && ! $request->hasHeader('X-QF-APPCODE')
        && openssl_decrypt(json_decode($request->body()), 'aes-128-cbc', '0123456789ABCDEF', 0, 'qfpay202306_hjsh')
            === "{content:{$content}, digest:'{$digest}'}");
});
