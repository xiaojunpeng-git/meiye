<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformHqStockCountQueryServices;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryErrorMessage;
use app\services\product\inventory\InventoryStockCountCatalogException;
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
        } catch (InventoryStockCountCatalogException $exception) {
            // 共用编辑器按稳定商品身份提示并高亮，不能在总部入口丢失问题行信息。
            return $this->fail('盘点商品已失效，请核对标红的明细。', ['code' => $exception->getMessage(), 'invalid_lines' => $exception->invalidLines()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部盘点参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail(InventoryErrorMessage::from($exception)['message'] ?? $this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
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

    /** 草稿命令与完成盘点分开，所有主体/创建人范围交服务端授权解析。 */
    public function saveDraft()
    {
        return $this->draftCommand('save');
    }

    public function draftDetail(int $id)
    {
        return $this->draftCommand('detail', $id);
    }

    public function deleteDraft(int $id)
    {
        return $this->draftCommand('delete', $id);
    }

    private function draftCommand(string $action, int $id = 0)
    {
        try {
            $input = $action === 'detail' ? $this->request->get() : $this->request->post();
            $locationId = (int)($input['hq_location_id'] ?? 0);
            $service = new InventoryStockCountDraftServices();
            if ($action === 'save') $result = $service->saveForHeadquarters((array)$this->adminInfo, $locationId, (array)$input);
            elseif ($action === 'detail') $result = $service->detailForHeadquarters((array)$this->adminInfo, $locationId, $id);
            else $result = $service->deleteForHeadquarters((array)$this->adminInfo, $locationId, $id, (int)($input['expected_version'] ?? 0));
            return $this->success($result);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return $this->fail((InventoryErrorMessage::from($exception)['message'] ?? '总部盘点草稿操作失败，请重试。'), ['code' => $exception->getMessage()]);
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
