<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryStoreUnifiedQueryContextFactory;
use app\services\product\inventory\query\InventoryUnifiedQueryCommandServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use think\facade\App;

/** Store facade for the neutral UQ inventory provider; no client scope is accepted. */
final class InventoryUnifiedQuery extends AuthController
{
    public function __construct(App $app) { parent::__construct($app); }

    public function batchStock()
    {
        try {
            $payload = $this->payload();
            $cutoff = (string)($payload['queryCutoffDate'] ?? $payload['query_cutoff_date'] ?? date('Y-m-d'));
            unset($payload['query_cutoff_date']);
            $runtime = UnifiedQueryRuntime::runtime();
            $contextFactory = new InventoryStoreUnifiedQueryContextFactory($runtime['contextFactory']);
            $context = $contextFactory->make(
                (int)$this->storeId,
                (int)$this->storeStaffId,
                is_array($this->auth) ? $this->auth : [],
                $cutoff
            );
            $provider = $runtime['providers']->resolve(InventoryBatchStockQueryContract::PAGE_CODE);
            return $this->success($provider->query($context, $payload));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('库存查询条件不合法。');
        }
    }

    public function capabilities()
    {
        try {
            $runtime = UnifiedQueryRuntime::runtime();
            $context = $this->context($runtime, date('Y-m-d'));
            $settings = $runtime['preferences']->load($context, InventoryBatchStockQueryContract::PAGE_CODE);
            $capability = $runtime['capabilities']->build(
                $context,
                InventoryBatchStockQueryContract::PAGE_CODE,
                (int)$settings['settingsVersion'],
                (int)($context['data_as_of'] ?? time())
            );
            $capability['commandContext'] = [
                'kind' => 'query_preference',
                'id' => InventoryBatchStockQueryContract::PAGE_CODE,
            ];
            return $this->success(['unifiedQueryCapability' => $capability]);
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        }
    }

    public function command(InventoryUnifiedQueryCommandServices $services)
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            if (($this->request->forceStoreSessionInjectedPostStoreId ?? false) === true
                && (int)($input['store_id'] ?? 0) === (int)$this->storeId) {
                unset($input['store_id']);
            }
            return $this->success($services->dispatch(
                (int)$this->storeId,
                (int)$this->storeStaffId,
                is_array($this->auth) ? $this->auth : [],
                $input
            ));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('库存统一查询操作参数不合法。');
        }
    }

    public function exportTask(string $taskNo)
    {
        try {
            $runtime = UnifiedQueryRuntime::runtime();
            $context = $this->context($runtime, date('Y-m-d'));
            $context['page_code'] = InventoryBatchStockQueryContract::PAGE_CODE;
            return $this->success(['exportTask' => $runtime['exports']->findForAccount($context, $taskNo)]);
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        }
    }

    private function context(array $runtime, string $cutoff): array
    {
        $contextFactory = new InventoryStoreUnifiedQueryContextFactory($runtime['contextFactory']);
        return $contextFactory->make(
            (int)$this->storeId,
            (int)$this->storeStaffId,
            is_array($this->auth) ? $this->auth : [],
            $cutoff
        );
    }

    private function payload(): array
    {
        $raw = $this->request->get();
        $raw = is_array($raw) ? $raw : [];
        if (($this->request->forceStoreSessionInjectedGetStoreId ?? false) === true
            && (int)($raw['store_id'] ?? 0) === (int)$this->storeId) {
            unset($raw['store_id']);
        }
        foreach (['tenant_id', 'store_id', 'organization_id', 'location_id', 'scope_dimensions', 'permissions', 'granted_features'] as $blocked) {
            if (array_key_exists($blocked, $raw)) {
                throw new \InvalidArgumentException('inventory_unified_query_client_scope_forbidden');
            }
        }
        $allowed = ['page', 'limit', 'keyword', 'filters', 'topFilters', 'topFilterConditions', 'keywordFilters', 'filterRelation', 'sorts', 'groupBy', 'summaries', 'dataScope', 'businessStatus', 'quickFilters', 'visibleFields', 'fieldVersions', 'queryCutoffDate', 'query_cutoff_date'];
        $payload = array_intersect_key($raw, array_fill_keys($allowed, true));
        foreach (['filters', 'topFilters', 'topFilterConditions', 'keywordFilters', 'sorts', 'groupBy', 'summaries', 'quickFilters', 'visibleFields', 'fieldVersions'] as $key) {
            if (!isset($payload[$key]) || !is_string($payload[$key])) continue;
            $decoded = json_decode($payload[$key], true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('inventory_unified_query_parameter_invalid');
            }
            $payload[$key] = $decoded;
        }
        $payload['pageCode'] = InventoryBatchStockQueryContract::PAGE_CODE;
        return $payload;
    }
}
