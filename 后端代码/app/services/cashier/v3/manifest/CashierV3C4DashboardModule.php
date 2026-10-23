<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\manifest;

/**
 * C4｜经营看板。
 *
 * 看板全部是只读投影：不产生命令、回执、事实、事件与 Outbox。
 * 异步聚合不主动推动工作台版本；看板查询按统一数据架构返回
 * dataAsOf、aggregationCaughtUp、metricVersion，由 C4 在激活处理器时实现。
 */
class CashierV3C4DashboardModule implements CashierV3ActionModule
{
    public const OWNER = 'C4';

    /** 经营看板与目标分别由门店端能力目录授权，查询仍复用原数据范围。 */
    private const FEATURE_DASHBOARD = 'cashier.v3.management.dashboard';

    public function owner(): string
    {
        return self::OWNER;
    }

    public function actions(): array
    {
        $projection = [];
        foreach ([
            'query-business-dashboard-summary',
            'query-business-dashboard-trend',
            'query-business-dashboard-ranking',
            'open-business-dashboard-detail',
            'query-store-target-dashboard',
        ] as $action) {
            $projection[$action] = $action === 'query-store-target-dashboard' ? 'cashier.v3.targets' : self::FEATURE_DASHBOARD;
        }

        return CashierV3ActionManifest::buildModuleActions(self::OWNER, [], $projection);
    }
}
