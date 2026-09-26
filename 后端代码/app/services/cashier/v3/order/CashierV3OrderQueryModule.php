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
            $page = self::unifiedPage($scope, 'sales');
            return [
                'data' => ['orderCenter' => self::pagePartition($page)],
                'versions' => (new CashierV3OrderCenterPartitionProvider($queries, $recordQueries))
                    ->salesOrderPublicVersions(['salesOrders' => $page['records']], $scope['data_scope']),
                'message' => '销售订单已重新读取。',
            ];
        });

        $handlers->registerProjection('query-order-center-records', function (array $scope) use ($queries, $recordQueries): array {
            $page = self::unifiedPage($scope, (string)($scope['payload']['recordType'] ?? ''));
            $partition = $recordQueries->pagePartition($page);
            return [
                'data' => ['orderCenter' => $partition],
                'versions' => (new CashierV3OrderCenterPartitionProvider($queries, $recordQueries))
                    ->recordPublicVersions(['records' => $page['records']], $scope['data_scope']),
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

    /** 所有列表条件进入同一执行器；底层 Reader 只作授权数据源，详情与写操作不改。 */
    private static function unifiedPage(array $scope, string $type): array
    {
        $pageCode = CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE[$type] ?? '';
        if ($pageCode === '') throw new \InvalidArgumentException('order_center_record_type_invalid');
        $runtime = \app\services\cashier\v3\query\UnifiedQueryModule::runtime();
        $payload = (array)($scope['payload'] ?? []);
        $payload['pageCode'] = $pageCode;
        try {
            $context = $runtime['contextFactory']->make($scope['operator_scope'], $scope['data_scope'], $payload);
            // Runtime 注册表可被复用；每次读取独占临时查询状态，不能串入别的账号/门店。
            $provider = clone $runtime['providers']->resolve($pageCode);
            return $provider->queryPage($context, $payload, $scope['operator_scope'], $scope['data_scope']);
        } catch (\app\services\query\UnifiedQueryException $e) {
            throw new CashierV3CommandException($e->getErrorCode(), $e->getMessage());
        }
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
