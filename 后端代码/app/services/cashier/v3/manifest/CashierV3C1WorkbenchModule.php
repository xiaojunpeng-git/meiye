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

    private const POLICY_STORE_V3_SESSION = 'policy:store_v3_session';

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
                // 建立已经过门店任职、入口与岗位规则校验的 V3 会话；
                // 组织数据权限人员即使无 system_store_staff 也可建立只读
                // 门店会话，但后续所有 command 由统一只读门禁拒绝。
                'open-cashier-workbench' => self::POLICY_STORE_V3_SESSION,
            ]
        );
    }
}
