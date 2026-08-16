<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * Shared organization-dimension projection for the platform report catalogue.
 * Statistic dimensions are maintained on organization nodes. They describe the
 * current reporting hierarchy, so the report does not use their save date as a
 * transaction-date gate.
 */
final class StoreUnifiedReportOrganizationDimensionServices
{
    private const TYPES = ['company', 'city_manager'];

    /** @var StoreUnifiedReportPhaseThreeFoundationServices */
    private $foundation;

    /** @var array<string,array{company:array<string,string>,city_manager:array<string,string>}> */
    private $resolved = [];

    /** @var array<int,array<int,string>>|null */
    private $storePaths = null;

    public function __construct(?StoreUnifiedReportPhaseThreeFoundationServices $foundation = null)
    {
        $this->foundation = $foundation ?: new StoreUnifiedReportPhaseThreeFoundationServices();
    }

    /**
     * Decorate an unaggregated fact row. Internal keys are deliberately kept
     * out of report columns so they can also be used to construct exact drilldowns.
     */
    public function project(array &$row, string $organizationId, string $organizationPath, string $businessDate): void
    {
        $storeId = (int)($row['store_id'] ?? 0);
        foreach (self::TYPES as $type) {
            $dimension = $this->resolve($type, $organizationId, $organizationPath, $businessDate, $storeId);
            $prefix = $type === 'company' ? 'company' : 'city_manager';
            $row[$prefix . '_dimension_id'] = $dimension['id'];
            $row[$prefix] = $dimension['name'];
        }
    }

