<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformWarehouseCommandServices;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
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

    public function locations()
    {
        try {
            $access = $this->access();
            return $this->success([
                'list' => $this->services->locations((array)$this->adminInfo),
                'permission_anchor' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
                'cost_visible' => in_array(InventoryBatchStockQueryContract::PERMISSION_COST, $access['features'], true),
            ]);
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('当前平台没有可用库存仓库。');
        }
    }

    public function createLocation(InventoryPlatformWarehouseCommandServices $services)
    {
        try {
            $input = $this->request->postMore([
                ['idempotency_key', ''], ['store_id', 0], ['location_name', ''],
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

    public function unifiedBatchStock()
    {
        try {
            $payload = $this->request->get(); $payload = is_array($payload) ? $payload : [];
            foreach (['tenant_id','store_id','organization_id','location_id','scope_dimensions','permissions'] as $key) if (array_key_exists($key, $payload)) throw new \InvalidArgumentException('inventory_platform_uq_scope_forbidden');
            foreach (['filters','topFilters','topFilterConditions','keywordFilters','sorts','groupBy','summaries','quickFilters','visibleFields','fieldVersions'] as $key) if (isset($payload[$key]) && is_string($payload[$key])) { $decoded = json_decode($payload[$key], true); if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('inventory_platform_uq_query_invalid'); $payload[$key] = $decoded; }
            $runtime = UnifiedQueryRuntime::runtime();
            $selectedLocationId = $this->selectedLocationId($payload);
            unset($payload['warehouseId']);
            $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, (string)($payload['queryCutoffDate'] ?? date('Y-m-d')), $selectedLocationId);
            $payload['pageCode'] = InventoryBatchStockQueryContract::PAGE_CODE;
            return $this->success($runtime['providers']->resolve(InventoryBatchStockQueryContract::PAGE_CODE)->query($context, $payload));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('平台库存查询条件不合法。'); }
    }

    public function unifiedCapabilities()
    {
        try {
            $raw = $this->request->get(); $raw = is_array($raw) ? $raw : [];
            $runtime = UnifiedQueryRuntime::runtime(); $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, date('Y-m-d'), $this->selectedLocationId($raw));
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
            $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make((int)$this->adminId, (array)$this->adminInfo, date('Y-m-d'), $this->selectedLocationId($input));
            unset($input['warehouseId']);
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

    private function selectedLocationId(array $input): int
    {
        foreach (['tenant_id','store_id','organization_id','location_id','scope_dimensions','permissions'] as $key) {
            if (array_key_exists($key, $input)) throw new \InvalidArgumentException('inventory_platform_uq_scope_forbidden');
        }
        if (!array_key_exists('warehouseId', $input)) return 0;
        $value = $input['warehouseId'];
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) {
            throw new \InvalidArgumentException('inventory_platform_uq_warehouse_invalid');
        }
        return (int)$value;
    }

    private function access(): array
    {
        return (new InventoryPlatformAccessPolicy())->resolve((array)$this->adminInfo);
    }
}
