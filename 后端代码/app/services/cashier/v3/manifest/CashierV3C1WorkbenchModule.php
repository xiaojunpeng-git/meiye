<?php
namespace app\services\cashier\v3\manifest;

/**
 * C1｜工作台 bootstrap／context-switch 投影。
 *
 * 调店／换账号／会话变化的唯一服务端入口；不承担 C2～C5 业务写命令。
 */
class CashierV3C1WorkbenchModule implements CashierV3ActionModule
{
    public const OWNER = 'C1';

    private const FEATURE_CASHIER = 'cashier.v3.cashier';

    public function owner(): string
    {
        return self::OWNER;
    }

    public function actions(): array
    {
        return CashierV3ActionManifest::buildModuleActions(
            self::OWNER,
            [],
            [
                'open-cashier-workbench' => self::FEATURE_CASHIER,
            ]
        );
    }
}
