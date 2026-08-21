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
    private const FEATURE_MEMBER_CREATE = 'cashier.v3.member.create';
    private const FEATURE_MEMBER_EDIT = 'cashier.v3.member.edit';
    private const FEATURE_MEMBER_BATCH = 'cashier.v3.member.batch';
    private const FEATURE_ORDER_CENTER = 'cashier.v3.order_center';
    private const FEATURE_MANAGEMENT = 'cashier.v3.management_center';
    private const FEATURE_CASHIER = 'cashier.v3.cashier';
    private const FEATURE_RECHARGE = 'cashier.v3.cashier.recharge';
    private const FEATURE_GIFT = 'cashier.v3.cashier.gift';
    private const FEATURE_RESERVATION = 'cashier.v3.reservation';
    private const FEATURE_HANG = 'cashier.v3.hang';
    private const FEATURE_ORDER_STAFF_ADJUST = 'cashier.v3.order.staff_adjust';
    private const FEATURE_ORDER_REFUND = 'cashier.v3.order.refund';
    private const FEATURE_ORDER_VOID = 'cashier.v3.order.void';
    private const FEATURE_ORDER_REOPEN = 'cashier.v3.order.reopen';
    private const FEATURE_ORDER_RECEIPT_PRINT = 'cashier.v3.order.receipt_print';
    private const FEATURE_ORDER_DEBT_VIEW = 'cashier.v3.order.debt_view';
    private const FEATURE_ORDER_SERVICE_DETAIL = 'cashier.v3.order.service_detail';
    private const FEATURE_ORDER_SERVICE_VOID = 'cashier.v3.order.service_void';

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
        $command['update-member'] = self::FEATURE_MEMBER_EDIT;
        $command['deactivate-member'] = self::FEATURE_MEMBER;
        foreach ([
            'submit-recharge',
            'prepare-recharge-checkout',
            'prepare-recharge-debt-repayment',
            'add-recharge-checkout-payment-method',
            'update-recharge-checkout-payment-line',
            'remove-recharge-checkout-payment-line',
            'update-recharge-checkout-business-source',
            'update-recharge-checkout-business-date',
            'reload-recharge-checkout',
            'submit-recharge-checkout',
            'submit-recharge-debt-repayment',
        ] as $action) {
            $command[$action] = self::FEATURE_RECHARGE;
        }

        foreach ([
            'query-members' => self::FEATURE_MEMBER,
            'open-member-detail' => self::FEATURE_MEMBER,
            'load-member-detail-tab' => self::FEATURE_MEMBER,
            'open-member-more-actions' => self::FEATURE_MEMBER,
            'open-member-editor' => self::FEATURE_MEMBER_EDIT,
            'open-member-creator' => self::FEATURE_MEMBER_CREATE,
            'open-member-batch-actions' => self::FEATURE_MEMBER_BATCH,
            'open-recharge' => self::FEATURE_RECHARGE,
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
            'open-order-debt-settlements' => self::FEATURE_ORDER_DEBT_VIEW,
            'open-debt-settlements' => self::FEATURE_ORDER_DEBT_VIEW,
            'open-sales-order-personnel-adjustment' => self::FEATURE_ORDER_STAFF_ADJUST,
            'open-order-refunds' => self::FEATURE_ORDER_REFUND,
            'open-refunds' => self::FEATURE_ORDER_REFUND,
            'open-order-void' => self::FEATURE_ORDER_VOID,
            'open-void-record' => self::FEATURE_ORDER_VOID,
            'open-order-reopenings' => self::FEATURE_ORDER_REOPEN,
            'open-reopen-records' => self::FEATURE_ORDER_REOPEN,
            'open-order-upgrades' => self::FEATURE_ORDER_CENTER,
            'open-upgrade-records' => self::FEATURE_ORDER_CENTER,
            'open-order-services' => self::FEATURE_ORDER_SERVICE_DETAIL,
            'open-service-records' => self::FEATURE_ORDER_SERVICE_DETAIL,
            'open-service-record-craftsman-adjustment' => self::FEATURE_ORDER_SERVICE_DETAIL,
            'open-order-writeoffs' => self::FEATURE_ORDER_CENTER,
        ] as $action => $feature) {
            $projection[$action] = $feature;
        }
        $command['adjust-sales-order-personnel'] = self::FEATURE_ORDER_STAFF_ADJUST;
        $command['update-sales-order-note'] = self::FEATURE_ORDER_CENTER;
        $command['refund-sales-order'] = self::FEATURE_ORDER_REFUND;
        $command['void-sales-order'] = self::FEATURE_ORDER_VOID;
        $command['void-service-record'] = self::FEATURE_ORDER_SERVICE_VOID;
        // 编辑入口与保存使用同一服务记录权限快照，不增加第二个功能权限点。
        $command['adjust-service-record-craftsmen'] = self::FEATURE_ORDER_SERVICE_DETAIL;
        $command['refund-recharge-order'] = self::FEATURE_ORDER_REFUND;
        $command['void-recharge-order'] = self::FEATURE_ORDER_VOID;
        $command['void-order-center-supplement'] = self::FEATURE_ORDER_VOID;
        $command['void-order-center-gift'] = self::FEATURE_ORDER_VOID;
        $command['reopen-sales-order'] = self::FEATURE_ORDER_REOPEN;
        $command['upgrade-sales-order'] = self::FEATURE_ORDER_CENTER;
        $command['print-sales-order-receipt'] = self::FEATURE_ORDER_RECEIPT_PRINT;

        // 四个保存查询方案：C5 command，权限沿用各入口
        $command['save-reservation-query-settings'] = self::FEATURE_RESERVATION;
        $command['save-hang-order-query-settings'] = self::FEATURE_HANG;
        $command['save-order-center-query-settings'] = self::FEATURE_ORDER_CENTER;
        $command['save-member-query-settings'] = self::POLICY_UNIFIED_QUERY_PAGE;
        $command['submit-direct-gift'] = self::FEATURE_GIFT;
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
        $projection['open-gift'] = self::FEATURE_GIFT;
        $projection['open-card-batch'] = self::FEATURE_MEMBER;
        $projection['open-card-benefits'] = self::FEATURE_MEMBER;

        $projection['query-query-entities'] = self::POLICY_QUERY_ENTITIES;
        $projection['query-staff'] = self::FEATURE_MANAGEMENT;
        $projection['open-management-entry'] = self::FEATURE_MANAGEMENT;

        return CashierV3ActionManifest::buildModuleActions(self::OWNER, $command, $projection);
    }
}
