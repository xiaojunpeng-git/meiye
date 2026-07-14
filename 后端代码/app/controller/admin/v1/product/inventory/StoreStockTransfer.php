<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\StoreStockTransferServices;
use think\facade\App;

/**
 * 平台端调拨管理
 */
class StoreStockTransfer extends AuthController
{
    public function __construct(App $app, StoreStockTransferServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['request_id', ''],
            ['from_store_id', ''],
            ['to_store_id', ''],
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
            ['request_id', 0],
            ['from_store_id', 0],
            ['to_store_id', 0],
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

    public function cancel($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->cancelDraft((int)$id, (int)$this->adminId, 0);
        return $this->success('已取消');
    }

    public function confirm($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $result = $this->services->confirm((int)$id, (int)$this->adminId, 0);
        return $this->success('确认调拨成功', $result);
    }

    public function reverse($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $data = $this->request->postMore([
            ['details', []],
            ['auto_confirm', 1],
        ]);
        $result = $this->services->createReverse(
            (int)$id,
            $data['details'] ?? [],
            (int)$this->adminId,
            0,
            (int)($data['auto_confirm'] ?? 1) === 1
        );
        return $this->success('冲销成功', $result);
    }
}
