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
//declare (strict_types=1);
namespace app\services\product\product;


use app\jobs\product\ProductBatchJob;
use app\jobs\product\ProductCouponJob;
use app\jobs\product\ProductRelationJob;
use app\services\BaseServices;
use app\dao\product\product\StoreProductDao;
use app\services\product\category\StoreProductCategoryServices;
use mohe\exceptions\AdminException;
use think\exception\ValidateException;

/**
 * 商品批量操作
 * Class StoreProductBatchProcessServices
 * @package app\services\product\product
 * @mixin StoreProductDao
 */
class StoreProductBatchProcessServices extends BaseServices
{
    /**
     * 批量处理参数
     * @var array[]
     */
    protected $data = [
        'cate_id' => [],//分类ids
        'store_label_id' => [],//商品标签
        'delivery_type' => [],//物流方式1：快递，2：门店核销，3：门店配送
        'freight' => 1,//运费设置1：包邮，2：固定运费，3：运费模版
        'postage' => 0,//邮费金额
        'temp_id' => 0,//运费模版
        'give_integral' => 0,//赠送积分
        'coupon_ids' => [],//赠送优惠券ids
        'label_id' => [],//关联用户标签ids
        'recommend' => [],//活动推荐：is_hot、is_benefit、is_new、is_best、is_good
//			'custom_form' => [],//自定义留言
        'system_form_id' => 0,//系统表单
    ];

