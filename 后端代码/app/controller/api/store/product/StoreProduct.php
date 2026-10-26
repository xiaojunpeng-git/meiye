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
namespace app\controller\api\store\product;

use app\Request;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreProductBatchProcessServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;

/**
 * 商品类
 * Class StoreProduct
 * @package app\api\controller\store
 */
class StoreProduct
{

	protected $services;

	/**
	 * @var int
	 */
	protected $uid;
	/**
	 * 门店店员信息
	 * @var array
	 */
	protected $staffInfo;
	/**
	 * 门店id
	 * @var int|mixed
	 */
	protected $store_id;

	/**
	 * 门店店员ID
	 * @var int|mixed
	 */
	protected $staff_id;

	/**
	 * 构造方法
	 * StoreProduct constructor.
	 * @param StoreProductServices $services
	 */
	public function __construct(StoreProductServices $services, Request $request)
	{
		$this->services = $services;
		$this->uid = (int)$request->uid();
		$this->getStaffInfo();
	}

	/**
	 * @return void
	 */
	protected function getStaffInfo()
	{
		try {
			/** @var SystemStoreStaffServices $staffServices */
			$staffServices = app()->make(SystemStoreStaffServices::class);
			$staffInfo = $staffServices->getStaffInfoByUid($this->uid)->toArray();
		} catch (\Throwable $e) {
			$staffInfo = [];
		}
		$this->staffInfo = $staffInfo;
		$this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
		$this->staff_id = (int)($this->staffInfo['id'] ?? 0);
	}

	/**
	 * 获取门店信息
	 * @return array|\think\Model|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	protected function getStoreInfo()
	{
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		return $storeServices->getStoreInfo((int)$this->store_id);
	}

    /**
     * 商品列表
     * @param Request $request
     * @return mixed
     */
    public function lst(Request $request, StoreProductCategoryServices $services)
    {
        $where = $request->getMore([
            [['sid', 'd'], 0],
            [['cid', 'd'], 0],
            [['tid', 'd'], 0],
            ['keyword', '', '', 'store_name'],
            ['priceOrder', ''],
            ['salesOrder', ''],
            ['defaultOrder', ''],
            [['news', 'd'], 0, '', 'is_new'],
            [['type', ''], '', '', 'status'],
            ['ids', ''],
            [['selectId', 'd'], ''],
            ['cate_id', ''],
            ['productId', ''],
            ['brand_id', ''],
            ['promotions_id', 0],
            ['promotions_type', 0],
            ['store_label_id', 0]
        ]);
        if ($where['selectId'] && (!$where['sid'] || !$where['cid'])) {
            $level = $services->value(['id' => (int)$where['selectId']], 'level') ?? 0;
            $levelArr = $services->cateField;
            $where[$levelArr[$level] ?? 'cid'] = $where['selectId'];
        }
        $where['ids'] = stringToIntArray($where['ids']);
        if (!$where['ids']) {
            unset($where['ids']);
        }
        $where['brand_id'] = stringToIntArray($where['brand_id']);
        $where['store_label_id'] = stringToIntArray($where['store_label_id']);

        $cateId = $where['cate_id'];
        if ($cateId) {
            $cateId = is_string($cateId) ? stringToIntArray($where['cate_id']) : $cateId;
            $cateId = array_merge($cateId, $services->getColumn(['pid' => $cateId], 'id'));
            $cateId = array_unique(array_diff($cateId, [0]));
        }
        $where['cate_id'] = $cateId;

        $type = 'mid';
        $field = ['image', 'recommend_image'];
        if ($where['store_name']) {//搜索
            $field = ['image'];
            $where['pid'] = 0;
        }
		$where['type'] = 1;
		$where['relation_id'] = $this->store_id;
        $list = $this->services->getGoodsList($where, (int)$request->uid(), (int)$where['promotions_type'], false);
        return app('json')->successful(get_thumb_water($list, $type, $field));
    }

	/**
	 * @param Request $request
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function adminList(Request $request)
    {
        $where = $request->getMore([
            ['store_name', ''],
            ['type', '', '', 'status'],
        ]);
		$where['is_del'] = 0;
		$where['type'] = 1;
		$where['relation_id'] = $this->store_id;
		//商品管理：去除预约（时段库存）
		$where['product_type'] = [0, 1, 2, 3, 4, 5];
        $data = $this->services->getList($where,true);
        return app('json')->successful($data);
    }

	/**
	 * 上下架
	 * @param Request $request
	 * @return \think\Response
	 */
    public function setShow(Request $request)
    {
        [$id, $is_show] = $request->postMore([
            ['id', 0],
            ['is_show', 0],
        ], true);
        if (!$id) return app('json')->fail('请选择商品');
        $ids = is_array($id) ? $id : [$id];
        $this->services->setShow($ids, $is_show);
        return app('json')->success($is_show == 1 ? '上架成功' : '下架成功');
    }

