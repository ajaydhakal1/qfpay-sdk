<?php

namespace AjayDhakal\QFPay;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

class QFPay
{
    private const ENVIRONMENTS = [
        'sandbox' => ['https://openapi-int.qfapi.com', 'https://cdn-int.qfapi.com', 'qa', 'qa'],
        'live_testing' => ['https://test-openapi-hk.qfapi.com', 'https://test-cdn-hk.qfapi.com', 'hk', 'test'],
        'production' => ['https://openapi-hk.qfapi.com', 'https://cdn-hk.qfapi.com', 'hk', 'prod'],
    ];

    public function __construct(
        private readonly Factory $http,
        #[SensitiveParameter] private readonly array $config,
    ) {}

    public function checkoutUrl(array $parameters): string
    {
        $checkout = $this->config['checkout'] ?? [];
        $type = Signature::algorithm($checkout['signature_type'] ?? 'SHA256');

        $parameters = $this->transactionDefaults($parameters, currency: true);
        $parameters = Signature::parameters(array_replace([
            'mchntid' => $this->config['merchant_id'] ?? null,
            'paysource' => $checkout['paysource'] ?? 'remotepay_checkout',
            'return_url' => $checkout['return_url'] ?? null,
            'failed_url' => $checkout['failed_url'] ?? null,
            'notify_url' => $this->config['notify_url'] ?? null,
            'cancel_url' => $checkout['cancel_url'] ?? null,
        ], $parameters, [
            'appcode' => $this->appCode(),
            'sign_type' => $type,
        ]));

        foreach (['txamt', 'txcurrcd', 'out_trade_no', 'txdtm', 'paysource', 'return_url', 'failed_url', 'notify_url'] as $name) {
            if (! isset($parameters[$name])) {
                throw new InvalidArgumentException("Missing QFPay checkout parameter: {$name}.");
            }
        }

        if (! str_ends_with($parameters['paysource'], '_checkout')) {
            throw new InvalidArgumentException('QFPay checkout paysource must end with _checkout.');
        }

        if (! ctype_digit($parameters['txamt']) || ltrim($parameters['txamt'], '0') === '') {
            throw new InvalidArgumentException('QFPay checkout amount must be a positive integer in minor units.');
        }

        foreach (['return_url', 'failed_url', 'notify_url', 'cancel_url'] as $name) {
            if (isset($parameters[$name]) && ! $this->isHttpUrl($parameters[$name])) {
                throw new InvalidArgumentException("Invalid QFPay checkout URL: {$name}.");
            }
        }

        $parameters['sign'] = Signature::sign($parameters, $this->clientKey(), $type);

        return $this->baseUrl().'/checkstand/#/?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{script_url: string, region: string, env: string} */
    public function elementOptions(): array
    {
        [, $cdn, $region, $environment] = $this->environment();
        $options = $this->config['element'] ?? [];

        return [
            'script_url' => $options['script_url'] ?? $cdn.'/qfpay_element/qfpay.js',
            'region' => $options['region'] ?? $region,
            'env' => $environment,
        ];
    }

    public function createPaymentIntent(array $parameters): array
    {
        $checkout = $this->config['checkout'] ?? [];

        return $this->request('POST', '/payment_element/v1/create_payment_intent', array_replace([
            'txcurrcd' => $this->config['currency'] ?? null,
            'notify_url' => $this->config['notify_url'] ?? null,
            'return_url' => $checkout['return_url'] ?? null,
            'failed_url' => $checkout['failed_url'] ?? null,
        ], $parameters));
    }

    public function payment(#[SensitiveParameter] array $parameters): array
    {
        return $this->request('POST', '/trade/v1/payment', array_replace([
            'notify_url' => $this->config['notify_url'] ?? null,
        ], $this->transactionDefaults($parameters, currency: true)));
    }

    public function query(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/query', $parameters);
    }

    public function refund(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/refund', $this->transactionDefaults($parameters));
    }

    public function reversal(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/reversal', $this->transactionDefaults($parameters));
    }

    public function close(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/close', $this->transactionDefaults($parameters));
    }

    public function addTransactionNote(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/add_note', $parameters);
    }

    public function capture(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/authtrade', $parameters);
    }

    public function unfreeze(array $parameters): array
    {
        return $this->request('POST', '/trade/v1/unfreeze', $this->transactionDefaults($parameters));
    }

    public function declareCustoms(array $parameters): array
    {
        return $this->request('POST', '/custom/v1/declare', $parameters);
    }

    public function queryCustoms(array $parameters, string $method = 'POST'): array
    {
        return $this->request($method, '/custom/v1/query', $parameters);
    }

    public function redeclareCustoms(array $parameters): array
    {
        return $this->request('POST', '/custom/v1/redeclare', $parameters);
    }

    public function createCustomer(#[SensitiveParameter] array $parameters = []): array
    {
        return $this->request('POST', '/customer/v1/create', $parameters);
    }

    public function updateCustomer(#[SensitiveParameter] array $parameters): array
    {
        return $this->request('POST', '/customer/v1/update', $parameters);
    }

    public function queryCustomers(#[SensitiveParameter] array $parameters = []): array
    {
        return $this->request('POST', '/customer/v1/query', $parameters);
    }

    public function deleteCustomer(array $parameters): array
    {
        return $this->request('POST', '/customer/v1/delete', $parameters);
    }

    public function queryTokens(#[SensitiveParameter] array $parameters): array
    {
        return $this->request('POST', '/token/v1/query', $parameters);
    }

    public function createProduct(array $parameters): array
    {
        return $this->request('POST', '/product/v1/create', $parameters);
    }

    public function updateProduct(array $parameters): array
    {
        return $this->request('POST', '/product/v1/update', $parameters);
    }

    public function queryProducts(array $parameters = []): array
    {
        return $this->request('POST', '/product/v1/query', $parameters);
    }

    public function deleteProduct(array $parameters): array
    {
        return $this->request('POST', '/product/v1/delete', $parameters);
    }

    public function createSubscription(#[SensitiveParameter] array $parameters): array
    {
        return $this->request('POST', '/subscription/v1/create', $parameters);
    }

    public function updateSubscription(#[SensitiveParameter] array $parameters): array
    {
        return $this->request('POST', '/subscription/v1/update', $parameters);
    }

    public function querySubscriptions(array $parameters = []): array
    {
        return $this->request('POST', '/subscription/v1/query', $parameters);
    }

    public function cancelSubscription(array $parameters): array
    {
        return $this->request('POST', '/subscription/v1/cancel', $parameters);
    }

    public function listBillingOrders(array $parameters): array
    {
        return $this->request('POST', '/subscription/billing_order/v1/list', $parameters);
    }

    public function chargeSubscription(array $parameters): array
    {
        return $this->request('POST', '/subscription/v1/charge', $parameters);
    }

    public function wechatOAuthUrl(string $redirectUri, ?string $merchantId = null): string
    {
        if (! $this->isHttpUrl($redirectUri)) {
            throw new InvalidArgumentException('Invalid WeChat OAuth redirect URL.');
        }

        $parameters = Signature::parameters([
            'app_code' => $this->appCode(),
            'redirect_uri' => $redirectUri,
            'mchid' => $merchantId ?? $this->config['merchant_id'] ?? null,
        ]);
        $parameters['sign'] = Signature::sign($parameters, $this->clientKey(), 'MD5');

        return $this->baseUrl().'/tool/v1/get_weixin_oauth_code?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function wechatOpenId(#[SensitiveParameter] string $code, ?string $merchantId = null): array
    {
        $parameters = ['code' => $code];

        if ($merchantId !== null) {
            $parameters['mchid'] = $merchantId;
        }

        return $this->request('GET', '/tool/v1/get_weixin_openid', $parameters, 'MD5');
    }

    public function wechatJsapiRedirectUrl(array $parameters): string
    {
        $parameters = Signature::parameters($parameters);
        foreach (['mchntnm', 'txamt', 'currency', 'redirect_url', 'package', 'timeStamp', 'signType', 'paySign', 'appId', 'nonceStr'] as $name) {
            if (! isset($parameters[$name])) {
                throw new InvalidArgumentException("Missing WeChat JSAPI redirect parameter: {$name}.");
            }
        }

        if (! $this->isHttpUrl($parameters['redirect_url'])) {
            throw new InvalidArgumentException('Invalid WeChat JSAPI redirect URL.');
        }

        return 'https://o2-hk.qfapi.com/q/direct?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function verifyWebhook(#[SensitiveParameter] string $body, ?string $signature): bool
    {
        return Signature::matches(strtoupper(md5($body.$this->clientKey())), $signature);
    }

    public function parseWebhook(#[SensitiveParameter] string $body, ?string $signature): array
    {
        if (! $this->verifyWebhook($body, $signature)) {
            throw new QFPayException('Invalid QFPay webhook signature.');
        }

        return $this->decodeObject($body);
    }

    /** Call a signed OpenAPI GET or form POST using a relative endpoint path. */
    public function request(
        string $method,
        string $path,
        #[SensitiveParameter] array $parameters = [],
        ?string $signatureType = null,
    ): array {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST'], true) || ! preg_match('~^/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $path)) {
            throw new InvalidArgumentException('QFPay requests require GET or POST and a relative API path.');
        }

        if (str_starts_with($path, '/trade/') || in_array($path, [
            '/payment_element/v1/create_payment_intent', '/tool/v1/get_weixin_openid',
        ], true)) {
            $parameters += ['mchid' => $this->config['merchant_id'] ?? null];
        }

        $type = Signature::algorithm($signatureType ?? $this->config['signature_type'] ?? 'MD5');
        $parameters = Signature::parameters($parameters);

        $headers = [
            'X-QF-APPCODE' => $this->appCode(),
            'X-QF-SIGN' => Signature::sign($parameters, $this->clientKey(), $type),
        ];

        if ($type !== 'MD5') {
            $headers['X-QF-SIGNTYPE'] = $type;
        }

        $pending = $this->http->withHeaders($headers)
            ->acceptJson()
            ->asForm()
            ->withoutRedirecting()
            ->connectTimeout($this->config['connect_timeout'] ?? 5)
            ->timeout($this->config['timeout'] ?? 30);

        try {
            $response = $pending->send($method, $this->baseUrl().$path, [
                $method === 'GET' ? 'query' : 'form_params' => $parameters,
            ]);
        } catch (ConnectionException) {
            throw new QFPayException('Could not connect to QFPay. Enquire before resubmitting a transaction.');
        }

        if (! $response->successful()) {
            throw new QFPayException("QFPay returned HTTP {$response->status()}.", $response->status());
        }

        $payload = $this->decodeObject($response->body());
        if ($this->config['verify_response_signatures'] ?? false) {
            $this->verifyResponse($response, $payload);
        }

        return $payload;
    }

    private function verifyResponse(Response $response, array $payload): void
    {
        $signature = $response->header('X-QF-SIGN');
        $type = $response->header('X-QF-SIGNTYPE') ?: 'MD5';
        $fields = $payload['data'] ?? $payload;

        if (! is_array($fields) || array_is_list($fields) || ! Signature::matches(
            Signature::sign($fields, $this->clientKey(), $type),
            $signature,
        )) {
            throw new QFPayException('Invalid or missing QFPay response signature.');
        }
    }

    private function decodeObject(#[SensitiveParameter] string $body): array
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new QFPayException('QFPay returned invalid JSON.');
        }

        if (! is_array($payload) || ! str_starts_with(ltrim($body), '{')) {
            throw new QFPayException('Expected a QFPay JSON object.');
        }

        return $payload;
    }

    private function transactionDefaults(array $parameters, bool $currency = false): array
    {
        if (! isset($parameters['txdtm'])) {
            $timezone = isset($this->config['timezone']) ? new DateTimeZone($this->config['timezone']) : null;
            $parameters['txdtm'] = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
        }

        if ($currency) {
            $parameters += ['txcurrcd' => $this->config['currency'] ?? null];
        }

        return $parameters;
    }

    /** @return array{string, string, string, string} */
    private function environment(): array
    {
        $environment = $this->config['environment'] ?? 'sandbox';

        return self::ENVIRONMENTS[$environment]
            ?? throw new InvalidArgumentException('QFPay environment must be sandbox, live_testing, or production.');
    }

    private function baseUrl(): string
    {
        $url = $this->config['base_url'] ?? $this->environment()[0];
        if (! $this->isHttpUrl($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || strpbrk($url, '?#') !== false) {
            throw new InvalidArgumentException('QFPAY_BASE_URL must be an HTTPS URL without a query or fragment.');
        }

        return rtrim($url, '/');
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            && parse_url($url, PHP_URL_USER) === null;
    }

    private function appCode(): string
    {
        return $this->credential('app_code', 'QFPAY_APP_CODE');
    }

    private function clientKey(): string
    {
        return $this->credential('client_key', 'QFPAY_CLIENT_KEY');
    }

    private function credential(string $key, string $environmentVariable): string
    {
        $value = $this->config[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Configure {$environmentVariable} before calling QFPay.");
        }

        return $value;
    }
}
