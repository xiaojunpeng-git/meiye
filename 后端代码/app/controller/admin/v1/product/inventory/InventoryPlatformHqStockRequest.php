<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryStockRequestServices;
use app\services\product\inventory\InventoryStockRequestQueryServices;
use app\services\query\UnifiedQueryException;
use think\facade\App;

/** Platform command boundary: authenticated administrator + HQ location are the request party. */
final class InventoryPlatformHqStockRequest extends AuthController
{
    public function __construct(App $app, InventoryStockRequestServices $services) { parent::__construct($app); $this->services = $services; }

    public function suppliers()
    {
        try {
            if ((string)$this->request->get('directory', '') === 'request_party') return $this->success($this->services->requestPartiesForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0)));
            return $this->success($this->services->suppliersForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), (string)$this->request->get('request_party_type', 'HQ'), (int)$this->request->get('request_party_id', 0)));
        }
        catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    public function requester()
    {
        try { return $this->success($this->services->requesterForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), (string)$this->request->get('request_party_type', 'HQ'), (int)$this->request->get('request_party_id', 0))); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    public function parties()
    {
        try { return $this->success($this->services->requestPartiesForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0))); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    public function index(InventoryStockRequestQueryServices $queries)
    {
        try {
            [$locationId, $keyword, $page, $limit, $requestPartyType, $requestPartyId, $status, $dateFrom, $dateTo] = $this->request->getMore([['hq_location_id', 0], ['keyword', ''], ['page', 1], ['limit', 20], ['request_party_type', ''], ['request_party_id', 0], ['status', ''], ['business_date_from', ''], ['business_date_to', '']], true);
            return $this->success($queries->listForHeadquarters((array)$this->adminInfo, (int)$locationId, (string)$keyword, (int)$page, (int)$limit, (string)$requestPartyType, (int)$requestPartyId, (string)$status, (string)$dateFrom, (string)$dateTo));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    public function detail(int $id, InventoryStockRequestQueryServices $queries)
    {
        try { return $this->success($queries->detailForHeadquarters((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), $id)); }
        catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('总部请货详情参数不合法。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    public function apply()
    {
        try {
            $input = $this->request->post(); $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0); unset($input['hq_location_id']);
            return $this->success('总部请货申请已保存。', $this->services->applyForHeadquarters((array)$this->adminInfo, $locationId, $input));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('总部请货参数不合法，请检查供货方和商品。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    public function cancel(int $id)
    {
        return $this->lifecycle($id, 'CANCEL');
    }

    public function terminate(int $id)
    {
        return $this->lifecycle($id, 'TERMINATE');
    }

    private function lifecycle(int $id, string $operation)
    {
        try {
            $input = $this->request->post(); $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0); unset($input['hq_location_id']);
            $result = $operation === 'CANCEL'
                ? $this->services->cancelForHeadquarters((array)$this->adminInfo, $locationId, $id, $input)
                : $this->services->terminateForHeadquarters((array)$this->adminInfo, $locationId, $id, $input);
            return $this->success($operation === 'CANCEL' ? '总部请货单已取消。' : '剩余请货已终止，已履约数量保持不变。', $result);
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('请填写本次操作原因。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]); }
    }

    private function message(string $code): string
    {
        return [
            'inventory_hq_location_scope_empty' => '当前平台范围内没有可用总部仓。',
            'inventory_hq_location_scope_denied' => '当前岗位无权操作该总部仓。',
            'inventory_hq_location_selection_required' => '请选择要操作的总部仓。',
            'inventory_hq_location_invalid' => '总部仓状态无效，无法请货。',
            'inventory_stock_request_supplier_invalid' => '供货门店不存在或已停用。',
            'inventory_stock_request_sku_not_found' => '所选总部商品或规格已失效，请重新选择。',
            'inventory_stock_request_not_found' => '总部请货单不存在或不属于当前总部仓。',
            'inventory_stock_request_cancel_state_invalid' => '只有未履约请货单可以取消。',
            'inventory_stock_request_terminate_state_invalid' => '只有部分履约请货单可以终止剩余数量。',
            'inventory_stock_request_lifecycle_idempotency_conflict' => '本次操作号已用于其他请货操作，请刷新后重试。',
        ][$code] ?? '总部请货申请未完成，请刷新后重试。';
    }
}