    /** @return array{id:string,name:string} */
    public function resolve(string $type, string $organizationId, string $organizationPath, string $businessDate, int $storeId = 0): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('组织统计维度类型无效');
        }
        $date = $this->date($businessDate);
        $factPath = $this->path($organizationPath, $organizationId);
        $storePath = $this->storePath($storeId);
        $path = $storePath !== [] ? $storePath : $factPath;
        $cacheKey = implode('|', [$type, $storeId, implode('/', $path), $date]);
        if (isset($this->resolved[$cacheKey])) return $this->resolved[$cacheKey];

        $match = $path === [] ? null : $this->resolveCurrentDimension($type, $path);
        return $this->resolved[$cacheKey] = [
            'id' => (string)($match['organization_id'] ?? ''),
            'name' => $match ? (string)$match['organization_name_snapshot'] : $this->missingName($type),
        ];
    }

    /**
     * Applies selected organization dimensions before aggregation. A selection
     * narrows by the reporting store's current ancestor hierarchy, matching the
     * names shown in the rows.
     */
    public function applyFilters($query, string $alias, array $input, array $range): void
    {
        foreach (['company' => 'company_dimension_id', 'city_manager' => 'city_manager_dimension_id'] as $type => $inputKey) {
            $dimensionId = trim((string)($input[$inputKey] ?? ''));
            if ($dimensionId === '') continue;
            $versions = Db::name(StoreUnifiedReportPhaseThreeFoundationServices::ORGANIZATION_DIMENSION_TABLE)
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('dimension_code', $type)->where('organization_id', $dimensionId)->where('enabled', 1)
                ->field('organization_id')->select()->toArray();
            if ($versions === []) {
                // A non-existent dimension is a valid narrowing filter which
                // intentionally returns no facts instead of widening scope.
                $query->whereRaw('1=0');
                continue;
            }
            $storeIds = $this->storesForOrganizationIds(array_column($versions, 'organization_id'));
            $query->whereIn($alias . '.store_id', $storeIds);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function filterSchema(array $range): array
    {
        $schema = [];
        foreach (['company' => ['company_dimension_id', '分公司'], 'city_manager' => ['city_manager_dimension_id', '城市经理']] as $type => $definition) {
            $rows = Db::name(StoreUnifiedReportPhaseThreeFoundationServices::ORGANIZATION_DIMENSION_TABLE)
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('dimension_code', $type)->where('enabled', 1)
                ->order('display_order', 'asc')->order('id', 'asc')
                ->field('organization_id,organization_name_snapshot')->select()->toArray();
            $options = [];
            foreach ($rows as $row) {
                $id = (string)$row['organization_id'];
                if ($id === '' || isset($options[$id])) continue;
                $options[$id] = ['value' => $id, 'label' => (string)$row['organization_name_snapshot']];
            }
            $schema[] = [
                'key' => $definition[0], 'label' => $definition[1], 'type' => 'select',
                'placeholder' => '全部', 'options' => array_values($options),
                'source_explanation' => '按业务事实发生日期匹配已配置的' . $definition[1] . '统计维度；仅缩小当前账号的数据权限范围。',
            ];
        }
        return $schema;
    }

    public function sourceExplanation(string $type): string
    {
        return $type === 'company'
            ? '从业务门店所属组织向上查找当前配置为分公司的统计维度，显示匹配组织名称；统计维度保存日期不影响历史交易显示；未配置时显示“未配置分公司”。'
            : '从业务门店所属组织向上查找当前配置为城市经理的统计维度，显示匹配组织名称；统计维度保存日期不影响历史交易显示；未配置时显示“未配置城市经理”。';
    }

    private function missingName(string $type): string
    {
        return $type === 'company' ? '未配置分公司' : '未配置城市经理';
    }

    /** @return array<int,string> */
    private function path(string $organizationPath, string $organizationId): array
    {
        $path = array_values(array_filter(explode('/', trim($organizationPath, '/')), static function (string $id): bool {
            return preg_match('/^\d+$/D', $id) === 1;
        }));
        if ($path === [] && preg_match('/^\d+$/D', $organizationId) === 1) $path[] = $organizationId;
        return array_values(array_unique($path));
    }

    /** @return array<int,string> */
    private function storePath(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $this->loadStorePaths();
        return $this->storePaths[$storeId] ?? [];
    }

    /** @param array<int,string> $organizationIds @return array<int,int> */
    private function storesForOrganizationIds(array $organizationIds): array
    {
        $wanted = array_fill_keys(array_filter(array_map('strval', $organizationIds)), true);
        if ($wanted === []) {
            return [];
        }
        $this->loadStorePaths();
        $storeIds = [];
        foreach ($this->storePaths as $storeId => $path) {
            foreach ($path as $organizationId) {
                if (isset($wanted[$organizationId])) {
                    $storeIds[] = (int)$storeId;
                    break;
                }
            }
        }
        return $storeIds;
    }

    private function loadStorePaths(): void
    {
        if ($this->storePaths !== null) {
            return;
        }
        $nodes = [];
        foreach (Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray() as $row) {
            $nodes[(int)$row['id']] = (int)$row['pid'];
        }
        $paths = [];
        foreach (Db::name('organization_store')->field('store_id,org_id')->select()->toArray() as $binding) {
            $storeId = (int)$binding['store_id'];
            $organizationId = (int)$binding['org_id'];
            if ($storeId <= 0 || $organizationId <= 0 || !isset($nodes[$organizationId])) {
                continue;
            }
            $path = [];
            $seen = [];
            for ($depth = 0; $depth < 64 && $organizationId > 0 && isset($nodes[$organizationId]); $depth++) {
                if (isset($seen[$organizationId])) {
                    $path = [];
                    break;
                }
                $seen[$organizationId] = true;
                $path[] = (string)$organizationId;
                $organizationId = $nodes[$organizationId];
            }
            if ($path !== []) {
                $paths[$storeId] = array_reverse($path);
            }
        }
        $this->storePaths = $paths;
    }

    /** @param array<int,string> $path @return array<string,mixed>|null */
    private function resolveCurrentDimension(string $type, array $path): ?array
    {
        $byOrganization = [];
        foreach (Db::name(StoreUnifiedReportPhaseThreeFoundationServices::ORGANIZATION_DIMENSION_TABLE)
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('dimension_code', $type)->where('enabled', 1)
            ->order('valid_from', 'desc')->order('id', 'desc')->select()->toArray() as $row) {
            $organizationId = (string)$row['organization_id'];
            if ($organizationId !== '' && !isset($byOrganization[$organizationId])) {
                $byOrganization[$organizationId] = $row;
            }
        }
        foreach (array_reverse($path) as $organizationId) {
            if (isset($byOrganization[$organizationId])) return $byOrganization[$organizationId];
        }
        return null;
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('报表业务日期不正确，无法匹配组织统计维度');
        }
        return $date;
    }
}
