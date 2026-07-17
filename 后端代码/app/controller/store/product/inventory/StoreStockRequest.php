<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\StoreStockCrossSkuServices;
use app\services\product\inventory\StoreStockRequestServices;
use think\facade\App;

/**
 * 门店端请货管理
 */
class StoreStockRequest extends AuthController
{
    public function __construct(App $app, StoreStockRequestServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['request_store_id', ''],
            ['supply_store_id', ''],
            ['supply_party_type', ''],
            ['keyword', ''],
            ['order_sn', ''],
        ]);
        return $this->success($this->services->getList($where, (int)$this->storeId));
    }

    public function info($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        return $this->success($this->services->detail((int)$id, (int)$this->storeId));
    }

    public function save($id = 0)
    {
        $data = $this->request->postMore([
            ['request_store_id', 0],
            ['request_party_type', 'store'],
            ['supply_store_id', 0],
            ['supply_party_type', 'store'],
            ['request_date', ''],
            ['request_staff_id', 0],
            ['remark', ''],
            ['details', []],
        ]);
        $newId = $this->services->saveDraft((int)$id, $data, (int)$this->storeStaffId, (int)$this->storeId);
        return $this->success('保存成功', ['id' => $newId]);
    }

    public function delete($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->deleteDraft((int)$id, (int)$this->storeId);
        return $this->success('删除成功');
    }

    public function confirmApply($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->confirmApply((int)$id, (int)$this->storeStaffId, (int)$this->storeId);
        return $this->success('已确认申请');
    }

    public function reject($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        [$reason] = $this->request->postMore([['reject_reason', '']], true);
        $this->services->reject((int)$id, (string)$reason, (int)$this->storeStaffId, (int)$this->storeId);
        return $this->success('已驳回');
    }

    public function cancel($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->cancel((int)$id, (int)$this->storeStaffId, (int)$this->storeId);
        return $this->success('已取消');
    }

    public function sharedSkus(StoreStockCrossSkuServices $cross)
    {
        [$storeA, $storeB, $keyword, $page, $limit, $partyA, $partyB] = $this->request->getMore([
            ['store_a', 0],
            ['store_b', 0],
            ['keyword', ''],
            ['page', 1],
            ['limit', 20],
            ['party_a', 'store'],
            ['party_b', 'store'],
        ], true);
        $partyA = strtolower(trim((string)$partyA)) ?: 'store';
        $partyB = strtolower(trim((string)$partyB)) ?: 'store';
        $storeA = (int)$storeA;
        $storeB = (int)$storeB;
        if ($partyA === 'store' && $storeA <= 0) {
            $storeA = (int)$this->storeId;
        }
        // 门店端：store 侧必须含本店；hq 侧 store=0
        $self = (int)$this->storeId;
        $touchSelf = ($partyA === 'store' && $storeA === $self) || ($partyB === 'store' && $storeB === $self);
        if (!$touchSelf) {
            return $this->fail('只能查询与本店相关的同源商品');
        }
        return $this->success($cross->listSharedSkus(
            $storeA,
            $storeB,
            (string)$keyword,
            (int)$page,
            (int)$limit,
            $partyA,
            $partyB
        ));
    }

    public function pendingBadge()
    {
        $count = $this->services->pendingSupplyCount((int)$this->storeId);
        /** @var \app\services\product\inventory\StoreStockRequestNoticeServices $notice */
        $notice = app()->make(\app\services\product\inventory\StoreStockRequestNoticeServices::class);
        return $this->success([
            'pending_supply' => $count,
            'failed_notice' => $notice->failedNoticeCount((int)$this->storeId),
        ]);
    }
}
