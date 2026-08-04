<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use think\facade\Log;

/** 七类订单记录的 orderCenter 根分区提供者。 */
final class CashierV3OrderCenterPartitionProvider implements CashierV3RootPartitionProvider
{
    /** @var CashierV3SalesOrderQueryServices */
    private $queries;
    /** @var CashierV3OrderCenterRecordQueryServices */
    private $recordQueries;

    public function __construct(
        CashierV3SalesOrderQueryServices $queries,
        ?CashierV3OrderCenterRecordQueryServices $recordQueries = null
    )
    {
        $this->queries = $queries;
        $this->recordQueries = $recordQueries ?: new CashierV3OrderCenterRecordQueryServices();
    }

    public function partitionKey(): string
    {
        return 'orderCenter';
    }

    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        try {
            return [
                'ready' => true,
                'payload' => $this->recordQueries->augmentInitialPartition(
                    $this->queries->initialPartition($operatorScope, $dataScope, $hints),
                    $operatorScope,
                    $dataScope
                ),
                'public_versions' => [],
            ];
        } catch (\Throwable $exception) {
            // 订单中心是按需只读分区。不能以空记录冒充权威数据，但其读取
            // 暂不可用不应阻断当前门店的登录、菜单与收银工作台根状态。
            Log::warning('[cashier_v3_order_center_unavailable] ' . json_encode([
                'store_id' => $operatorScope->storeId(),
                'operator_id' => $operatorScope->operatorId(),
                'error' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
            return [
                'ready' => true,
                'payload' => $this->unavailablePayload(),
                'public_versions' => [],
            ];
        }
    }

    private function unavailablePayload(): array
    {
        return [
            'availability' => [
                'contractVersion' => 'cashier-v3-order-center-v1',
                'status' => 'temporarily_unavailable',
                'reasonCode' => 'ORDER_CENTER_UNAVAILABLE',
                'dataLoaded' => false,
                'businessFactsIncluded' => false,
            ],
            'businessTypes' => [],
            'statusOptions' => [],
            'statusOptionsByType' => new \stdClass(),
            'salesOrders' => [],
            'rechargeOrders' => [],
            'supplementOrders' => [],
            'refundOrders' => [],
            'serviceRecords' => [],
            'giftRecords' => [],
            'projectReplacementRecords' => [],
            'cardUpgradeRecords' => [],
            'projectUpgradeRecords' => [],
            'cardOperationRecords' => [],
            'countsByType' => new \stdClass(),
            'recordsByType' => new \stdClass(),
            'pagesByType' => new \stdClass(),
            'querySettingsByType' => new \stdClass(),
            'total' => 0,
            'page' => 1,
            'pageSize' => 20,
            'salesOrderDetail' => null,
        ];
    }
}
