<?php

namespace AjayDhakal\QFPay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

/** HTTP(S) client for a Haojin POS terminal reachable from the Laravel server. */
class EcrTerminal
{
    private const OPERATIONS = [
        'payment' => ['trade', 1001],
        'refund' => ['cancel', 1002],
        'print_receipt' => ['print_receipt', 3001],
        'print_summary' => ['transaction_info', 3002],
        'query' => ['query_transaction', 4001],
        'cancel' => ['cancel_request', 5001],
    ];

    public function __construct(
        private readonly Factory $http,
        #[SensitiveParameter] private readonly array $config,
    ) {}

    public function request(string $operation, array $parameters = []): array
    {
        [$path, $functionType] = self::OPERATIONS[$operation]
            ?? throw new InvalidArgumentException('Unknown QFPay ECR operation.');
        $url = $this->config['url'] ?? '';
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            || parse_url($url, PHP_URL_USER) !== null
            || strpbrk($url, '?#') !== false
        ) {
            throw new InvalidArgumentException('Configure QFPAY_ECR_URL with the terminal HTTP(S) address.');
        }

        $key = $this->config['key'] ?? '';
        if (strlen($key) !== 16) {
            throw new InvalidArgumentException('QFPAY_ECR_KEY must be a 16-byte POS key.');
        }

        $caBundle = $this->config['ca_bundle'] ?? null;
        if ($caBundle !== null && (! is_string($caBundle) || ! is_readable($caBundle))) {
            throw new InvalidArgumentException('QFPAY_ECR_CA_BUNDLE must point to a readable CA certificate file.');
        }

        $parameters['func_type'] = $functionType;
        $content = $this->canonicalContent($parameters);
        $digest = strtoupper(md5($content.$key));
        $encrypted = openssl_encrypt("{content:{$content}, digest:'{$digest}'}", 'aes-128-cbc', $key, 0, 'qfpay202306_hjsh');

        if ($encrypted === false) {
            throw new QFPayException('Could not encrypt the QFPay ECR payload.');
        }

        try {
            $response = $this->http->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout($this->config['connect_timeout'] ?? 5)
                ->timeout($this->config['timeout'] ?? 150)
                ->withOptions(['verify' => $caBundle ?? true])
                ->withBody(json_encode($encrypted, JSON_THROW_ON_ERROR), 'application/json')
                ->post(rtrim($url, '/').'/api/pos/'.$path);
        } catch (ConnectionException) {
            throw new QFPayException('Could not connect to the QFPay terminal. Enquire before resubmitting.');
        }

        if (! $response->successful()) {
            throw new QFPayException("QFPay terminal returned HTTP {$response->status()}.", $response->status());
        }

        try {
            $result = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($result) || ! isset($result['respcd'])) {
                throw new QFPayException('Unexpected QFPay terminal response.');
            }

            if (is_string($result['data'] ?? null) && $result['data'] !== '') {
                $result['data'] = json_decode($result['data'], true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            }
        } catch (JsonException) {
            throw new QFPayException('QFPay terminal returned invalid JSON.');
        }

        return $result;
    }

    private function canonicalContent(array $parameters): string
    {
        ksort($parameters, SORT_STRING);
        $items = [];

        foreach ($parameters as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('Invalid QFPay ECR parameter name.');
            }

            if (is_string($value) && strpbrk($value, "'\\") === false && ! preg_match('/[\x00-\x1f]/', $value)) {
                $items[] = "{$name}='{$value}'";
            } elseif (is_int($value) || (is_float($value) && is_finite($value))) {
                $items[] = $name.'='.json_encode($value, JSON_THROW_ON_ERROR);
            } else {
                throw new InvalidArgumentException("Invalid QFPay ECR parameter value for {$name}.");
            }
        }

        return '{'.implode(',', $items).'}';
    }
}
