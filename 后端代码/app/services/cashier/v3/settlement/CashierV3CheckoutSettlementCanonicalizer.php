<?php

namespace app\services\cashier\v3\settlement;

/**
 * Canonical JSON used by authority, aggregate and idempotency fingerprints.
 * Floats are rejected so money and versions cannot silently change precision.
 */
final class CashierV3CheckoutSettlementCanonicalizer
{
    public static function fingerprint($value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function encode($value): string
    {
        $normalized = self::normalize($value, '$');
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw self::failure('canonical_json_encode_failed', [
                'json_error' => json_last_error_msg(),
            ]);
        }
        return $encoded;
    }

    private static function normalize($value, string $path)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            throw self::failure('canonical_float_forbidden', ['path' => $path]);
        }
        if (!is_array($value)) {
            throw self::failure('canonical_value_type_invalid', [
                'path' => $path,
                'type' => gettype($value),
            ]);
        }

        if (self::isList($value)) {
            $result = [];
            foreach ($value as $index => $item) {
                $result[] = self::normalize($item, $path . '[' . $index . ']');
            }
            return $result;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '') {
                throw self::failure('canonical_object_key_invalid', ['path' => $path]);
            }
            $result[$key] = self::normalize($item, $path . '.' . $key);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private static function failure(string $reason, array $detail = []): CashierV3CheckoutSettlementContractException
    {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
