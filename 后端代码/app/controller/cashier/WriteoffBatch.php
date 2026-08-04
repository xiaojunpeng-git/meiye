<?php

namespace app\controller\cashier;

use app\Request;
use app\services\order\BatchWriteoffServices;
use think\facade\App;
use think\facade\Db;

class WriteoffBatch extends AuthController
{
    public function __construct(App $app, BatchWriteoffServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function options(Request $request)
    {
        $uid = (int)$request->get('uid', 0);
        $keyword = (string)$request->get('keyword', '');
        return $this->success($this->services->options($uid, (int)$this->storeId, $keyword));
    }

    public function preview(Request $request)
    {
        $payload = $request->postMore([
            ['uid', 0],
            ['items', []],
            ['is_budan', 0],
            ['budan_time', ''],
            ['remark', ''],
        ]);
        $deny = $this->guardBatchItemsOrderAccess($payload['items'] ?? []);
        if ($deny !== null) {
            return $deny;
        }
        return $this->success($this->services->preview($payload, (int)$this->storeId, (int)$this->cashierId));
    }

    public function commit(Request $request)
    {
        $payload = $request->postMore([
            ['uid', 0],
            ['items', []],
            ['idempotency_key', ''],
            ['is_budan', 0],
            ['budan_time', ''],
            ['remark', ''],
        ]);
        $deny = $this->guardBatchItemsOrderAccess($payload['items'] ?? []);
        if ($deny !== null) {
            return $deny;
        }
        return $this->success('核销成功', $this->services->commit($payload, (int)$this->storeId, (int)$this->cashierId));
    }

    /**
     * 批量核销写前：对涉及订单叠加数据范围守卫
     * @return \think\Response|null
     */
    protected function guardBatchItemsOrderAccess($items)
    {
        if (!is_array($items) || !$items) {
            return null;
        }
        $oids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $oid = (int)($item['oid'] ?? 0);
            if ($oid > 0) {
                $oids[$oid] = true;
            }
        }
        if (!$oids) {
            return null;
        }
        try {
            /** @var \app\services\organization\EmployeeDataScopeServices $scopeSvc */
            $scopeSvc = app()->make(\app\services\organization\EmployeeDataScopeServices::class);
            $cashierInfo = is_array($this->cashierInfo ?? null) ? $this->cashierInfo : [];
            $rows = Db::name('store_order')
                ->whereIn('id', array_keys($oids))
                ->where('is_del', 0)
                ->field('id,store_id,staff_id,clerk_id,service_staff_id,gendan_staff_id')
                ->select()
                ->toArray();
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int)$row['id']] = $row;
            }
            foreach (array_keys($oids) as $oid) {
                if (!isset($byId[$oid])) {
                    return $this->fail(\app\services\organization\EmployeeDataScopeServices::DENY_ORDER_WRITEOFF_MSG);
                }
                $scopeSvc->guardOrderAccessFromCashier($cashierInfo, (int)$this->storeId, $byId[$oid]);
            }
            return null;
        } catch (\mohe\exceptions\AdminException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail(\app\services\organization\EmployeeDataScopeServices::DENY_ORDER_WRITEOFF_MSG);
        }
    }

    public function cancel(Request $request, $batchId)
    {
        [$remark] = $request->postMore([
            ['remark', ''],
        ], true);
        $this->services->cancelBatch((int)$batchId, (string)$remark, (int)$this->storeId);
        return $this->success('撤销成功');
    }
}
