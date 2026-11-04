<?php

declare(strict_types=1);

namespace app\services\cashier\v3\order;

/**
 * Translate a rejected whole-sale void into a customer-facing reason and next step.
 * The immutable reason code remains in the server log; this class changes only
 * the response wording and never relaxes a financial or entitlement guard.
 */
final class CashierV3SalesVoidFailureMessages
{
    /** Only known rollback-safe concurrency failures may be translated here. */
    public static function forUnexpected(string $raw): ?string
    {
        if ($raw === 'presale_claim_close_changed') {
            return '订单关联的预售领取状态刚发生变化。订单没有作废，请刷新详情后重试。';
        }
        return null;
    }

    public static function forReason(string $reason, string $original): string
    {
        if ($reason === 'sales_reversal_card_already_changed' && strpos($original, '卡项「') !== false) {
            return $original;
        }
        $messages = [
            'order_lifecycle_sales_source_required' => '没有找到要作废的销售订单。请从订单列表重新打开这笔订单。',
            'sales_order_not_mutable' => '作废请求与当前可操作的已结算订单对不上。请从订单列表重新打开详情；仍无法操作时请联系管理员核对订单来源和状态。',
            'order_lifecycle_member_balance_unavailable' => '会员余额账户状态异常，无法安全退回原余额支付金额。请联系管理员核对会员账户后再作废。',
            'order_lifecycle_scope_incomplete' => '当前操作员或门店信息已失效。请重新登录并打开订单详情。',
            'order_lifecycle_action_source_mismatch' => '这笔记录不是可作废的销售订单。请返回订单列表核对单据类型。',
            'order_lifecycle_reason_invalid' => '作废原因不能为空，且不能超过255字。请修改原因后重试。',
            'order_lifecycle_idempotency_missing' => '作废请求资料不完整。请刷新订单详情后重试。',
            'order_lifecycle_idempotency_conflict' => '这笔订单已有另一项操作正在处理。请刷新订单详情，确认最新结果后再试。',
            'order_lifecycle_operation_insert_failed' => '作废操作记录未能保存，订单没有作废。请联系管理员检查订单服务。',
            'order_lifecycle_financial_reversal_insert_failed' => '原收款撤回记录未能保存，订单没有作废。请联系管理员核对收款记录。',
            'order_lifecycle_reversal_insert_failed' => '原订单的冲销记录未能保存，订单没有作废。请联系管理员核对原订单。',
            'order_lifecycle_fact_allocation_invalid' => '原订单的金额分摊资料对不上，暂不能作废。请联系管理员核对原订单金额。',
            'order_lifecycle_project_count_snapshot_invalid' => '原订单的项目次数记录不完整，暂不能作废。请联系管理员核对项目明细。',
            'order_lifecycle_secret_missing' => '系统暂时无法核验作废请求。请联系管理员检查订单服务配置。',
            'order_lifecycle_money_invalid' => '原订单金额资料格式异常，暂不能作废。请联系管理员核对订单金额。',
            'order_already_reversed' => '这笔订单已经作废或退款，不能重复作废。请查看订单操作记录。',
            'sales_void_service_fact_invalid' => '订单关联的服务记录资料不完整。请联系管理员核对服务记录后再作废。',
            'sales_void_attribution_reversal_race' => '订单的销售归属刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_card_issuance_incomplete' => '订单包含购卡明细，但缺少对应的发卡记录。暂不能作废，请联系管理员核对原购卡资料。',
            'sales_historical_product_inventory_unverified' => '这笔历史商品订单缺少原出库记录，暂不能作废。请联系管理员先核对库存与出库流水。',
            'sales_historical_project_rights_unverified' => '这笔历史项目订单的权益或服务记录尚未核对完整，暂不能作废。请联系管理员先核对项目使用记录。',
            'sales_reversal_member_project_mapping_invalid' => '订单购买的项目与会员权益记录对不上。暂不能作废，请联系管理员核对项目权益。',
            'sales_reversal_order_coupon_restore_race' => '订单优惠券的使用记录刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_balance_direction_invalid' => '原订单的余额支付记录方向异常。暂不能作废，请联系管理员核对余额流水。',
            'sales_reversal_balance_restore_exceeds_source' => '要退回的会员余额超过原订单使用的余额。暂不能作废，请联系管理员核对原支付。',
            'sales_reversal_balance_fact_missing' => '原订单缺少余额支付流水。暂不能作废，请联系管理员核对余额流水。',
            'sales_reversal_balance_version_invalid' => '会员余额刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_balance_credit_failed' => '会员余额未能退回，订单没有作废。请联系管理员核对会员账户。',
            'sales_reversal_balance_component_allocation_invalid' => '原订单的本金与赠金金额对不上。暂不能作废，请联系管理员核对余额流水。',
            'sales_reversal_balance_fact_insert_failed' => '余额返还流水未能保存，订单没有作废。请联系管理员核对会员账户。',
            'sales_reversal_debt_already_repaid' => '订单欠款已经补交。请先作废对应补交单，再返回原订单作废。',
            'sales_reversal_debt_repayment_active' => '订单仍有未作废的欠款补交单。请先作废补交单，再返回原订单作废。',
            'sales_reversal_debt_cancelled' => '订单欠款已单独取消。请先在欠款管理中恢复欠款，再返回原订单作废。',
            'sales_reversal_debt_state_invalid' => '订单欠款状态与原记录对不上。请到欠款管理核对并恢复为待还后重试。',
            'sales_reversal_debt_cancel_race' => '订单欠款状态刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_debt_audit_insert_failed' => '欠款撤回记录未能保存，订单没有作废。请联系管理员核对欠款记录。',
            'sales_reversal_card_scope_mismatch' => '发出的卡项与原订单或会员不一致。暂不能作废，请联系管理员核对卡项归属。',
            'sales_reversal_card_already_changed' => '发出的卡项已被修改或撤回。请核对卡项状态和使用记录。',
            'sales_reversal_card_benefit_used' => '卡项权益次数未恢复到购买时的状态。请核对卡项使用和服务记录。',
            'sales_reversal_card_downstream_use_exists' => '卡项仍有未作废的核销记录。请先核对并撤回对应服务，再作废原订单。',
            'sales_reversal_card_active_occupation_exists' => '卡项仍有进行中的预约或服务单。请先处理这些预约或服务，再作废原订单。',
            'sales_reversal_card_operation_exists' => '卡项有未作废的后续操作单。请先处理卡项操作，再作废原订单。',
            'sales_reversal_card_rule_used' => '卡项次数或选项尚未恢复到购买时的状态。请先核对卡项使用记录。',
            'sales_reversal_card_holder_revoke_race' => '卡项状态刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_card_order_revoke_race' => '购卡订单状态刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_card_audit_insert_failed' => '卡项撤回记录未能保存，订单没有作废。请联系管理员核对卡项。',
            'sales_reversal_coupon_trace_invalid' => '原订单的优惠券使用记录不完整。暂不能作废，请联系管理员核对优惠券。',
            'sales_reversal_coupon_state_changed' => '原订单使用的优惠券状态已变化。请核对该券的使用记录。',
            'sales_reversal_coupon_restore_race' => '原订单使用的优惠券刚发生变化。请刷新订单详情后重试。',
            'sales_reversal_cash_refund_exceeds_source' => '原订单可撤回的收款金额不足。暂不能作废，请联系管理员核对收款记录。',
            'sales_reversal_entitlement_credit_invalid' => '订单卡项升级抵扣金额异常。暂不能作废，请联系管理员核对升级记录。',
            'sales_reversal_economic_amount_invalid' => '要撤回的款项与原订单金额对不上。暂不能作废，请联系管理员核对原收款。',
            'sales_void_source_equation_invalid' => '原订单的收款、余额及欠款合计与订单金额对不上。暂不能作废，请联系管理员核对收款。',
            'sales_reversal_money_invalid' => '原订单金额资料格式异常。暂不能作废，请联系管理员核对金额。',
            'sales_reversal_secret_missing' => '系统暂时无法核验原订单。请联系管理员检查订单服务配置。',
            'sales_void_inventory_receipt_scope_invalid' => '订单商品的出库记录与原订单不一致。暂不能作废，请联系管理员核对出库记录。',
            'sales_void_inventory_receipt_incomplete' => '订单商品缺少完整的出库记录。暂不能作废，请联系管理员核对出库记录。',
            'sales_void_inventory_already_reversed' => '订单商品库存已经撤回，不能重复作废。请刷新订单详情。',
            'sales_void_inventory_fact_invalid' => '订单商品库存记录不完整。请联系管理员核对后再作废。',
            'sales_void_inventory_stock_changed' => '商品当前库存与原出库记录对不上。请联系管理员核对库存后再作废。',
            'sales_void_inventory_batch_changed' => '商品批次库存与原出库记录对不上。请联系管理员核对批次后再作废。',
            'sales_void_inventory_stock_cas_failed' => '商品库存刚发生变化。请刷新订单详情后重试。',
            'sales_void_inventory_batch_cas_failed' => '商品批次库存刚发生变化。请刷新订单详情后重试。',
            'sales_void_inventory_movement_write_failed' => '库存退回记录未能保存。订单没有作废，请联系管理员核对库存服务。',
            'sales_void_inventory_quantity_overflow' => '商品退回数量超出库存可记录范围。订单没有作废，请联系管理员核对。',
            'card_operation_reversal_settlement_not_reversible' => '订单关联的卡项升级记录状态不允许撤回。请先核对卡项升级记录。',
            'card_operation_reversal_operation_not_settled' => '订单关联的卡项操作尚未完成。请核对卡项操作记录后再作废。',
            'card_operation_reversal_project_target_used' => '升级后获得的项目已经使用，不能直接作废原订单。请先核对项目使用记录。',
            'card_operation_reversal_project_target_downstream_use' => '升级后获得的项目有未撤回的服务或核销，不能直接作废原订单。',
            'card_operation_reversal_project_source_lines_missing' => '卡项升级前的项目资料缺失。请联系管理员核对卡项升级记录。',
            'card_operation_reversal_project_source_changed' => '卡项升级前的项目状态已变化。请核对卡项和项目记录。',
            'card_operation_reversal_card_source_changed' => '升级前的卡项状态已变化。请核对卡项操作记录。',
            'card_operation_reversal_settlement_race' => '卡项升级结算刚发生变化。请刷新订单详情后重试。',
            'card_operation_reversal_card_order_restore_race' => '升级前的卡项订单刚发生变化。请刷新订单详情后重试。',
            'card_operation_reversal_card_state_restore_race' => '升级前的卡项状态刚发生变化。请刷新订单详情后重试。',
            'card_operation_reversal_project_order_revoke_race' => '升级获得的项目订单刚发生变化。请刷新订单详情后重试。',
            'card_operation_reversal_project_source_restore_race' => '升级前的项目状态刚发生变化。请刷新订单详情后重试。',
            'card_operation_reversal_project_target_revoke_race' => '升级获得的项目状态刚发生变化。请刷新订单详情后重试。',
            'multi_card_upgrade_reversal_header_invalid' => '组合升级订单资料不完整。请联系管理员核对升级记录。',
            'multi_card_upgrade_reversal_sources_missing' => '组合升级使用的原卡记录缺失。请联系管理员核对升级记录。',
            'multi_card_upgrade_reversal_source_changed' => '组合升级使用的原卡状态已变化。请核对原卡记录。',
            'multi_card_upgrade_reversal_source_restore_race' => '组合升级使用的原卡刚发生变化。请刷新订单详情后重试。',
            'service_void_benefit_detail_missing' => '订单关联的项目权益记录缺失。请联系管理员核对服务和权益记录。',
            'service_void_card_holder_missing' => '订单关联的会员卡记录缺失。请联系管理员核对卡项归属。',
            'service_void_group_identity_invalid' => '订单关联的服务记录无法确定。请联系管理员核对服务记录。',
            'service_void_group_not_found_or_too_large' => '订单关联的服务记录数量或状态异常。请联系管理员核对服务记录。',
            'service_void_scope_incomplete' => '服务记录操作资料不完整，订单没有作废。请重新打开订单详情后重试。',
            'service_void_idempotency_missing' => '服务记录作废请求资料不完整，订单没有作废。请刷新订单详情后重试。',
            'service_void_operation_insert_failed' => '服务作废记录未能保存，订单没有作废。请联系管理员核对服务记录。',
            'service_void_entitlement_reversal_insert_failed' => '项目权益撤回记录未能保存，订单没有作废。请联系管理员核对权益。',
            'service_void_performance_reversal_insert_failed' => '服务业绩撤回记录未能保存，订单没有作废。请联系管理员核对业绩。',
            'service_void_secret_missing' => '系统暂时无法核验服务记录作废。请联系管理员检查订单服务配置。',
        ];
        if (isset($messages[$reason])) return $messages[$reason];

        // Keep already specific domain messages, including the card name and
        // usage count. Only the old catch-all texts need a safer fallback.
        $generic = [
            '订单资料或后续使用状态已变化，本次操作未提交。',
            '商品库存或批次状态已变化，本次作废未提交。',
            '升级订单对应权益已经发生变化，本次退款/作废未提交。',
            '订单相关记录异常，操作未完成。',
        ];
        if (!in_array($original, $generic, true)) return $original;

        if (strpos($reason, 'card_operation_reversal_') === 0 || strpos($reason, 'multi_card_upgrade_reversal_') === 0) {
            return '卡项升级记录刚发生变化，订单没有作废。请刷新订单详情并核对卡项操作记录。';
        }
        if (strpos($reason, 'service_void_') === 0) {
            return '关联服务记录未能安全撤回，订单没有作废。请联系管理员核对服务记录。';
        }
        if (strpos($reason, 'sales_void_inventory_') === 0) {
            return '商品库存记录未能安全退回，订单没有作废。请联系管理员核对库存记录。';
        }
        if (strpos($reason, 'order_lifecycle_') === 0 || strpos($reason, 'sales_void_') === 0) {
            return '原订单或关联记录未能安全撤回，订单没有作废。请联系管理员核对订单操作记录。';
        }
        return '订单没有作废，当前原因需要管理员核对。请提供订单编号联系管理员。';
    }
}
