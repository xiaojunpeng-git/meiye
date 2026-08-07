<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;

/** 订单中心七类只读记录 handler 与根分区安装器。 */
final class CashierV3OrderQueryModule
{
    public static function install(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3RootDomainAssembler $assembler,
        ?CashierV3SalesOrderQueryServices $queries = null,
        ?CashierV3OrderCenterRecordQueryServices $recordQueries = null
    ): CashierV3SalesOrderQueryServices {
        $queries = $queries ?: new CashierV3SalesOrderQueryServices();
        $recordQueries = $recordQueries ?: new CashierV3OrderCenterRecordQueryServices();
        $handlers = $dispatcher->handlers();
        foreach (['query-sales-orders', 'query-order-center-records', 'open-sales-order-detail'] as $action) {
            if ($handlers->hasProjection($action)) {
                throw new \LogicException('C5 order query module duplicate handler: ' . $action);
            }
        }

        $handlers->registerProjection('query-sales-orders', function (array $scope) use ($queries, $recordQueries): array {
            $page = $queries->querySalesOrders(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['orderCenter' => self::pagePartition($page)],
                'versions' => (new CashierV3OrderCenterPartitionProvider($queries, $recordQueries))
                    ->salesOrderPublicVersions(['salesOrders' => $page['records']], $scope['data_scope']),
                'message' => '销售订单已重新读取。',
            ];
        });

        $handlers->registerProjection('query-order-center-records', function (array $scope) use ($recordQueries): array {
            $page = $recordQueries->queryRecords(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['orderCenter' => $recordQueries->pagePartition($page)],
                'message' => '订单记录已重新读取。',
            ];
        });

        $handlers->registerProjection('open-sales-order-detail', function (array $scope) use ($queries, $recordQueries): array {
            $detail = $queries->salesOrderDetail(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            if ($detail === null) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::RESOURCE_NOT_FOUND,
                    '该销售订单不存在或当前账号无权查看。'
                );
            }
            return [
                'data' => ['orderCenter' => [
                    'contractVersion' => CashierV3SalesOrderQueryServices::CONTRACT_VERSION,
                    'salesOrderDetail' => $detail,
                ]],
                'versions' => (new CashierV3OrderCenterPartitionProvider($queries, $recordQueries))
                    ->salesOrderPublicVersions(['salesOrderDetail' => $detail], $scope['data_scope']),
                'message' => '销售订单详情已读取。',
            ];
        });

        $assembler->registerPartitionProvider(new CashierV3OrderCenterPartitionProvider($queries, $recordQueries));
        return $queries;
    }

    private static function pagePartition(array $page): array
    {
        return [
            'contractVersion' => $page['contractVersion'],
            'dataStatus' => $page['dataStatus'],
            'dataAsOf' => $page['dataAsOf'],
            'businessTimezone' => $page['businessTimezone'],
            'economicsDataStatus' => $page['economicsDataStatus'],
            'paginationMode' => $page['paginationMode'],
            'paginationCursor' => $page['paginationCursor'],
            'hasMore' => $page['hasMore'],
            'freshnessPolicy' => $page['freshnessPolicy'],
            'performancePolicy' => $page['performancePolicy'],
            'statusOptions' => $page['statusOptions'],
            'statusOptionsByType' => ['sales' => $page['statusOptions']],
            'salesOrders' => $page['records'],
            'recordsByType' => ['sales' => $page['records']],
            'pagesByType' => [
                'sales' => [
                    'total' => $page['total'],
                    'page' => $page['page'],
                    'pageSize' => $page['pageSize'],
                    'dataStatus' => $page['dataStatus'],
                    'paginationMode' => $page['paginationMode'],
                    'paginationCursor' => $page['paginationCursor'],
                    'hasMore' => $page['hasMore'],
                ],
            ],
            'total' => $page['total'],
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
        ];
    }
}
