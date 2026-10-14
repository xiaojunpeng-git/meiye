<?php

namespace app\services\cashier\v3;

use think\facade\Db;

/** 购物车与人员候选共用的手工费默认值读模型；不改写配置或历史分配事实。 */
final class CashierV3ProjectLaborDefault
{
    /**
     * 已售卡后续核销的手工费按当前卡内项目设置读取。
     * holder/detail/project 三个身份必须相互吻合；找不到仍有效的卡配置时退回项目通用值，
     * 已完成服务的手工费事实不在此处回算。
     */
    public static function entitlementCents(int $projectId, int $holderId, int $detailId, string $tenantId): int
    {
        $projectDefault = self::cents($projectId, $tenantId);
        if ($projectId <= 0 || $holderId <= 0 || $detailId <= 0) {
            return $projectDefault;
        }
        $holder = Db::name('user_card_holder')->where('id', $holderId)
            ->where('product_type', 5)->field('id,uid,oid,product_id')->find();
        if (!$holder) {
            return $projectDefault;
        }
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('oid', (int)$holder['oid'])->where('uid', (int)$holder['uid'])
            ->where('product_id', $projectId)->field('id,sku_unique')->find();
        if (!$detail) {
            return $projectDefault;
        }
        $cardId = (int)$holder['product_id'];
        $masterCardId = (int)(Db::name('store_product')->where('id', $cardId)
            ->where('product_type', 5)->value('pid') ?: $cardId);
        $masterProjectId = (int)(Db::name('store_product')->where('id', $projectId)
            ->where('product_type', 6)->value('pid') ?: $projectId);
        // 不依赖关联行 ID：卡配置保存会重建关联，而已售权益持有旧行 ID。
        $relations = Db::name('store_card_related')->where('card_product_id', $masterCardId)
            ->where('product_id', $masterProjectId)->where('status', 1)
            ->field('product_attr_unique,labor_fee_cents')->select()->toArray();
        if (!$relations && $masterCardId !== $cardId) {
            $relations = Db::name('store_card_related')->where('card_product_id', $cardId)
                ->where('product_id', $projectId)->where('status', 1)
                ->field('product_attr_unique,labor_fee_cents')->select()->toArray();
        }
        if (count($relations) === 1) {
            return max(0, (int)$relations[0]['labor_fee_cents']);
        }
        $sourceSuk = Db::name('store_product_attr_value')->where('product_id', $projectId)
            ->where('unique', (string)$detail['sku_unique'])->value('suk');
        if ($sourceSuk !== null && $sourceSuk !== '') {
            $masterUnique = Db::name('store_product_attr_value')->where('product_id', $masterProjectId)
                ->where('suk', (string)$sourceSuk)->value('unique');
            foreach ($relations as $relation) {
                if ((string)$relation['product_attr_unique'] === (string)$masterUnique) {
                    return max(0, (int)$relation['labor_fee_cents']);
                }
            }
        }
        return $projectDefault;
    }

    /** 返回当前租户平台主项目配置的金额（分）；未配置为 0，不改变人员资格或权限。 */
    public static function cents(int $projectId, string $tenantId): int
    {
        if ($projectId <= 0 || $tenantId === '') {
            return 0;
        }
        // 门店复制项目的规则属于 pid 指向的平台主项目。必须先解析身份，
        // 不能让门店旧占位规则（包括非零值）遮住平台配置；平台配置为 0 同样有效。
        $masterId = (int)(Db::name('store_product')
            ->where('id', $projectId)
            ->where('type', 1)
            ->where('product_type', 6)
            ->value('pid') ?: 0);
        $rule = Db::name('cashier_v3_project_performance_rule')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $masterId > 0 ? $masterId : $projectId)
            ->order('id', 'desc')
            ->find();
        return is_array($rule)
            ? max(0, (int)($rule['labor_configured_unit_amount_cents'] ?? 0))
            : 0;
    }
}
