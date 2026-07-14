<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\cashier;

use app\Request;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\store\SystemStoreServices;
use app\services\system\form\SystemFormServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 * 收银台商品
 * Class Product
 * @package app\controller\cashier
 */
class Product extends AuthController
{

	/**
	 * 获取分类列表
	 * @param SystemStoreServices $storeServices
	 * @param StoreProductCategoryServices $services
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
	public function category(SystemStoreServices $storeServices, StoreProductCategoryServices $services)
	{
		$storeInfo = $storeServices->getStoreInfo($this->storeId);
		$where = ['relation_id' => 0];
		if ($storeInfo && $storeInfo['product_category_status']) {//自建商品分类权限
			$where = ['relation_id' => $this->storeId];
		}
		$category = $services->getCashierCategory($where);
		return app('json')->success($category);
	}

	/**
	 * 获取商品一级分类
	 * @param Request $request
	 * @param SystemStoreServices $storeServices
	 * @param StoreProductCategoryServices $services
	 * @return mixed
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
    public function getOneCategory(Request $request, SystemStoreServices $storeServices, StoreProductCategoryServices $services)
    {
		$storeInfo = $storeServices->getStoreInfo($this->storeId);
		$whereCate = ['type' => 0, 'relation_id' => 0];
		if ($storeInfo && $storeInfo['product_category_status']) {//自建商品分类权限
            $whereCate = ['relation_id' => $this->storeId];
		}
        $where = $request->getMore([
            ['store_name', ''],
            ['field_key', ''],
            ['staff_id', ''],
            ['uid', 0],
            ['tourist_uid', ''],//虚拟用户uid
            ['show_type', ''],
            ['product_type', ''],//商品类型
        ]);
        $store_id = (int)$this->storeId;
        $where['field_key'] = $where['field_key'] == 'all' ? '' : $where['field_key'];
        $where['field_key'] = $where['field_key'] == 'id' ? 'product_id' : $where['field_key'];
        $where['show_type'] = [0, 2];
        $staff_id = (int)$where['staff_id'];
        $tourist_uid = (int)$where['tourist_uid'];
        $uid = (int)$where['uid'];
        if ($where['product_type'] === '') {
            $where['product_type'] = [0, 2, 4, 5, 6];
        } else {
            $where['product_type'] = strpos($where['product_type'], ',') !== false ? stringToIntArray($where['product_type']) : (int)$where['product_type'];
        }
        unset($where['staff_id'], $where['uid'], $where['tourist_uid']);
        $cateService=app()->make(StoreBranchProductServices::class);
        $whereCate['ids'] = $cateService->getCate($where, $store_id, $uid, $staff_id, $tourist_uid);
        return $this->success($services->getOneCategory($whereCate));
    }

    /**
     * 收银台商品列表
     * @param Request $request
     * @param StoreBranchProductServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductList(Request $request, StoreBranchProductServices $services, StoreProductCategoryServices $storeProductCategoryServices)
    {
        $where = $request->getMore([
            ['store_name', ''],
            ['cate_id', 0, ''],
            ['field_key', ''],
            ['staff_id', ''],
            ['uid', 0],
            ['tourist_uid', ''],//虚拟用户uid
            ['show_type', ''],
			['product_type', ''],//商品类型
        ]);
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
        $store_id = (int)$this->storeId;
        $where['field_key'] = $where['field_key'] == 'all' ? '' : $where['field_key'];
        $where['field_key'] = $where['field_key'] == 'id' ? 'product_id' : $where['field_key'];
        $where['show_type'] = [0, 2];
        $staff_id = (int)$where['staff_id'];
        $tourist_uid = (int)$where['tourist_uid'];
        $uid = (int)$where['uid'];
		if ($where['product_type'] === '') {
			$where['product_type'] = [0, 2, 4, 5, 6];
		} else {
			$where['product_type'] = strpos($where['product_type'], ',') !== false ? stringToIntArray($where['product_type']) : (int)$where['product_type'];
		}
        unset($where['staff_id'], $where['uid'], $where['tourist_uid']);
		$result = $services->getCashierProductListV2($where, $store_id, $uid, $staff_id, $tourist_uid);
		$result['count_4_5'] = $services->getCount(['product_type' => [4, 5], 'is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'type' => 1, 'relation_id' => $store_id]);
		$result['count_6'] = $services->getCount(['product_type' => 6, 'is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'type' => 1, 'relation_id' => $store_id]);
        return $this->success($result);
    }

    /**
     * 获取收银台商品详情
     * @param Request $request
     * @param StoreBranchProductServices $services
     * @param int $id
     * @param int $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductInfo(Request $request, StoreBranchProductServices $services, $id = 0, $uid = 0)
    {
        if (!$id) {
            return $this->fail('缺少商品id');
        }
        $touristUid = $request->get('tourist_uid');
        return $this->success($services->getProductDetail($this->storeId, (int)$id, (int)$uid, (int)$touristUid));
    }

    /**
     * 获取商品属性
     * @param Request $request
     * @return mixed
     */
    public function getProductAttr(Request $request)
    {
        [$id, $cartNum, $uid] = $request->getMore([
            ['id', 0],
            ['type', 0],
            ['uid', 0]
        ], true);
        if (!$id) return app('json')->fail('参数错误');
        /** @var StoreSeckillServices $seckillServices */
        $seckillServices = app()->make(StoreSeckillServices::class);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $storeInfo = $seckillServices->getOne(['id' => $id]);
        if (!$storeInfo) {
            return app('json')->fail('商品不存在');
        }
        $storeInfo = $storeInfo ? $storeInfo->toArray() : [];
        //系统表单
        $storeInfo['custom_form'] = [];
        if (isset($storeInfo['system_form_id']) && $storeInfo['system_form_id']) {
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $systemForm = $systemFormServices->value(['id' => $storeInfo['system_form_id']], 'value');
            if ($systemForm) {
                $storeInfo['custom_form'] = is_string($systemForm) ? json_decode($systemForm, true) : $systemForm;
            }
        }
        //有自定义表单或预售或虚拟不展示加入购物车按钮
        $storeInfo['cart_button'] = $storeInfo['custom_form'] || $storeInfo['product_type'] > 0 ? 0 : 1;
        $data['storeInfo'] = $storeInfo;
        $data['storeInfo']['store_name'] = $data['storeInfo']['title'] ?? '';
        [$data['productAttr'], $data['productValue']] = $storeProductAttrServices->getProductAttrDetail((int)$id, (int)$uid, (int)$cartNum, 1, (int)$storeInfo['product_id']);
        return app('json')->successful($data);
    }

