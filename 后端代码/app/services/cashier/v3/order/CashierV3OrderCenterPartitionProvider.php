<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use think\facade\Db;
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
            $payload = $this->recordQueries->augmentInitialPartition(
                $this->queries->initialPartition($operatorScope, $dataScope, $hints),
                $operatorScope,
                $dataScope
            );
            return [
                'ready' => true,
                'payload' => $payload,
                'public_versions' => $this->salesOrderPublicVersions($payload, $dataScope),
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

    /** @return array<int,array{kind:string,id:string,version:int}> */
    public function salesOrderPublicVersions(array $payload, CashierV3DataScopeContext $dataScope): array
    {
        $orders = is_array($payload['salesOrders'] ?? null) ? $payload['salesOrders'] : [];
        if (is_array($payload['salesOrderDetail'] ?? null)) $orders[] = $payload['salesOrderDetail'];
        $ids = [];
        foreach ($orders as $order) {
            if (!is_array($order)) continue;
            $id = trim((string)($order['id'] ?? $order['orderId'] ?? $order['salesOrderId'] ?? ''));
            if ($id !== '') $ids[$id] = true;
        }
        if ($ids === []) return [];

        // 销售单头保持不可变。销售单 command version 由追加的生命周期操作数
        // 派生，必须与 CashierV3OrderLifecycleVersionProvider 完全一致。
        $operationCounts = [];
        $countRows = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('source_type', 'sales')
            ->whereIn('source_order_id', array_keys($ids))
            ->field('source_order_id, COUNT(*) AS operation_count')
            ->group('source_order_id')
            ->select()
            ->toArray();
        foreach ($countRows as $row) {
            $operationCounts[(string)($row['source_order_id'] ?? '')] = (int)($row['operation_count'] ?? 0);
        }

        $versions = [];
        foreach (array_keys($ids) as $id) {
            $versions[$id] = [
                'kind' => 'sales_order',
                'id' => $id,
                'version' => 1 + (int)($operationCounts[$id] ?? 0),
            ];
        }
        return array_values($versions);
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
