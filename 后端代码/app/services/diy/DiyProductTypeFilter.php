<?php
namespace app\services\diy;

use think\exception\ValidateException;

/** Restrict DIY catalog queries using the existing product_type authority. */
class DiyProductTypeFilter
{
    /** Missing/null preserves old pages; an explicit empty list matches nothing. */
    public static function normalize($value): ?array
    {
        if (is_string($value)) {
            if (trim($value) !== 'null' && substr(ltrim($value), 0, 1) !== '[') {
                throw new ValidateException('商品类型参数错误');
            }
            $value = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new ValidateException('商品类型参数错误');
        }
        if ($value === null) return null;
        if (!is_array($value)) throw new ValidateException('商品类型参数错误');
        $result = [];
        foreach ($value as $type) {
            if (!in_array($type, [0, 5, 6, '0', '5', '6'], true)) {
                throw new ValidateException('商品类型仅支持产品、卡项和项目');
            }
            $result[] = (int)$type;
        }
        return array_values(array_unique($result));
    }

    /** Apply before sorting/pagination; never replace store/show/permission scope. */
    public static function apply(array $where, $value): array
    {
        $types = self::normalize($value);
        if ($types !== null) $where['product_type'] = $types ?: [-999];
        return $where;
    }
}
