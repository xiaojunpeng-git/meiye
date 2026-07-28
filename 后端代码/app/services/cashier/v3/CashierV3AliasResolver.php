<?php
namespace app\services\cashier\v3;

/**
 * 统一参数别名解析：先收集并规范化全部别名，再判定一致／缺失／冲突。
 *
 * 禁止在各私有函数里「取第一个非空别名」——那会漏掉
 * targetRoomId=null 与 target_room_id=其它房间 这类冲突。
 */
class CashierV3AliasResolver
{
    /**
     * @param string[] $keys
     * @return string 空串表示缺失
     */
    public static function resolveString(array $payload, array $keys, bool $required = false): string
    {
        $normalized = self::collectNormalizedStrings($payload, $keys);
        if (count($normalized) > 1) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象标识互相冲突，请刷新当前工作台后重试。',
                ['reason' => 'payload_id_alias_conflict', 'keys' => $keys, 'values' => $normalized]
            );
        }
        if (count($normalized) === 1) {
            return $normalized[0];
        }
        if ($required) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少必需的对象标识，请刷新当前工作台后重试。',
                ['reason' => 'payload_id_alias_missing', 'keys' => $keys]
            );
        }
        return '';
    }

    /**
     * 可空 ID：显式 null／空串／'0' 归一为 null；多个别名值不一致（含 null vs 非空）即冲突。
     *
     * @param string[] $keys
     * @return string|null
     */
    public static function resolveNullableId(array $payload, array $keys, bool $requiredPresent = false)
    {
        $present = false;
        $tokens = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $present = true;
            $raw = $payload[$key];
            if ($raw === null) {
                $tokens['__null__'] = null;
                continue;
            }
            if (is_array($raw) || is_bool($raw)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的对象标识格式无效，请刷新当前工作台后重试。',
                    ['reason' => 'payload_id_alias_type_invalid', 'keys' => $keys, 'key' => $key]
                );
            }
            $value = trim((string)$raw);
            if ($value === '' || $value === '0') {
                $tokens['__null__'] = null;
                continue;
            }
            $tokens[$value] = $value;
        }
        if ($requiredPresent && !$present) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少必需的对象标识，请刷新当前工作台后重试。',
                ['reason' => 'payload_id_alias_missing', 'keys' => $keys]
            );
        }
        if (count($tokens) > 1) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象标识互相冲突，请刷新当前工作台后重试。',
                [
                    'reason' => 'payload_id_alias_conflict',
                    'keys' => $keys,
                    'values' => array_map(static function ($v) {
                        return $v === null ? null : (string)$v;
                    }, array_values($tokens)),
                ]
            );
        }
        if (count($tokens) === 1) {
            return array_values($tokens)[0];
        }
        return null;
    }

    /**
     * 枚举／模式字符串：收集全部非空别名，冲突则拒绝。
     *
     * @param string[] $keys
     * @param string[]|null $allowed 非空时限制合法取值
     */
    public static function resolveEnum(array $payload, array $keys, bool $required = false, array $allowed = null): string
    {
        $normalized = self::collectNormalizedStrings($payload, $keys, false);
        if (count($normalized) > 1) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的参数互相冲突，请刷新当前工作台后重试。',
                ['reason' => 'payload_enum_alias_conflict', 'keys' => $keys, 'values' => $normalized]
            );
        }
        if (count($normalized) === 1) {
            $value = $normalized[0];
            if ($allowed !== null && $allowed !== [] && !in_array($value, $allowed, true)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的参数无效，请刷新当前工作台后重试。',
                    ['reason' => 'payload_enum_invalid', 'keys' => $keys, 'value' => $value]
                );
            }
            return $value;
        }
        if ($required) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少必需参数，请刷新当前工作台后重试。',
                ['reason' => 'payload_enum_missing', 'keys' => $keys]
            );
        }
        return '';
    }

    /**
     * 任一别名键是否出现在 payload（含显式 null）。
     *
     * @param string[] $keys
     */
    public static function hasAnyKey(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $keys
     * @return string[] 去重后的规范化值列表
     */
    protected static function collectNormalizedStrings(array $payload, array $keys, bool $rejectEmptyAsMissing = true): array
    {
        $found = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $raw = $payload[$key];
            if (is_array($raw) || is_bool($raw) || $raw === null) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的对象标识格式无效，请刷新当前工作台后重试。',
                    ['reason' => 'payload_id_alias_type_invalid', 'keys' => $keys, 'key' => $key]
                );
            }
            $value = trim((string)$raw);
            if ($rejectEmptyAsMissing && ($value === '' || $value === '0')) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $found[$value] = true;
        }
        return array_keys($found);
    }
}
