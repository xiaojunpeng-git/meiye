<?php
namespace app\services\cashier\v3\projection;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
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

        $workspaceId = sprintf('ws:%d:%d:%s', $storeId, $operatorId, $stateContextId);
        $workspaceRevision = $this->ensureWorkspaceVersion($workspaceId, $operatorScope);

        $features = [];
        foreach ($dataScope->grantedFeatures() as $code) {
            $features[$code] = true;
        }

        $extras = [
            'storeName' => (string)$store['name'],
            'currentStore' => [
                'id' => $storeId,
                'name' => (string)$store['name'],
            ],
            'featurePermissions' => $features ?: new \stdClass(),
            'workspace' => [
                'id' => $workspaceId,
                'revision' => $workspaceRevision,
                'status' => 'editing',
                'serverTime' => date('c'),
            ],
            'operator' => [
                'name' => $operatorName,
                'roleName' => (string)($dataScope->operatorProfile()['role_name'] ?? ''),
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

        $extras['_public_versions'] = $publicVersions;
        return $extras;
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

    /**
     * 空字符串不是有效展示值，不能用 ?? 阻断 staff_name/name 的权威回退。
     */
    protected function operatorDisplayName(array $profile): string
    {
        foreach (['account', 'staff_name', 'name'] as $field) {
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
