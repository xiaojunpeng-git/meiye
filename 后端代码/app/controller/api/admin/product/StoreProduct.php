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
namespace app\controller\api\admin\product;

use app\Request;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreProductBatchProcessServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;

/**
 * 商品类
 * Class StoreProduct
 * @package app\api\controller\store
 */
class StoreProduct
{

	protected $services;

	public function __construct(StoreProductServices $services)
	{
		$this->services = $services;
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
            ['user_uid', 0],
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
            unset($where['cid'],$where['sid'],$where['tid']);
        }
        $user_uid = (int)$where['user_uid'];
		//待客下单：去除卡项、预约
		$where['product_type'] = [0, 1, 2, 3, 4];
        unset($where['user_uid']);
        $list = $this->services->getGoodsList($where, $user_uid, (int)$where['promotions_type']);
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
		$where['type'] = [0, 2];
		//商品管理：去除预约（时段库存）
		$where['product_type'] = [0, 1, 2, 3, 4, 5];
        $data = $this->services->getList($where,true);
        return app('json')->successful($data);
    }

    /**
     * 上下架
     * @param $is_show
     * @param $id
     * @return mixed
     * User: liusl
     * DateTime: 2024/1/23 17:43
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
		[$attr_value] = $request->postMore([
			['attr_value', []],
		], true);
		if (!$attr_value) {
			return app('json')->fail('请填写属性值');
		}
		if (!$id) {
			return app('json')->fail('请选择商品');
		}
		$product = $storeProductServices->getCacheProductInfo((int)$id);
		if (!$product) {
			return app('json')->fail('商品不存在或已删除');
		}

        //判断规格的属性值是否存在
        $requiredKeys = ['unique', 'price', 'stock', 'cost', 'ot_price'];
        foreach ($attr_value as $attr) {
            $missingKeys = array_diff($requiredKeys, array_keys($attr));
            if (!empty($missingKeys)) {
                return app('json')->fail('请重新修改规格库存');
            }
        }

        // 【库存铁律】管理端此接口禁止改库存；仅保留改价（显式传 type=price）
        return app('json')->fail('已停用：不可在此修改库存，请到「库存管理」入库/出库/盘点操作。若仅改价格请使用改价入口。');
        // $services->updateAttrs($id, $attr_value);
        // return app('json')->success('修改成功');
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
        if(is_array($ids)){
            //批量操作
            $batchProcessServices->batchProcess((int)$type, $ids, $data);
        }else{
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
        return app('json')->success('修改成功');
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
