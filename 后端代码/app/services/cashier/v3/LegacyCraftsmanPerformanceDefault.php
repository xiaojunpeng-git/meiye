<?php
declare(strict_types=1);

namespace app\services\cashier\v3;

use think\exception\ValidateException;
use think\facade\Db;

/**
 * Bridge the remaining old writeoff/reservation entry points to the current
 * project or card-project craftsman default. The retired commission amount is
 * historical configuration and must not determine a newly completed service.
 */
final class LegacyCraftsmanPerformanceDefault
{
    /** A missing default means the caller keeps this service's actual unit amount. */
    public static function projectUnitYuan(int $projectId): ?string
    {
        $cents = CashierV3CraftsmanPerformanceDefault::projectUnitCents(
            $projectId, CashierV3ScopeResolver::TENANT_SCOPE_ID
        );
        return $cents === null ? null : self::centsToYuan($cents);
    }

    /** Batch read keeps the automatic writeoff path free of per-item rule queries. */
    public static function projectUnitsYuan(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
        if (!$projectIds) return [];
        // 子商品继承主项目规则，批量路径必须与单项解析使用同一项目身份。
        $products = Db::name('store_product')->whereIn('id', $projectIds)
            ->field('id,pid,type,product_type')->select()->toArray();
        $masters = array_fill_keys($projectIds, 0);
        foreach ($products as $product) {
            $id = (int)$product['id'];
            $masters[$id] = (int)$product['type'] === 1 && (int)$product['product_type'] === 6
                ? (int)$product['pid'] : 0;
        }
        $ruleIds = array_values(array_unique(array_map(
            static fn (int $id): int => $masters[$id] > 0 ? $masters[$id] : $id,
            $projectIds
        )));
        $rows = Db::name('cashier_v3_project_performance_rule')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('project_id', $ruleIds)->field('id,project_id,craftsman_default_unit_amount_cents')
            ->order('id', 'desc')->select()->toArray();
        $byRuleId = [];
        foreach ($rows as $row) {
            $id = (int)$row['project_id'];
            if (array_key_exists($id, $byRuleId)) continue;
            $amount = $row['craftsman_default_unit_amount_cents'];
            $byRuleId[$id] = $amount === null ? null : self::centsToYuan((int)$amount);
        }
        $result = [];
        foreach ($projectIds as $id) {
            $ruleId = $masters[$id] > 0 ? $masters[$id] : $id;
            if (array_key_exists($ruleId, $byRuleId)) $result[$id] = $byRuleId[$ruleId];
        }
        return $result;
    }

    /**
     * Existing cards read their current active card-project relation, not the
     * relation ID frozen when the card was sold. Ambiguous card/SKU identity is
     * an error rather than an opportunity to use another project's amount.
     */
    public static function cartUnitYuan(array $cart, int $holderId = 0): ?string
    {
        $projectId = (int)($cart['product_id'] ?? 0);
        if ($projectId <= 0) return null;
        if ((int)($cart['cart_type'] ?? 0) !== 2 || (int)($cart['is_gift'] ?? 0) === 1) {
            return self::projectUnitYuan($projectId);
        }
        $oid = (int)($cart['oid'] ?? 0);
        $detailId = (int)($cart['id'] ?? 0);
        $uid = (int)($cart['uid'] ?? 0);
        if ($oid <= 0 || $detailId <= 0 || $uid <= 0) {
            throw new ValidateException('卡项来源不完整，无法确定手艺人业绩');
        }
        $holderQuery = Db::name('user_card_holder')->where('oid', $oid)->where('uid', $uid)
            ->where('product_type', 5)->where('is_del', 0);
        if ($holderId > 0) $holderQuery->where('id', $holderId);
        $holders = $holderQuery->field('id')->select()->toArray();
        $values = [];
        foreach ($holders as $holder) {
            $relation = CashierV3ProjectPersonnelDefaults::currentCardRelation(
                $projectId, (int)$holder['id'], $detailId
            );
            if ($relation === null) continue;
            $values[] = self::relationUnitYuan($relation);
        }
        if (!$holders && $holderId > 0) {
            throw new ValidateException('已售卡来源不匹配，无法确定手艺人业绩');
        }
        // 旧卡已注销后仍有列表快照；没有可用持卡实例或当前关系时按默认单价展示。
        if (!$values) return null;
        if (count(array_unique(array_map(
            static fn (?string $value): string => $value === null ? 'default' : 'manual:' . $value,
            $values
        ))) !== 1) {
            throw new ValidateException('卡内项目配置无法唯一匹配，请检查已售卡');
        }
        return $values[0];
    }

    /** Validate the order-detail identity before looking up a sold card. */
    public static function orderDetailUnitYuan(int $projectId, int $oid, int $detailId, int $holderId = 0): ?string
    {
        if ($projectId <= 0 || $oid <= 0 || $detailId <= 0) {
            return self::projectUnitYuan($projectId);
        }
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('oid', $oid)->where('product_id', $projectId)
            ->field('id,oid,uid,product_id,cart_type,is_gift')->find();
        if (!$detail) throw new ValidateException('项目来源不匹配，无法确定手艺人业绩');
        return self::cartUnitYuan((array)$detail, $holderId);
    }

    /** Explicit zero is a configured value; only default_unit_price returns null. */
    public static function relationUnitYuan(array $relation): ?string
    {
        $mode = (string)($relation['craftsman_default_mode'] ?? 'default_unit_price');
        if ($mode === 'default_unit_price') return null;
        if ($mode !== 'manual_amount') {
            throw new ValidateException('卡内项目手艺人业绩模式无效');
        }
        $cents = (int)($relation['craftsman_default_unit_amount_cents'] ?? -1);
        if ($cents < 0 || $cents % 100 !== 0) {
            throw new ValidateException('卡内项目手艺人业绩须为整数元');
        }
        return self::centsToYuan($cents);
    }

    /** Historical fractional snapshots are only weights; a new labor fact saves integer yuan. */
    public static function actualUnitYuan(string $amount): string
    {
        if (!is_numeric($amount) || bccomp($amount, '0', 2) < 0) {
            throw new ValidateException('本次服务单价无效');
        }
        return bcadd($amount, '0.5', 0);
    }

    /** Preserve an integer total when several staff share a newly completed service. */
    public static function splitYuan(string $total, int $count): array
    {
        if ($count <= 0 || !preg_match('/^\d+$/', $total)) {
            throw new ValidateException('手艺人业绩分摊输入无效');
        }
        $base = intdiv((int)$total, $count);
        $remainder = (int)$total - $base * $count;
        $parts = array_fill(0, $count, $base);
        $parts[$count - 1] += $remainder;
        return $parts;
    }

    private static function centsToYuan(int $cents): string
    {
        if ($cents < 0 || $cents % 100 !== 0) {
            throw new ValidateException('项目手艺人业绩须为整数元');
        }
        return (string)intdiv($cents, 100);
    }
}
