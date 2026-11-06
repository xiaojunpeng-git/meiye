<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\services\product\inventory\InventoryListExport;
use app\controller\admin\AuthController;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\App;

/** Platform gateway for one explicitly selected store or headquarters warehouse. */
final class InventoryPlatformSalonUsage extends AuthController
{
    public function __construct(App $app, InventorySalonUsageServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        try {
            $input = $this->request->getMore([['store_id', 0], ['hq_location_id', 0], ['keyword', ''], ['from', date('Y-m-d')], ['to', date('Y-m-d')], ['page', 1], ['limit', 100]]);
            // 平台院装列表按授权仓库和完整筛选范围分页；详情仍由原单据身份独立校验。
            $mineOnly = $this->request->get('creator_scope', '') === 'mine';
            $read = fn (int $readPage, int $readLimit): array => $this->services->listForPlatform((array)$this->adminInfo, (int)$input['store_id'], (string)$input['keyword'], (string)$input['from'], (string)$input['to'], (int)$input['hq_location_id'], $readPage, $readLimit, $mineOnly);
            if ($this->request->get('list_export') === 'xlsx') {
                return InventoryListExport::response('inventory_salon_usage', $read, 100, true);
            }
            return $this->success($read((int)$input['page'], (int)$input['limit']));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('院装查询条件不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function projects()
    {
        try {
            $input = $this->request->getMore([['store_id', 0], ['hq_location_id', 0], ['keyword', ''], ['page', 1], ['limit', 50]]);
            return $this->success($this->services->projectsForPlatform((array)$this->adminInfo, (int)$input['store_id'], (string)$input['keyword'], (int)$input['page'], (int)$input['limit'], (int)$input['hq_location_id']));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('院装项目查询条件不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function detail(int $id)
    {
        try {
            return $this->success($this->services->detailForPlatform((array)$this->adminInfo, (int)$this->request->get('store_id', 0), $id, (int)$this->request->get('hq_location_id', 0)));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('院装单据不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function issue()
    {
        return $this->command('issueForPlatform', '院装领用未完成，请刷新后重试。');
    }

    /** 作废仓库身份与领用单原仓逐一核对，不允许跨仓退库。 */
    public function void(int $id)
    {
        try {
            $input = $this->request->post(); $input = is_array($input) ? $input : [];
            $storeId = (int)($input['store_id'] ?? 0);
            $hqLocationId = (int)($input['hq_location_id'] ?? 0);
            unset($input['store_id'], $input['hq_location_id']);
            return $this->success($this->services->voidForPlatform((array)$this->adminInfo, $storeId, $id, $input, $hqLocationId));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage(), '院装领用作废未完成，请刷新后重试。'), ['code' => $exception->getMessage()]);
        }
    }

    private function command(string $method, string $fallback)
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $storeId = (int)($input['store_id'] ?? 0);
            $hqLocationId = (int)($input['hq_location_id'] ?? 0);
            unset($input['store_id'], $input['hq_location_id']);
            return $this->success($this->services->{$method}((array)$this->adminInfo, $storeId, $input, $hqLocationId));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('院装操作参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage(), $fallback), ['code' => $exception->getMessage()]);
        }
    }

    private function message(string $code, string $fallback = '院装操作未完成，请刷新后重试。'): string
    {
        return [
            'inventory_salon_usage_platform_store_denied' => '当前岗位无权操作该门店库存仓。',
            'inventory_salon_usage_warehouse_selection_invalid' => '请选择一个可操作的领用仓库。',
            'inventory_hq_location_scope_denied' => '当前岗位无权操作该总部仓。',
            'inventory_hq_location_scope_empty' => '当前岗位没有可用的总部仓。',
            'inventory_salon_usage_return_exceeds_issue' => '退回数量不能超过原领用数量。',
            'inventory_salon_usage_return_source_not_found' => '退回来源不存在或不属于当前门店项目。',
            'inventory_salon_usage_return_location_denied' => '退回必须回到当前门店的默认库存仓。',
            'inventory_salon_usage_stock_insufficient' => '当前库存不足，无法完成院装领用。',
            'inventory_salon_usage_void_has_return' => '该领用单已有退回记录，不能再次作废退库。',
            'inventory_salon_usage_void_document_missing' => '领用单不存在或不属于当前仓库。',
            'inventory_manual_reversal_already_voided' => '该领用单已作废，请刷新列表。',
        ][$code] ?? $fallback;
    }
}
