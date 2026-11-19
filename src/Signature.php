<?php

namespace AjayDhakal\QFPay;

use InvalidArgumentException;
use SensitiveParameter;
use stdClass;

/** @internal */
final class Signature
{
    public static function algorithm(string $type): string
    {
        $type = strtoupper($type);

        if (! in_array($type, ['MD5', 'SHA256'], true)) {
            throw new InvalidArgumentException('QFPay signature type must be MD5 or SHA256.');
        }

        return $type;
    }

    /** @return array<string, string> */
    public static function parameters(#[SensitiveParameter] array $parameters): array
    {
        $normalized = [];

        foreach ($parameters as $name => $value) {
            if ($name === 'sign' || $value === null || $value === '') {
                continue;
            }

            if (! is_string($name) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('Invalid QFPay parameter name.');
            }

            $normalized[$name] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value), is_int($value), is_float($value) => (string) $value,
                is_array($value), $value instanceof stdClass => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                default => throw new InvalidArgumentException("Invalid QFPay parameter value for {$name}."),
            };
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    public static function sign(#[SensitiveParameter] array $parameters, #[SensitiveParameter] string $key, string $type): string
    {
        $pairs = [];
        foreach (self::parameters($parameters) as $name => $value) {
            $pairs[] = "{$name}={$value}";
        }

        return strtoupper(hash(strtolower(self::algorithm($type)), implode('&', $pairs).$key));
    }

    public static function matches(string $expected, ?string $received): bool
    {
        return $received !== null && hash_equals($expected, strtoupper($received));
    }
}
