<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\manifest;

/**
 * C2｜收银工作台、购物车、补单与结账。
 *
 * 本模块只登记「这个 action 存在、归谁、是不是写命令、需要什么功能入口权限」。
 * 是否可执行由 context policy 与 handler registry 决定；C2 激活业务时
 * 只需在自己的模块与注册器里补齐，不必改动其他子任务的文件。
 */
class CashierV3C2CashierModule implements CashierV3ActionModule
{
    public const OWNER = 'C2';

    /** 收银台功能入口权限码，与前端 featurePermissions 一致 */
    private const FEATURE_CASHIER = 'cashier.v3.cashier';

    public function owner(): string
    {
        return self::OWNER;
    }

    public function actions(): array
    {
        $command = [];
        $projection = [];

        // ---- 购物车与会员选择 ----
        foreach ([
            'choose-catalog-item',
            'remove-cart-line',
            'change-cart-line-quantity',
            'select-cashier-member',
            'set-guest-order',
            'change-supplement-date',
            'exit-supplement',
        ] as $action) {
            $command[$action] = self::FEATURE_CASHIER;
        }

        // ---- 结账流程 ----
        foreach ([
            'prepare-checkout',
            'prepare-debt-repayment',
            'checkout-step-back',
            'checkout-step-next',
            'toggle-combination-payment',
            'add-payment-method',
            'update-payment-line',
            'remove-payment-line',
            'confirm-debt-warning',
            'confirm-checkout-final-changes',
            'submit-checkout',
            'submit-debt-repayment',
            'return-to-payment-edit',
            'retry-checkout',
            'continue-partial-payment-recovery',
            'go-to-writeoff-after-checkout',
            'finish-checkout-and-return',
        ] as $action) {
            $command[$action] = self::FEATURE_CASHIER;
        }

        // ---- 收银台内的纯展示抽屉 ----
        foreach ([
            'open-line-assignment',
            'open-line-coupon',
            'open-line-debt',
            'open-price-change',
            'open-card-upgrade',
            'open-project-upgrade',
            'open-order-note',
            'open-supplement',
            'open-balance-payment',
            'open-balance-payment-identity-verification',
            'open-payment-note',
            'open-checkout-source-selector',
            'open-add-service-consumption',
            'open-add-card-service-project',
            'query-checkout-result',
            'open-member-debt-repayment',
            'query-debt-repayment-result',
        ] as $action) {
            $projection[$action] = self::FEATURE_CASHIER;
        }

        return CashierV3ActionManifest::buildModuleActions(self::OWNER, $command, $projection);
    }
}
