<?php

namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryPageRegistry;

/**
 * 订单中心统一查询／导出字段合同。
 *
 * 这里的 key 与门店端 OrderCenterView 的显示字段一一对应。页面列表和导出任务
 * 都只能引用本合同中的字段，不接受浏览器传入的数据库列名。
 */
final class CashierV3OrderCenterUnifiedQueryContract
{
    public const PAGE_BY_TYPE = [
        'sales' => 'order_center_sales',
        'recharge' => 'order_center_recharge',
        'refund' => 'order_center_refund',
        'debt' => 'order_center_debt',
        'service' => 'order_center_service',
        'supplement' => 'order_center_supplement',
        'gift' => 'order_center_gift',
        'card_operation' => 'order_center_card_operation',
    ];

    /** @return array<string,mixed> */
    public static function definition(string $pageCode): array
    {
        $type = self::typeForPage($pageCode);
        $definitions = [
            // 仅重命名销售页的展示标签；字段 key 保持 business_date，避免改变筛选和导出口径。
            'sales' => ['销售订单', [
                ['sales_order_no', '销售订单号', 'text', true, true], ['business_date', '销售日期', 'date', true, true],
                ['member_name', '会员姓名／游客'], ['phone', '手机号'], ['store', '销售门店'], ['item_summary', '商品摘要'],
                ['item_count', '商品数量', 'integer'], ['receivable_amount', '应收金额', 'amount'], ['discount_amount', '优惠金额', 'amount'],
                ['debt_amount', '欠款金额', 'amount'], ['actual_received_amount', '现金业绩', 'amount'], ['payment_method', '收款方式'],
                ['salesperson', '销售人'], ['cashier', '收银员／操作人'], ['source', '客户来源'],
                ['payment_status', '支付状态'], ['order_status', '订单状态'], ['supplement', '补单标记'], ['payment_completed_at', '支付完成时间', 'datetime'],
            ], ['sales_order_no', 'member_name', 'phone', 'item_summary']],
            'recharge' => ['充值订单', [
                ['recharge_order_no', '充值订单号', 'text', true, true], ['business_date', '业务日期', 'date', true, true],
                ['member_name', '会员姓名'], ['phone', '手机号'], ['store', '办理门店'], ['recharge_plan', '充值方案'],
                ['salesperson', '销售人'], ['recharge_amount', '充值金额', 'amount'], ['gift_amount', '赠送金额', 'amount'], ['actual_received_amount', '现金业绩', 'amount'],
                ['payment_method', '收款方式'], ['operator', '操作人'], ['payment_status', '支付状态'],
                ['order_status', '订单状态'], ['payment_completed_at', '支付完成时间', 'datetime'],
            ], ['recharge_order_no', 'member_name', 'phone']],
            'refund' => ['退款记录', [
                ['refund_order_no', '退款单号', 'text', true, true], ['business_date', '业务日期', 'date', true, true],
                ['source_order_no', '来源订单号'], ['member_name', '会员姓名／游客'], ['phone', '手机号'], ['refund_summary', '退款内容'],
                ['refund_amount', '退款金额', 'amount'], ['refund_method', '退款方式'], ['store', '办理门店'], ['operator', '操作人'],
                ['refund_status', '退款状态'], ['refund_completed_at', '退款完成时间', 'datetime'],
            ], ['refund_order_no', 'source_order_no', 'member_name', 'phone', 'refund_summary']],
            'debt' => ['欠款管理', [
                ['debt_no', '欠款编号', 'text', true, true], ['business_date', '业务日期', 'date', true, true], ['member_name', '客户'], ['phone', '手机号'], ['source_type', '欠款来源'],
                ['source_order_no', '来源订单'], ['original_debt_amount', '原欠款', 'amount'], ['repaid_amount', '已还', 'amount'],
                ['remaining_amount', '剩余', 'amount'], ['debt_status', '状态'], ['store', '欠款门店'], ['created_at', '创建时间', 'datetime'],
            ], ['debt_no', 'source_order_no', 'member_name', 'phone']],
            'service' => ['服务记录', [
                ['service_record_no', '服务记录号', 'text', true, true], ['source', '来源'], ['business_date', '业务日期', 'date', true, true],
                ['member_name', '会员姓名'], ['service_project', '服务项目'], ['entitlement_source', '权益来源'], ['source_card', '来源卡名称'],
                ['source_card_no', '完整卡号'], ['used_times', '本次使用次数', 'integer'], ['store', '服务门店'], ['craftsman', '手艺人'],
                ['labor_fee_amount', '手工费', 'amount'], ['labor_performance_type', '服务业绩类型'], ['labor_performance_ratio', '业绩比例'],
                ['labor_performance_amount', '劳动业绩', 'amount'], ['project_count', '工资项目数', 'integer'], ['operator', '操作人'], ['service_status', '状态'], ['service_completed_at', '服务完成时间', 'datetime'],
                ['voided_at', '作废时间', 'datetime'], ['void_reason', '作废原因'], ['void_operator', '作废操作人'], ['detail_remark', '明细备注'],
            ], ['service_record_no', 'source', 'member_name', 'service_project', 'entitlement_source', 'source_card_no', 'craftsman']],
            'supplement' => ['补交记录', [
                ['supplement_order_no', '补交单号', 'text', true, true], ['business_date', '业务日期', 'date', true, true],
                ['debt_no', '欠款编号'], ['source_order_no', '来源订单号'], ['member_name', '会员姓名'], ['phone', '手机号'],
                ['debt_summary', '欠款摘要'], ['salesperson', '销售人'], ['supplement_amount', '补交金额', 'amount'], ['payment_method', '收款方式'], ['store', '补交门店'],
                ['operator', '操作人'], ['payment_status', '支付状态'], ['payment_completed_at', '支付完成时间', 'datetime'],
            ], ['supplement_order_no', 'debt_no', 'source_order_no', 'member_name', 'phone']],
            'gift' => ['赠送记录', [
                ['gift_record_no', '赠送记录号', 'text', true, true], ['business_date', '业务日期', 'date', true, true],
                ['member_name', '会员姓名'], ['gift_source', '赠送来源'], ['gift_type', '赠送类型'], ['gift_content', '赠送内容'],
                ['gift_quantity', '赠送数量', 'integer'], ['effective_at', '生效时间', 'datetime'], ['expires_at', '到期时间', 'datetime'],
                ['gift_status', '赠送状态'], ['store', '办理门店'], ['operator', '操作人'], ['gift_reason', '赠送原因'], ['created_at', '创建时间', 'datetime'],
            ], ['gift_record_no', 'member_name', 'gift_source', 'gift_content']],
            'card_operation' => ['卡操作记录', [
                ['card_operation_no', '操作单号', 'text', true, true], ['business_date', '业务日期', 'date', true, true],
                ['member_name', '会员姓名'], ['operation_type', '操作类型', 'text', true, true], ['source_card', '原卡项／原项目'],
                ['target_content', '目标卡项／目标项目'], ['operation_amount', '补差／操作金额', 'amount'], ['sales_order_no', '关联销售订单号'],
                ['store', '办理门店'], ['operator', '操作人'], ['record_status', '记录状态'], ['operation_reason', '操作原因'], ['completed_at', '完成时间', 'datetime'],
            ], ['card_operation_no', 'member_name', 'source_card', 'target_content', 'operator']],
        ];
        if (!isset($definitions[$type])) throw new \InvalidArgumentException('订单中心统一查询页面不合法');
        [$label, $fields, $keywordFields] = $definitions[$type];
        $registered = [];
        foreach ($fields as $field) {
            [$key, $fieldLabel] = $field;
            $fieldType = $field[2] ?? 'text';
            $visible = $field[3] ?? true;
            $quick = $field[4] ?? false;
            $registered[] = UnifiedQueryPageRegistry::field($key, $fieldLabel, $fieldType, $visible, $quick);
        }
        // 名称用于展示/文本查询，选择器提交的实体 ID 走独立隐藏列；不改变导出名称。
        foreach (['salesperson', 'cashier', 'operator', 'craftsman', 'void_operator', 'store'] as $identity) {
            if (!in_array($identity, array_column($fields, 0), true)) continue;
            $registered[] = ['key' => $identity . '_query_ids', 'label' => $identity . '身份',
                'type' => 'text', 'defaultVisible' => false, 'defaultQuick' => false,
                'allowedOperations' => ['filter'], 'hidden' => true];
        }
        // The provider emits a domain-prefixed string key. Declare it explicitly
        // as text so the generic sorter never coerces every row to numeric zero.
        // It is hidden and exists only to give frozen plans a deterministic tie-breaker.
        $registered[] = [
            'key' => 'record_id',
            'label' => '记录标识',
            'type' => 'text',
            'defaultVisible' => false,
            'defaultQuick' => false,
            'allowedOperations' => ['sort'],
            'hidden' => true,
        ];
        return ['type' => $type, 'label' => $label, 'fields' => $registered, 'keywordFields' => $keywordFields];
    }

    public static function typeForPage(string $pageCode): string
    {
        $type = array_search($pageCode, self::PAGE_BY_TYPE, true);
        if (!is_string($type) || $type === '') throw new \InvalidArgumentException('订单中心统一查询页面不合法');
        return $type;
    }
}
