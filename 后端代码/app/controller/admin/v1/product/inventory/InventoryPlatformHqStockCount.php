<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformHqStockCountQueryServices;
use app\services\product\inventory\InventoryStockCountServices;
use think\facade\App;

/** Platform write boundary for physical counts owned by a headquarters warehouse. */
final class InventoryPlatformHqStockCount extends AuthController
{
    public function __construct(App $app, InventoryStockCountServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function confirm()
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0);
            unset($input['hq_location_id']);
            return $this->success($this->services->confirmForHeadquarters((array)$this->adminInfo, $locationId, $input));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部盘点参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function detail(int $id, InventoryPlatformHqStockCountQueryServices $queries)
    {
        try {
            return $this->success($queries->detail(
                (array)$this->adminInfo,
                (int)$this->request->get('hq_location_id', 0),
                $id
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部盘点单不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    private function message(string $code): string
    {
        return [
            'inventory_hq_location_scope_empty' => '当前平台范围内没有可用总部仓，请先完成库存迁移。',
            'inventory_hq_location_scope_denied' => '当前岗位无权操作该总部仓。',
            'inventory_hq_location_selection_required' => '请选择要盘点的总部仓。',
            'inventory_hq_location_invalid' => '总部仓状态无效，无法盘点。',
            'inventory_stock_count_stock_not_found' => '所选商品不在当前总部仓库存中，请刷新后重试。',
            'inventory_stock_count_loss_exceeds_book' => '盘亏数量不能超过当前账面库存。',
            'inventory_stock_count_idempotency_conflict' => '本次盘点内容与已提交记录不一致，请刷新后重试。',
        ][$code] ?? '总部盘点未完成，请刷新后重试。';
    }
}
