<?php

namespace app\services\cashier\v3\checkout;

/**
 * 独立赠送项目的原始订单与明细准入规则。历史问卷奖励由旧系统订单承载，
 * 系统隐藏仅影响订单列表；只有明确的问卷赠送来源可继续使用原明细余次。
 */
final class CashierV3IndependentGiftAuthority
{
    public static function isSupported(array $detail, array $order, ?int $now = null): bool
    {
        $now = $now ?? time();
        $migration = (int)($detail['cart_type'] ?? -1) === 2
            && (string)($detail['source_type'] ?? '') === 'migration'
            && strpos((string)($detail['cart_id'] ?? ''), 'RH-PG-') === 0
            && (int)($order['is_system_del'] ?? -1) === 0;
        $survey = (int)($detail['cart_type'] ?? -1) === 0
            && (string)($detail['source_type'] ?? '') === ''
            && preg_match('/^[1-9][0-9]*$/D', (string)($detail['cart_id'] ?? '')) === 1
            && preg_match('/^完成问卷赠送·任务#[1-9][0-9]*$/uD', (string)($order['mark'] ?? '')) === 1
            && in_array((int)($order['is_system_del'] ?? -1), [0, 1], true);

        // 只接受本人、同一原单且仍在有效期内的赠送项目；退款和作废不能复活。
        return ($migration || $survey)
            && (int)($detail['id'] ?? 0) > 0
            && (int)($detail['uid'] ?? 0) > 0
            && (int)($detail['uid'] ?? 0) === (int)($order['uid'] ?? -1)
            && (int)($detail['oid'] ?? 0) === (int)($order['id'] ?? -1)
            && (int)($detail['product_id'] ?? 0) > 0
            && (int)($detail['product_type'] ?? 0) === 6
            && (int)($detail['is_gift'] ?? 0) === 1
            && (int)($detail['is_writeoff'] ?? 1) === 0
            && (int)($detail['write_times'] ?? 0) > 0
            && (int)($detail['write_surplus_times'] ?? 0) > 0
            && (int)($detail['write_surplus_times'] ?? 0) <= (int)($detail['write_times'] ?? 0)
            && ((int)($detail['write_start'] ?? 0) <= 0 || (int)$detail['write_start'] <= $now)
            && ((int)($detail['write_end'] ?? 0) <= 0 || (int)$detail['write_end'] >= $now)
            && (int)($order['paid'] ?? 0) === 1
            && (int)($order['is_del'] ?? 1) === 0
            && (int)($order['is_user_del'] ?? 1) === 0
            && (int)($order['refund_status'] ?? -1) === 0
            && (int)($order['terminal_action'] ?? -1) === 0
            && (int)($order['card_upgrade_use_oid'] ?? -1) === 0;
    }
}
