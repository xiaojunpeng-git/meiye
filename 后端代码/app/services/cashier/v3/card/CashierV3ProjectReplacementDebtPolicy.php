<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

/**
 * 项目替换只转移操作时已可用的权益，来源订单欠款不能再占用目标次数。
 * 判定只接受服务端持久化的项目行及操作快照；普通购卡和项目升级保持原欠款口径。
 */
final class CashierV3ProjectReplacementDebtPolicy
{
    /** 只识别已落库的项目替换目标行，避免普通购卡或升级绕开原单欠款。 */
    public static function isTransferredRight(array $detail): bool
    {
        if ((int)($detail['id'] ?? 0) <= 0
            || (int)($detail['oid'] ?? 0) <= 0
            || (int)($detail['cart_type'] ?? -1) !== 2
            || (int)($detail['product_type'] ?? -1) !== 6) {
            return false;
        }

        $snapshot = is_string($detail['cart_info'] ?? null)
            ? json_decode($detail['cart_info'], true)
            : ($detail['cart_info'] ?? null);
        return is_array($snapshot)
            && ($snapshot['sourceType'] ?? '') === 'cashier_v3_project_replacement'
            && is_string($snapshot['cardOperationId'] ?? null)
            && preg_match('/^COP-[A-F0-9]{40}$/D', $snapshot['cardOperationId']) === 1;
    }
}
