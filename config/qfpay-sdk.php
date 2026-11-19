<?php

return [
    'environment' => env('QFPAY_ENVIRONMENT', 'sandbox'),
    'base_url' => env('QFPAY_BASE_URL'),
    'app_code' => env('QFPAY_APP_CODE'),
    'client_key' => env('QFPAY_CLIENT_KEY'),
    'merchant_id' => env('QFPAY_MERCHANT_ID'),
    'signature_type' => env('QFPAY_SIGNATURE_TYPE', 'MD5'),
    'verify_response_signatures' => env('QFPAY_VERIFY_RESPONSE_SIGNATURES', false),
    'currency' => env('QFPAY_CURRENCY'),
    'timezone' => env('QFPAY_TIMEZONE'),
    'notify_url' => env('QFPAY_NOTIFY_URL'),
    'checkout' => [
        'signature_type' => env('QFPAY_CHECKOUT_SIGNATURE_TYPE', 'SHA256'),
        'paysource' => env('QFPAY_CHECKOUT_PAYSOURCE', 'remotepay_checkout'),
        'return_url' => env('QFPAY_RETURN_URL'),
        'failed_url' => env('QFPAY_FAILED_URL'),
        'cancel_url' => env('QFPAY_CANCEL_URL'),
    ],
    'element' => [
        'script_url' => env('QFPAY_ELEMENT_SCRIPT_URL'),
        'region' => env('QFPAY_ELEMENT_REGION'),
    ],
    'connect_timeout' => (float) env('QFPAY_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('QFPAY_TIMEOUT', 30),
    'ecr' => [
        'url' => env('QFPAY_ECR_URL'),
        'key' => env('QFPAY_ECR_KEY'),
        'ca_bundle' => env('QFPAY_ECR_CA_BUNDLE'),
        'connect_timeout' => (float) env('QFPAY_ECR_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('QFPAY_ECR_TIMEOUT', 150),
    ],
];
