<?php

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * Resolve the current project and card-project configuration for personnel defaults.
 * The holder/detail/project identity is checked before an issued card may read its
 * current definition; completed service facts are never recalculated here.
 */
final class CashierV3ProjectPersonnelDefaults
{
    public static function projectRule(int $projectId, string $tenantId, bool $lock = false): ?array
    {
        if ($projectId <= 0 || $tenantId === '') return null;
        $masterId = (int)(Db::name('store_product')->where('id', $projectId)
            ->where('type', 1)->where('product_type', 6)->value('pid') ?: 0);
        $query = Db::name('cashier_v3_project_performance_rule')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $masterId > 0 ? $masterId : $projectId)
            ->order('id', 'desc');
        if ($lock) $query->lock(true);
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    public static function currentCardRelation(int $projectId, int $holderId, int $detailId): ?array
    {
        if ($projectId <= 0 || $holderId <= 0 || $detailId <= 0) return null;
        $holder = Db::name('user_card_holder')->where('id', $holderId)
            ->where('product_type', 5)->field('id,uid,oid,product_id')->find();
        if (!$holder) return null;
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('oid', (int)$holder['oid'])->where('uid', (int)$holder['uid'])
            ->where('product_id', $projectId)->field('id,sku_unique')->find();
        if (!$detail) return null;

        $cardId = (int)$holder['product_id'];
        $masterCardId = (int)(Db::name('store_product')->where('id', $cardId)
            ->where('product_type', 5)->value('pid') ?: $cardId);
        $masterProjectId = (int)(Db::name('store_product')->where('id', $projectId)
            ->where('product_type', 6)->value('pid') ?: $projectId);
        // Saving a card rebuilds relation rows, while issued rights retain the old
        // relation ID. Match by verified card/project/SKU identities instead.
        $relations = Db::name('store_card_related')->where('card_product_id', $masterCardId)
            ->where('product_id', $masterProjectId)->where('status', 1)->select()->toArray();
        if (!$relations && $masterCardId !== $cardId) {
            $relations = Db::name('store_card_related')->where('card_product_id', $cardId)
                ->where('product_id', $projectId)->where('status', 1)->select()->toArray();
        }
        if (count($relations) === 1) return $relations[0];
        $sourceSku = Db::name('store_product_attr_value')->where('product_id', $projectId)
            ->where('unique', (string)$detail['sku_unique'])->value('suk');
        if ($sourceSku === null || $sourceSku === '') return null;
        $masterUnique = Db::name('store_product_attr_value')->where('product_id', $masterProjectId)
            ->where('suk', (string)$sourceSku)->value('unique');
        foreach ($relations as $relation) {
            if ((string)$relation['product_attr_unique'] === (string)$masterUnique) return $relation;
        }
        return null;
    }
}
