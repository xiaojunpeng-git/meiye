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
namespace app\controller\store\export;


use app\controller\store\AuthController;
use app\services\order\store\BranchOrderServices;
use app\services\order\StoreOrderServices;
use app\services\other\export\ExportServices;
use app\services\other\Import\ImportRecordErrorServices;
use app\services\other\Import\ImportRecordServices;
use app\services\product\inventory\StoreProductStockCountServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\store\StoreUserServices;
use think\facade\App;

/**
 * 导出excel类
 * Class ExportExcel
 * @package app\controller\store\export
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
     * 门店账单下载
     * @param StoreFinanceFlowServices $services
     * @return mixed
     */
    public function financeRecord(StoreFinanceFlowServices $services)
    {
        $where = $this->request->getMore([
            ['timeType', 'day'],
            ['day', ''],
        ]);
        $where['trade_type'] = 1;
        $where['is_del'] = 0;
        $where['store_id'] = $this->storeId;
        $where['no_type'] = [1,15];
        $data = $services->getList($where);
        return $this->success($this->service->financeRecord($data['list'] ?? []));
    }

    /**
     * 店员交易统计导出
     * @param StoreFinanceFlowServices $services
     * @param BranchOrderServices $orderServices
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function statisticsExport(StoreFinanceFlowServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['staff_id', -1],
            ['time_key', 'add_time'],
            ['data', '', '', 'time'],
            ['type', 0]
        ]);
        if ($where['time_key'] && !in_array($where['time_key'], ['add_time', 'delivery_time', 'write_time'])) {
            return app('json')->fail('参数错误');
        }
        $where['time_key'] = 'add_time';
        $where['staff_id'] = $where['staff_id'] ?: -1;
        $where['store_id'] = $this->storeId;
        $where['trade_type'] = 2;
        if (!$where['type']) {
            $where['type'] = [7, 8, 9, 10, 11, 12, 13];
        } elseif ($where['type'] == 11) {
            $where['type'] = [11, 12, 13];
        }
        $where['time'] = $orderServices->timeHandle($where['time']);
        $where['is_del'] = 0;
        $data = $services->getList($where);
        return $this->success($this->service->statistics($data['list'] ?? [], '店员交易统计导出'));
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
		$data = $services->getStockOrderList($where, 1, $this->storeId);
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
		$data = $services->getStockOrderList($where, 1, $this->storeId);
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
		$data = $services->getStockCountList($where, 1, $this->storeId);
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
		$data = $services->getStockOrderList($where, 1, $this->storeId);
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
		$data = $services->getStockStatisticsList($stockType, $where, 1, $this->storeId);
		return $this->success($this->service->productStockOrderStatistics($stockType,$data['list'] ?? []));
	}

    /**
     * 商品迁移数据导出
     * @param StoreProductServices $services
     * @return \think\Response
     */
    public function storeProductImport(StoreProductServices $services)
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
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
        $data = $services->searchList($where, true, $this->service->limit);
        return $this->success($this->service->storeProductImport($data['list'] ?? []));
    }

    /**
     * 配送数据统计导出
     * @param StoreOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function statistics(StoreOrderServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['delivery_uid', 0],
            ['real_name', ''],
            ['status', ''],// 0:待付款1:待配送 2:配送中 3:待评价 4:已完成 -2:已退款
            ['data', '', '', 'time'],
        ]);
        $where['store_id'] = $this->storeId;
        $where['time'] = $orderServices->timeHandle($where['time']);
        $data = $services->getDeliveryStatistics($where);
        return app('json')->success($this->service->deliveryStatistics($data['list'] ?? []));
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
        $recordId = (int)$where['record_id'];
        $record = $recordServices->get($recordId);
        if (!$record || (int)($record['is_del'] ?? 0) === 1) {
            return $this->fail('导入记录不存在');
        }
        // 门店仅可下载本店记录
        if ((int)($record['type'] ?? -1) !== 1 || (int)($record['relation_id'] ?? -1) !== (int)$this->storeId) {
            return $this->fail('无权下载该导入记录');
        }
        unset($where['type']);
        $data = $errorServices->getErrorList($where);
        $recordServices->bcInc($recordId, 'down_count', 1);
        $importType = (string)($record['import_type'] ?? '');
        if ($type == 'user') {
            return $this->success($this->service->importUser($data['list'] ?? []));
        } else if (in_array($importType, ['stock_initial_in', 'stock_in', 'stock_out'], true)
            || in_array((string)$type, ['stock_initial_in', 'stock_in', 'stock_out'], true)) {
            return $this->success($this->service->importStock($data['list'] ?? []));
        } else {
            return $this->success($this->service->importProduct($data['list'] ?? []));
        }
    }

    /**
     * 门店导入记录列表（仅本店）
     */
    public function importUserList(ImportRecordServices $services)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],
            ['status', ''],
            ['keyword', ''],
            ['type', '', '', 'import_type'],
        ]);
        $where['type'] = 1;
        $where['relation_id'] = (int)$this->storeId;
        $where['is_del'] = 0;
        return $this->success($services->getImportList($where));
    }

    /**
     * 门店用户列表导出
     * @param StoreUserServices $services
     * @return \think\Response
     */
    public function user(StoreUserServices $services)
    {
        $where = $this->request->getMore([
            ['page', 1],
            ['limit', 1000],
            ['nickname', ''],
            ['keyword', ''],
            ['status', ''],
            ['pay_count', ''],
            ['is_promoter', ''],
            ['order', ''],
            ['data', ''],
            ['user_type', ''],
            ['country', ''],
            ['province', ''],
            ['city', ''],
            ['user_time_type', ''],
            ['user_time', ''],
            ['sex', ''],
            [['level', 0], 0],
            [['group_id', 'd'], 0],
            [['label_id', 'd'], 0],
            ['now_money', 'normal'],
            ['field_key', ''],
            ['isMember', ''],
            ['cash_consume_price', ''],
            ['recent_consume_type', ''],
            ['recent_consume_days', 0],
            ['recent_consume_time', ''],
            ['no_consume_type', ''],
            ['no_consume_days', 0],
            ['no_consume_time', ''],
            ['writeoff_count', ''],
            ['recent_writeoff_type', ''],
            ['recent_writeoff_days', 0],
            ['recent_writeoff_time', ''],
            ['no_writeoff_type', ''],
            ['no_writeoff_days', 0],
            ['no_writeoff_time', ''],
            ['integral', ''],
            ['now_money_peice', ''],
            ['sale_date_time', ''],
            ['ids', '', '', 'uid'],
        ]);
        if (empty($where['nickname']) && !empty($where['keyword'])) {
            $where['nickname'] = $where['keyword'];
        }
        unset($where['keyword']);
        if (!empty($where['label_id'])) {
            $where['label_id'] = stringToIntArray($where['label_id']);
        }
        $where['user_time_type'] = $where['user_time_type'] == 'all' ? '' : $where['user_time_type'];
        if ($where['uid'] !== '') {
            $where['uid'] = stringToIntArray($where['uid']);
        } else {
            unset($where['uid']);
        }
        $data = $services->index($where, (int)$this->storeId);
        return $this->success($this->service->storeUser($data['list'] ?? []));
    }
}
