<?php

namespace app\services\cashier\v3\dashboard;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;

/** C4 只读看板安装器。 */
final class CashierV3BusinessDashboardModule
{
    public static function install(CashierV3ActionDispatcher $dispatcher, CashierV3RootDomainAssembler $assembler): void
    {
        $reader = new CashierV3BusinessDashboardReadModel();
        $targetReader = new CashierV3StoreTargetDashboardReadModel();
        $handlers = $dispatcher->handlers();
        foreach (['query-business-dashboard-summary', 'query-business-dashboard-trend', 'query-business-dashboard-ranking', 'open-business-dashboard-detail', 'query-store-target-dashboard'] as $action) {
            if ($handlers->hasProjection($action)) {
                throw new \LogicException('C4 business dashboard handler duplicate: ' . $action);
            }
        }
        $handlers->registerProjection('query-business-dashboard-summary', static function (array $scope) use ($reader): array {
            return ['data' => ['businessDashboard' => $reader->dashboard((array)$scope['payload'], $scope['operator_scope'], $scope['data_scope'])], 'message' => '经营数据已刷新。'];
        });
        $handlers->registerProjection('query-business-dashboard-trend', static function (array $scope) use ($reader): array {
            $dashboard = $reader->dashboard((array)$scope['payload'], $scope['operator_scope'], $scope['data_scope']);
            return ['data' => ['businessDashboard' => $dashboard], 'message' => '经营趋势已刷新。'];
        });
        $handlers->registerProjection('query-business-dashboard-ranking', static function (array $scope) use ($reader): array {
            $dashboard = $reader->dashboard((array)$scope['payload'], $scope['operator_scope'], $scope['data_scope']);
            return ['data' => ['businessDashboard' => $dashboard], 'message' => '经营排行已刷新。'];
        });
        $handlers->registerProjection('open-business-dashboard-detail', static function (array $scope) use ($reader): array {
            return ['data' => ['businessDashboard' => ['detail' => $reader->detail((array)$scope['payload'], $scope['operator_scope'], $scope['data_scope'])]], 'message' => '经营明细已读取。'];
        });
        $handlers->registerProjection('query-store-target-dashboard', static function (array $scope) use ($targetReader): array {
            return ['data' => ['targetDashboard' => $targetReader->dashboard((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope'])], 'message' => '目标看板已刷新。'];
        });
        $assembler->registerPartitionProvider(new CashierV3BusinessDashboardPartitionProvider($reader));
    }
}