	/**
	 * 商品标签树
	 * @param StoreProductLabelServices $services
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function labelTreeList(StoreProductLabelServices $services)
    {
        return app('json')->success($services->getProductLabelTreeList());
    }

	/**
	 * 修改规格属性
	 * @param Request $request
	 * @param StoreProductServices $storeProductServices
	 * @param StoreProductAttrValueServices $services
	 * @param $id
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function updateAttrs(Request $request, StoreProductServices $storeProductServices, StoreProductAttrValueServices $services, $id)
    {
        [$attr_value, $type] = $request->postMore([
            ['attr_value', []],
			['type', 'stock']
        ], true);
		if (!$attr_value) {
			return app('json')->fail('请填写属性值');
		}
		if (!in_array($type, ['stock', 'price'])) {
			return app('json')->fail('不允许的修改类型');
		}
		// 【库存铁律】规格库存改入口已停用
		if ($type === 'stock') {
			return app('json')->fail('已停用：不可在此修改库存，请到「库存管理」入库/出库/盘点操作');
		}
        if (!$id) {
            return app('json')->fail('请选择商品');
        }
		$product = $storeProductServices->getCacheProductInfo((int)$id);
		if (!$product) {
			return app('json')->fail('商品不存在或已删除');
		}
		//判断规格的属性值是否存在
		if ($type == 'stock') {//改库存不需要重新审核
			$requiredKeys = ['unique', 'stock'];
			$is_verify = $product['is_verify'];
		} else {
			$requiredKeys = ['unique', 'price', 'cost', 'ot_price'];
			$is_verify = 0;
			$storeInfo = $this->getStoreInfo();
			//门店开启免审
			if (isset($storeInfo['product_verify_status']) && $storeInfo['product_verify_status']) {
				$is_verify = 1;
			}
		}
        foreach ($attr_value as $attr) {
            $missingKeys = array_diff($requiredKeys, array_keys($attr));
            if (!empty($missingKeys)) {
                return app('json')->fail('请重新修改规格库存');
            }
        }

        $services->updateAttrs($id, $attr_value, $type, $is_verify, 1, $this->store_id, $this->staff_id);
        return app('json')->success('修改成功'. ($type == 'stock' ? '' : ',待平台审核'));
    }

	/**
	 * 批量/修改标签/分类
	 * @param Request $request
	 * @param StoreProductBatchProcessServices $batchProcessServices
	 * @return \think\Response
	 */
    public function batchProcess(Request $request, StoreProductBatchProcessServices $batchProcessServices)
    {
        [$type, $ids, $data] = $request->postMore([
            ['type', 1],
            ['ids', ''],
            ['data', []]
        ], true);
        if (!$ids) return app('json')->fail('请选择处理商品');
        if (!$data) {
            return app('json')->fail('请选择处理数据');
        }
		$is_verify = 0;
		$storeInfo = $this->getStoreInfo();
		//门店开启免审
		if (isset($storeInfo['product_verify_status']) && $storeInfo['product_verify_status']) {
			$is_verify = 1;
		}
		$data['is_verify'] = $is_verify;
        if(is_array($ids)){
            //批量操作
            $batchProcessServices->batchProcess((int)$type, $ids, $data);
        } else {
            switch ($type){
                case 1://修改分类
                    $batchProcessServices->setPrdouctCate((int)$ids, $data);
                    break;
                case 2://修改标签
                    $batchProcessServices->runBatch([$ids], $data);
                    break;
               default:
                    return app('json')->fail('请选择处理类型');
            }
        }
        return app('json')->success('修改成功,待平台审核');
    }

	/**
	 * 根据 id 获取规格
	 * @param $id
	 * @param StoreProductAttrValueServices $services
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getAttr($id,StoreProductAttrValueServices $services)
    {
        if(!$id) return app('json')->fail('请选择商品');
        $list = $services->getProductAttrValue(['product_id' => $id, 'type' => 0]);
        return app('json')->success($list ? : []);
    }
}
