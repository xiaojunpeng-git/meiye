<?php

namespace app\services\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/**
 * 平台订单中心只读桥接。
 *
 * 复用 cashier-v3 订单中心的八类权威读取器，但绝不接受 cashier_token、
 * 不暴露任何写命令。平台数据范围唯一由 EmployeeDataScopeServices 推导。
 */
final class PlatformOrderCenterReadServices
{
    /** @var EmployeeDataScopeServices */
    private $employeeDataScope;

    /** @var CashierV3SalesOrderQueryServices */
    private $salesQueries;

    /** @var CashierV3OrderCenterRecordQueryServices */
    private $recordQueries;

    public function __construct(EmployeeDataScopeServices $employeeDataScope)
    {
        $this->employeeDataScope = $employeeDataScope;
        // These V3 readers deliberately accept optional callables. Letting the
        // generic container construct them tries to resolve optional callable
        // parameters (and the cursor codec's scalar secret) as services, which
        // turns an otherwise valid query into HTTP 500. They are pure readers;
        // instantiate their production defaults explicitly.
        $this->salesQueries = new CashierV3SalesOrderQueryServices();
        $this->recordQueries = new CashierV3OrderCenterRecordQueryServices();
    }

    /**
     * 导航元数据不读取各业务数量；平台复用同一无计数页签契约。
     * @return array{readOnly:bool,scope:array,businessTypes:array}
     */
    public function metadata(array $adminInfo): array
    {
        $context = $this->context($adminInfo);
        if ($context === null) {
            return [
                'readOnly' => true,
                'scope' => ['mode' => 'none'],
                'businessTypes' => $this->businessTypes(),
            ];
        }
        [, , $scopeMeta] = $context;
        return [
            'readOnly' => true,
            'scope' => $scopeMeta,
            'businessTypes' => $this->businessTypes(),
        ];
    }

    /** @return array{readOnly:bool,scope:array,page:array} */
    public function records(array $adminInfo, array $payload): array
    {
        $context = $this->context($adminInfo);
        if ($context === null) {
            return [
                'readOnly' => true,
                'scope' => ['mode' => 'none'],
                'page' => $this->emptyPage($payload),
            ];
        }
        [$operator, $scope, $scopeMeta] = $context;
        $type = trim((string)($payload['recordType'] ?? $payload['record_type'] ?? 'sales'));
        if ($type === 'sales') {
            $payload['recordType'] = 'sales';
            $page = $this->salesQueries->querySalesOrders($payload, $operator, $scope);
        } else {
            $payload['recordType'] = $type;
            $page = $this->recordQueries->queryRecords($payload, $operator, $scope);
        }
        return ['readOnly' => true, 'scope' => $scopeMeta, 'page' => $page];
    }

    /**
     * 平台订单中心和其他 Vue 3 平台报表共用组织／门店双栏选择体验，
     * 但树与可选门店严格从订单中心自身的数据权限上下文派生。
     */
    public function scope(array $adminInfo, OrganizationScopeService $organizations): array
    {
        $context = $this->context($adminInfo);
        if ($context === null) {
            return [
                'tree' => [],
                'allowed_store_ids' => [],
                'authorization_mode' => 'none',
                'permission_version' => '',
            ];
        }
        [, $scope, $scopeMeta] = $context;
        $allowedStoreIds = $scope->visibleStoreIds();
        if ($allowedStoreIds === null) {
            $allowedStoreIds = Db::name('system_store')
                ->where('is_del', 0)
                ->order('id', 'asc')
                ->column('id');
        }
        $allowedStoreIds = $this->storeIds($allowedStoreIds);
        return [
            'tree' => $organizations->buildPickerTree($allowedStoreIds),
            'allowed_store_ids' => $allowedStoreIds,
            'authorization_mode' => (string)($scopeMeta['mode'] ?? 'none'),
            'permission_version' => $scope->permissionVersion(),
        ];
    }

    /** @return array{readOnly:bool,scope:array,detail:array|null} */
    public function salesOrderDetail(array $adminInfo, array $payload): array
    {
        $context = $this->context($adminInfo);
        if ($context === null) {
            return ['readOnly' => true, 'scope' => ['mode' => 'none'], 'detail' => null];
        }
        [$operator, $scope, $scopeMeta] = $context;
        return [
            'readOnly' => true,
            'scope' => $scopeMeta,
            'detail' => $this->salesQueries->salesOrderDetail($payload, $operator, $scope),
        ];
    }

