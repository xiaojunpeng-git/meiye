<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\supplier\export;

use app\controller\supplier\AuthController;
use app\services\other\export\ExportServices;
use app\services\order\StoreOrderServices;
use app\services\other\ExpressServices;
use app\services\other\Import\ImportRecordErrorServices;
use app\services\other\Import\ImportRecordServices;
use app\services\other\queue\QueueAuxiliaryServices;
use app\services\other\queue\QueueServices;
use app\services\product\inventory\StoreProductStockCountServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\supplier\finance\SupplierFlowingWaterServices;
use think\facade\App;

/**
 * 导出excel类
 * Class ExportExcel
 * @package app\controller\supplier\export
 */
class ExportExcel extends AuthController
{
    /**
     * @var ExportServices
     */
    protected $service;

    /**
     * ExportExcel constructor.
     * @param App $app
     * @param ExportServices $services
     */
    public function __construct(App $app, ExportServices $services)
    {
        parent::__construct($app);
        $this->service = $services;
    }

    /**
     * 订单列表导出
     * @param StoreOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeOrder(StoreOrderServices $services)
    {
        $where_tmp = $this->request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['data', '', '', 'time'],
            ['type', ''],
            ['ids', '']
        ]);
        $type = $where_tmp['type'];
        $with = [];
        if ($where_tmp['ids']) {
            $where['id'] = explode(',', $where_tmp['ids']);
        }
        if ($type) {
            $where['status'] = 1;
            $where['paid'] = 1;
            $where['is_del'] = 0;
            $where['is_system_del'] = 0;
            $with = ['pink', 'refund' => function ($query) {
						$query->whereIn('refund_type', [0, 1, 2, 4, 5])->where('is_cancel', 0)->where('is_del', 0)->field('id,store_order_id');
					}];
        }
        if (!$where_tmp['ids'] && !$type) {
            unset($where_tmp['ids']);
            unset($where_tmp['type']);
            $where = $where_tmp;
        }
        if (!$where_tmp['real_name'] && !in_array($where_tmp['status'], [-1, -2, -3])) {
            $where['pid'] = 0;
        }
        $where['is_system_del'] = 0;
        $where['supplier_id'] = $this->supplierId;
        $data = $services->getExportList($where, $with, $this->service->limit);
        return $this->success($this->service->storeOrder($data, $type, 1, true));
    }

    /**
     * 导出批量任务发货的记录
     * @param int $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function batchOrderDelivery($id, $queueType, $cacheType)
    {
        /** @var QueueAuxiliaryServices $auxiliaryService */
        $auxiliaryService = app()->make(QueueAuxiliaryServices::class);
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $queueInfo = $queueService->getQueueOne(['id' => $id]);
        if (!$queueInfo) return $this->fail("数据不存在");
        $queueValue = json_decode($queueInfo['queue_in_value'], true);
        if (!$queueValue || !isset($queueValue['cacheType'])) return $this->fail("数据参数缺失");
        $data = $auxiliaryService->getExportData(['binding_id' => $id, 'type' => $cacheType], $this->service->limit);
        return $this->success($this->service->batchOrderDelivery($data, $queueType));
    }

    /**
     * 物流公司表导出
     * @return mixed
     */
    public function expressList()
    {
        /** @var ExpressServices $expressService */
        $expressService = app()->make(ExpressServices::class);
        $data = $expressService->apiExpressList();
        return $this->success($this->service->expressList($data));
    }

    /**
     * 供应商账单下载
     * @param SupplierFlowingWaterServices $services
     * @return mixed
     */
    public function financeRecord(SupplierFlowingWaterServices $services)
    {
        [$ids] = $this->request->getMore([
            ['ids', '']
        ], true);
        $where['id'] = $ids ? explode(',', $ids) : [];
        $where['is_del'] = 0;
        $where['supplier_id'] = $this->supplierId;
        $data = $services->getList($where);
        return $this->success($this->service->SupplierFinanceRecord($data['list'] ?? []));
    }

	/**
	 * 入库单导出
	 * @param StoreProductStockOrderServices $services
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function productStockInOrder(StoreProductStockOrderServices $services)
	{
		$where = $this->request->getMore([
			['order_type', ''],//入库类型
			['keyword', ''],//关键字搜索
			['stock_time', ''],//入库日期
			['add_time', '', '', 'time'],//创建时间
			['ids', ''],//选择ID
			['all', ''],//是否全选
		]);
		if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择要导出入库单');
		$where['ids'] = stringToIntArray($where['ids']);
		$where['stock_type'] = 1;
		$data = $services->getStockOrderList($where, 2, $this->supplierId);
		return $this->success($this->service->productStockOrder(1,$data['list'] ?? []));
	}

	/**
	 * 出库单导出
	 * @param StoreProductStockOrderServices $services
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function productStockOutOrder(StoreProductStockOrderServices $services)
	{
		$where = $this->request->getMore([
			['order_type', ''],//出库类型
			['keyword', ''],//关键字搜索
			['stock_time', ''],//出库日期
			['add_time', '', '', 'time'],//创建时间
			['ids', ''],//选择ID
			['all', ''],//是否全选
		]);
		if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择要导出出库单');
		$where['ids'] = stringToIntArray($where['ids']);
		$where['stock_type'] = 2;
		$data = $services->getStockOrderList($where, 2, $this->supplierId);
		return $this->success($this->service->productStockOrder(2,$data['list'] ?? []));
	}

	/**
	 * 库存盘点导出
	 * @param StoreProductStockCountServices $services
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function productStockCount(StoreProductStockCountServices $services)
	{
		$where = $this->request->getMore([
			['keyword', ''],//关键字搜索
			['status', ''],//0
			['add_time', '', '', 'time'],//创建时间
			['ids', ''],//选择ID
			['all', ''],//是否全选
		]);
		if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择要导出库存盘点');
		$where['ids'] = stringToIntArray($where['ids']);
		$data = $services->getStockCountList($where, 2, $this->supplierId);
		return $this->success($this->service->productStockCount($data['list'] ?? []));
	}

	/**
	 * 库存明细导出
	 * @param StoreProductStockOrderServices $services
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function productStockDetail(StoreProductStockOrderServices $services)
	{
		$where = $this->request->getMore([
			['order_type', ''],//入库类型
			['keyword', ''],//单据编号关键字搜索
			['stock_time', ''],//业务时间
			['add_time', '', '', 'time'],//创建时间
			['unique', ''],//商品sku
		]);
		$data = $services->getStockOrderList($where, 2, $this->supplierId);
		return $this->success($this->service->productStockDetail($data['list'] ?? []));
	}

	/**
	 * 出入库统计导出
	 * @param StoreProductStockDetailServices $services
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function productStockOrderStatistics(StoreProductStockDetailServices $services)
	{
		$where = $this->request->getMore([
			['keyword', ''],
			['stock_type', ''],//1入库2出库
			['stock_time', '', '', 'add_time'],//时间
		]);
		$stockType = (int)$where['stock_type'];
		unset($where['stock_type']);
		$data = $services->getStockStatisticsList($stockType, $where, 2, $this->supplierId);
		return $this->success($this->service->productStockOrderStatistics($stockType,$data['list'] ?? []));
	}

    /**
     * 商品迁移数据导出
     * @param StoreProductServices $services
     * @return \think\Response
     */
    public function supplierProductImport(StoreProductServices $services)
    {
        $where = $this->request->getMore([
            ['store_name', ''],
            ['cate_id', ''],
            ['type', '', '', 'status'],
            ['sales', 'normal'],
            ['store_label_id', ''],
            ['brand_id', ''],
            ['ids', ''],
            ['all', 0]
        ]);
        if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择批处理商品');

        $where['ids'] = stringToIntArray($where['ids']);

        unset($where['supplier_id'], $where['store_id'], $where['all']);
        $where['type'] = 2;
        $where['relation_id'] = $this->supplierId;
        $data = $services->searchList($where, true, $this->service->limit);
        return $this->success($this->service->supplierProductImport($data['list'] ?? []));
    }

    /**
     * 错误记录导出
     * @return \think\Response
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function importUserExport(ImportRecordErrorServices $errorServices, ImportRecordServices $recordServices)
    {
        $where = $this->request->getMore([
            ['record_id', '',],
            ['type', '',],
        ]);
        if (!$where['record_id']) {
            return $this->fail('参数错误');
        }
        $type = $where['type'];
        unset($where['type']);
        $data = $errorServices->getErrorList($where);
        $recordServices->bcInc($where['record_id'],'down_count',1);
        if($type == 'user') {
            return $this->success($this->service->importUser($data['list'] ?? []));
        }else{
            return $this->success($this->service->importProduct($data['list'] ?? []));
        }
    }
}