    /**
     * 卡项商品关联商品获取
     * @param Request $request
     * @param StoreCardRelatedServices $relatedService
     * @param $id
     * @return \think\Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getCardRelatedProduct(Request $request, StoreCardRelatedServices $relatedService, $id)
    {
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        $related = $relatedService->getCardRelatedProduct($id, true);
        return app('json')->successful($related);
    }

    /**
     * 获取选择的商品列表
     * @return mixed
     */
    public function search_list(Request $request)
    {
        $where = $request->getMore([
            ['cate_id', ''],
            ['store_cate_id', ''],
            ['store_name', ''],
            ['type', 1, '', 'status'],
            ['is_live', 0],
            ['is_new', ''],
            ['is_vip_product', ''],
            ['is_presale_product', ''],
            ['data', '', '', 'time'],
            ['store_label_id', ''],
            ['brand_id', ''],
            ['is_card', 0],//是否卡项获取关联商品
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
            ['choose_type', ''],//选择商品列表使用场景1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、7:新人礼、8:抽奖、 90:卡项关联商品、91：添加门店同步商品、92优惠活动参与商品、93优惠活动赠送商品
        ]);
        if($where['product_type'] == ''){
            $where['product_type']=[0,6];
        }else if(!in_array($where['product_type'],[0,6])){
            return $this->success([]);
        }
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
        $where['not_dingzhi']=1;
        /** @var StoreProductCategoryServices $storeCategoryServices */
        $storeCategoryServices = app()->make(StoreProductCategoryServices::class);
        if ($where['cate_id'] !== '') {
            if ($storeCategoryServices->value(['id' => $where['cate_id']], 'pid')) {
                $where['sid'] = $where['cate_id'];
            } else {
                $where['cid'] = $where['cate_id'];
            }
        }
        unset($where['cate_id']);
        $service=app()->make(StoreProductServices::class);
        $list = $service->searchList($where);
        return $this->success($list);
    }

    public function cascader_list($type = 1)
    {
        $relation_id = $type ? $this->storeId : 0;
        $service=app()->make(StoreProductCategoryServices::class);
        return $this->success($service->cascaderList($type,$relation_id));
    }
}
