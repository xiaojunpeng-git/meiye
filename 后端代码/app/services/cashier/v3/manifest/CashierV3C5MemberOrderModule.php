<?php
namespace app\services\cashier\v3\manifest;

/**
 * C5｜会员、订单、赠送与统一查询。
 *
 * 四个 save-*-query-settings 是 command（覆盖保存本人账号统一查询方案），
 * owner=C5，权限仍分别沿用各功能入口；完整 policy／handler 由 C5 接入前保持未激活。
 */
class CashierV3C5MemberOrderModule implements CashierV3ActionModule
{
    public const OWNER = 'C5';

    private const FEATURE_MEMBER = 'cashier.v3.member';
    // 本次只按功能入口授权；新增会员沿用会员入口，不引入不存在的细粒度菜单码。
    private const FEATURE_MEMBER_CREATE = self::FEATURE_MEMBER;
    private const FEATURE_MEMBER_BATCH = 'cashier.v3.member.batch';
    private const FEATURE_ORDER_CENTER = 'cashier.v3.order_center';
    private const FEATURE_MANAGEMENT = 'cashier.v3.management_center';
    private const FEATURE_CASHIER = 'cashier.v3.cashier';
    private const FEATURE_RESERVATION = 'cashier.v3.reservation';
    private const FEATURE_HANG = 'cashier.v3.hang';

    /** 选择器策略 ID（非单一 feature） */
    private const POLICY_MEMBER_SELECTOR = 'selector:member';
    private const POLICY_QUERY_ENTITIES = 'selector:query_entities';
    private const POLICY_UNIFIED_QUERY_PAGE = 'policy:unified_query_page';

    public function owner(): string
    {
        return self::OWNER;
    }

    public function actions(): array
    {
        $command = [];
        $projection = [];

        $command['create-member'] = self::FEATURE_MEMBER_CREATE;
        $command['update-member'] = self::FEATURE_MEMBER;
        $command['deactivate-member'] = self::FEATURE_MEMBER;
        $command['submit-recharge'] = self::FEATURE_MEMBER;
        $command['prepare-recharge-checkout'] = self::FEATURE_MEMBER;
        $command['add-recharge-checkout-payment-method'] = self::FEATURE_MEMBER;
        $command['update-recharge-checkout-payment-line'] = self::FEATURE_MEMBER;
        $command['remove-recharge-checkout-payment-line'] = self::FEATURE_MEMBER;
        $command['update-recharge-checkout-business-source'] = self::FEATURE_MEMBER;
        $command['reload-recharge-checkout'] = self::FEATURE_MEMBER;
        $command['submit-recharge-checkout'] = self::FEATURE_MEMBER;
        $command['submit-recharge-debt-repayment'] = self::FEATURE_MEMBER;

        foreach ([
            'query-members' => self::FEATURE_MEMBER,
            'open-member-detail' => self::FEATURE_MEMBER,
            'load-member-detail-tab' => self::FEATURE_MEMBER,
            'open-member-more-actions' => self::FEATURE_MEMBER,
            'open-member-editor' => self::FEATURE_MEMBER,
            'open-member-creator' => self::FEATURE_MEMBER_CREATE,
            'open-member-batch-actions' => self::FEATURE_MEMBER_BATCH,
            'open-recharge' => self::FEATURE_MEMBER,
            'open-member-selector' => self::FEATURE_CASHIER,
        ] as $action => $feature) {
            $projection[$action] = $feature;
        }
        // 共用会员选择器：按可信 selectorContext 判权
        $projection['query-member-selector'] = self::POLICY_MEMBER_SELECTOR;

        foreach ([
            'query-sales-orders' => self::FEATURE_ORDER_CENTER,
            'query-order-center-records' => self::FEATURE_ORDER_CENTER,
            'open-sales-order-detail' => self::FEATURE_ORDER_CENTER,
            'view-sales-order' => self::FEATURE_ORDER_CENTER,
            'open-order-operation-logs' => self::FEATURE_ORDER_CENTER,
            'open-operation-logs' => self::FEATURE_ORDER_CENTER,
            'open-order-debt-settlements' => self::FEATURE_ORDER_CENTER,
            'open-debt-settlements' => self::FEATURE_ORDER_CENTER,
            'open-order-refunds' => self::FEATURE_ORDER_CENTER,
            'open-refunds' => self::FEATURE_ORDER_CENTER,
            'open-order-void' => self::FEATURE_ORDER_CENTER,
            'open-void-record' => self::FEATURE_ORDER_CENTER,
            'open-order-reopenings' => self::FEATURE_ORDER_CENTER,
            'open-reopen-records' => self::FEATURE_ORDER_CENTER,
            'open-order-upgrades' => self::FEATURE_ORDER_CENTER,
            'open-upgrade-records' => self::FEATURE_ORDER_CENTER,
            'open-order-services' => self::FEATURE_ORDER_CENTER,
            'open-service-records' => self::FEATURE_ORDER_CENTER,
            'open-order-writeoffs' => self::FEATURE_ORDER_CENTER,
        ] as $action => $feature) {
            $projection[$action] = $feature;
        }
        foreach ([
            'refund-sales-order',
            'void-sales-order',
            'reopen-sales-order',
            'upgrade-sales-order',
            'print-sales-order-receipt',
        ] as $action) {
            $command[$action] = self::FEATURE_ORDER_CENTER;
        }

        // 四个保存查询方案：C5 command，权限沿用各入口
        $command['save-reservation-query-settings'] = self::FEATURE_RESERVATION;
        $command['save-hang-order-query-settings'] = self::FEATURE_HANG;
        $command['save-order-center-query-settings'] = self::FEATURE_ORDER_CENTER;
        $command['save-member-query-settings'] = self::POLICY_UNIFIED_QUERY_PAGE;
        $command['submit-direct-gift'] = self::FEATURE_MEMBER;
        foreach ([
            'save-unified-query-settings',
            'save-unified-query-field-aliases',
            'save-unified-query-custom-field',
            'change-unified-query-custom-field-status',
            'archive-unified-query-custom-field',
            'upgrade-unified-query-field-reference',
            'create-unified-query-export',
        ] as $action) {
            $command[$action] = self::POLICY_UNIFIED_QUERY_PAGE;
        }

        $projection['query-unified-query-capabilities'] = self::POLICY_UNIFIED_QUERY_PAGE;
        $projection['query-unified-query-export-task'] = self::POLICY_UNIFIED_QUERY_PAGE;

        $projection['open-order-gifts'] = self::FEATURE_ORDER_CENTER;
        $projection['open-gift-records'] = self::FEATURE_ORDER_CENTER;
        $projection['open-gift'] = self::FEATURE_MEMBER;
        $projection['open-card-batch'] = self::FEATURE_MEMBER;
        $projection['open-card-benefits'] = self::FEATURE_MEMBER;

        $projection['query-query-entities'] = self::POLICY_QUERY_ENTITIES;
        $projection['query-staff'] = self::FEATURE_MANAGEMENT;
        $projection['open-management-entry'] = self::FEATURE_MANAGEMENT;

        return CashierV3ActionManifest::buildModuleActions(self::OWNER, $command, $projection);
    }
}
