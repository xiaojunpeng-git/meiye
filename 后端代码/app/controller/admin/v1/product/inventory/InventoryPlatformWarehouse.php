<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformWarehouseCommandServices;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
use app\services\product\inventory\InventoryV3RolloutPolicy;
use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryContract;
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryContract;
use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\product\inventory\query\InventoryUnifiedQueryCommandServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use think\facade\App;

final class InventoryPlatformWarehouse extends AuthController
{
    public function __construct(App $app, InventoryPlatformWarehouseServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /** 历史查询可请求停用仓；返回目录不等于授予写权限。 */
    public function locations()
    {
        try {
            $access = $this->access();
            $locations = [];
            try {
                $resolved = $this->services->resolvedLocations((array)$this->adminInfo, (int)$this->request->get('include_inactive', 0) === 1);
                // 首页能力与仓库目录沿用同一次服务端身份解析，避免目录和指标各自推导出不同范围。
                $access = $resolved['access'];
                $locations = $resolved['locations'];
            } catch (\InvalidArgumentException $exception) {
                // 只有库存查询授权、没有原单据仓范围的账号仍可获得按员工数据权限裁剪的门店目录。
                (new InventoryPlatformAccessPolicy())->assertQueryPage($access, InventoryBatchStockQueryContract::PAGE_CODE);
            }
            $stockStoreLocations = [];
            try {
                $stockStoreLocations = array_values(array_filter(
                    $this->services->resolvedLocations((array)$this->adminInfo, false, true)['locations'],
                    static fn (array $location): bool => (string)$location['location_type'] === 'STORE'
                ));
            } catch (UnifiedQueryException | \InvalidArgumentException $exception) {
                // 非库存查询页面不获得库存查询专用门店目录；原页面权限仍由各自接口验证。
            }
            // 首页只消费独立的总部仓清单；其他单据页原有仓库目录与写能力保持各自授权。
            $canViewHqDashboard = (new InventoryPlatformAccessPolicy())->canReadHqDashboard($access);
            $dashboardHqLocations = $canViewHqDashboard ? array_values(array_filter(
                $locations,
                static fn (array $location): bool => (string)($location['location_type'] ?? '') === 'HQ'
                    && (string)($location['location_status'] ?? '') === 'ACTIVE'
            )) : [];
            return $this->success([
                'list' => $locations,
                'dashboard_hq_locations' => $dashboardHqLocations,
                'can_view_hq_dashboard' => $canViewHqDashboard,
                'stock_store_locations' => $stockStoreLocations,
                'permission_anchor' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
                'cost_visible' => in_array(InventoryBatchStockQueryContract::PERMISSION_COST, $access['features'], true),
                // 页面只消费可信写能力；读取库位不会自动开放新建、导入或作废。
                'can_manage_warehouses' => in_array('inventory.location.settings', $access['features'], true),
                'can_manage' => in_array('inventory.location.manage', $access['features'], true),
                // 调拨生成沿用可见页面，其余单据仍按各自新增节点返回能力。
                'can_create' => array_combine(['inbound', 'outbound', 'count', 'request', 'transfer'], array_map(
                    static fn (string $page): bool => $page === 'transfer'
                        ? (new InventoryPlatformAccessPolicy())->canGenerateTransfer($access)
                        : (new InventoryPlatformAccessPolicy())->canCreate($access, $page),
                    ['inbound', 'outbound', 'count', 'request', 'transfer']
                )),
            ]);
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('当前平台没有可用库存仓库。');
        }
    }

    /** 组织树仅暴露页面可读门店；组织节点的实际范围须由查询上下文重新解析。 */
    public function organizationScope()
    {
        try {
            $pageCode = trim((string)$this->request->get('pageCode', ''));
            $policy = new InventoryPlatformAccessPolicy();
            $access = $policy->forOperationalRead($this->access(), $pageCode);
            $storeIds = array_values(array_map('intval', (array)$access['store_ids']));
            return $this->success([
                'tree' => app()->make(OrganizationScopeService::class)->buildPickerTree($storeIds),
                'allowed_store_ids' => $storeIds,
                'permission_version' => (string)$access['permission_version'],
            ]);
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        }
    }

    /** 只接收已授权组织锚点建仓，不接受浏览器指定租户或组织所有权。 */
    public function createLocation(InventoryPlatformWarehouseCommandServices $services)
    {
        try {
            $input = $this->request->postMore([
                ['idempotency_key', ''], ['base_location_id', 0], ['location_name', ''],
            ]);
            return $this->success($services->create((array)$this->adminInfo, $input));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('仓库创建参数不合法。');
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    /** 分页、总数和筛选统一交由服务端按授权总部仓范围执行。 */
    public function settings()
    {
        try { return $this->success($this->services->settings((array)$this->adminInfo, (string)$this->request->get('keyword',''), (string)$this->request->get('status',''), (int)$this->request->get('page',1), (int)$this->request->get('limit',20))); }
        catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code'=>$exception->getErrorCode()]); }
    }

    /** 仓库设置独立检查管理权限及版本；业务添加节点不能获得启停/改名能力。 */
    public function updateLocation(int $id, InventoryPlatformWarehouseCommandServices $services)
    {
        try {
            return $this->success($services->update((array)$this->adminInfo, $id, $this->request->postMore([
                ['location_name', ''], ['location_status', ''], ['expected_version', 0],
            ])));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('请检查仓库名称和状态。'); }
        catch (\RuntimeException $exception) { return $this->fail($exception->getMessage()); }
    }

    public function batchStock()
    {
        try {
            $input = $this->request->getMore([['location_id', 0], ['query_cutoff_date', date('Y-m-d')]]);
            return $this->success($this->services->batchStock((array)$this->adminInfo, (int)$input['location_id'], (string)$input['query_cutoff_date']));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('平台库存查询参数不合法。');
        }
    }

    public function dashboard()
    {
        try {
            return $this->success($this->services->dashboard((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0)));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('当前平台没有可用库存仓库。');
        }
    }

    public function unifiedBatchStock()
    {
        try {
            $payload = $this->request->get(); $payload = is_array($payload) ? $payload : [];
            foreach (['tenant_id','store_id','organization_id','location_id','scope_dimensions','permissions'] as $key) if (array_key_exists($key, $payload)) throw new \InvalidArgumentException('inventory_platform_uq_scope_forbidden');
            foreach (['filters','topFilters','topFilterConditions','keywordFilters','sorts','groupBy','summaries','quickFilters','visibleFields','fieldVersions'] as $key) if (isset($payload[$key]) && is_string($payload[$key])) { $decoded = json_decode($payload[$key], true); if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('inventory_platform_uq_query_invalid'); $payload[$key] = $decoded; }
            $runtime = UnifiedQueryRuntime::runtime();
            $selectedStoreId = $this->selectedStoreId($payload);
            $subject = $this->selectedSubject($payload);
            $hqLocationId = $this->selectedHqLocationId($payload);
            unset($payload['storeId'], $payload['subject'], $payload['hq_location_id']);
            $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, (string)($payload['queryCutoffDate'] ?? date('Y-m-d')), $selectedStoreId, $subject, InventoryBatchStockQueryContract::PAGE_CODE, $hqLocationId);
            $payload['pageCode'] = InventoryBatchStockQueryContract::PAGE_CODE;
            return $this->success($runtime['providers']->resolve(InventoryBatchStockQueryContract::PAGE_CODE)->query($context, $payload));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('平台库存查询条件不合法。'); }
    }

    /** Executes platform inventory document/statistics queries in a server-resolved scope. */
    public function unifiedOperational()
    {
        try {
            $payload = $this->request->get();
            $payload = is_array($payload) ? $payload : [];
            foreach (['tenant_id','store_id','organization_id','location_id','scope_dimensions','permissions','granted_features'] as $key) {
                if (array_key_exists($key, $payload)) throw new \InvalidArgumentException('inventory_platform_uq_scope_forbidden');
            }
            $pageCode = trim((string)($payload['pageCode'] ?? ''));
            if (!in_array($pageCode, array_merge(InventoryOperationalUnifiedQueryContract::PAGE_CODES, InventoryStatisticsUnifiedQueryContract::PAGE_CODES), true)) {
                throw new \InvalidArgumentException('inventory_platform_operational_page_invalid');
            }
            foreach (['filters','topFilters','topFilterConditions','keywordFilters','sorts','groupBy','summaries','quickFilters','visibleFields','fieldVersions'] as $key) {
                if (!isset($payload[$key]) || !is_string($payload[$key])) continue;
                $decoded = json_decode($payload[$key], true);
                if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('inventory_platform_uq_query_invalid');
                $payload[$key] = $decoded;
            }
            $runtime = UnifiedQueryRuntime::runtime();
            $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make(
                (int)$this->adminId,
                (array)$this->adminInfo,
                (string)($payload['queryCutoffDate'] ?? date('Y-m-d')),
                $this->selectedStoreId($payload),
                $this->selectedSubject($payload),
                $pageCode,
                $this->selectedHqLocationId($payload),
                $this->selectedOrganizationId($payload)
            );
            unset($payload['storeId'], $payload['subject'], $payload['hq_location_id'], $payload['organizationId']);
            return $this->success($runtime['providers']->resolve($pageCode)->query($context, $payload));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('平台库存业务查询条件不合法。');
        }
    }

    public function unifiedCapabilities()
    {
        try {
            $raw = $this->request->get(); $raw = is_array($raw) ? $raw : [];
            $runtime = UnifiedQueryRuntime::runtime(); $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, date('Y-m-d'), $this->selectedStoreId($raw), $this->selectedSubject($raw), InventoryBatchStockQueryContract::PAGE_CODE, $this->selectedHqLocationId($raw));
            $settings = $runtime['preferences']->load($context, InventoryBatchStockQueryContract::PAGE_CODE);
            $capability = $runtime['capabilities']->build($context, InventoryBatchStockQueryContract::PAGE_CODE, (int)$settings['settingsVersion'], (int)$context['data_as_of']);
            $capability['commandContext'] = ['kind' => 'query_preference', 'id' => InventoryBatchStockQueryContract::PAGE_CODE];
            return $this->success(['unifiedQueryCapability' => $capability]);
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('平台库存仓库范围不合法。'); }
    }

    public function unifiedCommand(InventoryUnifiedQueryCommandServices $services)
    {
        try {
            $input = $this->request->post(); $input = is_array($input) ? $input : [];
            $access = $this->access();
            $feature = (string)($input['action'] ?? '') === 'create-unified-query-export'
                ? InventoryBatchStockQueryContract::PERMISSION_EXPORT
                : 'inventory.batch.query.manage';
            (new InventoryPlatformAccessPolicy())->assertFeature($access, $feature,
                $feature === InventoryBatchStockQueryContract::PERMISSION_EXPORT
                    ? '当前岗位未配置“平台库存导出”权限。'
                    : '当前岗位未配置“平台库存查询管理”权限。');
            $runtime = UnifiedQueryRuntime::runtime();
            $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, date('Y-m-d'), $this->selectedStoreId($input), $this->selectedSubject($input), (string)($input['pageCode'] ?? $input['payload']['pageCode'] ?? InventoryBatchStockQueryContract::PAGE_CODE), $this->selectedHqLocationId($input));
            unset($input['storeId'], $input['subject'], $input['hq_location_id']);
            return $this->success($services->dispatchWithContext($context, $input));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('平台库存统一查询操作参数不合法。'); }
    }