    /**
     * 与 cashier-v3 action gateway 对齐的只读 envelope。
     * 平台端不注册任何 command，前端即便篡改 action 也只能得到拒绝响应。
     */
    public function dispatchReadAction(array $adminInfo, array $body): array
    {
        $action = trim((string)($body['action'] ?? ''));
        $correlationId = trim((string)($body['correlationId'] ?? $body['correlation_id'] ?? ''));
        if ($correlationId === '') {
            $correlationId = 'CORR-' . str_replace('.', '', uniqid('', true));
        }
        $payload = $body;
        unset($payload['action'], $payload['command'], $payload['correlationId'], $payload['correlation_id']);
        if (!in_array($action, [
            'query-sales-orders',
            'query-order-center-records',
            'open-sales-order-detail',
            'query-unified-query-capabilities',
        ], true)) {
            return $this->failureEnvelope($action, $correlationId, 'PLATFORM_ORDER_CENTER_READ_ONLY', '平台订单中心仅支持只读查询。');
        }

        if ($action === 'query-sales-orders') {
            $result = $this->records($adminInfo, array_merge($payload, ['recordType' => 'sales']));
            $page = $result['page'];
            $data = ['orderCenter' => $this->salesPartition($page, $result)];
        } elseif ($action === 'query-order-center-records') {
            $result = $this->records($adminInfo, $payload);
            $data = ['orderCenter' => $this->recordPartition($result['page'], $result)];
        } elseif ($action === 'open-sales-order-detail') {
            $result = $this->salesOrderDetail($adminInfo, $payload);
            if ($result['detail'] === null) {
                return $this->failureEnvelope($action, $correlationId, 'RESOURCE_NOT_FOUND', '该销售订单不存在或当前账号无权查看。');
            }
            $data = ['orderCenter' => [
                'contractVersion' => CashierV3SalesOrderQueryServices::CONTRACT_VERSION,
                'salesOrderDetail' => $result['detail'],
                'readOnly' => true,
                'scope' => $result['scope'],
            ]];
        } else {
            // 平台订单中心不开放统一查询偏好、导出或任何设置写入。
            $data = ['unifiedQueryCapability' => [
                'readOnly' => true,
                'pages' => [],
                'actions' => [],
            ]];
        }
        return [
            'result' => ['status' => 'success', 'code' => '', 'message' => '查询完成。'],
            'data' => $data,
            'replay' => false,
            'stateContextId' => '',
            'contextChanged' => false,
            'boundAction' => $action,
            'boundCanonical' => $action,
            'correlationId' => $correlationId,
            'boundCorrelationId' => $correlationId,
        ];
    }

    /** @return array{0:CashierV3OperatorScope,1:CashierV3DataScopeContext,2:array}|null */
    private function context(array $adminInfo)
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        if ($adminId <= 0) {
            return null;
        }

        $where = [];
        $employeeId = $this->employeeDataScope->resolveEmployeeIdFromOperator($adminInfo, 'admin');
        $this->employeeDataScope->applyOrderListScope($where, $employeeId, 'admin', 0, $adminInfo);
        $resolved = (array)($where['employee_data_scope'] ?? ['mode' => 'none']);
        $mode = (string)($resolved['mode'] ?? 'none');
        if ($mode === 'all') {
            $visibleStoreIds = null;
            $authorizationMode = CashierV3DataScopeContext::MODE_ALL;
            $scopeMeta = ['mode' => 'all'];
        } elseif ($mode === 'stores') {
            $visibleStoreIds = $this->storeIds($resolved['store_ids'] ?? []);
            if ($visibleStoreIds === []) {
                return null;
            }
            $authorizationMode = CashierV3DataScopeContext::MODE_STORES;
            $scopeMeta = ['mode' => 'stores', 'store_ids' => $visibleStoreIds];
        } else {
            // personal/store_with_history requires participant and tenure predicates
            // that the V3 shared reader intentionally does not approximate. Hide
            // data rather than widen access until its canonical provider supports it.
            return null;
        }

        $anchorStoreId = $visibleStoreIds === null
            ? (int)Db::name('system_store')->where('is_del', 0)->order('id', 'asc')->value('id')
            : (int)$visibleStoreIds[0];
        if ($anchorStoreId <= 0) {
            return null;
        }

