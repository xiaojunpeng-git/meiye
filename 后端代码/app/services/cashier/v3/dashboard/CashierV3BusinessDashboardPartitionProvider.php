<?php

namespace app\services\cashier\v3\dashboard;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use think\facade\Log;

final class CashierV3BusinessDashboardPartitionProvider implements CashierV3RootPartitionProvider
{
    /** @var CashierV3BusinessDashboardReadModel */
    private $reader;

    public function __construct(CashierV3BusinessDashboardReadModel $reader)
    {
        $this->reader = $reader;
    }

    public function partitionKey(): string { return 'businessDashboard'; }

    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        try {
            return ['ready' => true, 'payload' => $this->reader->initial($operatorScope, $dataScope), 'public_versions' => []];
        } catch (\Throwable $exception) {
            // 经营看板是按需只读分区。其来源暂不可读时，不能阻断收银登录、
            // 门店身份和功能权限的完整根投影，也不能以空数组伪造经营事实。
            Log::warning('[cashier_v3_business_dashboard_unavailable] ' . json_encode([
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
        $today = date('Y-m-d');
        return [
            'availability' => [
                'contractVersion' => CashierV3BusinessDashboardReadModel::CONTRACT_VERSION,
                'status' => 'temporarily_unavailable',
                'reasonCode' => 'BUSINESS_DASHBOARD_UNAVAILABLE',
                'dataLoaded' => false,
                'businessFactsIncluded' => false,
            ],
            'mode' => 'store',
            'scope' => [
                'dateRange' => ['start' => date('Y-m-01'), 'end' => $today],
                'organization' => null,
                'store' => null,
                'forcedRangeLabel' => '',
            ],
            'cards' => [],
            'selectedMetricCode' => 'cash_performance',
            'trend' => ['metricCode' => 'cash_performance', 'points' => [], 'isLoading' => false],
            'ranking' => [
                'dimension' => 'operator',
                'sortBy' => 'cash_performance',
                'sortOrder' => 'desc',
                'sortOptions' => [],
                'columns' => [],
                'records' => [],
                'isLoading' => false,
            ],
            'metricVersion' => CashierV3BusinessDashboardReadModel::METRIC_VERSION,
            'dataAsOf' => date('Y-m-d H:i:s'),
            'aggregationCaughtUp' => null,
            'coverageStart' => date('Y-m-01'),
        ];
    }
}
