<?php
namespace app\services\cashier\v3\projection;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use think\facade\Db;

/**
 * 组合式根分区组装器。
 *
 * C1 仅提供：门店名、操作员档案、workspace 正版本、featurePermissions。
 * 业务分区（收银／服务／核销／房间／预约／会员／挂单／订单中心等）必须由
 * 已注册的 CashierV3RootPartitionProvider 在同一快照内提供。
 * 任一必需分区未就绪 → isReadyForFullRoot()=false → 不得返回完整 state。
 */
class CashierV3RootDomainAssembler
{
    /** 完整根必需业务分区（C2～C5 负责）；C1 未安装前不得标记就绪 */
    public const REQUIRED_BUSINESS_PARTITIONS = [
        'cashier',
        'serviceCompletion',
        'writeoff',
        'room',
        'reservation',
        'memberCenter',
        'memberSelector',
        'queryEntitySelector',
        'managementCenter',
        'businessDashboard',
        'hangOrders',
        'orderCenter',
        'pendingHangCount',
    ];

    /** @var CashierV3ResourceVersionServices|null */
    protected $versionServices;

    /** @var array<string,CashierV3RootPartitionProvider> */
    protected $partitionProviders = [];

    /** @var bool */
    protected $frozen = false;

    public function __construct(CashierV3ResourceVersionServices $versionServices = null)
    {
        $this->versionServices = $versionServices;
    }

