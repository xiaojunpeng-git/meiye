<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\StoreStockCrossSkuServices;
use app\services\product\inventory\StoreStockRequestServices;
use think\facade\App;

/**
 * 平台端请货管理
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
        return $this->success($this->services->getList($where, 0));
    }

    public function info($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        return $this->success($this->services->detail((int)$id, 0));
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
        $newId = $this->services->saveDraft((int)$id, $data, (int)$this->adminId, 0);
        return $this->success('保存成功', ['id' => $newId]);
    }

    public function delete($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->deleteDraft((int)$id, 0);
        return $this->success('删除成功');
    }

    public function confirmApply($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->confirmApply((int)$id, (int)$this->adminId, 0);
        return $this->success('已确认申请');
    }

    public function reject($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        [$reason] = $this->request->postMore([['reject_reason', '']], true);
        $this->services->reject((int)$id, (string)$reason, (int)$this->adminId, 0);
        return $this->success('已驳回');
    }

    public function cancel($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->cancel((int)$id, (int)$this->adminId, 0);
        return $this->success('已取消');
    }

    /**
     * 双店同源 SKU（平台代选）
     */
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
        return $this->success($cross->listSharedSkus(
            (int)$storeA,
            (int)$storeB,
            (string)$keyword,
            (int)$page,
            (int)$limit,
            (string)$partyA,
            (string)$partyB
        ));
    }

    /** 请货待办角标（总部仓=平台首页待办，非站内信） */
    public function pendingBadge()
    {
        $count = $this->services->pendingSupplyCount(0);
        /** @var \app\services\product\inventory\StoreStockRequestNoticeServices $notice */
        $notice = app()->make(\app\services\product\inventory\StoreStockRequestNoticeServices::class);
        return $this->success([
            'pending_supply' => $count,
            'failed_notice' => $notice->failedNoticeCount(0),
            // 总部仓通知渠道：仅平台首页待办，不伪造 system_message
            'hq_notice_channel' => 'platform_homepage_todo',
        ]);
    }

    /** 手动触发门店站内信重试扫描（总部仓无站内信，queued_failed 恒为 0） */
    public function retryNotice()
    {
        /** @var \app\services\product\inventory\StoreStockRequestNoticeServices $notice */
        $notice = app()->make(\app\services\product\inventory\StoreStockRequestNoticeServices::class);
        $pending = $notice->retryDue(50);
        // storeId=0：明确不重试总部仓（无站内信通道）
        $failed = $notice->retryFailed(50, 0);
        return $this->success('已投递重试', [
            'queued_pending' => $pending,
            'queued_failed' => $failed,
            'hq_notice_channel' => 'platform_homepage_todo',
        ]);
    }
}