        $scopeMeta['source'] = 'employee_data_scope';
        $scopeMeta['read_only'] = true;
        $permissionVersion = 'platform-order-center:' . hash('sha256', json_encode([
            'admin_id' => $adminId,
            'employee_id' => $employeeId,
            'scope' => $scopeMeta,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $operator = new CashierV3OperatorScope($anchorStoreId, $adminId, '', CashierV3ScopeResolver::TENANT_SCOPE_ID);
        $scope = new CashierV3DataScopeContext(
            $adminId,
            $employeeId,
            $anchorStoreId,
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            '',
            $visibleStoreIds,
            $authorizationMode,
            $scopeMeta,
            $authorizationMode === CashierV3DataScopeContext::MODE_ALL,
            'EmployeeDataScopeServices::applyOrderListScope(admin)',
            $permissionVersion,
            ['cashier.v3.order_center'],
            ['id' => $adminId, 'name' => (string)($adminInfo['real_name'] ?? $adminInfo['account'] ?? '')],
            true
        );
        return [$operator, $scope, $scopeMeta];
    }

    private function storeIds($values): array
    {
        $ids = [];
        foreach ((array)$values as $value) {
            $id = (int)$value;
            if ($id > 0) $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function businessTypes(): array
    {
        return [
            ['key' => 'sales', 'label' => '消费订单', 'ready' => true],
            ['key' => 'recharge', 'label' => '充值订单', 'ready' => true],
            ['key' => 'refund', 'label' => '退款记录', 'ready' => true],
            ['key' => 'debt', 'label' => '欠款管理', 'ready' => true],
            ['key' => 'service', 'label' => '服务记录', 'ready' => true],
            ['key' => 'supplement', 'label' => '补交记录', 'ready' => true],
            ['key' => 'gift', 'label' => '赠送记录', 'ready' => true],
            ['key' => 'card_operation', 'label' => '卡操作记录', 'ready' => true],
        ];
    }

    private function emptyPage(array $payload): array
    {
        $type = trim((string)($payload['recordType'] ?? $payload['record_type'] ?? 'sales'));
        return [
            'contractVersion' => CashierV3OrderCenterRecordQueryServices::CONTRACT_VERSION,
            'recordType' => $type,
            'records' => [],
            'total' => 0,
            'page' => max(1, (int)($payload['page'] ?? 1)),
            'pageSize' => max(1, min(100, (int)($payload['pageSize'] ?? 20))),
            'dataStatus' => 'permission_filtered',
        ];
    }

    private function salesPartition(array $page, array $result): array
    {
        return [
            'contractVersion' => (string)($page['contractVersion'] ?? CashierV3SalesOrderQueryServices::CONTRACT_VERSION),
            'dataStatus' => (string)($page['dataStatus'] ?? 'permission_filtered'),
            'dataAsOf' => $page['dataAsOf'] ?? null,
            'businessTimezone' => $page['businessTimezone'] ?? CashierV3OrderCenterRecordQueryServices::BUSINESS_TIMEZONE,
            'economicsDataStatus' => $page['economicsDataStatus'] ?? 'not_ready',
            'paginationMode' => $page['paginationMode'] ?? 'offset',
            'paginationCursor' => $page['paginationCursor'] ?? null,
            'hasMore' => !empty($page['hasMore']),
            'freshnessPolicy' => $page['freshnessPolicy'] ?? [],
            'performancePolicy' => $page['performancePolicy'] ?? [],
            'statusOptions' => $page['statusOptions'] ?? [],
            'statusOptionsByType' => ['sales' => $page['statusOptions'] ?? []],
            'salesOrders' => $page['records'] ?? [],
            'recordsByType' => ['sales' => $page['records'] ?? []],
            'pagesByType' => ['sales' => [
                'total' => (int)($page['total'] ?? 0), 'page' => (int)($page['page'] ?? 1),
                'pageSize' => (int)($page['pageSize'] ?? 20),
                'dataStatus' => (string)($page['dataStatus'] ?? 'permission_filtered'),
                'paginationMode' => $page['paginationMode'] ?? 'offset',
                'paginationCursor' => $page['paginationCursor'] ?? null,
                'hasMore' => !empty($page['hasMore']),
            ]],
            'total' => (int)($page['total'] ?? 0), 'page' => (int)($page['page'] ?? 1),
            'pageSize' => (int)($page['pageSize'] ?? 20),
            'readOnly' => true, 'scope' => $result['scope'],
        ];
    }

    private function recordPartition(array $page, array $result): array
    {
        $type = (string)($page['recordType'] ?? '');
        return [
            'contractVersion' => (string)($page['contractVersion'] ?? CashierV3OrderCenterRecordQueryServices::CONTRACT_VERSION),
            'dataStatus' => (string)($page['dataStatus'] ?? 'permission_filtered'),
            'dataAsOf' => $page['dataAsOf'] ?? null,
            'businessTimezone' => $page['businessTimezone'] ?? CashierV3OrderCenterRecordQueryServices::BUSINESS_TIMEZONE,
            'recordsByType' => $type === '' ? [] : [$type => $page['records'] ?? []],
            'pagesByType' => $type === '' ? [] : [$type => [
                'total' => (int)($page['total'] ?? 0), 'page' => (int)($page['page'] ?? 1),
                'pageSize' => (int)($page['pageSize'] ?? 20),
                'dataStatus' => (string)($page['dataStatus'] ?? 'permission_filtered'),
                'paginationMode' => 'offset',
                'hasMore' => ((int)($page['page'] ?? 1) * (int)($page['pageSize'] ?? 20)) < (int)($page['total'] ?? 0),
            ]],
            'statusOptionsByType' => $type === '' ? [] : [$type => $page['statusOptions'] ?? []],
            'readOnly' => true, 'scope' => $result['scope'],
        ];
    }

    private function failureEnvelope(string $action, string $correlationId, string $code, string $message): array
    {
        return [
            'result' => ['status' => 'failed', 'code' => $code, 'message' => $message],
            'data' => [], 'replay' => false, 'stateContextId' => '', 'contextChanged' => false,
            'boundAction' => $action, 'boundCanonical' => $action,
            'correlationId' => $correlationId, 'boundCorrelationId' => $correlationId,
        ];
    }
}
