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
namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\order\StoreOrderRefundServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\sku\StoreProductRuleServices;
use think\facade\App;

/**
 * 出入库明细
 * Class StoreProductStockDetail
 * @package app\controller\admin\v1\product\inventory
 */
class StoreProductStockDetail extends AuthController
{

    public function __construct(App $app, StoreProductStockDetailServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }


	/**
	 * 库存明细
	 * @return mixed
	 */
	public function index()
	{
		$where = $this->request->getMore([
			['stock_type', ''],//类型1入库2出库
			['order_type', ''],//出库单类型
			['order_id', ''],//关联出入库ID
			['stock_time', ''],//出入库日期
			['add_time', '', '', 'time'],//创建时间
			['field_key', ''],//商品名称:store_name 商品ID：product_id 商品编码：code 商品条形码：bar_code
			['keyword', ''],//关键字搜索
		]);
		return $this->success($this->services->getStockDetailList($where));
	}

	/**
	 * 商品sku库存明细顶部统计数据
	 * @return mixed
	 */
	public function productAttrStatistics()
	{
		$where = $this->request->getMore([
			['keyword', ''],//商品关键字
			['stock_range', ''],//库存区间
			['stock_time', '', '', 'time'],//业务时间
		]);
		$where['product_type'] = [0, 3, 5];
		return $this->success($this->services->getProductAttrStatistics($where));
	}

	/**
	 * 商品sku库存明细列表
	 * @param StoreProductAttrValueServices $attrValueServices
	 * @return mixed
	 */
	public function getProductAttrList(StoreProductAttrValueServices $attrValueServices)
	{
		$where = $this->request->getMore([
			['keyword', ''],//商品关键字
			['stock_range', ''],//库存区间
		]);
		$where['product_type'] = [0, 3, 5];
		return $this->success($attrValueServices->getAttrValueList($where));
	}

	/**
	 * 获取商品sku信息
	 * @param StoreProductServices $productServices
	 * @param StoreProductAttrValueServices $attrValueServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getProductAttrInfo(StoreProductServices $productServices, StoreProductAttrValueServices $attrValueServices)
	{
		$where = $this->request->getMore([
			['unique', ''],//商品sku唯一值
			['product_id', ''],//商品ID
		]);
		if (!$where['unique'] || !$where['product_id'])  return $this->fail('缺少参数');
		$where['type'] = 0;
		$info = $attrValueServices->getOne($where);
		if (!$info) {
			return $this->fail('商品信息获取失败');
		}
		$productInfo = $productServices->getCacheProductInfo((int)$where['product_id']);
		if (!$productInfo) {
			return $this->fail('商品信息获取失败');
		}
		$result = ['store_name' => $productInfo['store_name'], 'sku' => $info['suk'], 'image' => $info['image'], 'bar_code' => $info['bar_code']];
		return $this->success($result);
	}

	/**
	 * 获取商品sku出入库明细
	 * @param StoreProductStockOrderServices $stockOrderServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getProductAttrStockOrderList(StoreProductStockOrderServices $stockOrderServices)
	{
		$where = $this->request->getMore([
			['order_type', ''],//入库类型
			['keyword', ''],//单据编号关键字搜索
			['stock_time', ''],//业务时间
			['add_time', '', '', 'time'],//创建时间
			['unique', ''],//商品sku
		]);
		return $this->success($stockOrderServices->getStockOrderList($where));
	}

	/**
	 * 获取出入库统计顶部统计数据
	 * @return mixed
	 */
	public function stockOrderOverallStatistics()
	{
		$where = $this->request->getMore([
			['keyword', ''],
			['stock_type', ''],
			['stock_time', '', '', 'add_time'],//创建时间
		]);
		$stockType = (int)$where['stock_type'];
		unset($where['stock_type']);
		return $this->success($this->services->getStockOrderOverallStatistics($stockType, $where));
	}


	/**
	 * 获取出入库统计列表数据
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function stockOrderStatistics()
	{
		$where = $this->request->getMore([
			['keyword', ''],
			['stock_type', ''],
			['stock_time', '', '', 'add_time'],//创建时间
		]);
		$stockType = (int)$where['stock_type'];
		unset($where['stock_type']);
		return $this->success($this->services->getStockStatisticsList($stockType, $where));
	}





}
