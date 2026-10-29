<?php
namespace app\services\report\query;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

/** 人工登记独立登记到统一查询；金额不是收款/业绩指标，不能冒充经营事实。 */
final class BasicRegistrationQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public static function fields(): array
    {
        return [
            ['registration_id', '登记编号', 'integer', false, false],
            ['platform', '平台', 'text', true, true],
            ['store_name', '门店', 'text', true, false],
            ['registered_date', '核销日期', 'date', true, true],
            // 007 稳态表头采用“劵码”原文；只改展示名，登记字段和保存值不变。
            ['coupon_code', '劵码', 'text', true, true],
            ['amount', '金额', 'decimal', true, true],
            ['registration_type', '类型', 'text', true, true],
        ];
    }

    /** 同一字段登记同时服务平台、门店、筛选、合计及导出，不接收任意 SQL 配置。 */
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $fields = [];
        foreach (self::fields() as [$key, $label, $type, $visible, $quick]) {
            $fields[] = UnifiedQueryPageRegistry::field($key, $label, $type, $visible, $quick);
        }
        $registry->registerPage(BasicRegistrationQueryProvider::PAGE_CODE, '刷单登记', $fields,
            'registration_id', ['keywordFields' => ['coupon_code', 'platform', 'registration_type'],
                'requiredFeature' => 'cashier.v3.data']);
    }
}
