<?php

namespace app\services\order;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\cashier\v3\projection\CashierV3DisplayPolicyServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use think\facade\Db;

/**
 * 平台订单中心身份与组织范围适配。
 *
 * 复用 cashier-v3 全部共用查询与业务操作；仅接受管理员令牌，数据范围由 EmployeeDataScopeServices 推导。
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
                'readOnly' => false,
                'scope' => ['mode' => 'none'],
                'businessTypes' => $this->businessTypes(),
            ];
        }
        [, , $scopeMeta] = $context;
        return [
            'readOnly' => false,
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
                'readOnly' => false,
                'scope' => ['mode' => 'none'],
                'page' => $this->emptyPage($payload),
            ];
        }
        [$operator, $scope, $scopeMeta] = $context;
        $type = trim((string)($payload['recordType'] ?? $payload['record_type'] ?? 'sales'));
        if (isset(CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE[$type])) {
            // 平台与门店端共用同一订单查询执行器：授权门店候选、筛选、
            // 排序、分页和导出必须保持同一口径，不得因平台入口改走旧 Reader。
            $runtime = UnifiedQueryModule::runtime();
            $payload['pageCode'] = CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE[$type];
            $queryContext = $runtime['contextFactory']->make($operator, $scope, $payload);
            $provider = clone $runtime['providers']->resolve($payload['pageCode']);
            $page = $provider->queryPage($queryContext, $payload, $operator, $scope);
        } else {
            // 未知类型仍返回无数据权限页，不将非白名单输入交给统一查询注册表。
            $payload['recordType'] = $type;
            $page = $this->recordQueries->queryRecords($payload, $operator, $scope);
        }
        return ['readOnly' => false, 'scope' => $scopeMeta, 'page' => $page];
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
            return ['readOnly' => false, 'scope' => ['mode' => 'none'], 'detail' => null];
        }
        [$operator, $scope, $scopeMeta] = $context;
        return [
            'readOnly' => false,
            'scope' => $scopeMeta,
            'detail' => $this->salesQueries->salesOrderDetail($payload, $operator, $scope),
        ];
    }

    /** 平台仅适配已登录管理员身份，全部查询、导出与订单业务动作进入同一 gateway。 */
    public function dispatchReadAction(array $adminInfo, array $body): array
    {
        return app()->make(PlatformOrderCenterActionServices::class)->dispatch((int)($adminInfo['id'] ?? 0), $body);
    }

    /** 平台与门店共用功能不另设操作权限；真实管理员身份和当前数据范围仍须有效。 */
    public function exportScopes(int $adminId, string $pageCode): array
    {
        if (!in_array($pageCode, CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE, true)) {
            throw new \app\services\query\UnifiedQueryException('UNIFIED_QUERY_PAGE_CONFLICT', '订单页面无效。', []);
        }
        [$operator, $scope] = $this->trustedScopes($adminId);
        $context = UnifiedQueryModule::runtime()['contextFactory']->make($operator, $scope, ['pageCode' => $pageCode]);
        return [$operator, $scope, $context];
    }

    /** 只由已验证的 admin 会话、共用 gateway 和 worker 调用，不接收浏览器权限字段。 */
    public function trustedScopes(int $adminId, int $storeId = 0, bool $lock = false): array
    {
        $query = Db::name('system_admin')->where('id', $adminId)->where('status', 1)->where('is_del', 0);
        if ($lock) $query->lock(true);
        $admin = $query->find();
        $scopes = $admin ? $this->context($admin, $storeId) : null;
        if ($scopes === null) {
            throw new \app\services\query\UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '当前账号或数据范围已失效，请重新登录。', []);
        }
        return $scopes;
    }

    /** @return array{0:CashierV3OperatorScope,1:CashierV3DataScopeContext,2:array}|null */
    private function context(array $adminInfo, int $targetStoreId = 0)
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

        if ($targetStoreId > 0) {
            if ($visibleStoreIds !== null && !in_array($targetStoreId, $visibleStoreIds, true)) return null;
            if (!Db::name('system_store')->where('id', $targetStoreId)->where('is_del', 0)->value('id')) return null;
            $anchorStoreId = $targetStoreId;
        }
        $scopeMeta['source'] = 'employee_data_scope';
        $scopeMeta['read_only'] = false;
        $permissionVersion = 'platform-order-center:' . hash('sha256', json_encode([
            'admin_id' => $adminId,
            'employee_id' => $employeeId,
            'scope' => $scopeMeta,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $operator = (new CashierV3ScopeResolver())->operatorScope($anchorStoreId, $adminId);
        $scope = new CashierV3DataScopeContext(
            $adminId,
            $employeeId,
            $anchorStoreId,
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $operator->organizationId(),
            $visibleStoreIds,
            $authorizationMode,
            $scopeMeta,
            $authorizationMode === CashierV3DataScopeContext::MODE_ALL,
            'EmployeeDataScopeServices::applyOrderListScope(admin)',
            $permissionVersion,
            \app\services\cashier\v3\permission\CashierV3FeatureResolver::FEATURE_CODES,
            $adminInfo + ['_trusted_platform_order_center_admin' => $adminId, 'employee_id' => $employeeId, 'name' => (string)($adminInfo['real_name'] ?? $adminInfo['account'] ?? '')],
            false
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
        return CashierV3OrderCenterRecordQueryServices::businessTypes();
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
            'uiPolicy' => (new CashierV3DisplayPolicyServices())->snapshot(),
            'businessTypes' => $this->businessTypes(),
            'readOnly' => false, 'scope' => $result['scope'],
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
            'uiPolicy' => (new CashierV3DisplayPolicyServices())->snapshot(),
            'businessTypes' => $this->businessTypes(),
            'readOnly' => false, 'scope' => $result['scope'],
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
