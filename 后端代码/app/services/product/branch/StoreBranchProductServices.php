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

namespace app\services\product\branch;


use app\dao\product\product\StoreProductDao;
use app\jobs\product\ProductSyncStoreJob;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\BaseServices;
use app\services\order\StoreCartServices;
use app\services\product\brand\StoreBrandServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreCatalogWriteLease;
use app\services\product\product\StoreCatalogWriteLockGuard;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\product\StoreProductCouponServices;
use app\services\product\product\StoreProductRelationServices;
use app\services\product\product\StoreProductReplyCommentServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\store\SystemStoreServices;
use app\services\user\UserRelationServices;
use app\services\user\UserServices;
use mohe\exceptions\AdminException;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;

final class StoreBranchProductSyncPlanChangedException extends \RuntimeException
{
}

/**
 * Class StoreBranchProductServices
 * @package app\services\product\branch
 * @mixin StoreProductDao
 */
class StoreBranchProductServices extends BaseServices
{

    use ServicesTrait;

    /**
     * StoreBranchProductServices constructor.
     * @param StoreProductDao $dao
     */
    public function __construct(StoreProductDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取平台商品ID
     * @param int $product_id
     * @param $productInfo
     * @return int
     */
    public function getStoreProductId(int $product_id, $productInfo = [])
    {
        $id = 0;
        if (!$product_id) {
            return $id;
        }
        if (!$productInfo) {
            $productInfo = $this->dao->get($product_id, ['id', 'pid']);
        }
        if ($productInfo) {
            //门店平台共享商品
            if ($productInfo['pid']) {
                $id = (int)$productInfo['pid'];
            } else {
                $id = $productInfo['id'];
            }
        }
        return $id;
    }

    /**
     * 平台商品ID：获取在门店该商品详情
     * @param int $uid
     * @param int $id
     * @param int $store_id
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreProductInfo(int $uid, int $id, int $store_id)
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        try {
            $productInfo = $productServices->getCacheProductInfo($id);
        } catch (\Throwable $e) {
            $productInfo = [];
        }
        if (!$productInfo) {
            return [];
        }
        if ($productInfo['type'] != 1 && $store_id) {//查询该门店商品
            $info = $this->dao->get(['is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'pid' => $id, 'type' => 1, 'relation_id' => $store_id], ['id']);
            if ($info) {
                $id = (int)$info['id'];
            }
        }
        return $productServices->productDetail($uid, $id);
    }

    /**
     * 批量获取平台商品ID
     * @param array $product_ids
     * @return array
     */
    public function getStoreProductIds(array $product_ids)
    {
        $result = [];
        if (!$product_ids) {
            return $result;
        }
        $productInfos = $this->dao->getColumn([['id', 'IN', $product_ids]], 'id,pid', 'id');
        if ($productInfos) {
            foreach ($productInfos as $key => $productInfo) {
                //门店平台共享商品
                if ($productInfo['pid']) {
                    $id = (int)$productInfo['pid'];
                } else {
                    $id = $productInfo['id'];
                }
                $result[$key] = $id;
            }
        }
        return $result;
    }

    /**
     * 根据商品ID，获取适用门店ids
     * @param int $product_id
     * @param int $type
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getApplicableStoreIds(int $product_id, int $type = 0)
    {
        $ids = [];
        $productInfo = [];
		$productType = 0;
        switch ($type) {
            case 0://商品
                $productInfo = $this->dao->getOne(['id' => $product_id], 'id,type,relation_id,applicable_type,applicable_store_id');
                break;
            case 1://秒杀商品
                /** @var StoreSeckillServices $seckillServices */
                $seckillServices = app()->make(StoreSeckillServices::class);
                $productInfo = $seckillServices->getOne(['id' => $product_id], 'id,applicable_type,applicable_store_id');
                break;
        }
        if ($productInfo) {
			if ($type == 0 && $productInfo['type'] == 1 && $productInfo['relation_id']) {//门店商品
				$ids = [$productInfo['relation_id']];
				$productType = 1;
			} else {
				switch ($productInfo['applicable_type']) {
					case 0://仅平台
						break;
					case 1://所有门店 查询有商品的门店
						if ($type == 0 && isset($productInfo['type'])) {
							if (!$productInfo['type']) {//平台商品
								$ids = $this->dao->getColumn(['pid' => $product_id, 'is_show' => 1, 'is_del' => 0, 'type' => 1], 'relation_id');
							} else if ($productInfo['type'] == 1) {//门店商品
								$ids = [$productInfo['relation_id']];
							}
						}
						break;
					case 2://部分门店
						$ids = is_array($productInfo['applicable_store_id']) ? $productInfo['applicable_store_id'] : explode(',', $productInfo['applicable_store_id']);
						break;
					default:
						break;
				}
			}
        } else {
            $productInfo['applicable_type'] = 1;
        }
        return [$ids, $productInfo['applicable_type'], $productType];
    }

    //获得搜索到的商品列表
    public function getCate(array $where, int $store_id, int $uid = 0, int $staff_id = 0, int $tourist_uid = 0){
        $where['is_del'] = 0;
        $where['is_show'] = 1;
        $where['is_verify'] = 1;
        $where['type'] = 1;
        $where['relation_id'] = $store_id;
        $where['is_vip_product'] = 0;
        if ($uid) {
            /** @var UserServices $user */
            $user = app()->make(UserServices::class);
            $userInfo = $user->getUserCacheInfo($uid);
            $is_vip = $userInfo['is_money_level'] ?? 0;
            $where['is_vip_product'] = $is_vip ? -1 : 0;
        }
        $list = $this->dao->getSearchList($where, 0, 0, ['cate_id'], '', []);
        $cateIds=[];
        foreach ($list as $nk=>$nv){
               $cate=explode(",",$nv['cate_id']);
               foreach ($cate as $k=>$v){
                    $cateIds[]=$v;
               }
        }
        $cateIds=array_unique($cateIds);
        return $cateIds;
    }
    /**
     * 收银台获取门店商品
     * @param array $where
     * @param int $uid
     * @param int $staff_id
     * @param int $tourist_uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCashierProductListV2(array $where, int $store_id, int $uid = 0, int $staff_id = 0, int $tourist_uid = 0)
    {
        $where['is_del'] = 0;
        $where['is_show'] = 1;
        $where['is_verify'] = 1;
        $where['type'] = 1;
        $where['relation_id'] = $store_id;

        [$page, $limit] = $this->getPageValue();
        $where['is_vip_product'] = 0;
        if ($uid) {
            /** @var UserServices $user */
            $user = app()->make(UserServices::class);
            $userInfo = $user->getUserCacheInfo($uid);
            $is_vip = $userInfo['is_money_level'] ?? 0;
            $where['is_vip_product'] = $is_vip ? -1 : 0;
        }

        $list = $this->dao->getSearchList($where, $page, $limit, ['*'], 'sort desc,sales desc,id desc', []);
        $count = 0;
        if ($list) {
            $productIds = array_column($list, 'id');
            if ($uid || $tourist_uid) {
                if ($uid) {
                    $tourist_uid = 0;
                }
                /** @var StoreCartServices $cartServices */
                $cartServices = app()->make(StoreCartServices::class);
                $cartNumList = $cartServices->productIdByCartNum($productIds, $uid, $staff_id, $tourist_uid, $store_id);
                $data = [];
                foreach ($cartNumList as $item) {
                    $data[$item['product_id']][] = $item['cart_num'];
                }
                $newNumList = [];
                foreach ($data as $key => $item) {
                    $newNumList[$key] = array_sum($item);
                }
                $cartNumList = $newNumList;
            } else {
                $cartNumList = [];
            }
            $product = ['image' => '', 'id' => 0, 'store_name' => '', 'spec_type' => 0, 'store_info' => '', 'keyword' => '', 'price' => 0, 'stock' => 0, 'sales' => 0];
            /** @var StoreProductServices $storeProductServices */
            $storeProductServices = app()->make(StoreProductServices::class);
            $list = $storeProductServices->getProduceOtherList($list, $uid, true);
            $list = $storeProductServices->getProductPromotions($list);
            /** @var StoreCouponIssueServices $couponServices */
            $couponServices = app()->make(StoreCouponIssueServices::class);
            /** @var StoreProductCategoryServices $storeCategoryService */
            $storeCategoryService = app()->make(StoreProductCategoryServices::class);
            /** @var StoreBrandServices $storeBrandServices */
            $storeBrandServices = app()->make(StoreBrandServices::class);
            $brands = $storeBrandServices->getColumn([], 'id,pid', 'id');
            /** @var \app\services\product\product\StoreProductReservationServices $reservationServices */
            $reservationServices = app()->make(\app\services\product\product\StoreProductReservationServices::class);
            foreach ($list as &$item) {
                if ((int)($item['product_type'] ?? 0) === 6) {
                    $item = $reservationServices->fillServiceDuration($item);
                }
                $product = array_merge($product, array_intersect_key($item, $product));
                $item['product'] = $product;
                $item['product_id'] = $item['id'];
                $item['cart_num'] = $cartNumList[$item['id']] ?? 0;
                $item['branch_stock'] = $item['stock'];

                $cateId = $item['cate_id'];
                $cateId = explode(',', $cateId);
                $cateId = array_merge($cateId, $storeCategoryService->cateIdByPid($cateId));
                $cateId = array_diff($cateId, [0]);
                $brandId = [];
                if ($item['brand_id']) {
                    $brandId = $brands[$item['brand_id']] ?? [];
                }
                //平台商品
                $coupons = [];
                if ($item['pid'] > 0) $coupons = $couponServices->getIssueCouponListNew($uid, ['product_id' => $item['id'], 'cate_id' => $cateId, 'brand_id' => $brandId], 'id,coupon_title,coupon_price,use_min_price', 0, 1, 'coupon_price desc,sort desc,id desc');
                $item['coupon'] = $coupons[0] ?? [];
            }
        }
        $count = $this->dao->getCount($where);
        $code = $where['store_name'] ?? '';
        $attrValue = $userInfo = null;
        if ($code) {
            /** @var StoreProductAttrValueServices $attrService */
            $attrService = app()->make(StoreProductAttrValueServices::class);
            $attrValueArr = $attrService->getColumn(['bar_code' => $code, 'is_show' => 1], '*', 'product_id');
            if ($attrValueArr) {
                $product_ids = array_unique(array_column($attrValueArr, 'product_id'));
                $product = $this->dao->get(['id' => $product_ids, 'type' => 1, 'relation_id' => $store_id, 'is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'show_type' => [0, 2]], ['id', 'type', 'relation_id']);
                if ($product) {
                    $attrValue = $attrValueArr[$product['id']] ?? [];
                }
            }
            if (!$attrValue) {
                /** @var UserServices $userService */
                $userService = app()->make(UserServices::class);
				$field = ['uid', 'avatar', 'phone', 'nickname', 'now_money', 'integral', 'level', 'is_money_level', 'is_ever_level', 'overdue_time'];
                $userInfo = $userService->get(['bar_code' => $code], $field);
				if (!$userInfo) {//用户码没获取到 用微信付款码获取
					$userInfo = $userService->authCodeToUserInfo($code, $field);
				}
                if ($userInfo) {
                    $userInfo = $userInfo->toArray();
                    $list = [];
                    $count = 0;
                } else {
					$userInfo = null;
				}
            }
        }
        return compact('list', 'count', 'attrValue', 'userInfo');
    }

    /**
     * 获取商品详情
     * @param int $storeId
     * @param int $id
     * @param int $uid
     * @param int $touristUid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductDetail(int $storeId, int $id, int $uid, int $touristUid)
    {
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        $storeInfo = $productService->getOne(['id' => $id, 'is_show' => 1, 'is_del' => 0], '*');
        if (!$storeInfo) {
            throw new ValidateException('商品不存在');
        } else {
            $storeInfo = $storeInfo->toArray();
        }
        $siteUrl = sys_config('site_url');
        $storeInfo['image'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['image_base'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['fsales'] = ($storeInfo['ficti'] ?? 0) + ($storeInfo['sales'] ?? 0);

        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $storeProductAttrServices->setItem('touristUid', $touristUid);
        [$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetail($id, $uid, 1, 0, 0, $storeInfo);
        $storeProductAttrServices->reset();

        if (!$storeInfo['spec_type'] && $storeInfo['product_type'] != 6) {
            $productAttr = [];
            $productValue = [];
        }
        if ((int)($storeInfo['product_type'] ?? 0) === 6) {
            /** @var \app\services\product\product\StoreProductReservationServices $reservationServices */
            $reservationServices = app()->make(\app\services\product\product\StoreProductReservationServices::class);
            $storeInfo = $reservationServices->fillServiceDuration($storeInfo);
        }
        $data['productAttr'] = $productAttr;
        $data['productValue'] = $productValue;
        $data['storeInfo'] = $storeInfo;
        return $data;
    }

    /**
     * 保存或者修改门店数据
     * @param int $id
     * @param int $storeId
     * @param int $stock
     * @param array $data
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function saveStoreProduct(int $id, int $storeId, int $stock, array $data = [])
    {
        /** @var StoreProductServices $service */
        $service = app()->make(StoreProductServices::class);
        $productData = $service->get($id, ['store_name', 'image', 'sort', 'store_info', 'keyword', 'bar_code', 'cate_id', 'is_show']);
        if (!$productData) {
            throw new ValidateException('商品不存在');
        }
        $productData = $productData->toArray();
        $productInfo = $this->dao->get(['product_id' => $id, 'store_id' => $storeId]);
        if ($productInfo) {
            $productInfo->label_id = isset($data['label_id']) ? implode(',', $data['label_id']) : '';
            $productInfo->is_show = $data['is_show'] ?? 1;
            $productInfo->stock = $stock;
            $productInfo->image = $productData['image'];
            $productInfo->sort = $productData['sort'];
            $productInfo->store_name = $productData['store_name'];
            $productInfo->store_info = $productData['store_info'];
            $productInfo->keyword = $productData['keyword'];
            $productInfo->bar_code = $productData['bar_code'];
            $productInfo->cate_id = $productData['cate_id'];
            $productInfo->save();
        } else {
            $product = [];
            $product['product_id'] = $id;
            $product['label_id'] = isset($data['label_id']) ? implode(',', $data['label_id']) : '';
            $product['is_show'] = $data['is_show'] ?? 1;
            $product['store_id'] = $storeId;
            $product['stock'] = $stock;
            $product['image'] = $productData['image'];
            $product['sort'] = $productData['sort'];
            $product['store_name'] = $productData['store_name'];
            $product['store_info'] = $productData['store_info'];
            $product['keyword'] = $productData['keyword'];
            $product['bar_code'] = $productData['bar_code'];
            $product['cate_id'] = $productData['cate_id'];
            $product['add_time'] = time();
            $this->dao->save($product);
        }
        return true;
    }

    /**
     * 平台商品在门店是否存在
     * @param int $productId
     * @param int $storeId
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function isValidStoreProduct(int $productId, int $storeId)
    {
        $info = $this->dao->getOne(['id' => $productId, 'type' => 1, 'relation_id' => $storeId, 'is_del' => 0, 'is_show' => 1]);
        if ($info) {
            return $info;
        }
        return $this->dao->getOne(['pid' => $productId, 'type' => 1, 'relation_id' => $storeId, 'is_del' => 0, 'is_show' => 1]);
    }

    /**
     * 获取商品库存
     * @param int $productId
     * @param string $uniqueId
     * @return int|mixed
     */
    public function getProductStock(int $productId, int $storeId, string $uniqueId = '')
    {
        /** @var  StoreProductAttrValueServices $StoreProductAttrValue */
        $StoreProductAttrValue = app()->make(StoreProductAttrValueServices::class);
        return $uniqueId == '' ?
            $this->dao->value(['product_id' => $productId], 'stock') ?: 0
            : $StoreProductAttrValue->uniqueByStock($uniqueId);
    }

    /**
     * 回退｜扣除，门店、平台原商品库存
     * @param $order
     * @param array $cartInfo
     * @param int $platDec
     * @param int $storeDec
     * @return bool
     */
    public function regressionBranchProductStock($order, $cartInfo = [], int $platDec = 0, int $storeDec = 0, int $store_id = 0)
    {
        // 【库存铁律】旧门店库存回退旁路已停用；实物由统一退款/库存服务处理
        return true;
    }

    /**
     * 加库存减销量（已停用）
     */
    public function incProductStock(array $cartInfo, int $storeId)
    {
        throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
    }

    /**
     * 修改库存（已停用）
     */
    public function decProductStock(array $cartInfo, int $storeId, bool $dec = true)
    {
        throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
    }

    /**
     * 修改库存（已停用）
     */
    public function updataDecStock(int $num, int $productId, int $storeId, $unique, bool $dec = true)
    {
        throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
    }

    /**
     * 上下架
     * @param int $store_id
     * @param int $id
     * @param int $is_show
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setShow(int $store_id, int $id, int $is_show)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new AdminException('操作失败！');
        }
        $productIds = [$id];
        if ((int)$info['pid'] > 0) {
            $productIds[] = (int)$info['pid'];
        }
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        $catalogGuard->withComponentCatalogMutation($productIds, function (StoreCatalogWriteLease $catalogLease) use ($id, $is_show, $info) {
            $lockedInfo = $this->dao->get($id);
            if (!$lockedInfo || (int)$lockedInfo['pid'] !== (int)$info['pid']) {
                throw new AdminException('商品状态已变化，请刷新后重试');
            }
            if ($lockedInfo['pid']) {
                $productInfo = $this->dao->get($lockedInfo['pid']);
                if ($is_show && !$productInfo['is_show']) {
                    throw new AdminException('平台该商品暂未上架！');
                }
            }
            /** @var StoreCartServices $cartService */
            $cartService = app()->make(StoreCartServices::class);
            $cartService->batchUpdate([$id], ['status' => $is_show], 'product_id');
            $update = ['is_show' => $is_show];
            if ($is_show) {
                if ($lockedInfo['is_verify'] != 1) {
                    throw new AdminException('该商品暂未审核通过');
                }
                $update['auto_off_time'] = 0;
            }
            $res = $this->update($lockedInfo['id'], $update);
            /** @var StoreProductRelationServices $storeProductRelationServices */
            $storeProductRelationServices = app()->make(StoreProductRelationServices::class);
            $storeProductRelationServices->setShow([$id], (int)$is_show);
            /** @var StoreCardRelatedServices $relatedService */
            $relatedService = app()->make(StoreCardRelatedServices::class);
            $relatedService->setStatusInGuard($catalogLease, [$id], (int)$is_show);
            if (!$res) throw new AdminException('操作失败！');
        });
    }

    /**
     * 门店同步库存（已停用）
     * 【库存铁律】禁止平台库存覆盖门店余额；库存仅能通过库存管理与销售出入库变动
     * @param $ids
     * @param $storeId
     * @return mixed
     */
    public function synchStocks($ids, $storeId)
    {
        throw new ValidateException('已停用：不可同步平台库存覆盖门店，请到「库存管理」操作');
    }


    /**
     * 同步一个商品到多个门店
     * @param int $product_id
     * @param int $applicable_type
     * @param array $store_ids
     * @param int $is_sync_stock 已忽略：始终不同步实物库存
     * @param int $is_sync_show 状态同步到门店1：同平台商品状态0：同步至门店为下架状态
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function syncProductToStores(int $product_id, int $applicable_type, array $store_ids = [], int $is_sync_stock = 0, int $is_sync_show = 1)
    {
        if (!$product_id) {
            return true;
        }
        $where = ['is_del' => 0];
        //不传门店ID，默认同步至所有未删除门店
        if ($store_ids) {
            $where['id'] = $store_ids;
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $stores = $storeServices->getList($where, ['id']);
        if (!$stores) {
            return true;
        }
        $ids = array_column($stores, 'id');

        //查询目前商品已经同步的门店
        $alreadyIds = $this->dao->getColumn(['type' => 1, 'pid' => $product_id], 'relation_id');
        $deactivateProductIds = [];
        switch ($applicable_type) {
            case 0://仅平台
                $ids = [];
                $deactivateProductIds = $this->dao->getColumn(['type' => 1, 'pid' => $product_id], 'id');
                break;
            case 1://全部门店
                break;
            case 2://部分门店
                $delIds = array_merge(array_diff($alreadyIds, $ids));
                if ($delIds) {
                    $deactivateProductIds = $this->dao->getColumn([
                        'type' => 1,
                        'pid' => $product_id,
                        'relation_id' => $delIds,
                    ], 'id');
                }
                break;
        }
        if ($deactivateProductIds) {
            /** @var StoreCatalogWriteLockGuard $catalogGuard */
            $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
            $catalogGuard->withComponentCatalogMutation(
                array_merge([$product_id], $deactivateProductIds),
                function (StoreCatalogWriteLease $catalogLease) use ($deactivateProductIds) {
                    $this->dao->update(['id' => $deactivateProductIds], ['is_del' => 1, 'is_show' => 0]);
                    /** @var StoreCardRelatedServices $relatedService */
                    $relatedService = app()->make(StoreCardRelatedServices::class);
                    $relatedService->setStatusInGuard($catalogLease, $deactivateProductIds, 0);
                }
            );
        }
        if (!$ids) {
            return true;
        }
        $chunkIds = array_chunk($ids, 10, true);
        $i = 0;
        foreach ($chunkIds as $cids) {
            foreach ($cids as $store_id) {
                ProductSyncStoreJob::dispatchSece($i, 'syncProduct', [$product_id, $store_id, 0, $is_sync_stock, $is_sync_show]);
            }
			$i += 5;
        }
        return true;
    }

    /**
     * 同步门店商品
     * @param int $product_id
     * @param int $store_id
     * @param int $card_product_id
     * @param int $is_sync_stock 库存同步到门店1：同步0：门店库存为0
     * @param int $is_sync_show 状态同步到门店1：同平台商品状态0：同步至门店为下架状态
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function syncProduct(int $product_id, int $store_id, int $card_product_id = 0, int $is_sync_stock = 0, int $is_sync_show = 1)
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->syncProductAttempt(
                    $product_id,
                    $store_id,
                    $card_product_id,
                    $is_sync_stock,
                    $is_sync_show
                );
            } catch (StoreBranchProductSyncPlanChangedException $exception) {
                if ($attempt === 3) {
                    throw new AdminException('门店商品同步期间目录已变化，请稍后重试');
                }
            }
        }
        throw new AdminException('门店商品同步规划失败');
    }

    private function syncProductAttempt(int $product_id, int $store_id, int $card_product_id, int $is_sync_stock, int $is_sync_show)
    {
        // 【库存铁律】永久不同步实物库存
        $is_sync_stock = 0;
        if (!$product_id || !$store_id) {
            return true;
        }

        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        //同步正常普通商品、次卡商品、卡项商品、预约商品
        $productInfo = $productServices->get(['type' => [0, 2], 'product_type' => [0, 4, 5, 6], 'id' => $product_id]);
        if (!$productInfo) {
            return true;
        }
        $productInfo = $productInfo->toArray();
        $sourceProductSnapshot = $productInfo;
        $productInfo['pid'] = $productInfo['id'];
        $productInfo['slider_image'] = json_encode($productInfo['slider_image']);
        $productInfo['custom_form'] = json_encode($productInfo['custom_form']);
        $productInfo['specs'] = is_array($productInfo['specs']) ? json_encode($productInfo['specs']) : $productInfo['specs'];
        $applicableStoreIdUpdate = null;
        if ($productInfo['applicable_type'] == 2) {
            $applicable_store_id = $productInfo['applicable_store_id'];
            if (!is_array($applicable_store_id)) {
                $applicable_store_id = explode(',', $applicable_store_id);
            }
            $applicable_store_id[] = $store_id;
            $applicableStoreId = array_unique($applicable_store_id);
            $applicableStoreIdUpdate = $applicableStoreId;
            $productInfo['applicable_store_id'] = $applicableStoreId;
        }
        $deliveryType = is_string($productInfo['delivery_type']) ? explode(',', $productInfo['delivery_type']) : $productInfo['delivery_type'];
        $productInfo['delivery_type'] = array_diff($deliveryType, [1]);
        $storeDeliveryType = is_string($productInfo['store_delivery_type']) ? explode(',', $productInfo['store_delivery_type']) : $productInfo['store_delivery_type'];
        unset($productInfo['id'], $productInfo['sales']);
        /** @var StoreProductCategoryServices $productCategoryServices */
        $productCategoryServices = app()->make(StoreProductCategoryServices::class);
        $productInfo['store_cate_id'] = $productCategoryServices->getStoreCate($store_id, $productInfo['cate_id']);

        //关联补充信息
        $relationData = [];
        $relationData['cate_id'] = ($productInfo['cate_id'] ?? []) && is_string($productInfo['cate_id']) ? explode(',', $productInfo['cate_id']) : ($productInfo['cate_id'] ?? []);
        $relationData['store_cate_id'] = ($productInfo['store_cate_id'] ?? []) && is_string($productInfo['store_cate_id']) ? explode(',', $productInfo['store_cate_id']) : ($productInfo['store_cate_id'] ?? []);
        $relationData['brand_id'] = ($productInfo['brand_id'] ?? []) && is_string($productInfo['brand_id']) ? explode(',', $productInfo['brand_id']) : ($productInfo['brand_id'] ?? []);
        $relationData['store_label_id'] = ($productInfo['store_label_id'] ?? []) && is_string($productInfo['store_label_id']) ? explode(',', $productInfo['store_label_id']) : ($productInfo['store_label_id'] ?? []);
        $relationData['label_id'] = ($productInfo['label_id'] ?? []) && is_string($productInfo['label_id']) ? explode(',', $productInfo['label_id']) : ($productInfo['label_id'] ?? []);
        $relationData['ensure_id'] = ($productInfo['ensure_id'] ?? []) && is_string($productInfo['ensure_id']) ? explode(',', $productInfo['ensure_id']) : ($productInfo['ensure_id'] ?? []);
        $relationData['specs_id'] = ($productInfo['specs_id'] ?? []) && is_string($productInfo['specs_id']) ? explode(',', $productInfo['specs_id']) : ($productInfo['specs_id'] ?? []);
        $relationData['coupon_ids'] = ($productInfo['coupon_ids'] ?? []) && is_string($productInfo['coupon_ids']) ? explode(',', $productInfo['coupon_ids']) : ($productInfo['coupon_ids'] ?? []);
        if (isset($productInfo['store_cate_id'])) {
            $productInfo['store_cate_id'] = implode(',', $productInfo['store_cate_id']);
        }
        $where = ['product_id' => $product_id, 'type' => 0];
        /** @var StoreProductAttrServices $productAttrServices */
        $productAttrServices = app()->make(StoreProductAttrServices::class);
        $attrInfo = $productAttrServices->getProductAttr($where);
        /** @var StoreProductAttrResultServices $productAttrResultServices */
        $productAttrResultServices = app()->make(StoreProductAttrResultServices::class);
        $attrResult = $productAttrResultServices->getResult($where);
        /** @var StoreProductAttrValueServices $productAttrValueServices */
        $productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $attrValue = $productAttrValueServices->getList($where);
        /** @var StoreDescriptionServices $productDescriptionServices */
        $productDescriptionServices = app()->make(StoreDescriptionServices::class);
        $description = $productDescriptionServices->getDescription($where);
        $description = $description ?: '';
        /** @var StoreProductReservationTimeServices $productReservationTimeServices */
        $productReservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
		/** @var SystemStoreServices $systemStoreServices */
		$systemStoreServices = app()->make(SystemStoreServices::class);
		$storeInfo = $systemStoreServices->get($store_id, ['id', 'product_change_price_status']);

        $branchProductInfo = $this->dao->get(['pid' => $product_id, 'type' => 1, 'relation_id' => $store_id]);
        //存在修改
        if ($branchProductInfo) {
            $productInfo['store_cate_id'] = $branchProductInfo['store_cate_id'];
            $relationData['store_cate_id'] = $branchProductInfo['store_cate_id'];
        }
        $sourceCardRelated = [];
        $targetRefs = [];
        $explicitCardIds = $card_product_id > 0 ? [$card_product_id] : [];
        $catalogProductIds = [$product_id];
        if ($branchProductInfo) {
            $catalogProductIds[] = (int)$branchProductInfo['id'];
        }
        if ((int)$productInfo['product_type'] === 5) {
            /** @var StoreCardRelatedServices $sourceRelatedService */
            $sourceRelatedService = app()->make(StoreCardRelatedServices::class);
            $sourceCardRelated = $sourceRelatedService->getCardRelatedProduct($product_id);
            foreach ($sourceCardRelated as $row) {
                $row['cardProductId'] = $branchProductInfo ? (int)$branchProductInfo['id'] : 0;
                $targetRefs[] = $row;
            }
            if ($branchProductInfo) {
                $explicitCardIds[] = (int)$branchProductInfo['id'];
            }
        }
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        [$id, $is_new] = $catalogGuard->withCatalogMutation(
            $explicitCardIds,
            $catalogProductIds,
            $targetRefs,
            function (StoreCatalogWriteLease $catalogLease) use (
            $product_id, $branchProductInfo, &$productInfo, $store_id, $storeInfo, $attrInfo, $attrResult, $attrValue, &$description,
            $productServices, $productAttrServices, $productAttrResultServices, $productAttrValueServices, $productDescriptionServices, $productReservationTimeServices, $card_product_id, $is_sync_stock, $is_sync_show,
            $catalogGuard, &$sourceCardRelated, $applicableStoreIdUpdate, $sourceProductSnapshot, &$relationData
        ) {
            $currentProduct = $productServices->get([
                'type' => [0, 2],
                'product_type' => [0, 4, 5, 6],
                'id' => $product_id,
            ]);
            $currentProduct = $currentProduct ? $currentProduct->toArray() : [];
            $this->assertSyncSnapshotUnchanged('平台商品', $sourceProductSnapshot, $currentProduct);

            $currentBranchProductInfo = $this->dao->get([
                'pid' => $product_id,
                'type' => 1,
                'relation_id' => $store_id,
            ]);
            $plannedBranchId = $branchProductInfo ? (int)$branchProductInfo['id'] : 0;
            $currentBranchId = $currentBranchProductInfo ? (int)$currentBranchProductInfo['id'] : 0;
            if ($plannedBranchId !== $currentBranchId) {
                throw new StoreBranchProductSyncPlanChangedException('branch product changed');
            }
            $branchProductInfo = $currentBranchProductInfo;
            if ($branchProductInfo) {
                $productInfo['store_cate_id'] = $branchProductInfo['store_cate_id'];
                $relationData['store_cate_id'] = $branchProductInfo['store_cate_id'];
            }

            $where = ['product_id' => $product_id, 'type' => 0];
            $currentAttrInfo = $productAttrServices->getProductAttr($where);
            $currentAttrResult = $productAttrResultServices->getResult($where);
            $currentAttrValue = $productAttrValueServices->getList($where);
            $currentDescription = $productDescriptionServices->getDescription($where) ?: '';
            $this->assertSyncSnapshotUnchanged('平台商品规格', $attrInfo, $currentAttrInfo);
            $this->assertSyncSnapshotUnchanged('平台商品规格结果', $attrResult, $currentAttrResult);
            $this->assertSyncSnapshotUnchanged('平台商品 SKU', $attrValue, $currentAttrValue);
            $this->assertSyncSnapshotUnchanged('平台商品描述', $description, $currentDescription);
            $attrInfo = $currentAttrInfo;
            $attrResult = $currentAttrResult;
            $attrValue = $currentAttrValue;
            $description = $currentDescription;

            if ((int)$productInfo['product_type'] === 5) {
                /** @var StoreCardRelatedServices $sourceRelatedService */
                $sourceRelatedService = app()->make(StoreCardRelatedServices::class);
                $currentSourceCardRelated = $sourceRelatedService->getCardRelatedProduct($product_id);
                $this->assertSyncSnapshotUnchanged('平台卡项权益', $sourceCardRelated, $currentSourceCardRelated);
                $sourceCardRelated = $currentSourceCardRelated;
            }
            if ($applicableStoreIdUpdate !== null) {
                $this->dao->update($product_id, ['applicable_store_id' => $applicableStoreIdUpdate]);
            }
            $productInfo['is_del'] = 0;
            $productInfo['type'] = 1;
            $productInfo['relation_id'] = $store_id;
            $reservationTime = [];
            if ($branchProductInfo) {//二次同步，编辑
                $id = $branchProductInfo['id'];
                $catalogLease->assertCoversProducts([(int)$id]);
                unset($productInfo['stock'], $productInfo['is_show']);
                $res = $this->dao->update($id, $productInfo);
                if (!$res) throw new ValidateException('商品添加失败');

                $updateSuks = array_column($attrValue, 'suk');
                $oldSuks = [];
				//门店商品sku
                $oldAttrValue = $productAttrValueServices->getSkuArray(['product_id' => $id, 'type' => 0], '*', 'suk');
                if ($oldAttrValue) $oldSuks = array_column($oldAttrValue, 'suk');
                $delSuks = array_merge(array_diff($oldSuks, $updateSuks));
                $dataAll = [];
                $res1 = $res2 = $res3 = true;
                foreach ($attrValue as $item) {
                    unset($item['id'], $item['stock'], $item['sales']);
                    $item['product_id'] = $id;
                    $oldUnique = $item['unique'];
                    if ($oldSuks && in_array($item['suk'], $oldSuks) && isset($oldAttrValue[$item['suk']])) {
						//默认平台商品售价
						$price = $oldPrice = (float)$item['price'];
						if (isset($item['price_range_min']) && isset($item['price_range_max']) && $storeInfo && $storeInfo['product_change_price_status']) {//v3.3改价区间 门店有改价权限
							$oldAttrValueOne = $oldAttrValue[$item['suk']];
							//门店商品改价后售价
							$price = (float)$oldAttrValueOne['price'];
							$min = (float)$item['price_range_min'];
							$max = (float)$item['price_range_max'];
							if ($oldPrice == $min && $oldPrice == $max) {//不允许改价
								$price = $oldPrice;
							} elseif ($min && $max) {//限制区间
								if ($price < $min || $price > $max) {
									$price = $oldPrice;
								}
							} elseif (!$min && $max) {//限制最大值
								if ($price > $max) {
									$price = $oldPrice;
								}
							} elseif ($min && !$max) {//限制最小值
								if ($price < $min) {
									$price = $oldPrice;
								}
							} else {//不限制改价

							}
						}
						//重新赋值售价
						$item['price'] = $price;
                        $attrId = $oldAttrValue[$item['suk']]['id'];
                        $unique = $oldAttrValue[$item['suk']]['unique'];
                        unset($item['suk'], $item['unique']);
                        $res1 = $res1 && $productAttrValueServices->updateInGuard($catalogLease, $attrId, $item);
                    } else {
                        $unique = $productAttrServices->createAttrUnique($id, $item['suk']);
                        $item['unique'] = $unique;
                        $dataAll[] = $item;
                    }
                    if ($productInfo['product_type'] == 6) {//预约商品
                        $times = $productReservationTimeServices->getProductReservationTimes($oldUnique, $product_id, 'sku_unique,show_time,start,end,stock');
                        foreach ($times as &$time) {
                            $time['sku_unique'] = $unique;
                        }
                        $reservationTime = array_merge($reservationTime, $times);
                        unset($times, $time);
                    }
                }
                if ($delSuks) {
                    // 门店二次同步删规格前校验：禁止静默丢门店库存
                    $productAttrValueServices->assertSkusCanBeDeleted((int)$id, $delSuks, $oldAttrValue ?: [], $catalogLease);
                    $res2 = $productAttrValueServices->delInGuard($catalogLease, $id, 0, $delSuks);
                }
                if ($dataAll) {
                    $res3 = $productAttrValueServices->saveAllInGuard($catalogLease, $dataAll);
                }
                if (!$res1 || !$res2 || !$res3) {
                    throw new AdminException('商品规格信息保存失败');
                }
                $is_new = 0;
            } else {//新增
                // 【库存铁律】不同步实物库存；新门店商品强制库存 0（忽略 $is_sync_stock）
                // if (!$is_sync_stock) { unset($productInfo['stock']); }
                $productInfo['stock'] = 0;
                $productInfo['defective_stock'] = $productInfo['defective_stock'] ?? 0;
                if (!$is_sync_show) {//同步过去到门店为下架状态
                    $productInfo['is_show'] = 0;
                }
                $res = $this->dao->save($productInfo);
                if (!$res) throw new ValidateException('商品添加失败');
                $id = (int)$res->id;
                $newCardRefs = [];
                if ((int)$productInfo['product_type'] === 5) {
                    foreach ($sourceCardRelated as $row) {
                        $row['cardProductId'] = $id;
                        $newCardRefs[] = $row;
                    }
                }
                $catalogGuard->adoptCreatedProduct(
                    $catalogLease,
                    $id,
                    (int)$productInfo['product_type'],
                    $newCardRefs
                );
                if ($attrValue) {
                    foreach ($attrValue as &$value) {
                        unset($value['id'], $value['sales']);
                        // 【库存铁律】新门店 SKU 库存强制 0
                        // if (!$is_sync_stock) { unset($value['stock']); }
                        $value['stock'] = 0;
                        $value['defective_stock'] = 0;
                        $value['sum_stock'] = 0;
                        $value['product_id'] = $id;
                        $unique = $productAttrServices->createAttrUnique($id, $value['suk']);
                        if ($productInfo['product_type'] == 6) {//预约商品
                            $times = $productReservationTimeServices->getProductReservationTimes($value['unique'], $product_id, 'sku_unique,show_time,start,end,stock');
                            foreach ($times as &$time) {
                                $time['sku_unique'] = $unique;
                            }
                            $reservationTime = array_merge($reservationTime, $times);
                            unset($times, $time);
                        }
                        $value['unique'] = $unique;
                    }
                    $productAttrValueServices->saveAllInGuard($catalogLease, $attrValue);
                }
                $is_new = 1;
            }
            if ($attrInfo) {//规则数据
                foreach ($attrInfo as &$attr) {
                    unset($attr['id']);
                    $attr['product_id'] = $id;
                }
                $productAttrServices->delete(['product_id' => $id]);
                $productAttrServices->saveAll($attrInfo);
            }
            if ($reservationTime) {//预约商品时段库存
                foreach ($reservationTime as &$time) {
                    $time['product_id'] = $id;
                }
                $productReservationTimeServices->delete(['product_id' => $id]);
                $productReservationTimeServices->saveAll($reservationTime);
            }
            /** @var StoreCardRelatedServices $cardRelatedServices */
            $cardRelatedServices = app()->make(StoreCardRelatedServices::class);
            if ($productInfo['product_type'] == 5) {//卡项商品
                /** @var StoreCardRelatedServices $relatedService */
                $relatedService = app()->make(StoreCardRelatedServices::class);
                $related = $sourceCardRelated;
                $cardRelatedServices->handleCardRelatedInGuard($catalogLease, $id, $related);
            }
            //处理卡项关联商品
            if (in_array($productInfo['product_type'], [0, 6]) && $card_product_id) {
                $cardRelatedServices->updateCardProductInGuard($catalogLease, $productInfo['pid'], $card_product_id, $id);
            }
            if ($attrResult) $productAttrResultServices->setResult($attrResult, $id, 0);
            $productDescriptionServices->saveDescription($id, $description, 0);
            return [$id, $is_new];
        });

        if ((int)$productInfo['product_type'] === 5 && $sourceCardRelated) {
            ProductSyncStoreJob::dispatchDo(
                'syncCardRelatedProducts',
                [$store_id, $product_id, $sourceCardRelated, $id]
            );
        }

        //商品创建事件
        event('product.create', [$id, $productInfo, [], $is_new, [], $description, 1, $relationData]);

        $this->dao->cacheTag()->clear();
        $productAttrServices->cacheTag()->clear();
        return true;
    }

    private function assertSyncSnapshotUnchanged(string $label, $planned, $current): void
    {
        if ($this->normalizeSyncSnapshot($planned) !== $this->normalizeSyncSnapshot($current)) {
            throw new StoreBranchProductSyncPlanChangedException($label . ' changed');
        }
    }

    private function normalizeSyncSnapshot($value)
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        if (!is_array($value)) {
            return $value;
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeSyncSnapshot($item);
        }
        if (array_keys($normalized) !== range(0, count($normalized) - 1)) {
            ksort($normalized);
        }
        return $normalized;
    }

    /**
     * 删除门店、供应商同步删除商品
     * @param array $where
     * @param int $type
     * @param int $relation_id
     * @return bool
     */
    public function deleteProducts(array $where = [], int $type = 0, int $relation_id = 0)
    {
        $where['type'] = $type;
        $where['relation_id'] = $relation_id;
        $productIds = $this->dao->getColumn($where, 'id');
        if ($productIds) {
            /** @var StoreProductAttrServices $productAttrServices */
            $productAttrServices = app()->make(StoreProductAttrServices::class);
            /** @var StoreProductAttrResultServices $productAttrResultServices */
            $productAttrResultServices = app()->make(StoreProductAttrResultServices::class);
            /** @var StoreProductAttrValueServices $productAttrValueServices */
            $productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
            /** @var StoreDescriptionServices $productDescriptionServices */
            $productDescriptionServices = app()->make(StoreDescriptionServices::class);
            /** @var StoreProductRelationServices $productRelationServices */
            $productRelationServices = app()->make(StoreProductRelationServices::class);
			/** @var StoreProductCouponServices $productCoupon */
			$productCoupon = app()->make(StoreProductCouponServices::class);
			/** @var UserRelationServices $productRelation */
			$productRelation = app()->make(UserRelationServices::class);
            /** @var StoreProductReplyServices $productReplyServices */
            $productReplyServices = app()->make(StoreProductReplyServices::class);
            /** @var StoreProductReplyCommentServices $productReplyCommentServices */
            $productReplyCommentServices = app()->make(StoreProductReplyCommentServices::class);
            /** @var StoreProductReservationTimeServices $productReservationTimeServices */
            $productReservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
			/** @var StoreCardRelatedServices $relatedService */
			$relatedService = app()->make(StoreCardRelatedServices::class);
            // 校验与物理删除同一事务，保证行锁覆盖到删除；任一不满足则整批拒绝
            /** @var StoreCatalogWriteLockGuard $catalogGuard */
            $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
            $catalogGuard->withComponentCatalogMutation($productIds, function (StoreCatalogWriteLease $catalogLease) use (
                $productIds, $productAttrServices, $productAttrResultServices, $productAttrValueServices,
                $productDescriptionServices, $productRelationServices, $productCoupon, $productRelation,
                $productReservationTimeServices, $relatedService, $productReplyServices, $productReplyCommentServices
            ) {
                $productAttrValueServices->assertProductsSkusCanBeDeleted($productIds, $catalogLease);
                $idsArr = array_chunk($productIds, 100);
                foreach ($idsArr as $ids) {
                    $productAttrServices->delete(['product_id' => $ids, 'type' => 0]);
                    $productAttrResultServices->delete(['product_id' => $ids, 'type' => 0]);
                    $productAttrValueServices->deleteInGuard($catalogLease, ['product_id' => $ids, 'type' => 0]);
                    $productDescriptionServices->delete(['product_id' => $ids, 'type' => 0]);
                    $productRelationServices->delete(['product_id' => $ids]);
                    //删除商品关联优惠券数据
                    $productCoupon->delete(['product_id' => $ids]);
                    //删除商品收藏记录
                    $productRelation->delete(['relation_id' => $ids, 'category' => UserRelationServices::CATEGORY_PRODUCT]);
                    $productReservationTimeServices->delete(['product_id' => $ids]);
                    //删除卡项权益数据
                    $relatedService->deleteForProductsInGuard($catalogLease, $ids);
                    $this->dao->delete(['id' => $ids]);

                    $replyIds = $productReplyServices->getColumn([['product_id', 'IN', $ids]], 'id');
                    $replyIdsArr = array_chunk($replyIds, 100);
                    foreach ($replyIdsArr as $rids) {
                        $productReplyCommentServices->delete(['reply_id' => $rids]);
                        $productReplyServices->delete(['id' => $rids]);
                    }

                    event('product.delete', [$ids]);
                }
            });

            $this->dao->cacheTag()->clear();
            $productAttrServices->cacheTag()->clear();
        }
        return true;
    }

	/**
	 * 门店取消自主改价权限，商品恢复平台售价
	 * @param int $store_id
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function cancelProductChangePrice(int $store_id)
	{
		/** @var StoreProductAttrValueServices $productAttrValueServices */
		$productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
		//平台同步到门店商品
		$pIds = $this->dao->getColumn([['type', '=', 1], ['relation_id', '=', $store_id], ['pid', '>', 0]], 'id,pid', 'id');
		if (!$pIds) {
			return true;
		}
		$catalogProductIds = array_map('intval', array_keys($pIds));
		foreach ($pIds as $p) {
			$catalogProductIds[] = (int)($p['pid'] ?? 0);
		}
		/** @var StoreCatalogWriteLockGuard $catalogGuard */
		$catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
		$catalogGuard->withComponentCatalogMutation(
			$catalogProductIds,
			function (StoreCatalogWriteLease $catalogLease) use ($pIds, $productAttrValueServices) {
				foreach ($pIds as $id => $p) {
					// 商品与全部 SKU 已按 42 -> 43 锁定，再恢复门店售价。
					$attrValue = $productAttrValueServices->getList(['product_id' => $id, 'type' => 0]);
					$platAttrValue = $productAttrValueServices->getSkuArray([
						'product_id' => $p['pid'],
						'type' => 0,
					], '*', 'suk');
					$pProduct = $this->dao->get($p['pid'], ['id', 'price']);
					foreach ($attrValue as $item) {
						if (isset($platAttrValue[$item['suk']]['price'])) {
							$productAttrValueServices->updateInGuard(
								$catalogLease,
								$item['id'],
								['price' => $platAttrValue[$item['suk']]['price']]
							);
						}
					}
					$this->dao->update($id, ['price' => $pProduct['price']]);
				}
			}
		);
		// 清除缓存
		$this->dao->cacheTag()->clear();
		/** @var StoreProductAttrServices $attrService */
		$attrService = app()->make(StoreProductAttrServices::class);
		$attrService->cacheTag()->clear();
		return true;
	}

}
