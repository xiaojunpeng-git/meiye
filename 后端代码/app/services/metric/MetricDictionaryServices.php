<?php
namespace app\services\metric;

use app\services\BaseServices;

/**
 * 统一指标字典（口径说明由后端提供）
 */
class MetricDictionaryServices extends BaseServices
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDefinitions(): array
    {
        return [
            [
                'code' => 'cash_performance',
                'name' => '现金业绩',
                'formula' => 'sumStoreCashIncome + oldYeji(type=1)',
                'include' => '有效现金订单(paid=1, valid_cash_only=1, refund_status=0)，含组合支付非旧卡部分',
                'exclude' => '旧卡录入(cash_choose=9)、欠款、卡升级抵扣',
                'source' => 'ValidCashOrderServices::sumStoreCashIncome',
                'time_field' => 'store_order.add_time',
                'aliases' => ['store_income', '实际收款金额', '门店订单金额', 'target_revenue'],
            ],
            [
                'code' => 'actual_performance',
                'name' => '实收业绩',
                'formula' => '现金业绩 − 分成员工(is_fencheng=1)销售业绩与充值业绩',
                'include' => '同现金业绩',
                'exclude' => '同现金业绩 + 门店分成款业绩',
                'source' => 'AgentOrderServices::homeStatics → actual_performance',
                'time_field' => '订单add_time；分成staff_yeji.created_time',
                'aliases' => ['实际业绩'],
            ],
            [
                'code' => 'consume_amount',
                'name' => '消耗金额',
                'formula' => 'activeYeji + oldYeji(type=2)',
                'include' => '核销记录 writeoff_price，status=0',
                'exclude' => '合作类项目(relation_id=78关联商品)',
                'source' => 'ReportServices::activeYeji',
                'time_field' => 'store_order_writeoff.add_time',
                'aliases' => ['客户消耗金额', '核销订单金额', 'target_consume', '消耗业绩'],
            ],
            [
                'code' => 'pk_month_performance',
                'name' => '本月业绩',
                'formula' => 'YejiPkServices::monthYeji（pay_price+组合支付，not_old=1）',
                'include' => 'PK报表专用现金业绩统计',
                'exclude' => '旧卡录入、合作方等（见 YejiPkServices）',
                'source' => 'YejiPkServices::monthYeji',
                'time_field' => 'store_order.add_time / staff_yeji.created_time',
                'aliases' => ['PK本月业绩'],
                'note' => '名称保持「本月业绩」不变；编码独立，后续可与 cash_performance 对齐',
            ],
            [
                'code' => 'pk_month_complete',
                'name' => '实际完成业绩',
                'formula' => '本月业绩 − 本月分成款',
                'include' => 'PK报表',
                'exclude' => '',
                'source' => 'YejiPkServices::pkData',
                'time_field' => 'staff_yeji.created_time',
                'aliases' => ['PK实际完成业绩'],
            ],
            [
                'code' => 'new_customer',
                'name' => '新增客户数',
                'formula' => 'COUNT(DISTINCT store_user.uid) WHERE store_id IN scope_store_ids AND add_time BETWEEN start AND end',
                'include' => '范围内门店客户关联记录中去重后的用户',
                'exclude' => '不以 COUNT(*) 计关联条数；同一客户多店只计 1 人',
                'source' => 'MerchantCustomerMetricServices::newCustomerMetric',
                'time_field' => 'store_user.add_time',
                'aliases' => ['本月新增客户', '新增客户', 'new_month'],
                'note' => '产品确认口径 A（2026-07-15）：去重客户数；客群/我的客户/数仓客户分析必须共用本出口',
            ],
        ];
    }

    public function getByCode(string $code): ?array
    {
        foreach ($this->getDefinitions() as $item) {
            if (($item['code'] ?? '') === $code) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 前端 ⓘ 口径说明
     */
    public function getTooltip(string $code): array
    {
        $item = $this->getByCode($code);
        if (!$item) {
            return ['code' => $code, 'name' => '', 'description' => '指标未定义'];
        }
        return [
            'code' => $item['code'],
            'name' => $item['name'],
            'formula' => $item['formula'],
            'include' => $item['include'],
            'exclude' => $item['exclude'],
            'source' => $item['source'],
            'time_field' => $item['time_field'],
            'updated_at' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
        ];
    }
}
