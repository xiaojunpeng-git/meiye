<?php

namespace app\services\query;

/**
 * 查询合同 JSON 的稳定编码，供版本哈希、快照与对账使用。
 */
class UnifiedQueryJson
{
    public static function encode(array $value): string
    {
        $json = json_encode(self::sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_JSON_INVALID',
                '查询配置无法保存，请检查后重试。',
                ['json_error' => json_last_error_msg()]
            );
        }
        return $json;
    }

    public static function decode(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('统一查询元数据 JSON 损坏');
        }
        return $decoded;
    }

    protected static function sort(array $value): array
    {
        if (self::isList($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = self::sort($item);
                }
            }
            return $value;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }
        return $value;
    }

    protected static function isList(array $value): bool
    {
        if (!$value) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }
}
