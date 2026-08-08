<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\query\UnifiedQueryException;
use think\facade\App;

/** Platform boundary for the organization-root HQ warehouse. */
final class InventoryPlatformHqCrossTransfer extends AuthController
{
    public function __construct(App $app, InventoryCrossSubjectTransferServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        try {
            $input = $this->request->getMore([['hq_location_id', 0], ['keyword', ''], ['page', 1], ['limit', 20], ['from_party_type', ''], ['from_party_id', 0], ['to_party_type', ''], ['to_party_id', 0], ['status', ''], ['business_date_from', ''], ['business_date_to', '']]);
            return $this->success($this->services->listForHeadquarters((array)$this->adminInfo, (int)$input['hq_location_id'], (string)$input['keyword'], (int)$input['page'], (int)$input['limit'], (string)$input['from_party_type'], (int)$input['from_party_id'], (string)$input['to_party_type'], (int)$input['to_party_id'], (string)$input['status'], (string)$input['business_date_from'], (string)$input['business_date_to']));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function detail(int $id)
    {
        try {
            return $this->success($this->services->detailForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), $id));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function counterparties()
    {
        try {
            return $this->success($this->services->counterpartiesForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), (string)$this->request->get('source_party_type', 'HQ'), (int)$this->request->get('source_store_id', 0)));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function incomingRequests()
    {
        try {
            $access = (new \app\services\product\inventory\InventoryPlatformAccessPolicy())->resolve((array)$this->adminInfo);
            $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
            $sourcePartyType = (string)$this->request->get('source_party_type', 'HQ');
            $sourceStoreId = (int)$this->request->get('source_store_id', 0);
            return $this->success($this->services->incomingRequestsForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), $canViewCost, $sourcePartyType, $sourceStoreId));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function transferStaffs()
    {
        try {
            return $this->success($this->services->transferStaffCandidatesForHeadquarters(
                (array)$this->adminInfo,
                (int)$this->request->get('hq_location_id', 0),
                (string)$this->request->get('source_party_type', 'HQ'),
                (int)$this->request->get('source_store_id', 0),
                (int)$this->request->get('target_store_id', 0)
            ));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('调拨人员参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function create()
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0);
            unset($input['hq_location_id']);
            return $this->success('总部调拨草稿已保存。', $this->services->createDraftForHeadquarters((array)$this->adminInfo, $locationId, $input));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部调拨参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function dispatch(int $id)
    {
        try {
            $locationId = (int)$this->request->post('hq_location_id', 0);
            return $this->success('调拨已发货，正在等待收货。', $this->services->dispatchForHeadquarters((array)$this->adminInfo, $locationId, $id));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function receive(int $id)
    {
        try {
            $locationId = (int)$this->request->post('hq_location_id', 0);
            return $this->success('收货完成，库存已入账。', $this->services->receiveForHeadquarters((array)$this->adminInfo, $locationId, $id));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function cancel(int $id)
    {
        try {
            $locationId = (int)$this->request->post('hq_location_id', 0);
            return $this->success('总部调拨草稿已取消。', $this->services->cancelForHeadquarters((array)$this->adminInfo, $locationId, $id));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function reverse(int $id)
    {
        try {
            $input = $this->request->post(); $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0); unset($input['hq_location_id']);
            return $this->success('调拨单已作废，库存已按原批次冲销。', $this->services->reverseForHeadquarters((array)$this->adminInfo, $locationId, $id, $input));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('请填写作废原因。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    private function message(string $code): string
    {
        return [
            'inventory_hq_location_scope_empty' => '当前平台范围内没有可用总部仓。',
            'inventory_hq_location_scope_denied' => '当前岗位无权操作该总部仓。',
            'inventory_hq_location_selection_required' => '请选择要操作的总部仓。',
            'inventory_hq_location_invalid', 'inventory_cross_transfer_hq_location_invalid' => '总部仓状态无效，无法操作。',
            'inventory_cross_transfer_not_found' => '未找到当前总部仓可查看的调拨单。',
            'inventory_cross_transfer_stock_insufficient' => '总部仓库存不足，无法发货。',
            'inventory_cross_transfer_source_sku_not_found' => '供货方商品或规格已失效，请重新选择商品。',
            'inventory_cross_transfer_target_sku_unavailable' => '调入方缺少对应商品，请先同步商品后再调拨。',
            'inventory_cross_transfer_request_unavailable' => '关联请货单已失效或与本次调拨方向不一致，请重新选择。',
            'inventory_cross_transfer_request_line_invalid' => '关联请货商品已变更，请重新选择请货单。',
            'inventory_cross_transfer_request_quantity_exceeded' => '调拨数量超过请货单剩余可调数量，请刷新后重试。',
            'inventory_cross_transfer_dispatch_state_invalid' => '当前调拨单不可发货。',
            'inventory_cross_transfer_receive_state_invalid' => '当前调拨单不可收货。',
            'inventory_cross_transfer_target_scope_denied' => '只能操作当前组织范围内的调拨。',
            'inventory_cross_transfer_transfer_staff_invalid' => '请选择当前授权组织范围内的在职员工。',
            'inventory_cross_transfer_reverse_state_invalid' => '只有在途或已收货调拨单可以作废。',
            'inventory_cross_transfer_reverse_scope_denied' => '只有调出方可以作废该调拨单。',
            'inventory_cross_transfer_target_reversal_stock_insufficient' => '调入方对应批次剩余库存不足，无法作废。',
            'inventory_cross_transfer_reverse_idempotency_conflict' => '本次操作号已用于其他调拨操作，请刷新后重试。',
        ][$code] ?? '总部调拨未完成，请刷新后重试。';
    }
}