    /**
     * StoreProductBatchProcessServices constructor.
     * @param StoreProductDao $dao
     */
    public function __construct(StoreProductDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 根据搜索条件查询ids
     * @param $where
     * @return array
     */
    public function getIdsByWhere($where)
    {
        $ids = [];
        $cateIds = [];
        if (isset($where['cate_id']) && $where['cate_id']) {
            /** @var StoreProductCategoryServices $storeCategory */
            $storeCategory = app()->make(StoreProductCategoryServices::class);
            $cateIds = $storeCategory->getColumn([['pid', 'IN', $where['cate_id']]], 'id');
        }
        if ($cateIds) {
            $where['cate_id'] = array_unique(array_merge($where['cate_id'], $cateIds));
        }
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        $dataInfo = $productService->getProductListByWhere($where, 'id');
        if ($dataInfo) {
            $ids = array_unique(array_column($dataInfo, 'id'));
        }
        return $ids;
    }

    /**
     * 商品批量操作
     * @param int $type
     * @param array $ids
     * @param array $input_data
     * @param bool $is_all
     * @param array $where
     * @return bool
     */
    public function batchProcess(int $type, array $ids, array $input_data, bool $is_all = false, array $where = [])
    {
        //全选
        if ($is_all == 1) {
            $ids = $this->getIdsByWhere($where);
        }
        $data = [];
        $isBatch = true;
        switch ($type) {
            case 1://分类
                $isBatch = false;
                $cate_id = $input_data['cate_id'] ?? 0;
                $store_cate_id = $input_data['store_cate_id'] ?? 0;
                if (!$cate_id && !$store_cate_id) throw new ValidateException('请选择分类');

                $data['cate_id'] = $cate_id;
                $data['store_cate_id'] = $store_cate_id;
                break;
            case 2://商品标签 不选默认清空标签
                $store_label_id = $input_data['store_label_id'] ?? [];
                $data['store_label_id'] = $store_label_id;
                break;
            case 3://物流设置
                if ($where['type'] == 1 && $input_data['delivery_type']) {
                    $delivery_type = $input_data['delivery_type'];
                    if ((in_array(1, $delivery_type) || in_array(3, $delivery_type))) {
                        $data['delivery_type'][] = 3;
                        if (in_array(1, $delivery_type)) {
                            $data['store_delivery_type'][] = 1;
                        }
                        if (in_array(3, $delivery_type)) {
                            $data['store_delivery_type'][] = 2;
                        }
                    }
                    if (in_array(2, $delivery_type)) $data['delivery_type'][] = 2;
                    $data['delivery_type'] = array_diff($data['delivery_type'], [1]);
                } else {
                    $data['delivery_type'] = $input_data['delivery_type'] ?? [];
                    $data['store_delivery_type'] = $input_data['store_delivery_type'] ?? [];
                }
                if (!$data['delivery_type']) throw new AdminException('请选择商品配送方式');
                if (in_array(3, $data['delivery_type']) && !$data['store_delivery_type']) throw new AdminException('请选择商品门店配送方式');
                break;
            case 4://购买即送积分、优惠券
                $isBatch = false;
                $data['give_integral'] = $input_data['give_integral'] ?? 0;
                $data['coupon_ids'] = $input_data['coupon_ids'] ?? [];
                if (!$data['give_integral'] && !$data['coupon_ids']) {
                    throw new AdminException('请输入赠送积分或选择赠送优惠券');
                }
                break;
            case 5://关联用户标签 不选默认清空标签
                $label_id = $input_data['label_id'] ?? [];
                $data['label_id'] = $label_id;
                break;
            case 6://活动推荐
                $recommend = $input_data['recommend'] ?? [];
                $data['is_hot'] = $data['is_benefit'] = $data['is_new'] = $data['is_good'] = $data['is_best'] = 0;
                if ($recommend) {
                    $arr = ['is_hot', 'is_benefit', 'is_new', 'is_good', 'is_best'];
                    foreach ($recommend as $item) {
                        if (in_array($item, $arr)) {
                            $data[$item] = 1;
                        }
                    }
                }
                break;
            case 7://自定义留言 可以为空 清空之前的设置
                $data['system_form_id'] = $input_data['system_form_id'] ?? 0;
                break;
            case 8://运费设置
                $data['freight'] = $input_data['freight'] ?? 0;
                $data['postage'] = $input_data['postage'] ?? 0;
                $data['temp_id'] = $input_data['temp_id'] ?? 0;
                if ($data['freight'] == 2 && !$data['postage']) {
                    throw new AdminException('请设置运费金额');
                }
                if ($data['freight'] == 3 && !$data['temp_id']) {
                    throw new AdminException('请选择运费模版');
                }
                if ($data['freight'] == 1) {
                    $data['temp_id'] = 0;
                    $data['postage'] = 0;
                } elseif ($data['freight'] == 2) {
                    $data['temp_id'] = 0;
                } elseif ($data['freight'] == 3) {
                    $data['postage'] = 0;
                }
                break;
            case 9://商品展示
                $data['show_type'] = $input_data['show_type'] ?? 0;
                if (!in_array($data['show_type'], [0, 1, 2])) {
                    throw new AdminException('请重新选择商品展示');
                }
                break;
            case 10://商品品牌 不选默认清空标签
                $data['brand_com'] = isset($input_data['brand_id']) && $input_data['brand_id'] ? implode(',', $input_data['brand_id']) : '';
                $data['brand_id'] = isset($input_data['brand_id']) && $input_data['brand_id'] ? end($input_data['brand_id']) : 0;
                break;
            case 11://商品放入回收站｜从回收站恢复
                $data['is_del'] = $input_data['is_del'] ?? 1;
                $isBatch = false;
                break;
            case 12://删除回收站商品
                $isBatch = false;
                break;
            case 13://强制下架
                $isBatch = false;
                $data['is_verify'] = $input_data['is_verify'] ?? 1;
                $data['refusal'] = $input_data['refusal'] ?? '';
                break;
            default:
                throw new AdminException('暂不支持该类型批操作');
        }
        //加入批量队列
        ProductBatchJob::dispatchDo('productBatch', [$type, $ids, $data, $isBatch]);
        return true;
    }

    /**
     * 执行批量修改eb_store_product
     * @param array $ids
     * @param array $data
     * @return bool
     */
    public function runBatch(array $ids, array $data, int $type = 2)
    {
        if (!$ids || !$data) {
            return false;
        }
        if (isset($data['delivery_type']) && $data['delivery_type']) {
            $data['delivery_type'] = is_array($data['delivery_type']) ? implode(',', $data['delivery_type']) : $data['delivery_type'];
        }
        if (isset($data['store_delivery_type']) && $data['store_delivery_type']) {
            $data['store_delivery_type'] = is_array($data['store_delivery_type']) ? implode(',', $data['store_delivery_type']) : $data['store_delivery_type'];
        }
        switch ($type) {
            case 2://商品标签
				if (!isset($data['store_label_id'])) {
					return true;
				}
				if (is_array($data['store_label_id'])) {
					$store_label_id = implode(',', array_unique(array_diff(array_map('intval', $data['store_label_id']), [0])));
				} else {
					$store_label_id = (int)$data['store_label_id'];
				}
				$update = ['store_label_id' => $store_label_id];
				if (isset($data['is_verify'])) {
					$update['is_verify'] = $data['is_verify'];
				}
				$this->dao->batchUpdate($ids, $update, 'id');
				foreach ($ids as $id) {
					ProductRelationJob::dispatch([$id, $data['store_label_id'], 3]);
					//门店商品
					$sIds = $this->getIdsByWhere(['pid' => $id, 'is_del' => 0]);
					if ($sIds) {
						$this->dao->batchUpdate($sIds, $update, 'id');
						foreach ($sIds as $sid) {
							ProductRelationJob::dispatch([$sid, $data['store_label_id'], 3]);
						}
					}
				}
                break;
            case 3://物流设置 仅设置普通商品 其他商品物流方式比较特殊
                $ids = $this->getIdsByWhere(['id' => $ids, 'product_type' => 0, 'is_del' => 0]);
                $this->dao->batchUpdate($ids, $data, 'id');
                foreach ($ids as $id) {
                    //门店商品
                    $sIds = $this->getIdsByWhere(['pid' => $id, 'is_del' => 0]);
                    if ($sIds) {
                        $this->dao->batchUpdate($sIds, $data, 'id');
                    }
                }
                break;
            case 5://用户标签
				if (!isset($data['label_id'])) {
					return true;
				}
                $update = ['label_id' => implode(',', $data['label_id'])];
                if (isset($data['is_verify'])) {
                    $update['is_verify'] = $data['is_verify'];
                }
                $this->dao->batchUpdate($ids, $update, 'id');
                foreach ($ids as $id) {
                    ProductRelationJob::dispatch([$id, $data['label_id'], 4]);
                    //门店商品
                    $sIds = $this->getIdsByWhere(['pid' => $id, 'is_del' => 0]);
                    if ($sIds) {
                        $this->dao->batchUpdate($sIds, $update, 'id');
                        foreach ($sIds as $sid) {
                            ProductRelationJob::dispatch([$sid, $data['label_id'], 4]);
                        }
                    }
                }
                break;
            case 6://活动推荐 用商品标签关联
                $this->dao->batchUpdate($ids, $data, 'id');
                $arr = ['is_hot' => 1, 'is_benefit' => 2, 'is_best' => 3, 'is_new' => 4, 'is_good' => 5];
                $label_ids = [];
                foreach ($data as $key => $value) {
                    $label_id = $arr[$key] ?? 0;
                    if (!$label_id || !$value) continue;
                    $label_ids[] = $label_id;
                }
                foreach ($ids as $id) {
                    ProductRelationJob::dispatch([$id, $label_ids, 3]);
                    //门店商品
                    $sIds = $this->getIdsByWhere(['pid' => $id, 'is_del' => 0]);
                    if ($sIds) {
                        foreach ($sIds as $sid) {
                            ProductRelationJob::dispatch([$sid, $label_ids, 3]);
                        }
                    }
                }
                break;
            case 10:// 商品品牌
				if (!isset($data['brand_id'])) {
					return true;
				}
                $this->dao->batchUpdate($ids, ['brand_id' => $data['brand_id'], 'brand_com' => $data['brand_com']], 'id');
                foreach ($ids as $id) {
                    ProductRelationJob::dispatch([$id, $data['brand_id'], 2]);
                    //门店商品
                    $sIds = $this->getIdsByWhere(['pid' => $id, 'is_del' => 0]);
                    if ($sIds) {
                        $this->dao->batchUpdate($sIds, ['brand_id' => $data['brand_id'], 'brand_com' => $data['brand_com']], 'id');
                        foreach ($sIds as $sid) {
                            ProductRelationJob::dispatch([$sid, $data['brand_id'], 2]);
                        }
                    }
                }
                break;
            default://
                $this->dao->batchUpdate($ids, $data, 'id');
                foreach ($ids as $id) {
                    //门店商品
                    $sIds = $this->getIdsByWhere(['pid' => $id, 'is_del' => 0]);
                    if ($sIds) {
                        $this->dao->batchUpdate($sIds, $data, 'id');
                    }
                }
                break;
        }
        //更新商品缓存
        $this->dao->cacheTag()->clear();
        return true;
    }

    /**
     * 保存商品分类
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function setPrdouctCate(int $id, array $data)
    {
        if (!$id || !$data) {
            return true;
        }
        $cate_id = $data['cate_id'] ?? [];
        $store_cate_id = $data['store_cate_id'] ?? [];
        $update = [];
        $ids = [];
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        $product = $productService->getOne(['id' => $id], 'pid,type,cate_id,store_cate_id');
        if (!$product['pid']) {
            $ids = $productService->getColumn(['pid' => $id], 'id');
        }
        if ($cate_id && !$store_cate_id) {
            $storeCateId = $product['store_cate_id'];
            $storeCateIdArr = explode(',', $storeCateId);
            $update1 = ['cate_id' => implode(',', $cate_id)];
            $update = array_merge($update, $update1);
            $relation_id = array_merge($storeCateIdArr, $cate_id);
        } else if ($store_cate_id && !$cate_id) {
            $cateId = $product['cate_id'];
            $cateIdArr = explode(',', $cateId);
            $update = ['store_cate_id' => implode(',', $store_cate_id)];
            $relation_id = array_merge($cateIdArr, $store_cate_id);
        } else {
            $update1 = ['cate_id' => implode(',', $cate_id), 'store_cate_id' => implode(',', $store_cate_id)];
            $update = array_merge($update, $update1);
            $relation_id = array_merge($cate_id, $store_cate_id);
        }

        $this->dao->update($id, $update);
        //商品分类关联
        ProductRelationJob::dispatch([$id, $relation_id, 1]);
        if ($ids) {
            $data['cate_id'] = isset($update['cate_id']) ? explode(',', $update['cate_id']) : [];
            $data['store_cate_id'] = isset($update['store_cate_id']) ? explode(',', $update['store_cate_id']) : [];
            //加入批量队列
            ProductBatchJob::dispatchSece(10, 'productBatch', [1, $ids, $data, false]);
        }
        return true;
    }

    /**
     * 保存商品赠送积分、优惠券
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function setGiveIntegralCoupon(int $id, array $data)
    {
        if (!$id || !$data) {
            return true;
        }
        $give_integral = $data['give_integral'] ?? 0;
        if ($give_integral) {
            $this->dao->update($id, ['give_integral' => $give_integral]);
        }
        $coupon_ids = $data['coupon_ids'] ?? [];
        if ($coupon_ids) {
            //商品关联优惠券
            ProductCouponJob::dispatchDo('setProductCoupon', [$id, $coupon_ids]);
        }
        return true;
    }

    /**
     * 商品放入回收站｜从回收站恢复
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function deleteProduct(int $id, array $data, int $type)
    {
        if (!$id || !$type) {
            return true;
        }
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        switch ($type) {
            case 11: //商品放入回收站｜从回收站恢复
                //删除商品检测是否有参与活动
                $productService->checkActivity($id);
                $productService->del($id, true, $data['is_del']);
                break;
            case 12: //删除回收站商品
                $productService->delProduct($id);
                break;
        }
        event('product.delete', [$id]);
        return true;
    }

    /**
     * 强制下架
     * @param int $id
     * @param array $data
     * @param int $type
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setVerifyProduct(int $id, array $data, int $type)
    {
        if (!$id || !$type || !$data) {
            return true;
        }
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        $data['is_show'] = 0;
        $productService->verify($id, $data);
        return true;
    }
}