    public function setVersionServices(CashierV3ResourceVersionServices $versionServices): void
    {
        if ($this->frozen) {
            throw new \LogicException('CashierV3RootDomainAssembler 已 freeze');
        }
        $this->versionServices = $versionServices;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function registerPartitionProvider(CashierV3RootPartitionProvider $provider): void
    {
        if ($this->frozen) {
            throw new \LogicException('CashierV3RootDomainAssembler 已 freeze');
        }
        $key = $provider->partitionKey();
        if ($key === '' || isset($this->partitionProviders[$key])) {
            throw new \LogicException('root partition provider 无效或重复: ' . $key);
        }
        $this->partitionProviders[$key] = $provider;
    }

    /** @return string[] */
    public function registeredPartitionKeys(): array
    {
        return array_keys($this->partitionProviders);
    }

    /**
     * C2～C5 未通过 installer 注册全部必需分区前，空壳不得标记就绪。
     */
    public function isReadyForFullRoot(): bool
    {
        if ($this->versionServices === null) {
            return false;
        }
        foreach (self::REQUIRED_BUSINESS_PARTITIONS as $key) {
            if (!isset($this->partitionProviders[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array 供 emptyRoot extras 合并的权威字段（含 _public_versions）
     * @throws \RuntimeException 权威数据不可用或分区未就绪
     */
    public function assemble(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        if (!$this->isReadyForFullRoot()) {
            throw new \RuntimeException('root_assembler_partitions_not_ready');
        }

        $storeId = $operatorScope->storeId();
        $operatorId = $operatorScope->operatorId();

        $store = $this->loadStore($storeId);
        if ($store === null) {
            throw new \RuntimeException('root_assembler_store_unavailable');
        }

        $operatorName = $this->operatorDisplayName($dataScope->operatorProfile());
        if ($operatorName === '') {
            // 禁止伪造「操作员」展示名；档案缺失即权威不可用
            throw new \RuntimeException('root_assembler_operator_name_unavailable');
        }

        $workspaceId = CashierV3CheckoutWorkspaceIdentity::id($storeId, $stateContextId);
        $workspaceRevision = $this->ensureWorkspaceVersion($workspaceId, $operatorScope);

        $features = [];
        foreach ($dataScope->grantedFeatures() as $code) {
            $features[$code] = true;
        }
        $operationFeatures = [];
        foreach ($this->resolveOperationFeatures($dataScope) as $code) {
            $operationFeatures[$code] = true;
        }

        $extras = [
            'storeName' => (string)$store['name'],
            'currentStore' => [
                'id' => $storeId,
                'name' => (string)$store['name'],
            ],
            // 菜单展示和岗位目录读取同一份配置，客户端只消费名称/排序，不接收可执行路由。
            'navigationRules' => (new \app\services\organization\StorePermissionCatalogServices())->snapshot()['rules'],
            // 实例级展示开关随每次权威根投影下发；缺少配置时保持原有入口可见。
            'storeDisplayOptions' => [
                // Same policy as the display snapshot; no separate checkout configuration.
                'projectAutoComplete' => \app\services\cashier\v3\settlement\CashierV3ProjectPurchasePolicy::enabled(),
                // 缺省为开，仅下发显示策略；人员快照里的售前标记仍按原业务事实保留。
                'preSaleEnabled' => (int)sys_config('cashier_presale_switch', 1) === 1,
                'salesAttributionEnabled' => (int)sys_config('cashier_sales_attribution_switch', 1) === 1,
                'experienceEnabled' => (int)sys_config('cashier_experience_switch', 1) === 1,
                'craftsmanExcludeCountsEnabled' => (int)sys_config('cashier_craftsman_exclude_counts_switch', 1) === 1,
                // 计算法仅是显隐选项名称；缺失定义/null 均保持现有金额与入口。
                'laborFeeVisible' => (int)sys_config('cashier_labor_fee_mode', 0) !== 1,
                // 只下发选择器默认范围，历史会员事实与可见权限继续由查询服务保护。
                'memberDefaultAll' => (int)sys_config('cashier_member_default_all_switch', 0) === 1,
            ],
            'featurePermissions' => $features ?: new \stdClass(),
            'operationPermissions' => $operationFeatures ?: new \stdClass(),
            'readOnly' => $dataScope->isReadOnlySession(),
            'sessionMode' => $dataScope->isReadOnlySession() ? 'store_read_only' : 'store_staff',
            'workspace' => [
                'id' => $workspaceId,
                'revision' => $workspaceRevision,
                'status' => 'editing',
                'serverTime' => date('c'),
            ],
            'operator' => [
                'name' => $operatorName,
                'account' => (string)($dataScope->operatorProfile()['account'] ?? ''),
                'roleName' => (string)($dataScope->operatorProfile()['role_name'] ?? ''),
                'profile' => $this->operatorDetails($dataScope->operatorProfile()),
            ],
        ];

        $publicVersions = [[
            'kind' => 'cashier_workspace',
            'id' => $workspaceId,
            'version' => $workspaceRevision,
        ]];

        foreach (self::REQUIRED_BUSINESS_PARTITIONS as $key) {
            $provider = $this->partitionProviders[$key];
            $part = $provider->readPartition(
                $stateContextId,
                $stateRevision,
                $operatorScope,
                $dataScope,
                $hints
            );
            if (empty($part['ready'])) {
                throw new \RuntimeException('root_partition_not_ready:' . $key);
            }
            if ($key === 'pendingHangCount') {
                if (!array_key_exists('payload', $part) || !is_int($part['payload'])) {
                    throw new \RuntimeException('root_partition_invalid:pendingHangCount');
                }
                $extras['pendingHangCount'] = $part['payload'];
            } else {
                if (!isset($part['payload']) || !is_array($part['payload'])) {
                    throw new \RuntimeException('root_partition_invalid:' . $key);
                }
                $extras[$key] = $part['payload'];
            }
            if (!empty($part['public_versions']) && is_array($part['public_versions'])) {
                foreach ($part['public_versions'] as $row) {
                    $publicVersions[] = $row;
                }
            }
        }

        // Source UI and initial choices belong to this authenticated root, not
        // browser-wide remembered state. A broken fixed default blocks only
        // its new transaction, never the whole login or historical order list.
        $sourcePolicy = new \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices();
        $extras['businessSourcePolicy'] = $sourcePolicy->read();
        $member = (array)($extras['cashier']['member'] ?? []);
        $memberId = (int)($member['memberId'] ?? $member['id'] ?? 0);
        $extras['businessSourceDefaultsMemberId'] = $memberId;
        $extras['businessSourceDefaults'] = $sourcePolicy->initialChoices($dataScope->tenantId(), $storeId, $memberId);
        $extras['_public_versions'] = $publicVersions;
        return $extras;
    }

    /** @return string[] */
    private function resolveOperationFeatures(CashierV3DataScopeContext $dataScope): array
    {
        if ($dataScope->isReadOnlySession()) {
            return [];
        }
        try {
            $features = app()->make(\app\services\cashier\v3\permission\CashierV3FeatureResolver::class)
                ->resolveGrantedFeatures($dataScope->operatorProfile());
            $allowed = array_fill_keys(\app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices::operationFeatureCodes(), true);
            return array_values(array_filter($features, static function ($feature) use ($allowed) {
                return isset($allowed[(string)$feature]);
            }));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{id:int,name:string}|null
     */
    protected function loadStore(int $storeId)
    {
        if ($storeId <= 0) {
            return null;
        }
        try {
            $row = Db::name('system_store')->where('id', $storeId)->field('id,name')->find();
            if (!$row || trim((string)($row['name'] ?? '')) === '') {
                return null;
            }
            return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 只读取已认证操作员关联员工的本人资料和有效任职；不接受前端指定员工，不影响权限或结账身份。 */
    protected function operatorDetails(array $profile): array
    {
        $employeeId=(int)($profile['employee_id'] ?? 0);
        $employee=$employeeId>0 ? Db::name('employee')->where('id',$employeeId)->where('is_del',0)->where('status',1)->field('name,phone')->find() : null;
        $staffId=(int)($profile['id'] ?? $profile['staff_id'] ?? 0);
        // 组织选店会话的编号不是门店员工编号，必须使用该员工的直属任职，不得碰撞其他员工。
        if (!empty($profile['_cashier_v3_delegated']) || !empty($profile['_cashier_v3_organization'])
            || \app\services\cashier\v3\CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)) $staffId=0;
        $positions=[];
        if ($employeeId>0) {
            $jobs=app()->make(\app\services\organization\StaffJobPositionServices::class)->listActiveJobs($staffId,$employeeId);
            foreach ($jobs as $job) {
                if ((int)$job['employee_id']!==$employeeId || (int)$job['position_status']!==1) continue;
                $name=trim((string)$job['position_name']);
                if ($name!=='') $positions[$name]=true;
            }
        }
        return ['name'=>trim((string)($employee['name'] ?? '')) ?: $this->operatorDisplayName($profile),
            'phone'=>trim((string)($employee['phone'] ?? $profile['phone'] ?? '')),
            'positions'=>array_keys($positions)];
    }

    /**
     * 空字符串不是有效展示值，不能用 ?? 阻断 staff_name/name 的权威回退。
     */
    protected function operatorDisplayName(array $profile): string
    {
        // 收银侧账号区展示员工姓名；登录账号只作为姓名缺失时的兜底，
        // 避免把内部账号编号当成当前操作员工展示。
        foreach (['staff_name', 'name', 'account'] as $field) {
            $value = trim((string)($profile[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    protected function ensureWorkspaceVersion(string $workspaceId, CashierV3OperatorScope $operatorScope): int
    {
        if ($this->versionServices === null) {
            throw new \RuntimeException('root_assembler_version_services_missing');
        }
        $scope = CashierV3ResourceScope::of(
            CashierV3ResourceScope::TYPE_STORE,
            (string)$operatorScope->storeId()
        );
        return $this->versionServices->ensureRegistered($scope, 'cashier_workspace', $workspaceId);
    }
}
