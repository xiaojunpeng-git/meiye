<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use think\exception\ValidateException;

/** 拼团交易门店取服务端报价中的本人购买门店；团长和推荐人不能改变交易归属。 */
final class CombinationPurchaseStoreServices
{
    /** 非拼团返回 null；拼团只接受已确认报价的同一门店，不允许提交时静默换店。 */
    public static function fromQuote(array $lines, int $requestedStoreId): ?int
    {
        $groupLines = array_values(array_filter($lines, static function (array $line): bool {
            return (int)($line['type'] ?? 0) === 3;
        }));
        if (!$groupLines) return null;
        $stores = array_values(array_unique(array_map(static function (array $line): int {
            return (int)($line['store_id'] ?? 0);
        }, $groupLines)));
        if (count($groupLines) !== count($lines) || count($stores) !== 1 || $stores[0] <= 0
            || $requestedStoreId !== $stores[0]) {
            throw new ValidateException('购买门店与确认订单不一致，请返回确认订单页重新选择');
        }
        return $stores[0];
    }
}
