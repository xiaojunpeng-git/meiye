<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\App;

/** Platform gateway for one selected store's V3 salon-consumable documents. */
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
            $input = $this->request->getMore([['store_id', 0], ['keyword', ''], ['from', date('Y-m-d')], ['to', date('Y-m-d')]]);
            return $this->success($this->services->listForPlatform((array)$this->adminInfo, (int)$input['store_id'], (string)$input['keyword'], (string)$input['from'], (string)$input['to']));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('院装查询条件不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function projects()
    {
        try {
            $input = $this->request->getMore([['store_id', 0], ['keyword', ''], ['page', 1], ['limit', 50]]);
            return $this->success($this->services->projectsForPlatform((array)$this->adminInfo, (int)$input['store_id'], (string)$input['keyword'], (int)$input['page'], (int)$input['limit']));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('院装项目查询条件不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function detail(int $id)
    {
        try {
            return $this->success($this->services->detailForPlatform((array)$this->adminInfo, (int)$this->request->get('store_id', 0), $id));
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

    public function returnToDefault()
    {
        return $this->command('returnForPlatform', '院装退回未完成，请刷新后重试。');
    }

    private function command(string $method, string $fallback)
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $storeId = (int)($input['store_id'] ?? 0);
            unset($input['store_id']);
            return $this->success($this->services->{$method}((array)$this->adminInfo, $storeId, $input));
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
            'inventory_salon_usage_return_exceeds_issue' => '退回数量不能超过原领用数量。',
            'inventory_salon_usage_return_source_not_found' => '退回来源不存在或不属于当前门店项目。',
            'inventory_salon_usage_return_location_denied' => '退回必须回到当前门店的默认库存仓。',
            'inventory_salon_usage_stock_insufficient' => '当前库存不足，无法完成院装领用。',
        ][$code] ?? $fallback;
    }
}