    public function unifiedExportTask(string $taskNo)
    {
        try {
            (new InventoryPlatformAccessPolicy())->assertFeature($this->access(), InventoryBatchStockQueryContract::PERMISSION_EXPORT,
                '当前岗位未配置“平台库存导出”权限。');
            $runtime = UnifiedQueryRuntime::runtime();
            $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, date('Y-m-d'));
            $context['page_code'] = InventoryBatchStockQueryContract::PAGE_CODE;
            return $this->success(['exportTask' => $runtime['exports']->findForAccount($context, $taskNo)]);
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
    }

    private function selectedStoreId(array $input): int
    {
        foreach (['tenant_id','store_id','organization_id','location_id','scope_dimensions','permissions'] as $key) {
            if (array_key_exists($key, $input)) throw new \InvalidArgumentException('inventory_platform_uq_scope_forbidden');
        }
        if (!array_key_exists('storeId', $input)) return 0;
        $value = $input['storeId'];
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) {
            throw new \InvalidArgumentException('inventory_platform_uq_store_invalid');
        }
        return (int)$value;
    }

    /** Only this operational read endpoint accepts a typed organization choice, never raw store IDs. */
    private function selectedOrganizationId(array $input): ?int
    {
        if (!array_key_exists('organizationId', $input)) return null;
        $value = $input['organizationId'];
        if ((!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) || (int)$value < 0) {
            throw new \InvalidArgumentException('inventory_platform_uq_organization_invalid');
        }
        return (int)$value;
    }

    private function selectedSubject(array $input): string
    {
        if (!array_key_exists('subject', $input)) return 'HQ';
        $subject = strtoupper(trim((string)$input['subject']));
        if (!in_array($subject, ['HQ', 'STORE'], true)) throw new \InvalidArgumentException('inventory_platform_uq_subject_invalid');
        return $subject;
    }

    private function selectedHqLocationId(array $input): int
    {
        if (!array_key_exists('hq_location_id', $input)) return 0;
        $value = $input['hq_location_id'];
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) {
            throw new \InvalidArgumentException('inventory_platform_uq_hq_location_invalid');
        }
        return (int)$value;
    }

    private function access(): array
    {
        return (new InventoryPlatformAccessPolicy())->resolve((array)$this->adminInfo);
    }
}
