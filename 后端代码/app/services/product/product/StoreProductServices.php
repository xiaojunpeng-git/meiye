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
namespace app\services\product\product;

use app\dao\product\product\StoreProductDao;
use app\jobs\product\ProductRelationJob;
use app\jobs\product\ProductStockJob;
use app\jobs\product\ProductStockTips;
use app\model\product\product\StoreProduct;
use app\services\activity\discounts\StoreDiscountsProductsServices;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\promotions\StorePromotionsServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\activity\seckill\StoreSeckillTimeServices;
use app\services\BaseServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\community\CommunityServices;
use app\services\diy\DiyServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderComputedServices;
use app\services\other\Import\ImportRecordErrorServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\brand\StoreBrandServices;
use app\services\other\queue\QueueServices;
use app\services\product\ensure\StoreProductEnsureServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\product\sku\StoreProductRuleServices;
use app\services\product\shipping\ShippingTemplatesServices;
use app\services\product\sku\StoreProductVirtualServices;
use app\services\product\specs\StoreProductSpecsServices;
use app\services\store\SystemStoreServices;
use app\services\supplier\SystemSupplierServices;
use app\services\system\form\SystemFormServices;
use app\services\user\label\UserLabelServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\member\MemberCardServices;
use app\services\activity\collage\UserCollagePartakeServices;
use app\services\other\Import\ImportRecordServices;
use app\services\user\UserRelationServices;
use app\services\user\UserSearchServices;
use app\services\user\UserServices;
use app\jobs\product\ProductLogJob;
use app\services\cashier\v3\CashierV3ScopeResolver;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use mohe\services\SystemConfigService;
use mohe\traits\ServicesTrait;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Route as Url;
use think\facade\Db;

/**
 * Class StoreProductService
 * @package app\services\product\product
 * @mixin StoreProductDao
 */
class StoreProductServices extends BaseServices
{
    use OptionTrait, ServicesTrait;

    /**
     * StoreProductServices constructor.
     * @param StoreProductDao $dao
     */
    public function __construct(StoreProductDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 根据进店规则返回查询条件
     * @param int $uid
     * @return array
     */
    public function getWhereByEntryRules(int $uid = 0)
    {
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $entryRules = $storeServices->getStoreIdByEntryRules($uid);
        $storeId = $entryRules['store_id'] ?? 0;
        $is_alone = 0;
        if ($storeId) {
            $storeInfo = $storeServices->get($storeId, ['id', 'is_alone']);
            $is_alone = $storeInfo['is_alone'] ?? 0;
        }
        $shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
        $where = [];
        switch ($shop_operation_type) {
            case 1://多门店+平台模式 展示平台+供应商商品
                $where['type'] = [0, 2];
                if ($is_alone) {
                    $where['shop_operation_relation_id'] = $storeId;
                }
                break;
            case 2://单门店+平台模式  展示门店+平台+供应商商品
                if ($storeId) {
                    $where['shop_operation_relation_id'] = $storeId;
                    $where['pid'] = 0;
                } else {
                    $where['type'] = [0, 2];
                }
                break;
            case 3://单店模式 仅展示门店商品
                $where['type'] = [1];
                if ($storeId) {
                    $where['relation_id'] = $storeId;
                }
                break;
            default://默认
                $where['type'] = [0, 2];
                break;
        }
        return $where;
    }

    /**
     * 获取顶部标签
     * @param int $store_id
     * @param array $where
     * @return array[]
     */
    public function getHeader(int $store_id = 0, array $where = [])
    {
        if ($store_id || (isset($where['store_id']) && $where['store_id'])) {
            $where['type'] = 1;
            $where['relation_id'] = $store_id ?: $where['store_id'];
            unset($where['store_id']);
        } elseif (isset($where['supplier_id']) && $where['supplier_id']) {
            $where['type'] = 2;
            $where['relation_id'] = $where['supplier_id'];
            unset($where['supplier_id']);
        }
        if (!isset($where['type']) || $where['type'] == 0) {//平台商品
            $where['pid'] = 0;
        }
        //出售中的商品
        $onsale = $this->dao->getCount(['status' => 1] + $where);
        //已经售馨商品
        $outofstock = $this->dao->getCount(['status' => 4] + $where);
        //警戒库存商品
        $store_stock = sys_config('store_stock', 0);
        $policeforce = $this->dao->getCount(['status' => 5, 'store_stock' => $store_stock > 0 ? $store_stock : 2] + $where);
        //仓库中的商品
        $forsale = $this->dao->getCount(['status' => 2] + $where);
        //回收站商品
        $delete = $this->dao->getCount(['status' => 6] + $where);
        //待审核商品
        $unVerify = $this->dao->getCount(['status' => 0] + $where);
        //审核未通过商品
        $refuseVerify = $this->dao->getCount(['status' => -1] + $where);
        //强制下架商品
        $removeVerify = $this->dao->getCount(['status' => -2] + $where);

        return [
            ['type' => 1, 'name' => '销售中', 'count' => $onsale],
            ['type' => 2, 'name' => '仓库中', 'count' => $forsale],
            ['type' => 4, 'name' => '已售罄', 'count' => $outofstock],
            ['type' => 5, 'name' => '库存预警', 'count' => $policeforce],
            ['type' => 6, 'name' => '回收站', 'count' => $delete],
            ['type' => 0, 'name' => '待审核', 'count' => $unVerify],
            ['type' => -1, 'name' => '审核未通过', 'count' => $refuseVerify],
            ['type' => -2, 'name' => '强制下架', 'count' => $removeVerify]
        ];
    }

    /**
     * 获取列表
     * @param array $where
     * @param $is_move
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, $is_move = false)
    {
        $store_stock = sys_config('store_stock', 0);
        $where['store_stock'] = $store_stock > 0 ? $store_stock : 2;
        [$page, $limit] = $this->getPageValue();
        $order_string = '';
        $order_arr = ['asc', 'desc'];
        if (isset($where['sales']) && in_array($where['sales'], $order_arr)) {
            $order_string = 'sales ' . $where['sales'];
        }
        //门店不展示卡密商品
        $count = $this->dao->getCount($where);
        $list = $this->dao->getList($where, $page, $limit, $order_string);
        if ($list) {
            // 项目手工费来自收银 V3 项目业绩规则权威表，批量读取避免逐行查询。
            $laborFeeByProject = [];
            // 门店项目是平台主项目的复制行（type=1，pid=主项目ID）。
            // 手工费规则只保存在主项目规则上，因此列表必须按主项目 ID
            // 批量读取，再把结果投影回门店复制行，不能直接按门店行 ID 查。
            $projectIds = array_values(array_unique(array_filter(array_map(function ($item) {
                if ((int)($item['product_type'] ?? 0) !== 6) return 0;
                $masterId = (int)($item['pid'] ?? 0);
                return $masterId > 0 ? $masterId : (int)($item['id'] ?? 0);
            }, $list))));
            if ($projectIds) {
                try {
                    $laborFeeByProject = Db::name('cashier_v3_project_performance_rule')
                        ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                        ->whereIn('project_id', array_unique($projectIds))
                        ->column('labor_configured_unit_amount_cents', 'project_id');
                } catch (\Throwable $e) {
                    // 旧实例尚未执行规则升级时，列表仍可正常打开，手工费按 0 展示。
                    $laborFeeByProject = [];
                }
            }
            $cateIds = implode(',', array_column($list, 'cate_id'));
            /** @var StoreProductCategoryServices $categoryService */
            $categoryService = app()->make(StoreProductCategoryServices::class);
            $cateList = $categoryService->getCateParentAndChildName($cateIds);
            $supplierIds = $storeIds = [];
            foreach ($list as $value) {
                switch ($value['type']) {
                    case 0:
                        break;
                    case 1://门店
                        $storeIds[] = $value['relation_id'];
                        break;
                    case 2://供应商
                        $supplierIds[] = $value['relation_id'];
                        break;
                }
            }
            $supplierIds = array_unique($supplierIds);
            $storeIds = array_unique($storeIds);
            $supplierList = $storeList = [];
            if ($supplierIds) {
                /** @var SystemSupplierServices $supplierServices */
                $supplierServices = app()->make(SystemSupplierServices::class);
                $supplierList = $supplierServices->getColumn([['id', 'in', $supplierIds], ['is_del', '=', 0]], 'id,supplier_name', 'id');
            }
            if ($storeIds) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeList = $storeServices->getColumn([['id', 'in', $storeIds], ['is_del', '=', 0]], 'id,name', 'id');
            }
            if ($is_move) {
                $proIds = array_column(array_filter($list, function ($item) {
                    return $item['spec_type'] == 0;
                }), 'id', 'id');
                /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
                $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
                $attrValueList = $storeProductAttrValueServices->getSkuArray(['product_id' => $proIds, 'type' => 0], 'unique,cost,price,ot_price,stock,product_id', 'product_id');
            }
            /** @var UserLabelServices $userLabelServices */
            $userLabelServices = app()->make(UserLabelServices::class);
            foreach ($list as &$item) {
                $projectId = (int)($item['id'] ?? 0);
                $ruleProjectId = ((int)($item['type'] ?? 0) === 1 && (int)($item['pid'] ?? 0) > 0)
                    ? (int)$item['pid']
                    : $projectId;
                $item['labor_fee'] = (int)($item['product_type'] ?? 0) === 6
                    ? intdiv((int)($laborFeeByProject[$ruleProjectId] ?? 0), 100)
                    : 0;
                if ($item['spec_type'] == 0 && $is_move) {
                    $item['attr_value'] = $attrValueList[$item['id']] ?? [];
                }

                $item['branch_sales'] = $item['sales'] ?? 0;
                $item['branch_stock'] = $item['stock'] ?? 0;
                $item['is_show'] = $item['branch_is_show'] ?? $item['is_show'];
                $item['cate_name'] = '';
                if (isset($item['cate_id']) && $item['cate_id']) {
                    $cate_name = $categoryService->getCateName(explode(',', $item['cate_id']), $cateList);
                    if ($cate_name) {
                        $item['cate_name'] = is_array($cate_name) ? implode(',', $cate_name) : '';
                    }
                }
                if (isset($item['store_label_id']) && $item['store_label_id']) {
                    /** @var StoreProductLabelServices $storeProductLabelServices */
                    $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
                    $item['store_label_id'] = $storeProductLabelServices->getColumn([['id', 'in', $item['store_label_id']]], 'id,label_name');
                } else {
                    $item['store_label_id'] = [];
                }
                if (isset($item['label_id']) && $item['label_id']) {
                    $label_id = is_array($item['label_id']) ? $item['label_id'] : explode(',', $item['label_id']);
                    $item['label_id'] = $userLabelServices->getLabelList(['ids' => $label_id], ['id', 'label_name']);
                } else {
                    $item['label_id'] = [];
                }
                $item['stock_attr'] = $item['stock'] > 0;//库存
                $item['plate_name'] = '平台';
                switch ($item['type']) {
                    case 0:
                        $item['plate_name'] = '平台';
                        break;
                    case 1://门店
                        $item['plate_name'] = '门店：' . ($storeList[$item['relation_id']]['name'] ?? '');
                        break;
                    case 2://供应商
                        $item['plate_name'] = '供应商：' . ($supplierList[$item['relation_id']]['supplier_name'] ?? '');
                        if ($item['settle_price'] <= 0 && isset($where['type']) && $where['type'] == 2) {
                            /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
                            $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
                            $attrValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $item['id'], 'type' => 0], 'product_id,unique,settle_price', 'product_id');
                            $item['settle_price'] = min(array_column($attrValue, 'settle_price'));
                        }
                        break;
                }
            }
        }

        return compact('list', 'count');
    }

    /**
     * 设置商品上下架
     * @param $ids
     * @param $is_show
     */
    public function setShow(array $ids, int $is_show)
    {
        $storeProductIds = $this->dao->getColumn(['pid' => $ids], 'id');
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        return $catalogGuard->withComponentCatalogMutation(
            array_merge($ids, $storeProductIds ?: []),
            function (StoreCatalogWriteLease $catalogLease) use ($ids, $is_show) {
                return $this->setShowInGuard($catalogLease, $ids, $is_show);
            }
        );
    }

    public function setShowInGuard(StoreCatalogWriteLease $catalogLease, array $ids, int $is_show)
    {
        if ($is_show == 0) {
            //下架检测是否有参与活动商品
//            $this->checkActivity($ids);
        } else {
            $count = $this->dao->getCount(['ids' => $ids, 'is_del' => 1]);
            if ($count) throw new AdminException('回收站商品无法直接上架，请先恢复商品');
        }
        $storeProductIds = $this->dao->getColumn(['pid' => $ids], 'id');
        $catalogLease->assertCoversProducts(array_merge($ids, $storeProductIds ?: []));
        /** @var StoreCartServices $cartService */
        $cartService = app()->make(StoreCartServices::class);
        $cartService->batchUpdate(array_merge($ids, $storeProductIds), ['status' => $is_show], 'product_id');
        $update = ['is_show' => $is_show];
        if ($is_show) {//手动上架 清空定时下架状态
            $update['auto_off_time'] = 0;
        }
        $this->dao->batchUpdate($ids, $update);
        /** @var StoreProductRelationServices $storeProductRelationServices */
        $storeProductRelationServices = app()->make(StoreProductRelationServices::class);
        $storeProductRelationServices->setShow($ids, (int)$is_show);
        //门店商品处理
        /** @var StoreBranchProductServices $storeBranchProductServices */
        $storeBranchProductServices = app()->make(StoreBranchProductServices::class);
        $storeBranchProductServices->update(['pid' => $ids, 'type' => 1], ['is_show' => $is_show ? 1 : 0]);

        /** @var StoreCardRelatedServices $relatedService */
        $relatedService = app()->make(StoreCardRelatedServices::class);
        $relatedService->setStatusInGuard(
            $catalogLease,
            array_merge($ids, $storeProductIds ?: []),
            $is_show ? 1 : 0
        );

        event('product.status', [$ids, $is_show]);

        $this->dao->cacheTag()->clear();
        return true;

        return true;
    }

    /**
     * 队列批量上下架
     * @param array $ids
     * @param int $is_show
     * @param $redisKey
     * @param $queueId
     */
    public function setBatchShow(array $ids, int $is_show, $redisKey, $queueId)
    {
        $res = $this->dao->batchUpdate($ids, ['is_show' => $is_show]);
        /** @var StoreProductRelationServices $storeProductRelationServices */
        $storeProductRelationServices = app()->make(StoreProductRelationServices::class);
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $re = true;
        if ($is_show == 0) {
            try {
                //下架检测是否有参与活动商品
//                $re = $this->checkActivity($ids);
                //改变购物车中状态
                $storeProductRelationServices->setShow($ids, (int)$is_show);
                /** @var StoreCartServices $cartService */
                $cartService = app()->make(StoreCartServices::class);
                $cartService->changeStatus($ids, 1);
            } catch (\Throwable $e) {
                $re = false;
            }
        }
        if ($re) $storeProductRelationServices->setShow($ids, (int)$is_show);
        $queueService->doSuccessSremRedis($ids, $redisKey, $queueId['type']);
        if (!$res) {
            $queueService->addQueueFail($queueId['id'], $redisKey);
        }
        return true;
    }

    /**
     * 商品审核表单
     * @param int $id
     * @param int $is_verify
     * @return mixed
     */
    public function verifyForm(int $id, int $is_verify = 1)
    {
        $f = [];
        if ($is_verify == 1) {
            $f[] = Form::radio('is_verify', '审核状态：', 1)->options([['value' => 1, 'label' => '通过'], ['value' => -1, 'label' => '拒绝']])->appendControl(-1, [
                Form::textarea('refusal', '拒绝原因：')->required('请输入拒绝原因')]);
        } else {
            $f[] = Form::hidden('is_verify', '-2');
            $f[] = Form::textarea('refusal', '下架原因：')->required('请输入下架原因');
        }
        return create_form($is_verify == 1 ? '商品审核' : '强制下架', $f, Url::buildUrl('/product/product/set_verify/' . $id), 'post');
    }

    /**
     * 商品审核
     * @param int $id
     * @param int $is_verify
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function verify(int $id, array $data)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('商品不存在');
        }
        /** @var StoreCartServices $cartService */
        $cartService = app()->make(StoreCartServices::class);
        $status = $data['is_verify'] == 1 ? 1 : 0;
        $cartService->update(['product_id' => $id], ['status' => $status]);
        $this->dao->update($id, $data);
        $this->dao->update(['pid' => $id], $data);
        return true;
    }

    /**
     * 获取规格模板
     * @param int $type
     * @param int $relation_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRule(int $type = 0, int $relation_id = 0)
    {
        /** @var StoreProductRuleServices $storeProductRuleServices */
        $storeProductRuleServices = app()->make(StoreProductRuleServices::class);
        $list = $storeProductRuleServices->getList(['type' => $type, 'relation_id' => $relation_id])['list'] ?? [];
        foreach ($list as &$item) {
            $attr_name = $attr_value = [];
            if ($item['rule_value']) {
                $item['rule_value'] = $specs = json_decode($item['rule_value'], true);
                foreach ($item['rule_value'] as $ruleKey => $ruleItem) {
                    foreach ($ruleItem['detail'] as $valueKey => $valueItem) {
                        if (is_string($valueItem)) {
                            $item['rule_value'][$ruleKey]['detail'][$valueKey] = ['value' => $valueItem, 'pic' => ''];
                            $item['rule_value'][$ruleKey]['add_pic'] = 0;
                        }
                    }
                }
                if ($specs) {
                    foreach ($specs as $key => $value) {
                        $attr_name[] = $value['value'];
                        $attr_value[] = implode(',', $value['detail']);
                    }
                } else {
                    $attr_name[] = '';
                    $attr_value[] = '';
                }
                $item['attr_name'] = implode(',', $attr_name);
                $item['attr_value'] = $attr_value;
            }
        }
        return $list;
    }

    /**
     * 获取商品详情
     * @param int $id
     * @return array|\think\Model|null
     */
    public function getInfo(int $id)
    {
        /** @var StoreCardRelatedServices $relatedService */
        $relatedService = app()->make(StoreCardRelatedServices::class);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
        $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        /** @var StoreCouponIssueServices $storeCouponIssueServices */
        $storeCouponIssueServices = app()->make(StoreCouponIssueServices::class);
        /** @var UserLabelServices $userLabelServices */
        $userLabelServices = app()->make(UserLabelServices::class);
        $storeProductCategoryServices = app()->make(StoreProductCategoryServices::class);
        $storeBrandServices = app()->make(StoreBrandServices::class);
        $productInfo = $this->dao->get($id, ['*'], ['coupons']);
        if ($productInfo) $productInfo = $productInfo->toArray();
        else throw new ValidateException('商品不存在');
//        $data['tempList'] = $this->getTemp((int)$productInfo['type'], (int)$productInfo['relation_id']);
//        $data['cateList'] = $storeCatecoryService->cascaderList();
        $productInfo['sale_time_data'] = $productInfo['reservation_times'] = [];
        if ($productInfo['product_type'] == 6) {
            if ($productInfo['sale_time_type'] == 3) {//销售日期自定义
                $productInfo['sale_time_data'] = [date('Y-m-d', $productInfo['sale_time_start']), date('Y-m-d', $productInfo['sale_time_end'])];
            }
            $productInfo['reservation_times'] = [$productInfo['reservation_time_start'], $productInfo['reservation_time_end']];
        }
        $productInfo['related'] = [];
        if ($productInfo['product_type'] == 5) {
            $related = $relatedService->getCardRelatedProduct($id);
            $productInfo['related'] = $related ?? [];
        }
        $couponIds = $productInfo['coupons'] ? array_column($productInfo['coupons'], 'issue_coupon_id') : [];
        $is_sub = $recommend = [];
        if ($productInfo['is_sub'] == 1) array_push($is_sub, 1);
        if ($productInfo['is_vip'] == 1) array_push($is_sub, 0);
        $productInfo['is_sub'] = $is_sub;
        $productInfo['recommend'] = $recommend;
        $productInfo['price'] = floatval($productInfo['price']);
        $productInfo['postage'] = floatval($productInfo['postage']);
        $productInfo['ot_price'] = floatval($productInfo['ot_price']);
        $productInfo['vip_price'] = floatval($productInfo['vip_price']);
        $productInfo['is_limit'] = boolval($productInfo['is_limit'] ?? 0);
        $productInfo['cost'] = floatval($productInfo['cost']);
        $productInfo['brand_id'] = $productInfo['brand_com'] ? array_map('intval', explode(',', $productInfo['brand_com'])) : [];
        if ($productInfo['brand_id']) {
            $productInfo['brand_name'] = $storeBrandServices->getColumn(['id' => $productInfo['brand_id']], 'id,brand_name');
        }
        $productInfo['video_open'] = (bool)$productInfo['video_open'];
        if ($productInfo['video_link'] && strpos($productInfo['video_link'], 'http') === false) {
            $productInfo['video_link'] = sys_config('site_url') . $productInfo['video_link'];
        }
        $productInfo['coupons'] = $storeCouponIssueServices->productCouponList([['id', 'in', $couponIds]], 'title,id');
        $productInfo['cate_id'] = is_array($productInfo['cate_id']) ? $productInfo['cate_id'] : explode(',', $productInfo['cate_id']);
        if ($productInfo['cate_id']) {
            $productInfo['cate_name'] = $storeProductCategoryServices->getColumn(['id' => $productInfo['cate_id']], 'id,cate_name');
        }
        $productInfo['store_cate_id'] = is_array($productInfo['store_cate_id']) ? $productInfo['store_cate_id'] : ($productInfo['store_cate_id'] ? explode(',', $productInfo['store_cate_id']) : []);
        if ($productInfo['label_id']) {
            $label_id = is_array($productInfo['label_id']) ? $productInfo['label_id'] : explode(',', $productInfo['label_id']);
            $productInfo['label_id'] = $userLabelServices->getLabelList(['ids' => $label_id], ['id', 'label_name']);
        } else {
            $productInfo['label_id'] = [];
        }
        if ($productInfo['store_label_id']) {
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            $productInfo['store_label_id'] = $storeProductLabelServices->getColumn([['id', 'in', $productInfo['store_label_id']]], 'id,label_name');
        } else {
            $productInfo['store_label_id'] = [];
        }
        $productInfo['supplier_id'] = 0;
        if ($productInfo['type'] == 2) {
            $productInfo['supplier_id'] = $productInfo['relation_id'];
            $productInfo['supplier_name'] = app()->make(SystemSupplierServices::class)->value(['id' => $productInfo['relation_id']], 'supplier_name');
        }
        $productInfo['give_integral'] = floatval($productInfo['give_integral']);
        $productInfo['presale_time'] = $productInfo['presale_start_time'] == 0 ? [] : [date('Y-m-d H:i:s', $productInfo['presale_start_time']), date('Y-m-d H:i:s', $productInfo['presale_end_time'])];
        $productInfo['auto_on_time'] = $productInfo['is_show'] ? '' : ($productInfo['auto_on_time'] ? date('Y-m-d H:i:s', $productInfo['auto_on_time']) : '');
        $productInfo['auto_off_time'] = !$productInfo['is_show'] ? '' : ($productInfo['auto_off_time'] ? date('Y-m-d H:i:s', $productInfo['auto_off_time']) : '');
        $productInfo['description'] = $storeDescriptionServices->getDescription(['product_id' => $id, 'type' => 0]);
        //系统表单
        $productInfo['custom_form'] = $productInfo['custom_form_info'] = [];
        // 商品详情接口必须保持只读。
        // 旧逻辑在这里调用 checkProductReseultAndSaveAttr：打开编辑页时，
        // 如果默认 SKU 缺失就会开启商品目录写事务并拿目录锁。平台商品还
        // 可能已经有大量门店副本，导致“读取另一个商品”也被保存/同步任务
        // 阻塞，最终返回“商品目录正在被其他操作修改”。保存接口本身会
        // 按当前表单重新写入 SKU，因此详情读取只需在内存中提供默认值。
        $result = $storeProductAttrResultServices->getResult(['product_id' => $id, 'type' => 0]);
        $hasStructuredResult = is_array($result)
            && isset($result['value'], $result['attr'])
            && is_array($result['value'])
            && is_array($result['attr']);
        if ($productInfo['spec_type'] == 1 && $hasStructuredResult) {
            $attrInfo = $storeProductAttrValueServices->getColumn(['product_id' => $id, 'type' => 0], '*');
            foreach ($result['value'] as $k => $v) {
                if (!isset($v['is_show'])) {
                    $result['value'][$k]['is_show'] = 1;
                }
                $num = 1;
                foreach ($v['detail'] as $dv) {
                    $result['value'][$k]['value' . $num] = $dv;
                    $num++;
                }
                if (!isset($v['attr_arr'])) {
                    $result['value'][$k]['attr_arr'] = array_values($v['detail']);
                    foreach ($v['detail'] as $detailKey => $detailValue) {
                        $result['value'][$k][$detailKey] = $detailValue;
                    }
                }
                foreach ($attrInfo as $attrKey => $attrItem) {
                    if (implode(',', $result['value'][$k]['attr_arr']) == $attrKey) {
                        $result['value'][$k]['unique'] = $attrItem['unique'] ?? '';
                        $result['value'][$k]['price'] = $attrItem['price'] ?? 0;
                        $result['value'][$k]['price_range_min'] = $attrItem['price_range_min'] ?? 0;
                        $result['value'][$k]['price_range_max'] = $attrItem['price_range_max'] ?? 0;
                        $result['value'][$k]['stock'] = $attrItem['stock'] ?? 0;
                        $result['value'][$k]['cost'] = $attrItem['cost'] ?? 0;
                        $result['value'][$k]['ot_price'] = $attrItem['ot_price'] ?? 0;
                        $result['value'][$k]['stock_unit'] = $attrItem['stock_unit'] ?? '';
                        $result['value'][$k]['sale_unit'] = $attrItem['sale_unit'] ?? '';
                        $result['value'][$k]['unit_convert'] = isset($attrItem['unit_convert']) ? (float)$attrItem['unit_convert'] : 1;
                        $result['value'][$k]['decimal_scale'] = isset($attrItem['decimal_scale']) ? (int)$attrItem['decimal_scale'] : 0;
                    }
                }
            }
            foreach ($result['attr'] as $attrKey => $attrItem) {
                foreach ($attrItem['detail'] as $valueKey => $valueItem) {
                    if (is_string($valueItem)) {
                        $result['attr'][$attrKey]['detail'][$valueKey] = ['value' => $valueItem, 'pic' => ''];
                        $result['attr'][$attrKey]['add_pic'] = 0;
                    }
                }
            }
            $productInfo['items'] = $result['attr'];
            $productInfo['attrs'] = $result['value'];
            $productInfo['attr'] = ['pic' => '', 'vip_price' => 0, 'price' => 0, 'settle_price' => 0, 'cost' => 0, 'ot_price' => 0, 'stock' => 0, 'bar_code' => '', 'weight' => 0, 'volume' => 0, 'brokerage' => 0, 'brokerage_two' => 0, 'code' => '', 'write_times' => 1, 'write_valid' => 1, 'days' => 0, 'section_time' => []];
        } else {
            // 历史数据可能只有商品主表而没有规格结果。不要在 GET
            // 请求中补写数据库，降级为单规格编辑模型即可。
            $productInfo['spec_type'] = 0;
            /** @var StoreProductVirtualServices $virtualService */
            $virtualService = app()->make(StoreProductVirtualServices::class);
            /** @var StoreProductReservationTimeServices $reservationTimeServices */
            $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
            $result = $storeProductAttrValueServices->getOne(['product_id' => $id, 'type' => 0]);
            $productInfo['items'] = [];
            $productInfo['attrs'] = [];
            $productInfo['attr'] = [
                'pic' => $result['image'] ?? '',
                'vip_price' => isset($result['vip_price']) ? floatval($result['vip_price']) : 0,
                'price' => isset($result['price']) ? floatval($result['price']) : 0,
                'price_range_min' => isset($result['price_range_min']) ? floatval($result['price_range_min']) : 0,
                'price_range_max' => isset($result['price_range_max']) ? floatval($result['price_range_max']) : 0,
                'settle_price' => isset($result['settle_price']) ? floatval($result['settle_price']) : 0,
                'cost' => isset($result['cost']) ? floatval($result['cost']) : 0,
                'ot_price' => isset($result['ot_price']) ? floatval($result['ot_price']) : 0,
                'stock' => isset($result['stock']) ? floatval($result['stock']) : 0,
                'bar_code' => isset($result['bar_code']) ? $result['bar_code'] : '',
                'code' => isset($result['code']) ? $result['code'] : '',
                'virtual_list' => $productInfo['product_type'] == 1 ? $virtualService->getArr($result['unique'] ?? '', $id) : [],
                'reservation_time_data' => $productInfo['product_type'] == 6 ? $reservationTimeServices->getProductReservationTimes($result['unique'] ?? '', $id) : [],
                'weight' => isset($result['weight']) ? floatval($result['weight']) : 0,
                'volume' => isset($result['volume']) ? floatval($result['volume']) : 0,
                'stock_unit' => $result['stock_unit'] ?? '',
                'sale_unit' => $result['sale_unit'] ?? '',
                'unit_convert' => isset($result['unit_convert']) ? floatval($result['unit_convert']) : 1,
                'decimal_scale' => isset($result['decimal_scale']) ? intval($result['decimal_scale']) : 0,
                'brokerage' => isset($result['brokerage']) ? floatval($result['brokerage']) : 0,
                'brokerage_two' => isset($result['brokerage_two']) ? floatval($result['brokerage_two']) : 0,
                'disk_info' => $result['disk_info'] ?? [],
                'write_times' => intval($result['write_times'] ?? 1),//核销次数
                'write_valid' => intval($result['write_valid'] ?? 1),//核销时效类型
                'days' => intval($result['write_days'] ?? $result['days'] ?? 0),//购买后：N天有效
                'section_time' => [($result['write_start'] ?? '') ? date('Y-m-d H:i:s', $result['write_start'] ?? '') : '', ($result['write_end'] ?? '') ? date('Y-m-d H:i:s', $result['write_end'] ?? '') : ''],//[核销开始时间,核销结束时间]
            ];
        }
        if ($productInfo['activity']) {
            $activity = explode(',', $productInfo['activity']);
            foreach ($activity as $k => $v) {
                if ($v == 1) {
                    $activity[$k] = '秒杀';
                } elseif ($v == 2) {
                    $activity[$k] = '砍价';
                } elseif ($v == 3) {
                    $activity[$k] = '拼团';
                } elseif ($v == 0) {
                    $activity[$k] = '默认';
                }
            }
            $productInfo['activity'] = $activity;
        } else {
            $productInfo['activity'] = ['默认', '秒杀', '砍价', '拼团'];
        }
        //推荐产品
        $recommend_list = [];
        if ($productInfo['recommend_list'] != '') {
            $productInfo['recommend_list'] = explode(',', $productInfo['recommend_list']);
            if (count($productInfo['recommend_list'])) {
                $images = $this->getColumn([['id', 'in', $productInfo['recommend_list']]], 'image', 'id');
                foreach ($productInfo['recommend_list'] as $item) {
                    $recommend_list[] = [
                        'product_id' => $item,
                        'image' => $images[$item] ?? ''
                    ];
                }
            }
        }
        $productInfo['recommend_list'] = $recommend_list;
        //适用门店
        $productInfo['stores'] = [];
        if (isset($productInfo['applicable_type']) && ($productInfo['applicable_type'] == 1 || ($productInfo['applicable_type'] == 2 && isset($productInfo['applicable_store_id']) && $productInfo['applicable_store_id']))) {//查询门店信息
            $where = ['is_del' => 0];
            if ($productInfo['applicable_type'] == 2) {
                $store_ids = is_array($productInfo['applicable_store_id']) ? $productInfo['applicable_store_id'] : explode(',', $productInfo['applicable_store_id']);
                $where['id'] = $store_ids;
            }
            $field = ['id', 'cate_id', 'name', 'phone', 'address', 'detailed_address', 'image', 'is_show', 'day_time', 'day_start', 'day_end'];
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $storeData = $storeServices->getStoreList($where, $field, '', '', 0, ['categoryName']);
            $productInfo['stores'] = $storeData['list'] ?? [];
        }
        /** @var ProductGiftServices $productGiftServices */
        $productGiftServices = app()->make(ProductGiftServices::class);
        $productInfo['send_config'] = $productGiftServices->getConfig($id);
        $data['productInfo'] = $productInfo;
        return $data;
    }

    /**
     * 获取运费模板列表
     * @param int $type
     * @param int $relation_id
     * @return array
     */
    public function getTemp(int $type = 0, int $relation_id = 0)
    {
        /** @var ShippingTemplatesServices $shippingTemplatesServices */
        $shippingTemplatesServices = app()->make(ShippingTemplatesServices::class);
        return $shippingTemplatesServices->getSelectList(['type' => $type, 'relation_id' => $relation_id]);
    }

    /**
     * 获取商品规格
     * @param array $data
     * @param int $id
     * @param int $type
     * @param int $plat_type
     * @param int $relation_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAttr(array $data, int $id, int $type, int $plat_type = 0, int $relation_id = 0)
    {
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $productInfo = [];
        if ($id) {
            $productInfo = $this->dao->get($id);
            if (!$productInfo) {
                throw new ValidateException('商品不存在');
            }
        }
        /** @var StoreProductVirtualServices $virtualService */
        $virtualService = app()->make(StoreProductVirtualServices::class);
        /** @var StoreProductReservationTimeServices $reservationTimeServices */
        $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
        $attr = $data['attrs'];
        $product_type = $productInfo['product_type'] ?? $data['product_type'] ?? 0; //商品类型
        $productType = $productInfo['type'] ?? 0; //商品所属
        foreach ($attr as $k => $v) {
            foreach ($v['detail'] as $key => $item) {
                if (isset($item['value'])) {
                    $attr[$k]['detail'][$key] = $item['value'];
                }
            }
        }
        $value = attr_format($attr)[1];
        $valueNew = [];
        $count = 0;
        foreach ($value as $key => $item) {
            $detail = $item['detail'];
            foreach ($detail as $v => $d) {
                $detail[$v] = trim($d);
            }
//            sort($item['detail'], SORT_STRING);
            $suk = implode(',', $detail);
            $types = 1;
            if ($id) {
                $sukValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $id, 'type' => 0, 'suk' => $suk], 'unique,bar_code,code,cost,price,settle_price,ot_price,stock,image as pic,weight,volume,brokerage,brokerage_two,vip_price,disk_info', 'suk');
                if (!$sukValue) {
                    if ($type == 0) $types = 0; //编辑商品时，将没有规格的数据不生成默认值
                    $sukValue[$suk]['pic'] = '';
                    $sukValue[$suk]['price'] = 0;
                    $sukValue[$suk]['settle_price'] = 0;
                    $sukValue[$suk]['cost'] = 0;
                    $sukValue[$suk]['ot_price'] = 0;
                    $sukValue[$suk]['stock'] = 0;
                    $sukValue[$suk]['bar_code'] = '';
                    $sukValue[$suk]['code'] = '';
                    $sukValue[$suk]['weight'] = 0;
                    $sukValue[$suk]['volume'] = 0;
                    $sukValue[$suk]['brokerage'] = 0;
                    $sukValue[$suk]['brokerage_two'] = 0;
                    switch ($product_type) {
                        case 1://卡密
                            $sukValue[$suk]['virtual_list'] = [];
                            break;
                        case 2://优惠券
                            $sukValue[$suk]['coupon_id'] = 0;
                            break;
                        case 3://虚拟商品
                            break;
                        case 4://次卡商品
                        case 5://卡项商品
                            $sukValue[$suk]['write_times'] = 1;//核销次数
                            $sukValue[$suk]['write_valid'] = 1;//核销时效类型
                            $sukValue[$suk]['days'] = 0;//购买后：N天有效
                            $sukValue[$suk]['section_time'] = [];//[核销开始时间,核销结束时间]
                            break;
                        case 6://预约商品
                            $sukValue[$suk]['reservation_time_data'] = [];
                            break;
                    }
                }
            } else {
                $sukValue[$suk]['pic'] = '';
                $sukValue[$suk]['price'] = 0;
                $sukValue[$suk]['settle_price'] = 0;
                $sukValue[$suk]['cost'] = 0;
                $sukValue[$suk]['ot_price'] = 0;
                $sukValue[$suk]['stock'] = 0;
                $sukValue[$suk]['bar_code'] = '';
                $sukValue[$suk]['code'] = '';
                $sukValue[$suk]['weight'] = 0;
                $sukValue[$suk]['volume'] = 0;
                $sukValue[$suk]['brokerage'] = 0;
                $sukValue[$suk]['brokerage_two'] = 0;
                switch ($product_type) {
                    case 1://卡密
                        $sukValue[$suk]['virtual_list'] = [];
                        break;
                    case 2://优惠券
                        $sukValue[$suk]['coupon_id'] = 0;
                        break;
                    case 3://虚拟商品
                        break;
                    case 4://次卡商品
                    case 5://卡项商品
                        $sukValue[$suk]['write_times'] = 1;//核销次数
                        $sukValue[$suk]['write_valid'] = 1;//核销时效类型
                        $sukValue[$suk]['days'] = 0;//购买后：N天有效
                        $sukValue[$suk]['section_time'] = [];//[核销开始时间,核销结束时间]
                        break;
                    case 6://预约商品
                        $sukValue[$suk]['reservation_time_data'] = [];
                        break;
                }
            }
            if ($types) { //编辑商品时，将没有规格的数据不生成默认值
                foreach (array_keys($detail) as $k => $title) {
                    $header[$k]['title'] = $title;
                    $header[$k]['align'] = 'center';
                    $header[$k]['minWidth'] = 130;
                }
                $values = '';
                foreach (array_values($detail) as $k => $v) {
                    $valueNew[$count]['value' . ($k + 1)] = $v;
                    $header[$k]['slot'] = 'value' . ($k + 1);
                    $values .= $v . ',';
                }
                $valueNew[$count]['values'] = substr($values, 0, strlen($values) - 1);
                $valueNew[$count]['detail'] = $detail;
                $valueNew[$count]['pic'] = $sukValue[$suk]['pic'] ?? '';
                $valueNew[$count]['price'] = $sukValue[$suk]['price'] ? floatval($sukValue[$suk]['price']) : 0;
                $valueNew[$count]['settle_price'] = $sukValue[$suk]['settle_price'] ? floatval($sukValue[$suk]['settle_price']) : 0;
                $valueNew[$count]['cost'] = $sukValue[$suk]['cost'] ? floatval($sukValue[$suk]['cost']) : 0;
                $valueNew[$count]['ot_price'] = isset($sukValue[$suk]['ot_price']) ? floatval($sukValue[$suk]['ot_price']) : 0;
                $valueNew[$count]['vip_price'] = isset($sukValue[$suk]['vip_price']) ? floatval($sukValue[$suk]['vip_price']) : 0;
                $valueNew[$count]['stock'] = $sukValue[$suk]['stock'] ? intval($sukValue[$suk]['stock']) : 0;
                $valueNew[$count]['bar_code'] = $sukValue[$suk]['bar_code'] ?? '';
                $valueNew[$count]['code'] = $sukValue[$suk]['code'] ?? '';
                $valueNew[$count]['weight'] = floatval($sukValue[$suk]['weight']) ?? 0;
                $valueNew[$count]['volume'] = floatval($sukValue[$suk]['volume']) ?? 0;
                $valueNew[$count]['brokerage'] = floatval($sukValue[$suk]['brokerage']) ?? 0;
                $valueNew[$count]['brokerage_two'] = floatval($sukValue[$suk]['brokerage_two']) ?? 0;
                switch ($product_type) {
                    case 1://卡密
                        if (!$type) {
                            $valueNew[$count]['virtual_list'] = isset($sukValue[$suk]['unique']) && $sukValue[$suk]['unique'] ? $virtualService->getArr($sukValue[$suk]['unique'], $id) : [];
                            $valueNew[$count]['disk_info'] = $sukValue[$suk]['disk_info'] ?? '';
                        }
                        break;
                    case 2://优惠券
                        break;
                    case 3://虚拟商品
                        break;
                    case 4://次卡商品
                    case 5://卡项商品
                        $valueNew[$count]['write_times'] = intval($sukValue[$suk]['write_times'] ?? 1);//核销次数
                        $valueNew[$count]['write_valid'] = intval($sukValue[$suk]['write_valid'] ?? 1);//核销时效类型
                        $valueNew[$count]['days'] = intval($sukValue[$suk]['write_days'] ?? $sukValue[$suk]['days'] ?? 0);//购买后：N天有效
                        $start = $sukValue[$suk]['write_start'] ?? '';
                        $end = $sukValue[$suk]['write_end'] ?? '';
                        $valueNew[$count]['section_time'] = [$start ? date('Y-m-d H:i:s', $start) : '', $end ? date('Y-m-d H:i:s', $end) : ''];//[核销开始时间,核销结束时间]
                        break;
                    case 6://预约商品
                        if (!$type) {
                            $valueNew[$count]['reservation_time_data'] = isset($sukValue[$suk]['unique']) && $sukValue[$suk]['unique'] ? $reservationTimeServices->getProductReservationTimes($sukValue[$suk]['unique'], $id) : [];
                        }
                        break;
                }
                $count++;
            }
        }
        $header[] = ['title' => '图片', 'slot' => 'pic', 'align' => 'center', 'minWidth' => 80];
        if ($plat_type == 2) {//供应商
            $header[] = ['title' => '结算价', 'slot' => 'settle_price', 'align' => 'center', 'minWidth' => 120];
        } else if ($plat_type == 0) {
            if ($productType == 2) {
                $header[] = ['title' => '结算价', 'slot' => 'settle_price', 'align' => 'center', 'minWidth' => 120];
            }
            $header[] = ['title' => '售价', 'slot' => 'price', 'align' => 'center', 'minWidth' => 120];
            $header[] = ['title' => '成本价', 'slot' => 'cost', 'align' => 'center', 'minWidth' => 140];
            $header[] = ['title' => '划线价', 'slot' => 'ot_price', 'align' => 'center', 'minWidth' => 140];
        } else {//供应商不展示成本价
            $header[] = ['title' => '售价', 'slot' => 'price', 'align' => 'center', 'minWidth' => 120];
            $header[] = ['title' => '成本价', 'slot' => 'cost', 'align' => 'center', 'minWidth' => 140];
            $header[] = ['title' => '划线价', 'slot' => 'ot_price', 'align' => 'center', 'minWidth' => 140];
        }
//        $header[] = ['title' => '会员价', 'slot' => 'vip_price', 'align' => 'center', 'minWidth' => 140];
        $header[] = ['title' => '库存', 'slot' => 'stock', 'align' => 'center', 'minWidth' => 140];
        $header[] = ['title' => '商品条形码', 'slot' => 'bar_code', 'align' => 'center', 'minWidth' => 140];
        $header[] = ['title' => '商品编码', 'slot' => 'code', 'align' => 'center', 'minWidth' => 140];
        switch ($product_type) {
            case 0:
                $header[] = ['title' => '重量(KG)', 'slot' => 'weight', 'align' => 'center', 'minWidth' => 140];
                $header[] = ['title' => '体积(m³)', 'slot' => 'volume', 'align' => 'center', 'minWidth' => 140];
                break;
            case 1://卡密
                $header[] = ['title' => '卡密商品', 'slot' => 'fictitious', 'align' => 'center', 'minWidth' => 140];
                break;
            case 2://优惠券
                break;
            case 3://虚拟商品
                break;
            case 4://次卡商品
                break;
            default:
                break;
        }
        $header[] = ['title' => '操作', 'slot' => 'action', 'align' => 'center', 'minWidth' => 70];
        return ['attr' => $attr, 'value' => $valueNew, 'header' => $header, 'product_type' => $product_type];
    }

    /**
     * SPU
     * @return string
     */
    public function createSpu()
    {
        mt_srand();
        return substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8) . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * New cashier catalog writes use whole RMB. This validation deliberately
     * rejects rather than rounds, and never rewrites historic decimal rows.
     */
    private function wholeYuanMoney($value, string $label): int
    {
        $raw = is_int($value) || is_float($value) || is_string($value) ? trim((string)$value) : '';
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.0+)?$/D', $raw) !== 1) {
            throw new AdminException($label . '必须填写整数金额');
        }
        $integerPart = explode('.', $raw, 2)[0];
        if (strlen($integerPart) > 10 || (strlen($integerPart) === 10 && strcmp($integerPart, '9999999999') === 1)) {
            throw new AdminException($label . '超出允许范围');
        }
        return (int)$integerPart;
    }

    private function normalizeSkuWholeYuanMoney(array $item, array $data): array
    {
        if (!in_array((int)($data['product_type'] ?? -1), [0, 4, 5, 6], true)) {
            return $item;
        }

        $isVipEnabled = !empty($data['is_vip']);
        $isCustomBrokerageEnabled = !empty($data['is_brokerage']) && !empty($data['is_sub']);
        if (!$isVipEnabled) {
            $item['vip_price'] = 0;
        }
        if (!$isCustomBrokerageEnabled) {
            $item['brokerage'] = 0;
            $item['brokerage_two'] = 0;
        }

        $moneyLabels = [
            'price' => '售价',
            'ot_price' => '划线价',
            'settle_price' => '结算价',
            'cost' => '成本价',
        ];
        if ($isVipEnabled) {
            $moneyLabels['vip_price'] = '会员价';
        }
        if ($isCustomBrokerageEnabled) {
            $moneyLabels['brokerage'] = '一级返佣';
            $moneyLabels['brokerage_two'] = '二级返佣';
        }

        $skuLabel = trim((string)($item['suk'] ?? '默认')) ?: '默认';
        foreach ($moneyLabels as $field => $moneyLabel) {
            if (array_key_exists($field, $item)) {
                $item[$field] = $this->wholeYuanMoney(
                    $item[$field],
                    '规格【' . $skuLabel . '】的' . $moneyLabel
                );
            }
        }

        if ((int)($data['level_type'] ?? 1) === 2 && isset($item['level_price']) && is_array($item['level_price'])) {
            foreach ($item['level_price'] as $index => $levelPrice) {
                $levelLabel = trim((string)($levelPrice['name'] ?? $levelPrice['level_name'] ?? '等级会员价')) ?: '等级会员价';
                $price = $levelPrice['price'] ?? $levelPrice['inputPrice'] ?? null;
                $item['level_price'][$index]['price'] = $this->wholeYuanMoney(
                    $price,
                    '规格【' . $skuLabel . '】的' . $levelLabel
                );
                if (array_key_exists('inputPrice', $levelPrice)) {
                    $item['level_price'][$index]['inputPrice'] = $item['level_price'][$index]['price'];
                }
            }
        }

        return $item;
    }

    private function buildDefaultReservationTimeData(string $startTime, string $endTime, int $interval): array
    {
        $interval = $interval > 0 ? $interval : 60;
        $startTimestamp = strtotime(date('Y-m-d') . ' ' . ($startTime ?: '10:00'));
        $endTimestamp = strtotime(date('Y-m-d') . ' ' . ($endTime ?: '22:00'));
        if (!$startTimestamp || !$endTimestamp || $endTimestamp <= $startTimestamp) {
            $startTimestamp = strtotime(date('Y-m-d') . ' 10:00');
            $endTimestamp = strtotime(date('Y-m-d') . ' 22:00');
        }

        $result = [];
        for ($cursor = $startTimestamp; $cursor < $endTimestamp; $cursor += $interval * 60) {
            $slotEnd = min($cursor + ($interval * 60), $endTimestamp);
            if ($slotEnd <= $cursor) {
                break;
            }
            $result[] = [
                'show_time' => date('H:i', $cursor) . '-' . date('H:i', $slotEnd),
                'start' => date('H:i', $cursor),
                'end' => date('H:i', $slotEnd),
                'stock' => 0,
            ];
        }
        return $result;
    }

    /**
     * 新增编辑商品
     * @param int $id
     * @param array $data
     * @param int $type
     * @param int $relation_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function saveData(int $id, array $data, int $type = 0, int $relation_id = 0, int $adminId = 0)
    {
        $productType = (int)($data['product_type'] ?? 0);
        if ($productType === 5) {
            $data['unit_name'] = '张';
            $data['is_inventory'] = 0;
            $data['allow_negative_stock'] = 1;
            $data['salon_stock_enabled'] = 0;
        } elseif ($productType === 6) {
            $data['unit_name'] = '次';
            $data['is_inventory'] = 0;
            $data['allow_negative_stock'] = 1;
            $data['salon_stock_enabled'] = 0;
        }
        if (count($data['cate_id']) < 1) throw new AdminException('请选择商品分类');
        if (!$data['store_name']) throw new AdminException('请输入商品名称');
        if (count($data['slider_image']) < 1) throw new AdminException('请上传商品轮播图');
        if (in_array($data['product_type'], [4, 5, 6])) {//次卡 卡项 预约都是仅到店自提
            $data['delivery_type'] = [2];
            $data['store_delivery_type'] = [];
        } elseif (in_array($data['product_type'], [1, 2, 3])) {//次卡 卡项 预约都是仅到店自提
            $data['delivery_type'] = [];
            $data['store_delivery_type'] = [];
        }
        if ($data['product_type'] == 0 && isset($data['delivery_type']) && count($data['delivery_type']) < 1) throw new AdminException('请选择商品配送方式');
        if (in_array($data['product_type'], [1, 2, 3, 4, 5, 6])) {//除普通商品外，都免运费
            $data['freight'] = 1;
            $data['temp_id'] = 0;
            $data['postage'] = 0;
        } else {
            $data['is_support_refund'] = 1;
            if ($data['freight'] == 1) {
                $data['temp_id'] = 0;
                $data['postage'] = 0;
            } elseif ($data['freight'] == 2) {
                $data['temp_id'] = 0;
            } elseif ($data['freight'] == 3) {
                $data['postage'] = 0;
            }
            if ($data['freight'] == 2 && !$data['postage']) {
                throw new AdminException('请设置运费金额');
            }
            if ($data['freight'] == 3 && !$data['temp_id']) {
                throw new AdminException('请选择运费模版');
            }
        }
        // 开启ERP后商品编码验证
        $isOpen = sys_config('erp_open');
        if ($isOpen && $data['product_type'] == 0 && empty($data['code'])) {
            throw new AdminException('请输入商品编码');
        }
        //预约商品
        if ($data['product_type'] == 6) {
            /** @var StoreProductReservationServices $reservationServices */
            $reservationServices = app()->make(StoreProductReservationServices::class);
            $data = $reservationServices->validateData($data);
        }
        /** @var StoreCardRuleServices $cardRuleServices */
        $cardRuleServices = app()->make(StoreCardRuleServices::class);
        if ($id > 0 && $type === 1 && (int)($data['product_type'] ?? 0) === 5) {
            $existingProduct = $this->dao->get($id, ['id', 'pid', 'product_type']);
            if ($existingProduct && (int)$existingProduct['pid'] > 0) {
                $existingDefinition = $this->getInfo($id)['productInfo'] ?? [];
                $data = $cardRuleServices->preserveLockedDefinition($data, $existingDefinition);
            }
        }
        $data = $cardRuleServices->normalizeForSave($data);
        if ($data['product_type'] == 5) {
            if (!$data['related']) throw new AdminException('请选择关联商品');
            if ($data['card_cover'] == 1 && !$data['card_cover_image']) {
                $data['card_cover_image'] = sys_config('site_url') . '/statics/images/card_cover_image.png';
            }
            if ($data['card_cover'] == 2 && !$data['card_cover_color']) throw new AdminException('请选择卡项封面颜色');
        }
        $detail = $data['spec_type'] == 0 ? [$data['attr']] : $data['attrs'];
        $attr = $data['items'];
        $coupon_ids = $data['coupon_ids'];
        $is_copy = (int)($data['is_copy'] ?? 0);
        $createRequestKey = trim((string)($data['create_request_key'] ?? ''));
        unset($data['create_request_key']);
        // 非项目强制清零增项服务时长
        if ((int)($data['product_type'] ?? 0) !== 6) {
            $data['addon_service_duration'] = 0;
        } else {
            $data['addon_service_duration'] = max(0, (int)($data['addon_service_duration'] ?? 0));
        }
        //关联补充信息
        $relationData = [];
        $relationData['cate_id'] = $data['cate_id'] ?? [];
        if (isset($data['store_cate_id'])) {
            $relationData['store_cate_id'] = $data['store_cate_id'] ?? [];
        }
        $relationData['brand_id'] = $data['brand_id'] ?? [];
        $relationData['store_label_id'] = $data['store_label_id'] ?? [];
        $relationData['label_id'] = $data['label_id'] ?? [];
        $relationData['ensure_id'] = $data['ensure_id'] ?? [];
        $relationData['specs_id'] = $data['specs_id'] ?? [];
        $relationData['coupon_ids'] = $data['coupon_ids'] ?? [];
        $relationData['is_sync_stock'] = 0;// 【库存铁律】不同步实物库存；忽略前端传值
        // $relationData['is_sync_stock'] = $data['is_sync_stock'] ?? 1;
        $relationData['is_sync_show'] = $data['is_sync_show'] ?? 1;

        $description = $data['description'];
        $data['type'] = $type;
        $data['relation_id'] = $relation_id;
        $supplier_id = $data['supplier_id'] ?? 0;
        if ($supplier_id) {
            $data['type'] = 2;
            $data['relation_id'] = $supplier_id;
        }
        if ($data['type'] == 0 || $type == 0) {//平台商品不需要审核
            $data['is_verify'] = 1;
        }
        //视频
        if ($data['video_link'] && strpos($data['video_link'], 'http') === false) {
            $data['video_link'] = sys_config('site_url') . $data['video_link'];
        }
        //品牌
        $data['brand_com'] = $data['brand_id'] ? implode(',', $data['brand_id']) : '';
        $data['brand_id'] = $data['brand_id'] ? end($data['brand_id']) : 0;
//        $data['is_vip'] = $type == 0 && in_array(0, $data['is_sub']) ? 1 : 0;
//        $data['is_sub'] = in_array(1, $data['is_sub']) ? 1 : 0;
        $data['product_type'] = intval($data['product_type']);
        $data['is_vip_product'] = intval($type == 0 && $data['is_vip_product']);
        if ($type == 0) {
            $data['is_presale_product'] = intval($data['is_presale_product']);
            $data['presale_start_time'] = $data['is_presale_product'] ? strtotime($data['presale_time'][0]) : 0;
            $data['presale_end_time'] = $data['is_presale_product'] ? strtotime($data['presale_time'][1]) : 0;
            $data['presale_status'] = intval($data['presale_status']);
            if ($data['presale_start_time'] && $data['presale_start_time'] < time()) {
                throw new AdminException('预售开始时间不能小于当前时间');
            }
            if ($data['presale_end_time'] && $data['presale_end_time'] < time()) {
                throw new AdminException('预售结束时间不能小于当前时间');
            }
        }
        $data['auto_on_time'] = $data['auto_on_time'] ? strtotime($data['auto_on_time']) : 0;
        $data['auto_off_time'] = $data['auto_off_time'] ? strtotime($data['auto_off_time']) : 0;
        if ($data['is_show'] == 2) {//定时上架
            if (!$data['auto_on_time']) {
                throw new AdminException('请选择定时上架时间');
            }
            $data['is_show'] = 0;
        }
        $data['is_limit'] = intval($data['is_limit']);
        if (!$data['is_limit']) {
            $data['limit_type'] = 0;
            $data['limit_num'] = 0;
        } else {
            if (!in_array($data['limit_type'], [1, 2])) throw new AdminException('请选择限购类型');
            if ($data['limit_num'] <= 0) throw new AdminException('限购数量不能小于1');
        }
        $data['custom_form'] = json_encode($data['custom_form']);
        $storeLabelId = $data['store_label_id'];
        if ($data['store_label_id']) {
            $data['store_label_id'] = is_array($data['store_label_id']) ? implode(',', $data['store_label_id']) : $data['store_label_id'];
        } else {
            $data['store_label_id'] = '';
        }
        if ($data['ensure_id']) {
            $data['ensure_id'] = is_array($data['ensure_id']) ? implode(',', $data['ensure_id']) : $data['ensure_id'];
        } else {
            $data['ensure_id'] = '';
        }
        if (!$data['specs_id'] && !$data['specs']) {
            $data['specs'] = '';
        }
        if ($data['specs']) {
            $specs = [];
            if (is_array($data['specs'])) {
                /** @var StoreProductSpecsServices $storeProductSpecsServices */
                $storeProductSpecsServices = app()->make(StoreProductSpecsServices::class);
                foreach ($data['specs'] as $item) {
                    $specs[] = $storeProductSpecsServices->checkSpecsData($item);
                }
                $data['specs'] = json_encode($specs);
            }
        } else {
            $data['specs'] = '';
        }
        if ($data['spec_type'] == 0) {
            $singleSpecName = '规格';
            if ((int)$data['product_type'] === 0) {
                $singleSpecName = trim((string)($data['single_spec_name'] ?? '规格'));
                if ($singleSpecName === '') {
                    throw new AdminException('请输入规格名称');
                }
                if (mb_strlen($singleSpecName) > 30) {
                    throw new AdminException('规格名称不能超过30个字符');
                }
            }
            $attr = [
                [
                    'value' => $singleSpecName,
                    'detailValue' => '',
                    'attrHidden' => '',
                    'detail' => ['默认']
                ]
            ];
            $detail[0]['suk'] = '默认';
            $detail[0]['detail'] = [$singleSpecName => '默认'];
            $data['default_sku'] = '';
        }
        foreach ($detail as &$item) {
            //默认规格
            if ($data['spec_type'] == 1) {
                if (isset($item['is_default_select']) && $item['is_default_select'] == 1) {
                    $data['default_sku'] = implode(",", $item['attr_arr']);
                }
            }
            if (!isset($item['price'])) $item['price'] = 0;
            if (!isset($item['ot_price'])) $item['ot_price'] = 0;
            if (!isset($item['settle_price'])) $item['settle_price'] = 0;
            $item = $this->normalizeSkuWholeYuanMoney($item, $data);
            if ((int)$data['product_type'] === 5) {
                $item['stock'] = 0;
            }
            //默认sku图片
            if (!$item['pic']) {
                $item['pic'] = $data['slider_image'][0] ?? '';
            }
            if ($isOpen && $data['product_type'] == 0 && (!isset($item['code']) || !$item['code'])) {
                throw new AdminException('请输入【' . ($item['values'] ?? '默认') . '】商品编码');
            }
            if ($type == 2 && !$item['settle_price']) {
                throw new AdminException('请输入结算价');
            }
            if (isset($item['price']) && $item['price'] > 0 && $type == 2 && $item['settle_price'] > $item['price']) {
                throw new AdminException('结算价不能超过商品售价');
            }
            $item['product_type'] = $data['product_type'];
            if (isset($data['is_vip'])) {
                if (!isset($item['vip_price']) || !$data['is_vip']) {
                    $item['vip_price'] = 0;
                }
            }
            if (isset($data['is_sub'])) {
                if (!isset($item['brokerage']) || !$data['is_sub']) {
                    $item['brokerage'] = 0;
                }
                if (!isset($item['brokerage_two']) || !$data['is_sub']) {
                    $item['brokerage_two'] = 0;
                }
            }

            if (($item['brokerage'] + $item['brokerage_two']) > $item['price']) {
                throw new AdminException('一二级返佣相加不能大于商品售价');
            }
            if (in_array($data['product_type'], [4, 5])) {//验证次卡商品数据
                if ((!isset($item['write_times']) || !$item['write_times']) && $data['product_type'] == 4) {
                    throw new AdminException('请输入核销次数');
                }
                if (!isset($item['write_valid'])) {
                    throw new AdminException('请选择核销时效类型');
                }
                switch ($item['write_valid']) {
                    case 1://永久
                        $item['days'] = 0;
                        $item['section_time'] = [0, 0];
                        break;
                    case 2://购买后n天
                        $item['section_time'] = [0, 0];
                        if (!isset($item['days']) || !$item['days']) {
                            throw new AdminException('填写时效天数');
                        }
                        break;
                    case 3://固定时间
                        $item['days'] = 0;
                        if (!isset($item['section_time']) || !$item['section_time'] || !is_array($item['section_time']) || count($item['section_time']) != 2) {
                            throw new AdminException('请选择固定有效时间段或时间格式错误');
                        }
                        [$start, $end] = $item['section_time'];
                        $data['start_time'] = $start ? strtotime($start) : 0;
                        $data['end_time'] = $end ? strtotime($end) : 0;
                        if ($data['start_time'] && $data['end_time'] && $data['end_time'] <= $data['start_time']) {
                            throw new AdminException('请重新选择：结束时间必须大于开始时间');
                        }
                        break;
                    default:
                        throw new AdminException('请选择核销时效类型');
                        break;
                }
            } elseif ($data['product_type'] == 6) {//验证预约商品数据
                if (!isset($item['reservation_time_data']) || !$item['reservation_time_data']) {
                    $item['reservation_time_data'] = $this->buildDefaultReservationTimeData(
                        (string)($data['reservation_time_start'] ?? '10:00'),
                        (string)($data['reservation_time_end'] ?? '22:00'),
                        (int)($data['reservation_time_interval'] ?? 60)
                    );
                }
                if (!$item['reservation_time_data']) {
                    throw new AdminException('预约商品：请选择确认时段划分');
                }
                $item['stock'] = array_sum(array_column($item['reservation_time_data'], 'stock'));
            }
        }
        foreach ($data['activity'] as $k => $v) {
            if ($v == '秒杀') {
                $data['activity'][$k] = 1;
            } elseif ($v == '砍价') {
                $data['activity'][$k] = 2;
            } elseif ($v == '拼团') {
                $data['activity'][$k] = 3;
            } else {
                $data['activity'][$k] = 0;
            }
        }
        $data['activity'] = implode(',', $data['activity']);
        $data['recommend_list'] = count($data['recommend_list']) ? implode(',', $data['recommend_list']) : '';
        $data['price'] = min(array_column($detail, 'price'));
        $data['ot_price'] = min(array_column($detail, 'ot_price'));
        $settleArr = array_column($detail, 'settle_price');
        $data['settle_price'] = $settleArr ? min($settleArr) : 0;
        $costArr = array_column($detail, 'cost');
        $data['cost'] = $costArr ? min($costArr) : 0;
        if (!$data['cost']) {
            $data['cost'] = 0;
        }
        $data['cate_id'] = implode(',', $data['cate_id']);
        if (isset($data['store_cate_id'])) {
            $data['store_cate_id'] = implode(',', $data['store_cate_id']);
        }
        $data['label_id'] = implode(',', $data['label_id']);
        $data['image'] = $data['slider_image'][0];//封面图
        $slider_image = $data['slider_image'];
        $data['slider_image'] = json_encode($data['slider_image']);

        /** @var \app\services\product\inventory\StockQtyValidateServices $qtyValidate */
        $qtyValidate = app()->make(\app\services\product\inventory\StockQtyValidateServices::class);
        $resolvedIsInventoryEarly = ((int)$data['product_type'] === 0) ? (int)($data['is_inventory'] ?? 1) : 0;
        $salonEnabledEarly = ($resolvedIsInventoryEarly === 1) ? (int)($data['salon_stock_enabled'] ?? 0) : 0;
        $decimalScale = $qtyValidate->resolveScale((int)$data['product_type'], $resolvedIsInventoryEarly, $salonEnabledEarly);
        $unitName = mb_substr(trim((string)($data['unit_name'] ?? '件')) ?: '件', 0, 32);
        $initialSkuQty = [];
        // 产品：强制 sale_unit/decimal_scale；真实新建提取初始库存后 SKU 先写 0；复制强制库存 0
        if ((int)$data['product_type'] === 0) {
            foreach ($detail as $idx => $row) {
                $stockUnit = trim((string)($row['stock_unit'] ?? ''));
                $detail[$idx]['stock_unit'] = mb_substr($stockUnit !== '' ? $stockUnit : $unitName, 0, 32);
                $detail[$idx]['sale_unit'] = $unitName;
                $detail[$idx]['decimal_scale'] = $decimalScale;
                if ($salonEnabledEarly === 1) {
                    $uc = $row['unit_convert'] ?? 1;
                    if ($uc === '' || $uc === null || !is_numeric($uc)
                        || (float)$uc != (int)$uc
                        || (int)$uc < 1 || (int)$uc > 99) {
                        throw new AdminException('销售单位换算数必须为1～99的整数');
                    }
                    $detail[$idx]['unit_convert'] = (int)$uc;
                } elseif (!isset($row['unit_convert']) || $row['unit_convert'] === '' || !is_numeric($row['unit_convert']) || (float)$row['unit_convert'] <= 0) {
                    $detail[$idx]['unit_convert'] = 1;
                } else {
                    // 非院装：历史换算数只读保留；新填非法时回落为 1，不强制 1～99 阻断
                    $uc = (float)$row['unit_convert'];
                    $detail[$idx]['unit_convert'] = ($uc == (int)$uc && (int)$uc >= 1 && (int)$uc <= 99) ? (int)$uc : $uc;
                }
                $goodsLabel = (string)($data['store_name'] ?? '') . (isset($row['suk']) && $row['suk'] !== '' ? ('/' . $row['suk']) : '');
                if (!$id && $is_copy === 0 && $resolvedIsInventoryEarly === 1) {
                    $qty = $qtyValidate->assertQty($row['stock'] ?? 0, $decimalScale, $goodsLabel);
                    if (bccomp($qty, '0', 4) > 0) {
                        $initialSkuQty[$idx] = $qty;
                    }
                }
                if (!$id) {
                    // 新建/复制：SKU 余额一律先 0，初始库存走入库单
                    $detail[$idx]['stock'] = 0;
                    $detail[$idx]['sum_stock'] = 0;
                    $detail[$idx]['defective_stock'] = 0;
                    $detail[$idx]['old_stock'] = 0;
                    unset($detail[$idx]['inventory'], $detail[$idx]['pm']);
                }
            }
            if ($data['spec_type'] == 0) {
                $data['attr'] = $detail[0];
            } else {
                $data['attrs'] = $detail;
            }
        }

        $data['stock'] = array_sum(array_map('floatval', array_column($detail, 'stock')));
        $stock = $detail ? min(array_map('floatval', array_column($detail, 'stock'))) : 0;
        //是否售罄
        $data['is_sold'] = $stock ? 0 : 1;
        unset($data['supplier_id'], $data['is_copy'], $data['description'], $data['coupon_ids'], $data['items'], $data['attrs'], $data['recommend'], $data['is_sync_stock'], $data['is_sync_show'], $data['single_spec_name']);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        /** @var StoreProductCouponServices $storeProductCouponServices */
        $storeProductCouponServices = app()->make(StoreProductCouponServices::class);
        /** @var StoreDiscountsProductsServices $storeDiscountProduct */
        $storeDiscountProduct = app()->make(StoreDiscountsProductsServices::class);
        /** @var StoreCardRelatedServices $relatedService */
        $relatedService = app()->make(StoreCardRelatedServices::class);
        /** @var ProductCreateIdempotentServices $createIdempotentServices */
        $createIdempotentServices = app()->make(ProductCreateIdempotentServices::class);

        //同一链接不多次保存
        if (!$id && $data['soure_link']) {
            $productInfo = $this->dao->getOne(['soure_link' => $data['soure_link'], 'is_del' => 0], 'id');
            if ($productInfo) $id = (int)$productInfo['id'];
        }
        // 创建请求幂等：已成功创建过则直接返回
        if (!$id && $createRequestKey !== '') {
            $exist = $createIdempotentServices->findExisting((int)$data['type'], (int)$data['relation_id'], $createRequestKey);
            if ($exist) {
                return ['product_id' => (int)$exist['product_id'], 'duplicated' => true];
            }
        }
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        $targetRefs = [];
        $catalogProductIds = $id ? [$id] : [];
        if ((int)$data['product_type'] === 5) {
            foreach ((array)($data['related'] ?? []) as $relatedRow) {
                if ($id) {
                    $relatedRow['cardProductId'] = $id;
                }
                $targetRefs[] = $relatedRow;
            }
        }
        if ($id && (int)$data['type'] === 0) {
            $catalogProductIds = array_merge(
                $catalogProductIds,
                $this->dao->getColumn(['pid' => $id], 'id') ?: []
            );
        }
        $catalogWrite = function (StoreCatalogWriteLease $catalogLease) use ($id, $data, $description, $storeDescriptionServices, $storeProductAttrServices, $storeProductCouponServices, $detail, $attr, $coupon_ids, $storeDiscountProduct, $slider_image, $relatedService, $adminId, $is_copy, $initialSkuQty, $createRequestKey, $createIdempotentServices, $resolvedIsInventoryEarly, $catalogGuard) {

            if ($id) {
                //上下架处理
                $this->setShowInGuard($catalogLease, [$id], $data['is_show']);
                $oldInfo = $this->get($id)->toArray();
                if ($oldInfo['product_type'] != $data['product_type']) {
                    throw new AdminException('商品类型不能切换！');
                }
                //修改不改变商品来源
                if ($oldInfo['type'] == 1) {
                    $data['type'] = $oldInfo['type'];
                    $data['relation_id'] = $oldInfo['relation_id'];
                }
                unset($data['sales']);
                $res = $this->dao->update($id, $data);
                if (!$res) throw new AdminException('修改失败');
                // 平台预约商品：服务时长立即同步到已下发门店商品（不依赖异步队列）
                if ((int)($oldInfo['type'] ?? 0) === 0 && (int)($data['product_type'] ?? 0) === 6) {
                    $this->dao->update(['pid' => $id], [
                        'project_service_duration' => (int)($data['project_service_duration'] ?? 0),
                        'addon_service_duration' => (int)($data['addon_service_duration'] ?? 0),
                    ]);
                }
                // 修改优惠套餐商品的运费模版id
                $storeDiscountProduct->update(['product_id' => $id], ['temp_id' => $data['temp_id']]);
                if (!empty($coupon_ids)) {
                    $storeProductCouponServices->setCoupon($id, $coupon_ids);
                } else {
                    $storeProductCouponServices->delete(['product_id' => $id]);
                }
                if ($oldInfo['type'] == 1 && !$oldInfo['pid'] && $data['is_verify'] < 1) {
                    /** @var StoreCartServices $cartService */
                    $cartService = app()->make(StoreCartServices::class);
                    $cartService->batchUpdate([$id], ['status' => 0], 'product_id');
                }
                if ($data['is_verify'] == 1 && $data['is_show'] == 1 && $data['is_sold'] == 0) {
                    $status = 1;
                } else {
                    $status = 0;
                }
                $relatedService->setStatusInGuard($catalogLease, [$id], $status);
                $is_new = 1;
            } else {
                //默认评分
                $data['star'] = config('admin.product_default_star');
                $data['add_time'] = time();
                $data['code_path'] = '';
                $data['spu'] = $this->createSpu();
                $res = $this->dao->save($data);
                if (!$res) throw new AdminException('添加失败');
                $id = (int)$res->id;
                $newTargetRefs = [];
                if ((int)$data['product_type'] === 5) {
                    foreach ((array)($data['related'] ?? []) as $relatedRow) {
                        $relatedRow['cardProductId'] = $id;
                        $newTargetRefs[] = $relatedRow;
                    }
                }
                $catalogGuard->adoptCreatedProduct(
                    $catalogLease,
                    $id,
                    (int)$data['product_type'],
                    $newTargetRefs
                );
                if (!empty($coupon_ids)) $storeProductCouponServices->setCoupon($id, $coupon_ids);
                $is_new = 0;
            }
            //商品详情
            $storeDescriptionServices->saveDescription($id, $description);

            $skuList = $storeProductAttrServices->validateProductAttr($attr, $detail, $id);
            foreach ($skuList['valueGroup'] as &$item) {
                if (!isset($item['sum_stock']) || !$item['sum_stock']) $item['sum_stock'] = $item['stock'] ?? 0;
            }

            $proudctVipPrice = 0;
            $detailTemp = array_column($skuList['valueGroup'], 'vip_price');
            if ($detailTemp) {
                $proudctVipPrice = min($detailTemp);
                $this->dao->update($id, ['vip_price' => $proudctVipPrice]);
            }

            $valueGroup = $storeProductAttrServices->saveProductAttrInGuard($catalogLease, $skuList, $id);
            if (!$valueGroup) throw new AdminException('添加失败！');
            if ($data['product_type'] == 1) {//卡密商品
                /** @var StoreProductVirtualServices $productVirtual */
                $productVirtual = app()->make(StoreProductVirtualServices::class);
                $productVirtual->saveProductVirtual($id, $valueGroup);
            } elseif ($data['product_type'] == 6) {//预约商品
                /** @var StoreProductReservationTimeServices $productReservationTime */
                $productReservationTime = app()->make(StoreProductReservationTimeServices::class);
                $productReservationTime->setProductReservationTime($id, $valueGroup);
            } elseif ($data['product_type'] == 5) {//卡项商品
                /** @var StoreCardRelatedServices $cardRelatedServices */
                $cardRelatedServices = app()->make(StoreCardRelatedServices::class);
                $cardRelatedServices->handleCardRelatedInGuard($catalogLease, $id, $data['related']);
            }
            if (in_array($data['product_type'], [0, 3, 5]) && isset($skuList['stockData'])) {
                // 库存改造：商品资料保存不再因库存差额生成出入库单；库存仅由库存业务变更。
            }
            // 真实新建产品：SKU 已按 0 落库后，同一事务生成一张 order_type=6 初始入库单
            if ($is_new === 0 && (int)$is_copy === 0 && (int)$data['product_type'] === 0 && $resolvedIsInventoryEarly === 1 && $initialSkuQty) {
                $inProductDetail = [];
                $skuRows = array_values(is_array($valueGroup) ? $valueGroup : $valueGroup->toArray());
                foreach ($skuRows as $idx => $skuRow) {
                    if (!isset($initialSkuQty[$idx])) {
                        continue;
                    }
                    $inProductDetail[] = [
                        'product_id' => $id,
                        'unique' => $skuRow['unique'] ?? '',
                        'stock' => $initialSkuQty[$idx],
                        'defective_stock' => 0,
                    ];
                }
                if ($inProductDetail) {
                    /** @var \app\services\product\inventory\StoreProductStockOrderServices $stockOrderServices */
                    $stockOrderServices = app()->make(\app\services\product\inventory\StoreProductStockOrderServices::class);
                    $stockOrderServices->saveData(1, [
                        'order_type' => 6,
                        'stock_time' => time(),
                        'remark' => '商品创建初始库存',
                        'import_key' => 'product_init:' . (int)$data['type'] . ':' . (int)$data['relation_id'] . ':' . $id,
                        'in_product_detail' => $inProductDetail,
                    ], (int)$data['type'], (int)$data['relation_id'], (int)$adminId, true, false);
                    // 入账后重读 SKU 汇总
                    $valueGroup = app()->make(\app\services\product\sku\StoreProductAttrValueServices::class)
                        ->getSkuArray(['product_id' => $id, 'type' => 0], '*', 'unique');
                    $valueGroup = array_values($valueGroup ?: []);
                }
            }
            //修改商品库存汇总：新建保持 SKU 写入值；编辑时由 attr 保存逻辑保留原库存后汇总
            $attrStockArr = array_column($valueGroup, 'stock');
            if (!$attrStockArr) {
                $attrStockArr = [0];
            }
            // 仅产品(product_type=0)参与库存；院装耗材开关须同时满足 product_type=0 && is_inventory=1，否则强制置 0
            $resolvedIsInventory = ((int)$data['product_type'] === 0) ? (int)($data['is_inventory'] ?? 1) : 0;
            $this->dao->update($id, [
                'stock' => array_sum(array_map('floatval', $attrStockArr)),
                'is_sold' => min(array_map('floatval', $attrStockArr)) > 0 ? 0 : 1,
                'is_inventory' => $resolvedIsInventory,
                'allow_negative_stock' => ((int)$data['product_type'] === 0) ? (int)($data['allow_negative_stock'] ?? 1) : 1,
                'salon_stock_enabled' => ($resolvedIsInventory === 1) ? (int)($data['salon_stock_enabled'] ?? 0) : 0,
            ]);
            if ($is_new === 0 && $createRequestKey !== '') {
                $createIdempotentServices->save((int)$data['type'], (int)$data['relation_id'], $createRequestKey, (int)$id);
            }
            return [$skuList, $id, $is_new, $data];
        };
        if ($id) {
            [$skuList, $id, $is_new, $data] = $catalogGuard->withCatalogMutation(
                [],
                $catalogProductIds,
                $targetRefs,
                $catalogWrite
            );
        } else {
            [$skuList, $id, $is_new, $data] = $catalogGuard->withNewProductCreation($catalogWrite, $targetRefs);
        }
        //事件（商品+初始入库全部提交成功后）
        event('product.create', [$id, $data, $skuList, $is_new, $slider_image, $description, $is_copy, $relationData]);
        //清空缓存
        $this->dao->cacheTag()->clear();
        $storeProductAttrServices->cacheTag()->clear();
        return ['product_id' => (int)$id, 'duplicated' => false];
    }

    /**
     * 放入回收站
     * @param int $id
     * @return string
     */
    public function del(int $id, bool $is_batch = false, int $is_del = 0)
    {
        if (!$id) throw new AdminException('参数不正确');
        $productInfo = $this->dao->get($id);
        if (!$productInfo) throw new AdminException('商品数据不存在');
        $msg = '';
        $data = $update = [];
        if ($is_batch) {
            if ($is_del) {
                $data['is_del'] = 1;
                $data['is_show'] = 0;
                $update = ['is_del' => 1];
                $msg = '成功移到回收站';
            } else {
                $data['is_del'] = 0;
                $data['is_show'] = 0;
                $update = ['is_del' => 0];
                $msg = '成功恢复商品';
            }
        } else {
            if ($productInfo['is_del'] == 1) {
                $data['is_del'] = 0;
                $data['is_show'] = 0;
                $update = ['is_del' => 0];
                $msg = '成功恢复商品';
            } else {
                $data['is_del'] = 1;
                $data['is_show'] = 0;
                $update = ['is_del' => 1];
                $msg = '成功移到回收站';
            }
        }
        $data['is_verify'] = 1;
        $catalogProductIds = [$id];
        if ((int)$productInfo['type'] === 0) {
            $catalogProductIds = array_merge(
                $catalogProductIds,
                $this->dao->getColumn(['pid' => $id], 'id') ?: []
            );
        }
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        $catalogGuard->withComponentCatalogMutation($catalogProductIds, function (StoreCatalogWriteLease $catalogLease) use ($id, $data, $productInfo, $update) {
            $where['id'] = $id;
            $ids = [];
            //门店商品处理
            switch ($productInfo['type']) {
                case 0://平台商品
                    $this->dao->update(['pid' => $id], $update);
                    $ids = $this->dao->getColumn(['pid' => $id], 'id', 'id');
                    break;
                case 1://门店商品
                    /** @var SystemStoreServices $storeServices */
                    $storeServices = app()->make(SystemStoreServices::class);
                    $storeInfo = $storeServices->getStoreInfo((int)$productInfo['relation_id']);
                    $data['is_verify'] = 0;
                    //门店开启免审
                    if (isset($storeInfo['product_verify_status']) && $storeInfo['product_verify_status']) {
                        $data['is_verify'] = 1;
                    }
                    $ids = [$id];
                    $where['pid'] = 0;
                    break;
                case 2://供应商
                    $data['is_verify'] = 0;
                    $ids = [$id];
                    break;
            }
            $res = $this->dao->update($where, $data);
            if (!$res) throw new AdminException($productInfo['is_del'] == 1 ? '恢复失败,请稍候再试!' : '删除失败,请稍候再试!');
            /** @var StoreCardRelatedServices $relatedService */
            $relatedService = app()->make(StoreCardRelatedServices::class);
            $relatedService->setStatusInGuard($catalogLease, $ids, 0);
        });
        return $msg;
    }

    /**
     * 删除商品数据
     * @param int $id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function delProduct(int $id)
    {
        if (!$id) throw new AdminException('参数不正确');
        $productInfo = $this->dao->get($id);
        if (!$productInfo) throw new AdminException('商品数据不存在');
        //查询门店商品
        $productIds = $this->dao->getColumn(['pid' => $id], 'id');
        $ids = [$id];
        if ($productIds) {
            $ids = array_merge($ids, $productIds);
        }
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
        /** @var StoreProductReplyServices $productReply */
        $productReply = app()->make(StoreProductReplyServices::class);
        /** @var StoreProductReplyCommentServices $productReplyCommentServices */
        $productReplyCommentServices = app()->make(StoreProductReplyCommentServices::class);
        /** @var StoreProductReservationTimeServices $productReservationTime */
        $productReservationTime = app()->make(StoreProductReservationTimeServices::class);
        /** @var StoreCardRelatedServices $relatedService */
        $relatedService = app()->make(StoreCardRelatedServices::class);
        // 永久删除前统一校验（含门店子商品）；禁止静默丢库存；失败必须抛出
        // 校验与物理删除同一事务，保证行锁覆盖到删除
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        $catalogGuard->withComponentCatalogMutation($ids, function (StoreCatalogWriteLease $catalogLease) use (
            $ids, $productAttrServices, $productAttrResultServices, $productAttrValueServices,
            $productDescriptionServices, $productRelationServices, $productCoupon, $productRelation,
            $productReply, $productReplyCommentServices, $productReservationTime, $relatedService
        ) {
            $productAttrValueServices->assertProductsSkusCanBeDeleted($ids, $catalogLease);
            foreach ($ids as $id) {
                $where = ['product_id' => $id, 'type' => 0];
                //清除规格表数据
                $productAttrServices->delete($where);
                //清除规格表数据
                $productAttrResultServices->delete($where);
                //删除商品sku
                $productAttrValueServices->deleteInGuard($catalogLease, $where);
                //删除商品详情
                $productDescriptionServices->delete($where);
                //删除商品关联数据
                $productRelationServices->delete(['product_id' => $id]);
                //删除商品关联优惠券数据
                $productCoupon->delete(['product_id' => $id]);
                //删除商品收藏记录
                $productRelation->delete(['relation_id' => $id, 'category' => UserRelationServices::CATEGORY_PRODUCT]);
                //删除商品的评论
                $replyIds = $productReply->getColumn(['product_id' => $id], 'id');
                $replyIdsArr = array_chunk($replyIds, 100);
                foreach ($replyIdsArr as $rids) {
                    $productReplyCommentServices->delete(['reply_id' => $rids]);
                    $productReply->delete(['id' => $rids]);
                }
                //删除预约商品时段库存数据
                $productReservationTime->delete(['product_id' => $id]);
                //删除卡项权益数据
                $relatedService->deleteForProductsInGuard($catalogLease, [$id]);
                //删除商品
                $this->dao->delete($id);

                event('product.delete', [$id]);
            }
        });
        $this->dao->cacheTag()->clear();
        $productAttrServices->cacheTag()->clear();
        return true;
    }

    /**
     * 获取选择的商品列表
     * @param array $where
     * @param bool $isStock
     * @param int $limit
     * @return array
     */
    public function searchList(array $where, bool $isStock = false, int $limit = 0)
    {
        $store_stock = sys_config('store_stock');
        $where['store_stock'] = $store_stock > 0 ? $store_stock : 2;
        $data = $this->getProductList($where, $isStock, $limit, ['attrValue', 'descriptions']);
        if ($data['list']) {
            $supplierIds = $storeIds = [];
            foreach ($data['list'] as $value) {
                switch ($value['type']) {
                    case 0:
                        break;
                    case 1://门店
                        $storeIds[] = $value['relation_id'];
                        break;
                    case 2://供应商
                        $supplierIds[] = $value['relation_id'];
                        break;
                }
            }
            $supplierIds = array_unique($supplierIds);
            $storeIds = array_unique($storeIds);
            $supplierList = $storeList = [];
            if ($supplierIds) {
                /** @var SystemSupplierServices $supplierServices */
                $supplierServices = app()->make(SystemSupplierServices::class);
                $supplierList = $supplierServices->getColumn([['id', 'in', $supplierIds], ['is_del', '=', 0]], 'id,supplier_name', 'id');
            }
            if ($storeIds) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeList = $storeServices->getColumn([['id', 'in', $storeIds], ['is_del', '=', 0]], 'id,name', 'id');
            }
            $cateIds = implode(',', array_column($data['list'], 'cate_id'));
            /** @var StoreProductCategoryServices $categoryService */
            $categoryService = app()->make(StoreProductCategoryServices::class);
            $cateList = $categoryService->getCateParentAndChildName($cateIds);
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            foreach ($data['list'] as &$item) {
                $item['cate_name'] = '';
                if (isset($item['cate_id']) && $item['cate_id']) {
                    $cate_ids = explode(',', $item['cate_id']);
                    $cate_name = $categoryService->getCateName($cate_ids, $cateList);
                    if ($cate_name) {
                        $item['cate_name'] = is_array($cate_name) ? implode(',', $cate_name) : '';
                    }
                }
                if (isset($item['brand_id']) && $item['brand_id']) {
                    $item['brand_name'] = app()->make(StoreBrandServices::class)->value(['id' => $item['brand_id']], 'brand_name');
                } else {
                    $item['brand_name'] = '';
                }
                $item['give_integral'] = floatval($item['give_integral']);
                $item['price'] = floatval($item['price']);
                $item['vip_price'] = floatval($item['vip_price']);
                $item['ot_price'] = floatval($item['ot_price']);
                $item['postage'] = floatval($item['postage']);
                $item['cost'] = floatval($item['cost']);
                $item['delivery_type'] = is_string($item['delivery_type']) ? explode(',', $item['delivery_type']) : $item['delivery_type'];
                $item['store_label'] = '';
                if ($item['store_label_id']) {
                    $storeLabelList = $storeProductLabelServices->getColumn([['relation_id', '=', 0], ['type', '=', 0], ['id', 'IN', $item['store_label_id']]], 'id,label_name');
                    $item['store_label'] = $storeLabelList ? implode(',', array_column($storeLabelList, 'label_name')) : '';
                }
                $item['plate_name'] = '平台';
                switch ($item['type']) {
                    case 0:
                        $item['plate_name'] = '平台';
                        break;
                    case 1://门店
                        $item['plate_name'] = '门店：' . ($storeList[$item['relation_id']]['name'] ?? '');
                        break;
                    case 2://供应商
                        $item['plate_name'] = '供应商：' . ($supplierList[$item['relation_id']]['supplier_name'] ?? '');
                        if ($item['settle_price'] <= 0 && isset($where['type']) && $where['type'] == 2) {
                            /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
                            $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
                            $attrValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $item['id'], 'type' => 0], 'product_id,unique,settle_price', 'product_id');
                            $item['settle_price'] = min(array_column($attrValue, 'settle_price'));
                        }
                        break;
                }
            }
        }
        return $data;
    }

    /**
     * 后台获取商品列表展示
     * @param array $where
     * @param bool $isStock
     * @param int $limit
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductList(array $where, bool $isStock = true, int $limit = 0, array $with = ['attrValue'])
    {
        $field = ['*'];
        if ($isStock) {
            $prefix = Config::get('database.connections.' . Config::get('database.default') . '.prefix');
            $field = [
                '*',
                '(SELECT count(*) FROM `' . $prefix . 'user_relation` WHERE `relation_id` = `' . $prefix . 'store_product`.`id` AND `type` = \'collect\') as collect',
                '(SELECT count(*) FROM `' . $prefix . 'user_relation` WHERE `relation_id` = `' . $prefix . 'store_product`.`id` AND `type` = \'like\') as likes',
                '(SELECT SUM(stock) FROM `' . $prefix . 'store_product_attr_value` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `type` = 0) as stock',
                '(SELECT SUM(defective_stock) FROM `' . $prefix . 'store_product_attr_value` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `type` = 0) as defective_stock',
//                '(SELECT SUM(sales) FROM `' . $prefix . 'store_product_attr_value` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `type` = 0) as sales',
                '(SELECT count(*) FROM `' . $prefix . 'store_visit` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `product_type` = \'product\') as visitor',
            ];
        }
        if ($limit) {
            [$page] = $this->getPageValue();
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getSearchList($where, $page, $limit, $field, '', $with);
        $count = $this->dao->getCount($where);
        return compact('count', 'list');
    }

    /**
     * 获取商品规格
     * @param int $id
     * @param int $type
     * @return array
     */
    public function getProductRules(int $id, int $type = 0)
    {
        $productInfo = $this->dao->get($id);
        if (!$productInfo) {
            throw new ValidateException('商品不存在');
        }
        $product_type = $productInfo['product_type'] ?? $data['product_type'] ?? 0; //商品类型
        /** @var StoreProductAttrServices $storeProductAttrService */
        $storeProductAttrService = app()->make(StoreProductAttrServices::class);
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $productAttr = $storeProductAttrService->getProductAttr(['product_id' => $id, 'type' => 0]);
        if (!$productAttr) return [];
        $attr = [];
        foreach ($productAttr as $key => $value) {
            $attr[$key]['value'] = $value['attr_name'];
            $attr[$key]['detailValue'] = '';
            $attr[$key]['attrHidden'] = true;
            $attr[$key]['detail'] = $value['attr_values'];
        }
        $value = attr_format($attr)[1];
        $valueNew = [];
        $count = 0;
//        $sukValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $id, 'type' => $type]);
        $sukValue = $sukDefaultValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $id, 'type' => 0]);
        foreach ($value as $key => $item) {
            $detail = $item['detail'];
//            sort($item['detail'], SORT_STRING);
            $suk = implode(',', $item['detail']);
            if (!isset($sukDefaultValue[$suk])) continue;
//            if (!isset($sukValue[$suk])) {
//                $sukValue[$suk] = $sukDefaultValue[$suk];
//            }
            foreach (array_keys($detail) as $k => $title) {
                $header[$k]['title'] = $title;
                $header[$k]['align'] = 'center';
                $header[$k]['minWidth'] = 80;
            }
            $valueNew[$count]['value'] = '';
            foreach (array_values($detail) as $k => $v) {
                $valueNew[$count]['value' . ($k + 1)] = $v;
                $header[$k]['key'] = 'value' . ($k + 1);
                $valueNew[$count]['value'] .= $valueNew[$count]['value'] == '' ? $v : '，' . $v;
            }
            if ($type == 4) {
                $valueNew[$count]['integral'] = $sukValue[$suk]['integral'] ?? 0;
                $valueNew[$count]['product_id'] = $sukValue[$suk]['product_id'];
            }
            $valueNew[$count]['detail'] = $detail;
            $valueNew[$count]['pic'] = $sukValue[$suk]['pic'];
            $valueNew[$count]['price'] = floatval($sukValue[$suk]['price']);
            if ($type == 2) $valueNew[$count]['min_price'] = 0;
            if ($type == 3) $valueNew[$count]['r_price'] = floatval($sukValue[$suk]['price']);
            if ($type == 0) $valueNew[$count]['p_price'] = floatval($sukValue[$suk]['price']);
            $valueNew[$count]['cost'] = floatval($sukValue[$suk]['cost']);
            $valueNew[$count]['ot_price'] = floatval($sukValue[$suk]['ot_price']);
            $valueNew[$count]['stock'] = intval($sukValue[$suk]['stock']);
            $valueNew[$count]['quota'] = intval($sukValue[$suk]['quota']);
            $valueNew[$count]['bar_code'] = $sukValue[$suk]['bar_code'] ?? '';
            $valueNew[$count]['code'] = $sukValue[$suk]['code'] ?? '';
            $valueNew[$count]['weight'] = $sukValue[$suk]['weight'] ? floatval($sukValue[$suk]['weight']) : 0;
            $valueNew[$count]['volume'] = $sukValue[$suk]['volume'] ? floatval($sukValue[$suk]['volume']) : 0;
            $valueNew[$count]['brokerage'] = $sukValue[$suk]['brokerage'] ? floatval($sukValue[$suk]['brokerage']) : 0;
            $valueNew[$count]['brokerage_two'] = $sukValue[$suk]['brokerage_two'] ? floatval($sukValue[$suk]['brokerage_two']) : 0;
            $count++;
        }
        $header[] = ['title' => '图片', 'slot' => 'pic', 'align' => 'center', 'minWidth' => 120];
        if ($type == 1) {
            $header[] = ['title' => '秒杀价', 'key' => 'price', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '成本价', 'key' => 'cost', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '划线价', 'key' => 'ot_price', 'align' => 'center', 'minWidth' => 80];
        } elseif ($type == 2) {
            $header[] = ['title' => '砍价起始金额', 'slot' => 'price', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '砍价最低价', 'slot' => 'min_price', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '成本价', 'key' => 'cost', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '划线价', 'key' => 'ot_price', 'align' => 'center', 'minWidth' => 80];
        } elseif ($type == 3) {
            $header[] = ['title' => '拼团价', 'key' => 'price', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '成本价', 'key' => 'cost', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '日常售价', 'key' => 'r_price', 'align' => 'center', 'minWidth' => 80];
        } elseif ($type == 4) {
            $header[] = ['title' => '兑换积分', 'key' => 'integral', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '金额', 'key' => 'price', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
        } else {
            $header[] = ['title' => '成本价', 'key' => 'cost', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '划线价', 'key' => 'ot_price', 'align' => 'center', 'minWidth' => 80];
            $header[] = ['title' => '售价', 'key' => 'p_price', 'align' => 'center', 'minWidth' => 80];
        }
        $header[] = ['title' => '库存', 'key' => 'stock', 'align' => 'center', 'minWidth' => 80];
        if ($type == 2) {
            $header[] = ['title' => '限量', 'slot' => 'quota', 'align' => 'center', 'minWidth' => 80];
        } else if ($type == 4) {
            $header[] = ['title' => '兑换次数', 'key' => 'quota', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
        } else {
            $header[] = ['title' => '限量', 'key' => 'quota', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
        }
        $header[] = ['title' => '重量(KG)', 'key' => 'weight', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '体积(m³)', 'key' => 'volume', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '商品条形码', 'key' => 'bar_code', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '商品编码', 'key' => 'code', 'align' => 'center', 'minWidth' => 80];
        return ['items' => $attr, 'attrs' => $valueNew, 'header' => $header, 'product_type' => $product_type];
    }

    /**
     * 检查商品是否有活动
     * @param  $id
     * @return bool
     */
    public function checkActivity($id = 0)
    {
        if ($id) {
            /** @var StoreSeckillServices $storeSeckillService */
            $storeSeckillService = app()->make(StoreSeckillServices::class);
            /** @var StoreBargainServices $storeBargainService */
            $storeBargainService = app()->make(StoreBargainServices::class);
            /** @var StoreCombinationServices $storeCombinationService */
            $storeCombinationService = app()->make(StoreCombinationServices::class);
            $res1 = $storeSeckillService->count(['product_id' => $id, 'is_del' => 0, 'status' => 1, 'seckill_time' => 1]);
            $res2 = $storeBargainService->count(['product_id' => $id, 'is_del' => 0, 'status' => 1, 'bargain_time' => 1]);
            $res3 = $storeCombinationService->count(['product_id' => $id, 'is_del' => 0, 'is_show' => 1, 'pinkIngTime' => 1]);
            if ($res1 || $res2 || $res3) throw new AdminException('商品有活动开启，无法进行此操作');
        }
        return true;
    }

    /**
     * 获取临时缓存商品数据
     * @param int $id
     * @return mixed
     */
    public function getCacheProductInfo(int $id)
    {
        $storeInfo = $this->dao->cacheTag()->remember((string)$id, function () use ($id) {
            $storeInfo = $this->dao->getOne(['id' => $id], '*', ['descriptions']);
            if (!$storeInfo) {
                return [];
            } else {
                return $storeInfo->toArray();
            }
        }, 600);

        return $storeInfo;
    }

    /**
     * 保存
     * @param array $data
     * @return mixed
     */
    public function create(array $data)
    {
        return $this->dao->save($data);
    }

    public function createErpProductInGuard(
        StoreCatalogWriteLockGuard $catalogGuard,
        StoreCatalogWriteLease $catalogLease,
        array $data
    ): int {
        $catalogLease->assertActive();
        $productId = (int)$this->dao->ErpProductSave($data);
        if ($productId <= 0) {
            throw new AdminException('ERP 商品创建失败');
        }
        $catalogGuard->adoptCreatedProduct(
            $catalogLease,
            $productId,
            (int)($data['product_type'] ?? 0)
        );
        return $productId;
    }

    public function __call($name, $arguments)
    {
        if ($name === 'ErpProductSave') {
            throw new AdminException('ERP 商品与初始 SKU 必须使用同一目录写锁事务');
        }
        return parent::__call($name, $arguments);
    }

    /**
     * 前台获取商品列表
     * @param array $where
     * @param int $uid
     * @param int $promotions_type
     * @return array|array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getGoodsList(array $where, int $uid, int $promotions_type = 0, bool $isEntryRules = true)
    {
        $appendWhere = [];
        if (isset($where['coupon_id']) && $where['coupon_id']) {
            $isEntryRules = false;
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            $where['relation_id'] = $couponIssueService->value(['id' => $where['coupon_id']], 'relation_id');
            $where['is_delivery_type'] = true;
        }
        //进店规则查询商品
        if ($isEntryRules) {
            $appendWhere = $this->getWhereByEntryRules($uid);
            if (isset($appendWhere['relation_id']) && isset($where['ids']) && $where['ids']) {//单店模式 商品列表diy选择商品ID
                //处理成适用门店
                $ids = $this->dao->getColumn(['is_verify' => 1, 'is_show' => 1, 'is_del' => 0, 'pid' => $where['ids'], 'type' => 1, 'relation_id' => $appendWhere['relation_id']], 'id');
                if (!$ids) {
                    return [];
                }
                $where['ids'] = $ids;
            }
        }
        $where = array_merge($where, $appendWhere);
        $where['is_verify'] = 1;
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        $collate_code_id = 0;
        if (isset($where['collate_code_id'])) {
            $collate_code_id = $where['collate_code_id'];
        }
        if (isset($where['store_name']) && $where['store_name']) {
            /** @var UserSearchServices $userSearchServices */
            $userSearchServices = app()->make(UserSearchServices::class);
            $searchIds = $userSearchServices->vicSearch($uid, $where['store_name'], $where);
            if ($searchIds) {//之前查询结果记录
                $where['ids'] = $searchIds;
                unset($where['store_name']);
            }
        }
        $promotionsWhere = [];
        $list = [];
        //优惠活动凑单
        if (isset($where['promotions_id']) && $where['promotions_id']) {
            /** @var StorePromotionsServices $storePromotionsServices */
            $storePromotionsServices = app()->make(StorePromotionsServices::class);
            $promotionsWhere = $storePromotionsServices->collectProductById((int)$where['promotions_id']);
            unset($where['promotions_id']);
        } else if (isset($where['promotions_type']) && $where['promotions_type']) {
            /** @var StorePromotionsServices $storePromotionsServices */
            $storePromotionsServices = app()->make(StorePromotionsServices::class);
            $promotionsWhere = $storePromotionsServices->collectProductByType([(int)$where['promotions_type']]);
            unset($where['promotions_type']);
        }
        if (!$promotionsWhere || (isset($promotionsWhere['ids']) && $promotionsWhere['ids'])) {
            $where = array_merge($where, $promotionsWhere);
            unset($where['promotions_id']);
            if (isset($where['productId']) && $where['productId'] != '') {
                $where['ids'] = is_string($where['productId']) ? stringToIntArray($where['productId']) : $where['productId'];
                $where['ids'] = array_unique(array_map('intval', $where['ids']));
                unset($where['productId']);
            }
            $where['is_vip_product'] = 0;
            $discount = 100;
            $level_name = '';
            if (!$promotions_type && $uid) {
                /** @var UserServices $user */
                $user = app()->make(UserServices::class);
                $userInfo = $user->getUserCacheInfo($uid);
                $is_vip = $userInfo['is_money_level'] ?? 0;
                $where['is_vip_product'] = $is_vip ? -1 : 0;
                //用户等级是否开启
                /** @var SystemUserLevelServices $systemLevel */
                $systemLevel = app()->make(SystemUserLevelServices::class);
                $levelInfo = $systemLevel->getLevelCache((int)($userInfo['level'] ?? 0));
                if (sys_config('member_func_status', 1) && $levelInfo) {
                    $discount = $levelInfo['discount'] ?? 100;
                }
                $level_name = $levelInfo['name'] ?? '';
            }

            [$page, $limit] = $this->getPageValue();
            $field = ['id,relation_id,type,pid,delivery_type,product_type,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price,is_presale_product,is_vip_product,system_form_id,system_form_type,is_presale_product,presale_start_time,presale_end_time,is_limit,limit_num,video_open,video_link,freight,star,store_label_id,brand_id'];
            $list = $this->dao->getSearchList($where, $page, $limit, $field, '', ['couponId']);
            if ($list) {
                /** @var MemberCardServices $memberCardService */
                $memberCardService = app()->make(MemberCardServices::class);
                $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price') && sys_config('svip_price_status', 1);
                /** @var SystemFormServices $systemFormServices */
                $systemFormServices = app()->make(SystemFormServices::class);
                $systemForms = $systemFormServices->getColumn([['id', 'in', array_unique(array_column($list, 'system_form_id'))], ['is_del', '=', 0]], 'id,value', 'id');
                /** @var StoreProductLabelServices $storeProductLabelServices */
                $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
                foreach ($list as &$item) {
                    $minData = $this->getMinPrice($uid, $item, $discount);
                    $item['price_type'] = $minData['price_type'] ?? '';
                    $item['level_name'] = $level_name;
                    $item['vip_price'] = $item['level_price'] = 0.00;
                    if ($item['price_type'] == 'member') {
                        $item['vip_price'] = $minData['vip_price'] ?? 0;
                        if (!$item['is_vip'] || !$vipStatus) {
                            $item['vip_price'] = 0;
                        }
                    } else {
                        $item['level_price'] = $minData['vip_price'] ?? 0;
                    }
                    $custom_form = $systemForms[$item['system_form_id']]['value'] ?? [];
                    $item['custom_form'] = is_string($custom_form) ? json_decode($custom_form, true) : $custom_form;
                    $item['cart_button'] = $item['product_type'] > 0 || $item['is_presale_product'] || $item['system_form_id'] ? 0 : 1;
                    $item['presale_pay_status'] = $this->checkPresaleProductPay((int)$item['id'], $item);
                    if (!$item['video_open']) {
                        $item['video_link'] = '';
                    }
                    $item['store_label'] = [];
                    if ($item['store_label_id']) {
                        $item['store_label'] = $storeProductLabelServices->getLabelCache($item['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                    }
                    if (isset($item['brand_id']) && $item['brand_id']) {
                        $item['brand_name'] = $this->productIdByBrandName((int)$item['id'], $item);
                    }
                    $item['activity'] = [];
                    if (isset($item['couponId'])) {
                        $item['checkCoupon'] = (bool)count($item['couponId']);
                        unset($item['couponId']);
                    } else {
                        $item['checkCoupon'] = false;
                    }
                }
//                if (!$collate_code_id) {
//                    $list = $this->getActivityList($list);
//                }
                $list = $this->getProduceOtherList($list, $uid, isset($where['status']) && !!$where['status'], $collate_code_id);
                $list = $this->getProductPromotions($list, $promotions_type ? [$promotions_type] : []);
            }
        }
        return $list;
    }

    /**
     * 搜索获取商品品牌列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getBrandList(array $where)
    {
        $where['status'] = 1;
        /** @var StoreProductCategoryBrandServices $productCategoryBrandServices */
        $productCategoryBrandServices = app()->make(StoreProductCategoryBrandServices::class);
        return $productCategoryBrandServices->getList($where, 'distinct(`brand_id`) as id, brand_name');
    }

    /**
     * 搜索获取商品品牌列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function searchFilter(int $uid, array $where, string $type = 'brand')
    {
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        if (isset($where['store_name']) && $where['store_name']) {
            $keyword = $where['store_name'];
            /** @var UserSearchServices $userSearchServices */
            $userSearchServices = app()->make(UserSearchServices::class);
            $searchIds = $userSearchServices->vicSearch($uid, $keyword, $where);
            if ($searchIds) {//之前查询结果记录
                $where['ids'] = $searchIds;
                unset($where['store_name']);
            } else {//分词查询
            }
        }
        if ($where['productId'] !== '') {
            $where['ids'] = explode(',', $where['productId']);
            $where['ids'] = array_unique(array_map('intval', $where['ids']));
            unset($where['productId']);
        }
        $promotions = $pIds = $brand = $store_label = [];
        //优惠活动凑单
        $promotionsWhere = [];
        $promotionsType = [];
        if (isset($where['promotions_id']) && $where['promotions_id']) {
            /** @var StorePromotionsServices $storePromotionsServices */
            $storePromotionsServices = app()->make(StorePromotionsServices::class);
            $promotionsWhere = $storePromotionsServices->collectProductById((int)$where['promotions_id']);
            unset($where['promotions_id']);
        } else if (isset($where['promotions_type']) && $where['promotions_type']) {
            $promotionsType = [(int)$where['promotions_type']];
            /** @var StorePromotionsServices $storePromotionsServices */
            $storePromotionsServices = app()->make(StorePromotionsServices::class);
            $promotionsWhere = $storePromotionsServices->collectProductByType($promotionsType);
            unset($where['promotions_type']);
        }
        if (!$promotionsWhere || (isset($promotionsWhere['ids']) && $promotionsWhere['ids'])) {
            $where = array_merge($where, $promotionsWhere, $this->getWhereByEntryRules($uid));
            $products = $this->dao->getColumnList($where, 'id,brand_id,type,store_label_id');
            if ($products) {
                $products = $this->getProductPromotions($products, $promotionsType);
                $promotionsArr = array_column($products, 'promotions');
                if ($promotionsArr) {
                    foreach ($promotionsArr as $item) {
                        if ($item && !in_array($item['id'], $pIds)) {
                            $pIds[] = $item['id'];
                            unset($item['product_id'], $item['products'], $item['promotions']);
                            $promotions[] = $item;
                        }
                    }
                }
                $brandIds = array_unique(array_filter(array_column($products, 'brand_id')));
                if ($brandIds) {
                    /** @var StoreBrandServices $storeBrandServices */
                    $storeBrandServices = app()->make(StoreBrandServices::class);
                    $brand = $storeBrandServices->getColumn(['id' => $brandIds, 'is_del' => 0, 'is_show' => 1], 'id,brand_name');
                }
                $store_label_ids = array_unique(explode(',', implode(',', array_filter(array_column($products, 'store_label_id')))));
                if ($store_label_ids) {
                    /** @var StoreProductLabelServices $storeProductLabelServices */
                    $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
                    $store_label = $storeProductLabelServices->getLabelCache($store_label_ids, ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                }
            }
        }
        return compact('promotions', 'brand', 'store_label');
    }

    /**
     * 获取商品所在优惠活动
     * @param array $list
     * @param array $promotions_type
     * @return array|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductPromotions(array $list, array $promotions_type = [])
    {
        if (!$list) {
            return $list;
        }
        $productIds = array_column($list, 'id');
        /** @var StorePromotionsServices $storePromotionsServices */
        $storePromotionsServices = app()->make(StorePromotionsServices::class);
        $with = ['products' => function ($query) {
            $query->field('promotions_id,product_id,is_all,unique');
        }];
        $field = 'id,promotions_type,name,desc,image,promotions_type,title,product_id,product_partake_type,discount,discount_type,start_time,stop_time,applicable_type,applicable_store_id';
        [$promotionsArr, $productDetails, $promotionsDetail] = $storePromotionsServices->getProductsPromotionsDetail($productIds, $field, $with, $promotions_type);
        $promotionsArr = array_combine(array_column($promotionsArr, 'id'), $promotionsArr);
        foreach ($list as &$item) {
            $item['product_id'] = $item['id'];
            $item['promotions'] = $item['activity_frame'] = $item['activity_background'] = [];
            if ($item['type'] == 1) {//门店商品
                if (isset($item['pid']) && $item['pid']) {//平台共享到门店商品
                    $id = $item['pid'];
                } else {//门店独立商品
                    continue;
                }
            } else {
                $id = $item['id'];
            }
            $item['promotions'] = $item['activity_frame'] = $item['activity_background'] = [];
            $promotionsIds = $productDetails[$id] ?? [];
            if ($promotionsIds) {
                foreach ($promotionsIds as $id) {
                    $promotions = $promotionsArr[$id] ?? [];
                    switch ($promotions['promotions_type']) {
                        case 1:
                        case 2:
                        case 3:
                        case 4:
                            if (!$promotions_type) {//无指定优惠类型
                                if ($item['promotions']) {
                                    if (($promotions['promotions_type'] ?? 0) <= ($item['promotions']['promotions_type'] ?? 0)) {
                                        if (($promotions['promotions_type']['discount'] ?? 0) > ($item['promotions']['discount'] ?? 0)) {
                                            $item['promotions'] = $promotions;
                                        }
                                    } else {
                                        break;
                                    }
                                } else {
                                    $item['promotions'] = $promotions;
                                }
                            } else {
                                if (!$item['promotions']) {
                                    $item['promotions'] = $promotions;
                                } else {//同类活动展示最新的一个
                                    break;
                                }
                                break;
                            }
                            break;
                        case 5://边框
                            if (!$item['activity_frame']) {
                                $item['activity_frame'] = [
                                    'id' => $promotions['id'],
                                    'name' => $promotions['name'],
                                    'image' => $promotions['image'],
                                ];
                            } else {//同类活动展示最新的一个
                                break;
                            }
                            break;
                        case 6://背景
                            if (!$item['activity_background']) {
                                $item['activity_background'] = [
                                    'id' => $promotions['id'],
                                    'name' => $promotions['name'],
                                    'image' => $promotions['image'],
                                ];
                            } else {//同类活动展示最新的一个
                                break;
                            }
                            break;
                        default:
                            break;
                    }
                }
            }
        }
        return $list;
    }

    /**
     * 获取某些模板所需得购物车数量
     * @param array $list
     * @param int $uid
     * @param bool $type
     * @return array
     */
    public function getProduceOtherList(array $list, int $uid, bool $type = true, int $collate_code_id = 0)
    {
        if (!$type || !$list) {
            return $list;
        }
        $productIds = array_column($list, 'id');
        if ($productIds) {
            /** @var StoreProductAttrValueServices $services */
            $services = app()->make(StoreProductAttrValueServices::class);
            $store_id = (int)$this->getItem('store_id', 0);
            $staff_id = (int)$this->getItem('staff_id', 0);
            $tourist_uid = (int)$this->getItem('tourist_uid', 0);
            if ($uid || $tourist_uid) {
                if ($collate_code_id) {
                    /** @var UserCollagePartakeServices $cartServices */
                    $cartServices = app()->make(UserCollagePartakeServices::class);
                    $cartNumList = $cartServices->productIdByCartNum($productIds, $uid, $collate_code_id);
                } else {
                    /** @var StoreCartServices $cartServices */
                    $cartServices = app()->make(StoreCartServices::class);
                    $cartNumList = $cartServices->productIdByCartNum($productIds, $uid, $staff_id, $tourist_uid, $store_id);
                }
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
            foreach ($list as &$item) {
                $item['is_att'] = false;
                $item['cart_num'] = $cartNumList[$item['id']] ?? 0;
            }
        }
        return $list;
    }

    /**
     * 获取商品活动标签
     * @param array $list
     * @param bool $status
     * @return array|array[]|mixed
     */
    public function getActivityList(array $list, bool $status = true)
    {
        if (!$list) return [];
        if (!$status) {//一维数组
            $list = [$list];
        }
        $productIds = [];
        //处理平台共享到门店、门店独立商品活动
        foreach ($list as $product) {
            if (isset($product['type']) && isset($product['pid'])) {
                if ($product['type'] == 1) {//门店商品
                    if ($product['pid']) {//平台共享到门店商品
                        $productIds[] = $product['pid'];
                    } else {//门店独立商品

                    }
                } else {
                    $productIds[] = $product['id'];
                }
            }
        }
        if ($productIds) {
            /** @var StoreSeckillServices $storeSeckillService */
            $storeSeckillService = app()->make(StoreSeckillServices::class);
            /** @var StoreBargainServices $storeBargainServices */
            $storeBargainServices = app()->make(StoreBargainServices::class);
            /** @var StoreCombinationServices $storeCombinationServices */
            $storeCombinationServices = app()->make(StoreCombinationServices::class);
            $seckillIdsList = $storeSeckillService->getSeckillIdsArrayCache($productIds);
            $pinkIdsList = $storeCombinationServices->getPinkIdsArrayCache($productIds);
            $bargrainIdsList = $storeBargainServices->getBargainIdsArrayCache($productIds);
            foreach ($list as &$item) {
                if ($item['type'] == 1) {//门店商品
                    if ($item['pid']) {//平台共享到门店商品
                        $id = $item['pid'];
                    } else {//门店独立商品
                        continue;
                    }
                } else {
                    $id = $item['id'];
                }
                $seckillId = $seckillIdsList && is_array($seckillIdsList) ? array_filter($seckillIdsList, function ($val) use ($item, $id) {
                    if ($val['product_id'] === $id) {
                        return $val;
                    }
                }) : [];
                $item['activity'] = $this->activity($item['activity'],
                    $item['id'],
                    $pinkIdsList[$id] ?? 0,
                    $seckillId,
                    $bargrainIdsList[$id] ?? 0,
                    $status);
                if (isset($item['couponId'])) {
                    $item['checkCoupon'] = (bool)count($item['couponId']);
                    unset($item['couponId']);
                } else {
                    $item['checkCoupon'] = false;
                }
            }
        }
        if ($status) {
            return $list;
        } else {
            return $list[0]['activity'];
        }
    }

    /**
     * 获取商品在此时段活动优先类型
     * @param string $activity
     * @param int $id
     * @param int $combinationId
     * @param array $seckillId
     * @param int $bargainId
     * @param bool $status
     * @return array
     */
    public function activity(string $activity, int $id, int $combinationId, array $seckillId, int $bargainId, bool $status = true)
    {
        if (!$activity) {
            $activity = '0,1,2,3';//如果老商品没有活动顺序，默认活动顺序，秒杀-砍价-拼团
        }
        $activity = explode(',', $activity);
        if ($activity[0] == 0 && $status) return [];
        $activityId = [];
        $combinationInfo = [];
        $time = 0;
        $timeId = 0;
        if ($seckillId) {
            /** @var StoreSeckillTimeServices $storeSeckillTimeServices */
            $storeSeckillTimeServices = app()->make(StoreSeckillTimeServices::class);
            $timeList = $storeSeckillTimeServices->time_list();
            if ($timeList) {
                $timeList = array_combine(array_column($timeList, 'id'), $timeList);
                $today = date('Y-m-d');
                $currentHour = date('Hi');
                foreach ($seckillId as $v) {
                    $time_ids = is_string($v['time_id']) ? explode(',', $v['time_id']) : $v['time_id'];
                    if ($time_ids) {
                        foreach ($time_ids as $time_id) {
                            $timeInfo = $timeList[$time_id] ?? [];
                            if ($timeInfo) {
                                $start = str_replace(':', '', $timeInfo['start_time']);
                                $end = str_replace(':', '', $timeInfo['end_time']);
                                if ($currentHour >= $start && $currentHour < $end) {
                                    $activityId[1] = $v['id'];
                                    $timeId = $timeInfo['id'];
                                    $time = strtotime($today . ' ' . $timeInfo['end_time']);
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($bargainId) $activityId[2] = $bargainId;
        if ($combinationId) {
            /** @var StoreCombinationServices $storeCombinationServices */
            $storeCombinationServices = app()->make(StoreCombinationServices::class);
            $combinationInfo = $storeCombinationServices->get($combinationId, ['id', 'people']);
            $activityId[3] = $combinationId;
        }
        $data = [];
        foreach ($activity as $k => $v) {
            if (array_key_exists($v, $activityId)) {
                if ($status) {
                    $data['type'] = $v;
                    $data['id'] = $activityId[$v];
                    if ($v == 1) $data['time'] = $timeId;
                    break;
                } else {
                    if ($v != 0) {
                        $arr['type'] = $v;
                        $arr['id'] = $activityId[$v];
                        if ($v == 3) {
                            $arr['people'] = $combinationInfo['people'] ?? 2;
                        }
                        if ($v == 1) $arr['time'] = $timeId;
                        $data[] = $arr;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * 获取热门商品
     * @param array $where
     * @param string $order
     * @return array|array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProducts(array $where, string $order = '', int $num = 0, array $with = ['couponId', 'descriptions'])
    {
        [$page, $limit] = $this->getPageValue();
        if ($num) {
            $page = 1;
            $limit = $num;
        }
        $list = $this->dao->getSearchList($where, $page, $limit, ['id,pid,type,product_type,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,is_vip,vip_price,stock,activity,unit_name,freight,star,is_presale_product,presale_start_time,presale_end_time,store_label_id,brand_id'], $order, $with);
        if ($list) {
            $discount = 100;
            $level_name = '';
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price') && sys_config('svip_price_status', 1);
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            foreach ($list as $k => &$item) {
                $minData = $this->getMinPrice(0, $item, $discount);
                $item['price_type'] = $minData['price_type'] ?? '';
                $item['level_name'] = $level_name;
                $item['vip_price'] = $item['level_price'] = 0.00;
                if ($item['price_type'] == 'member') {
                    $item['vip_price'] = $minData['vip_price'] ?? 0;
                    if (!$item['is_vip'] || !$vipStatus) {
                        $item['vip_price'] = 0;
                    }
                } else {
                    $item['level_price'] = $minData['vip_price'] ?? 0;
                }
                $item['store_label'] = [];
                if ($item['store_label_id']) {
                    $item['store_label'] = $storeProductLabelServices->getLabelCache($item['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                }
                if ($item['brand_id']) {
                    $item['brand_name'] = $this->productIdByBrandName((int)$item['id'], $item);
                }
                $item['presale_pay_status'] = $this->checkPresaleProductPay((int)$item['id'], $item);
                $item['activity'] = [];
                if (isset($item['couponId'])) {
                    $item['checkCoupon'] = (bool)count($item['couponId']);
                    unset($item['couponId']);
                } else {
                    $item['checkCoupon'] = false;
                }
            }
//            $list = $this->getActivityList($list);
            $list = $this->getProductPromotions($list);
        }
        return $list;
    }

    /**
     * 检测预售商品是否可以购买
     * @param int $id
     * @param array $productInfo
     * @return int
     */
    public function checkPresaleProductPay(int $id, array $productInfo = [])
    {
        if (!$id) return 0;
        if (!$productInfo) {
            $productInfo = $this->getCacheProductInfo($id);
            if (!$productInfo) {
                return 0;
            }
        }
        if (!isset($productInfo['is_presale_product']) || !isset($productInfo['presale_start_time']) || !isset($productInfo['presale_end_time'])) {
            return 0;
        }
        if ($productInfo['is_presale_product']) {
            if ($productInfo['presale_start_time'] > time()) {
                return 1;
            } elseif ($productInfo['presale_start_time'] <= time() && $productInfo['presale_end_time'] >= time()) {
                return 2;
            } elseif ($productInfo['presale_end_time'] < time()) {
                return 3;
            } else {
                return 0;
            }
        } else {
            return 0;
        }
    }

    /**
     * 获取商品详情
     * @param int $uid
     * @param int $id
     * @param int $type
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Throwable
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productDetail(int $uid, int $id, int $type = 0)
    {
        $data['uid'] = $uid;
        $storeInfo = $this->getCacheProductInfo($id);
        if (!$storeInfo) {
            throw new ValidateException('商品不存在');
        }
        $storeInfo['sale_time_data'] = $storeInfo['reservation_times'] = [];
        if ($storeInfo['product_type'] == 6) {
            if ($storeInfo['sale_time_type'] == 3) {//销售日期自定义
                $storeInfo['sale_time_data'] = [date('Y-m-d', $storeInfo['sale_time_start']), date('Y-m-d', $storeInfo['sale_time_end'])];
            }
            $storeInfo['reservation_times'] = [$storeInfo['reservation_time_start'], $storeInfo['reservation_time_end']];
        }
        $storeInfo['description'] = $storeInfo['description'] ?: '';
        /** @var DiyServices $diyServices */
        $diyServices = app()->make(DiyServices::class);
        $infoDiy = $diyServices->getProductDetailDiy();
        //diy控制参数
        if (!isset($infoDiy['showService']) || !in_array(3, $infoDiy['showService'])) {
            $storeInfo['specs'] = [];
        }
        $storeInfo['brand_name'] = $this->productIdByBrandName((int)$storeInfo['id'], $storeInfo);
        $storeInfo['store_label'] = $storeInfo['ensure'] = [];
        if ($storeInfo['store_label_id']) {
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            $storeInfo['store_label'] = $storeProductLabelServices->getLabelCache($storeInfo['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
        }
        if ($storeInfo['ensure_id'] && isset($infoDiy['showService']) && in_array(2, $infoDiy['showService'])) {
            /** @var StoreProductEnsureServices $storeProductEnsureServices */
            $storeProductEnsureServices = app()->make(StoreProductEnsureServices::class);
            $storeInfo['ensure'] = $storeProductEnsureServices->getEnsurCache($storeInfo['ensure_id'], ['id', 'name', 'image', 'desc']);
        }

        $discount = isset($storeInfo['promotions'][0]['promotions_type']) && $storeInfo['promotions'][0]['promotions_type'] == 1 ? $storeInfo['promotions'][0]['discount'] : -1;

        $configData = SystemConfigService::more(['site_url', 'tengxun_map_key', 'store_self_mention', 'routine_contact_type', 'site_name', 'share_qrcode', 'store_func_status', 'product_poster_title']);
        $siteUrl = $configData['site_url'] ?? '';
        if ($storeInfo['video_open']) {
            if ($storeInfo['video_link'] && strpos($storeInfo['video_link'], 'http') === false) {
                $storeInfo['video_link'] = $siteUrl . $storeInfo['video_link'];
            }
        } else {
            $storeInfo['video_link'] = '';
        }

        $storeInfo['image'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['image_base'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['fsales'] = ($storeInfo['ficti'] ?? 0) + ($storeInfo['sales'] ?? 0);
//        /** @var QrcodeServices $qrcodeService */
//        $qrcodeService = app()->make(QrcodeServices::class);
//        $storeInfo['code_base'] = $qrcodeService->getWechatQrcodePath($id . '_product_detail_wap.jpg', '/pages/goods_details/index?id=' . $id);
        /** @var UserRelationServices $userRelationServices */
        $userRelationServices = app()->make(UserRelationServices::class);
        $storeInfo['userCollect'] = $userRelationServices->isProductRelationCache(['uid' => $uid, 'relation_id' => $id, 'type' => 'collect', 'category' => UserRelationServices::CATEGORY_PRODUCT]);
        $storeInfo['userLike'] = 0;

        //预售相关
        $storeInfo['presale_pay_status'] = $this->checkPresaleProductPay($id, $storeInfo);

        $storeInfo['presale_start_time'] = $storeInfo['presale_start_time'] ? date('Y-m-d H:i', $storeInfo['presale_start_time']) : '';
        $storeInfo['presale_end_time'] = $storeInfo['presale_end_time'] ? date('Y-m-d H:i', $storeInfo['presale_end_time']) : '';
        //系统表单
        $storeInfo['custom_form'] = [];
        if ($storeInfo['system_form_id']) {
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $systemForm = $systemFormServices->value(['id' => $storeInfo['system_form_id']], 'value');
            if ($systemForm) {
                $storeInfo['custom_form'] = is_string($systemForm) ? json_decode($systemForm, true) : $systemForm;
            }
        }
        //有自定义表单或预售或虚拟不展示加入购物车按钮
        $storeInfo['cart_button'] = $storeInfo['custom_form'] || $storeInfo['is_presale_product'] || $storeInfo['product_type'] > 0 ? 0 : 1;

        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        [$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetailCache($id, $uid, $type, 0, 0, $storeInfo, $discount);
        $attrValue = $productValue;
        if (!$storeInfo['spec_type']) {
            $productAttr = [];
            $productValue = [];
        }
        $data['productAttr'] = $productAttr;
        $data['productValue'] = $productValue;
        $storeInfo['small_image'] = get_thumb_water($storeInfo['image']);

        /**
         * 判断配送方式
         */
        $storeInfo['delivery_type'] = $this->getDeliveryType($uid, (int)$storeInfo['id'], (int)$storeInfo['type'], (int)$storeInfo['relation_id'], $storeInfo['delivery_type']);
        $data['storeInfo'] = $storeInfo;

        /** @var MemberCardServices $memberCardService */
        $memberCardService = app()->make(MemberCardServices::class);
        $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price') && sys_config('svip_price_status', 1);
        $price_count = count($infoDiy['showPrice'] ?? []);
        if ($price_count >= 1) {
            //两个都选 取最低的
            $minPrice = $this->getMinPrice($uid, $data['storeInfo'], null, $price_count == 2);
            if ($price_count == 1) {
                if (in_array(1, $infoDiy['price_type'])) {//svip
                    $minPrice['price_type'] = 'member';
                } else {//用户等级
                    $minPrice['price_type'] = 'level';
                    $minPrice['vip_price'] = $minPrice['level_price'];
                }
            }
        } else {//一个都不展示
            $minPrice = ['vip_price' => 0, 'price_type' => '', 'level_name' => ''];
        }

        $data['storeInfo'] = array_merge($data['storeInfo'], $minPrice);
        if ($data['storeInfo']['price_type'] == 'member' && (!$data['storeInfo']['is_vip'] || !$vipStatus)) {
            $data['storeInfo']['vip_price'] = 0;
        }
        $data['priceName'] = 0;
        if ($uid) {
            $data['priceName'] = $this->getPacketPrice($storeInfo, $attrValue, $uid);
        }
        $data['reply'] = [];
        $data['replyChance'] = $data['replyCount'] = 0;
        if (isset($infoDiy['showReply']) && $infoDiy['showReply']) {
            /** @var StoreProductReplyServices $storeProductReplyService */
            $storeProductReplyService = app()->make(StoreProductReplyServices::class);
            $reply = $storeProductReplyService->getRecProductReplyCache($id, (int)($infoDiy['replyNum'] ?? 1));
            $data['reply'] = $reply ? get_thumb_water($reply, 'small', ['pics']) : [];
            [$replyCount, $goodReply, $replyChance] = $storeProductReplyService->getProductReplyData($id);
            $data['replyChance'] = $replyChance;
            $data['replyCount'] = $replyCount;
        }
        //种草秀
        $data['elegant_list'] = [];
        $data['elegant_count'] = 0;
        if (isset($infoDiy['showCommunity']) && $infoDiy['showCommunity']) {
            /** @var CommunityServices $communityServices */
            $communityServices = app()->make(CommunityServices::class);
            $elegant = $communityServices->getJoinCommunityList(['product_id' => $id], 0, 'left_id', (int)($infoDiy['communityNum'] ?? 1));
            $data['elegant_list'] = $elegant['list'] ?? [];
            $data['elegant_count'] = $elegant['count'] ?? [];
        }
        $data['mer_id'] = 0;
        $data['mapKey'] = $configData['tengxun_map_key'] ?? '';
        $data['store_func_status'] = (int)($configData['store_func_status'] ?? 1);//门店是否开启
        $data['store_self_mention'] = $data['store_func_status'] ? (int)($configData['store_self_mention'] ?? 1) : 0;//门店核销是否开启
        $data['routine_contact_type'] = $configData['routine_contact_type'] ?? 0;
        $data['site_name'] = $configData['site_name'] ?? '';
        $data['share_qrcode'] = $configData['share_qrcode'] ?? 0;
        $data['product_poster_title'] = $configData['product_poster_title'] ?? '';
        $data['is_store_buy'] = 0;
        $count = 0;
        //平台商品 && 支持门店（配送｜核销）&& 适用门店（全部｜部分）
        if ($storeInfo['type'] == 0 && array_intersect([2, 3], $storeInfo['delivery_type']) && in_array($storeInfo['applicable_type'], [1, 2])) {
            $where = ['is_del' => 0, 'is_show' => 1, 'is_verify' => 1, 'type' => 1];
            if ($storeInfo['applicable_type'] == 2) {
                $applicable_store_id = is_string($storeInfo['applicable_store_id']) ? explode(',', $storeInfo['applicable_store_id']) : $storeInfo['applicable_store_id'];
                if ($applicable_store_id) {//门店商品正常
                    $where['relation_id'] = $applicable_store_id;
                    $count = $this->dao->count($where);
                }
            } else {
                $count = $this->dao->count($where);
            }
        }
        if (!$storeInfo['stock'] && $count) {//平台无库存，支持门店购买
            $data['is_store_buy'] = 1;
        }
        /** @var StoreProductRankServices $productRankServices */
        $productRankServices = app()->make(StoreProductRankServices::class);
        $keyArr = [1, 2, 3];
        $rank = 0;
        $rank_type = 1;
        foreach ($keyArr as $item) {
            $rankOne = $productRankServices->getProductRank($uid, (int)$id, (int)$item);
            if (!$rankOne) continue;
            if ($rank) {
                if ($rank > $rankOne) {
                    $rank = $rankOne;
                    $rank_type = $item;
                }
            } else {
                $rank = $rankOne;
                $rank_type = $item;
            }
        }
        $data['storeInfo']['rank'] = $rank;
        $data['storeInfo']['rank_type'] = $rank_type;
        //浏览记录
        ProductLogJob::dispatch(['visit', ['uid' => $uid, 'id' => $id, 'product_id' => $id], 'product']);
        return $data;
    }

    /**
     * 是否开启vip
     * @param bool $vip
     * @return bool
     */
    public function vipIsOpen(bool $vip = false, $vipStatus = -1)
    {
        if (!$vip) {
            return false;
        }
        $member_status = sys_config('member_card_status', 1);
        if (!$member_status) {
            return false;
        }
        if ($vipStatus == -1) {
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price', false, $member_status);
        }
        return $vipStatus && $member_status && $vip && sys_config('svip_price_status', 1);
    }

    /**
     * 获取商品分销佣金最低和最高
     * @param $storeInfo
     * @param $productValue
     * @param int $uid
     * @return int|string
     */
    public function getPacketPrice($storeInfo, $productValue, int $uid)
    {
        if (!count($productValue)) {
            return 0;
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        //一级返佣金额
        $store_brokerage_ratio = sys_config('store_brokerage_ratio');
        $store_brokerage_ratio = (string)bcdiv((string)$store_brokerage_ratio, '100', 2);
        if (!$userServices->checkUserPromoter($uid)) {
            $maxPrice = (float)max(array_column($productValue, 'price'));
            return (float)bcmul($store_brokerage_ratio, (string)$maxPrice, 2);
        }
        //独立反拥
        if (isset($storeInfo['is_sub']) && $storeInfo['is_sub'] == 1) {
            $maxPrice = (float)max(array_column($productValue, 'brokerage'));
            $minPrice = (float)min(array_column($productValue, 'brokerage'));
        } else {
            //佣金计算方式
            $brokerageComputeType = sys_config('brokerage_compute_type', 1);

            $brokeragePrice = [];
            foreach ($productValue as $attr) {
                switch ($brokerageComputeType) {
                    case 1://售价
                    case 2://实付金额
                        //都用售价计算
                        $brokeragePrice[] = (float)bcmul($store_brokerage_ratio, (string)($attr['price'] ?? 0), 2);
                        break;
                    case 3://商品利润
                        $brokeragePrice[] = bcmul($store_brokerage_ratio, bcsub((string)($attr['price'] ?? 0), (string)($attr['cost'] ?? 0), 2), 2);
                        break;
                }
            }
            $maxPrice = max($brokeragePrice);
            $minPrice = min($brokeragePrice);
            //大于1 取整（两位小数前端展示超出）
            $maxPrice = $maxPrice > 1 ? floor($maxPrice) : $maxPrice;
            $minPrice = $minPrice > 1 ? floor($minPrice) : $minPrice;
        }
        if ($minPrice == 0 && $maxPrice == 0) {
            $priceName = 0;
        } else if ($minPrice == 0 && $maxPrice)
            $priceName = $maxPrice;
        else if ($maxPrice == 0 && $minPrice)
            $priceName = $minPrice;
        else if ($maxPrice == $minPrice && $minPrice)
            $priceName = $maxPrice;
        else
            $priceName = $minPrice . '~' . $maxPrice;
        return max(strlen(trim($priceName)) ? $priceName : 0, 0);
    }

    /**
     * 获取商品用户等级、svip最低价格，优惠类型
     * @param int $uid
     * @param $productInfo
     * @param $discount
     * @param bool $is_min
     * @return array
     */
    public function getMinPrice(int $uid, $productInfo, $discount = null, $is_min = true)
    {
        $level_name = '';
        $vip_price = 0;
        $price_type = '';
        $level_price = 0;
        if ($productInfo && !($productInfo['type'] == 1 && $productInfo['pid'] == 0)) {
            if (is_null($discount)) {
                $discount = 100;
                if ($uid) {
                    /** @var UserServices $user */
                    $user = app()->make(UserServices::class);
                    $userInfo = $user->getUserCacheInfo($uid);
                    //用户等级是否开启
                    /** @var SystemUserLevelServices $systemLevel */
                    $systemLevel = app()->make(SystemUserLevelServices::class);
                    $levelInfo = $systemLevel->getLevelCache((int)($userInfo['level'] ?? 0));
                    if (sys_config('member_func_status', 1) && $levelInfo) {
                        $discount = $levelInfo['discount'] ?? 100;
                    }
                    $level_name = $levelInfo['name'] ?? '';
                }
            }
            if ($discount >= 0 && $discount < 100) {//等级价格
                $level_price = (float)bcmul((string)bcdiv((string)$discount, '100', 2), (string)$productInfo['price'], 2);
            } else {
                $level_price = $productInfo['price'];
            }
            if ($productInfo['is_vip']) {//svip价格
                $vip_price = $productInfo['vip_price'];
            }
            if (($discount != 100 || $productInfo['is_vip']) && $is_min) {//需要对比价格
                if ($discount != 100 && $productInfo['is_vip']) {
                    if ($level_price < $productInfo['vip_price']) {
                        $price_type = 'level';
                        $vip_price = $level_price;
                    } else {
                        $price_type = 'member';
                        $vip_price = $productInfo['vip_price'];
                    }
                } else if ($discount != 100 && !$productInfo['is_vip']) {
                    $price_type = 'level';
                    $vip_price = $level_price;
                } else if ($discount == 100 && $productInfo['is_vip']) {
                    $price_type = 'member';
                    $vip_price = $productInfo['vip_price'];
                }
            }
        }
        return compact('level_name', 'vip_price', 'price_type', 'level_price');
    }

    /**
     * 计算商品优惠后金额、优惠价格
     * @param $price
     * @param int $uid
     * @param $userInfo
     * @param $vipStatus
     * @param int $discount
     * @param float $vipPrice
     * @param int $is_vip
     * @param bool $is_show
     * @param array $level_info
     * @return array  [优惠后的总金额,优惠金额]
     */
    public function setLevelPrice($price, int $uid, $userInfo, $vipStatus, $discount = 0, $vipPrice = 0.00, $is_vip = 0, $is_show = false, $level_info = [])
    {
        if (!(float)$price) return [(float)$price, (float)$price, ''];
        if (!$vipStatus) $is_vip = 0;
        //已登录
        if ($uid) {
            if (!$userInfo) {
                /** @var UserServices $user */
                $user = app()->make(UserServices::class);
                $userInfo = $user->getUserCacheInfo($uid);
            }
            if ($discount === 0) {
                /** @var SystemUserLevelServices $systemLevel */
                $systemLevel = app()->make(SystemUserLevelServices::class);
                $discount = $systemLevel->getDiscount($uid, (int)$userInfo['level']);
            }
        } else {
            //没登录
            $discount = 100;
        }
        $discount = bcdiv((string)$discount, '100', 2);
        $level_price = -1;
        if (count($level_info) > 0 && $level_info['level_type'] == 2 && $uid && $userInfo['level'] != 0 && $level_info['level_price']) {
            $level_prices = json_decode($level_info['level_price'], true);
            foreach ($level_prices as $val) {
                if ($userInfo['level'] == $val['id']) {
                    $level_price = $val['price'];
                }
            }
        }

        //执行减去会员优惠金额
        [$truePrice, $vip_truePrice, $type] = $this->isPayLevelPrice($uid, $userInfo, $vipStatus, $price, $discount, $vipPrice, $is_vip, $is_show, $level_price);
        //返回优惠后的总金额
        $truePrice = $truePrice < 0.01 ? 0.01 : $truePrice;
        //优惠的金额
        $vip_truePrice = $vip_truePrice == $price ? bcsub((string)$vip_truePrice, '0.01', 2) : $vip_truePrice;
        return [(float)$truePrice, (float)$vip_truePrice, $type];
    }

    /**
     * 获取会员价格（付费会员价格和购买商品会员价格）
     * @param int $uid
     * @param $userInfo
     * @param $vipStatus
     * @param $price
     * @param string $discount
     * @param $payVipPrice
     * @param $is_vip
     * @param $is_show
     * @param $level_price
     * @return array
     */
    public function isPayLevelPrice(int $uid, $userInfo, $vipStatus, $price, string $discount, $payVipPrice = 0.00, $is_vip = 0, $is_show = false, $level_price = -1)
    {
        //is_vip == 0表示会员价格不启用，展示为零
        if ($is_vip == 0) $payVipPrice = 0;
        if (!$userInfo && $uid) {
            //检测用户是否是付费会员
            /** @var  UserServices $userService */
            $userService = app()->make(UserServices::class);
            $userInfo = $userService->getUserCacheInfo($uid);
        }
        $noPayVipPrice = ($level_price == -1) ? (($discount && $discount != 0.00) ? bcmul((string)$discount, (string)$price, 2) : $price) : $level_price;
        if ($payVipPrice < $noPayVipPrice && $payVipPrice > 0) {
            $vipPrice = $payVipPrice;
            $type = 'member';
        } else {
            $vipPrice = $noPayVipPrice;
            $type = 'level';
        }
        //如果$isSingle==true 返回优惠后的总金额，否则返回优惠的金额
        if ($vipStatus && $is_vip == 1) {
            //$is_show == false 是计算支付价格，true是展示
            if (!$is_show) {
                return [$vipPrice, bcsub((string)$price, (string)$vipPrice, 2), $type];
            } else {
                $isVip = $this->getItem('isVip', 0);
                //强制计算用户是vip
                if ($isVip || ($userInfo && isset($userInfo['is_money_level']) && $userInfo['is_money_level'] > 0)) {
                    return [$vipPrice, bcsub((string)$price, (string)$vipPrice, 2), $type];
                } else {
                    $type = 'level';
                    return [$noPayVipPrice, bcsub((string)$price, (string)$noPayVipPrice, 2), $type];
                }
            }
        } else {
            $type = 'level';
            return [(float)$noPayVipPrice, (float)bcsub((string)$price, (string)$noPayVipPrice, 2), $type];
        }
    }

    /**
     * 商品列表
     * @param array $where
     * @param $limit
     * @param $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductLimit(array $where, $limit, $field)
    {
        return $this->dao->getProductLimit($where, $limit, $field);
    }

    /**
     * 通过条件获取商品列表
     * @param $where
     * @param $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductListByWhere($where, $field)
    {
        return $this->dao->getProductListByWhere($where, $field);
    }

    /**
     * 根据指定id获取商品列表
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductColumn(array $ids, string $field = '')
    {
        $productData = [];
        $productInfoField = 'id,image,price,ot_price,vip_price,postage,give_integral,sales,stock,store_name,unit_name,is_show,is_del,is_postage,cost,is_sub,temp_id';
        if (!empty($ids)) {
            $productAll = $this->dao->idByProductList($ids, $field ?: $productInfoField);
            if (!empty($productAll))
                $productData = array_combine(array_column($productAll, 'id'), $productAll);
        }
        return $productData;
    }

    /**
     * 商品是否存在
     * @param int $productId
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function isValidProduct(int $productId, bool $is_show = false, string $field = '*')
    {
        $where = ['id' => $productId, 'is_del' => 0, 'is_show' => 1, 'is_verify' => 1];
        if ($is_show) unset($where['is_show']);
        return $this->dao->getOne($where, $field);
    }

    /**
     * 获取商品库存
     * @param int $productId
     * @param string $uniqueId
     * @return int|mixed
     */
    public function getProductStock(int $productId, string $uniqueId = '')
    {
        /** @var  StoreProductAttrValueServices $StoreProductAttrValue */
        $StoreProductAttrValue = app()->make(StoreProductAttrValueServices::class);
        return $uniqueId == '' ?
            $this->dao->value(['id' => $productId], 'stock') ?: 0
            : $StoreProductAttrValue->uniqueByStock($uniqueId);
    }

    /**
     * 下单、退款商品、规格库存变化清空缓存
     * @return void
     */
    public function clearProductCache()
    {
        $this->dao->cacheTag()->clear();
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $storeProductAttrServices->cacheTag()->clear();
    }

    /**
     * 加销量（不减库存）
     * @param int $num
     * @param int $productId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function incProductSales(
        int $num,
        int $productId,
        string $unique = '',
        int $store_id = 0,
        bool $assumeLocked = false,
        ?array $resolvedTarget = null
    )
    {
		return $this->mutateProductSales(
			true,
			$num,
			$productId,
			$unique,
			$store_id,
			$assumeLocked,
			$resolvedTarget
		);
    }


    /**
     * 减库存,加销量
     * @param int $num
     * @param int $productId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function decProductStock(int $num, int $productId, string $unique = '', int $store_id = 0)
    {
        // 【库存铁律】旧减库存加销量已停用；实物库存仅允许库存管理与销售出库/退货统一服务
        throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
    }

    /**
     * 加库存,减销量（已停用直写实物库存）
     * @param int $num
     * @param int $productId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function incProductStock(int $num, int $productId, string $unique = '', int $store_id = 0)
    {
        // 【库存铁律】旧加库存减销量已停用
        throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
    }

    /**
     * 减销量(不加库存)
     * @param int $num
     * @param int $productId
     * @param string $unique
     * @param int $store_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function decProductSales(
        int $num,
        int $productId,
        string $unique = '',
        int $store_id = 0,
        bool $assumeLocked = false,
        ?array $resolvedTarget = null
    )
    {
		return $this->mutateProductSales(
			false,
			$num,
			$productId,
			$unique,
			$store_id,
			$assumeLocked,
			$resolvedTarget
		);
    }

    /**
     * @param array<int,array{product_id:int,unique:string,num:int}> $lines
     */
    public function decProductSalesBatch(array $lines, int $storeId = 0)
    {
        return $this->transaction(function () use ($lines, $storeId) {
            /** @var StoreProductAttrValueServices $skuValueServices */
            $skuValueServices = app()->make(StoreProductAttrValueServices::class);
            $prepared = [];
            $lockTargets = [];
            foreach ($lines as $line) {
                $sourceProductId = (int)($line['product_id'] ?? 0);
                $sourceUnique = (string)($line['unique'] ?? '');
                $num = (int)($line['num'] ?? 0);
                if ($sourceProductId <= 0 || $num <= 0) {
                    continue;
                }
                $resolvedTarget = $this->resolveProductSalesTarget(
                    $sourceProductId,
                    $sourceUnique,
                    $storeId,
                    $skuValueServices
                );
                $lockTargets[] = [
                    'product_id' => (int)$resolvedTarget['product_id'],
                    'unique' => (string)$resolvedTarget['unique'],
                    'platform_pid' => (int)$resolvedTarget['platform_pid'],
                ];
                $prepared[] = compact(
                    'sourceProductId',
                    'sourceUnique',
                    'num',
                    'resolvedTarget'
                );
            }

            /** @var StoreProductSkuWriteLock $catalogWriteLock */
            $catalogWriteLock = app()->make(StoreProductSkuWriteLock::class);
            $catalogWriteLock->lock($lockTargets);

            $result = true;
            foreach ($prepared as $line) {
                $lineResult = $this->mutateProductSales(
                    false,
                    (int)$line['num'],
                    (int)$line['sourceProductId'],
                    (string)$line['sourceUnique'],
                    $storeId,
                    true,
                    $line['resolvedTarget']
                );
                $result = (bool)$lineResult && $result;
            }
            return $result;
        });
    }

    private function mutateProductSales(
        bool $increase,
        int $num,
        int $sourceProductId,
        string $sourceUnique,
        int $storeId,
        bool $assumeLocked,
        ?array $resolvedTarget
    ) {
        if ($sourceProductId <= 0 || $num <= 0) {
            return true;
        }

        $write = function () use (
            $increase,
            $num,
            $sourceProductId,
            $sourceUnique,
            $storeId,
            $assumeLocked,
            $resolvedTarget
        ) {
            /** @var StoreProductAttrValueServices $skuValueServices */
            $skuValueServices = app()->make(StoreProductAttrValueServices::class);
            if (is_array($resolvedTarget)) {
                $target = [
                    'product_id' => (int)($resolvedTarget['product_id'] ?? 0),
                    'unique' => (string)($resolvedTarget['unique'] ?? ''),
                    'platform_pid' => (int)($resolvedTarget['platform_pid'] ?? 0),
                ];
            } else {
                $target = $this->resolveProductSalesTarget(
                    $sourceProductId,
                    $sourceUnique,
                    $storeId,
                    $skuValueServices
                );
            }
            $productId = (int)$target['product_id'];
            $unique = (string)$target['unique'];
            $platformProductId = (int)$target['platform_pid'];
            if ($productId <= 0) {
                throw new ValidateException('商品不存在');
            }

            if (!$assumeLocked) {
                /** @var StoreProductSkuWriteLock $catalogWriteLock */
                $catalogWriteLock = app()->make(StoreProductSkuWriteLock::class);
                $catalogWriteLock->lock([[
                    'product_id' => $productId,
                    'unique' => $unique,
                    'platform_pid' => $platformProductId,
                ]]);
            }

            $method = $increase ? 'bcInc' : 'bcDec';
            $res = true;
            if ($platformProductId > 0 && $platformProductId !== $productId) {
                $res = (bool)$this->dao->{$method}($platformProductId, 'sales', (string)$num);
            }
            if ($unique !== '') {
                $res = $res && (bool)$skuValueServices->{$method}([
                    'product_id' => $productId,
                    'unique' => $unique,
                    'type' => 0,
                ], 'sales', (string)$num);
            }
            $res = $res && (bool)$this->dao->{$method}($productId, 'sales', (string)$num);
            $this->clearProductCache();
            return $res;
        };

        return $assumeLocked ? $write() : $this->transaction($write);
    }

    private function resolveProductSalesTarget(
        int $sourceProductId,
        string $sourceUnique,
        int $storeId,
        StoreProductAttrValueServices $skuValueServices
    ): array {
        $productId = $sourceProductId;
        $unique = $sourceUnique;
        $platformProductId = 0;
        if ($storeId > 0) {
            /** @var StoreBranchProductServices $branchProductServices */
            $branchProductServices = app()->make(StoreBranchProductServices::class);
            $info = $branchProductServices->isValidStoreProduct($sourceProductId, $storeId);
            if (!$info) {
                throw new ValidateException('门店商品不存在或未上架');
            }
            $productId = (int)($info['id'] ?? 0);
            $platformProductId = (int)($info['pid'] ?? 0);
            if ($productId !== $sourceProductId) {
                $suk = $skuValueServices->value([
                    'unique' => $sourceUnique,
                    'product_id' => $sourceProductId,
                    'type' => 0,
                ], 'suk');
                $unique = (string)$skuValueServices->value([
                    'suk' => $suk,
                    'product_id' => $productId,
                    'type' => 0,
                ], 'unique');
            }
        }
        if ($productId <= 0) {
            throw new ValidateException('商品不存在');
        }
        return [
            'product_id' => $productId,
            'unique' => $unique,
            'platform_pid' => $platformProductId,
        ];
    }

    /**
     * 库存预警发送消息
     * @param int $productId
     */
    public function workSendStock(int $productId)
    {
        ProductStockTips::dispatch([$productId]);
    }

    /**
     * 获取推荐商品
     * @param int $uid
     * @param array $where
     * @param int $num
     * @param string $type
     * @return array|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function getRecommendProduct(int $uid, array $where = [], int $num = 0, string $type = 'mid', string $order = 'sort DESC, id DESC')
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_vip_product'] = 0;
        $where['is_verify'] = 1;
        if (!isset($where['relation_id'])) $where['pid'] = 0;
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        if ($uid) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $is_vip = $userServices->value(['uid' => $uid], 'is_money_level');
            $where['is_vip_product'] = $is_vip ? -1 : 0;
        }
        $field = ['id', 'type', 'product_type', 'pid', 'image', 'store_name', 'store_info', 'cate_id', 'price', 'ot_price', 'IFNULL(sales,0) + IFNULL(ficti,0) as sales', 'unit_name', 'sort', 'activity', 'stock', 'vip_price', 'is_vip', 'video_link', 'freight', 'star', 'is_presale_product', 'presale_start_time', 'presale_end_time', 'store_label_id'];
        $list = $this->dao->getRecommendProduct($where, $field, $num, $page, $limit, ['couponId'], $order);
        if ($list) {
            $list = get_thumb_water($list, $type);
//            $list = $this->getActivityList($list);
            $list = $this->getProductPromotions($list);
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price', false) && sys_config('svip_price_status', 1);
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            foreach ($list as &$item) {
                if (!($vipStatus && $item['is_vip'])) {
                    $item['vip_price'] = 0;
                }
                $item['store_label'] = [];
                if ($item['store_label_id']) {
                    $item['store_label'] = $storeProductLabelServices->getLabelCache($item['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                }
                $item['presale_pay_status'] = $this->checkPresaleProductPay((int)$item['id'], $item);
                $item['activity'] = [];
                if (isset($item['couponId'])) {
                    $item['checkCoupon'] = (bool)count($item['couponId']);
                    unset($item['couponId']);
                } else {
                    $item['checkCoupon'] = false;
                }
            }
        }
        return $list;
    }

    /**
     * 商品名称 图片
     * @param array $productIds
     * @return array
     */
    public function getProductArray(array $where, string $field, string $key)
    {
        return $this->dao->getColumn($where, $field, $key);
    }

    /**
     * 获取商品详情
     * @param int $productId
     * @param string $field
     * @param array $with
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductInfo(int $productId, string $field = '*', array $with = [])
    {
        return $this->dao->getOne(['is_del' => 0, 'is_show' => 1, 'id' => $productId], $field, $with);
    }

    /** 生成商品复制口令关键字
     * @param int $productId
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductWords(int $productId)
    {
        $productInfo = $this->dao->getOne(['is_del' => 0, 'is_show' => 1, 'id' => $productId]);
        $keyWords = "";
        if ($productInfo) {
            $oneKey = "mohe-fu致文本 Http:/ZБ";
            $twoKey = "Б轉移至☞" . sys_config('site_name') . "☜";
            $threeKey = "【" . $productInfo['store_name'] . "】";
            $mainKey = base64_encode($productId);
            $keyWords = $oneKey . $mainKey . $twoKey . $threeKey;
        }
        return $keyWords;
    }

    /**
     * 通过商品id获取商品分类
     * @param array $productId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productIdByProductCateName(array $productId)
    {
        $data = $this->dao->productIdByCateId($productId);
        $cateData = [];
        foreach ($data as $item) {
            $cateData[$item['id']] = implode(',', array_map(function ($i) {
                return $i['cate_name'];
            }, $item['cateName']));
        }
        return $cateData;
    }

    /**
     * 根据商品id获取品牌名称
     * @param $productId
     * @return mixed
     */
    public function productIdByBrandName($productId, $productInfo = [])
    {
        if ($productInfo) {
            $brand_id = $productInfo['brand_id'] ?? 0;
        } else {
            $storeInfo = $this->getCacheProductInfo($productId);
            $brand_id = $storeInfo['brand_id'] ?? 0;
        }

        /** @var StoreBrandServices $storeBrandServices */
        $storeBrandServices = app()->make(StoreBrandServices::class);
        $storeBrandInfo = $storeBrandServices->getCacheBrandInfo((int)$brand_id);

        return $storeBrandInfo['brand_name'] ?? '';
    }

    /**
     * 自动上下架
     * @return bool
     */
    public function autoUpperShelves()
    {
        $this->dao->overUpperShelves(1);
        $this->dao->overUpperShelves(0);
        //预约商品:超出可售日期自动下架
        $this->dao->unShowReservationProduct();
        //次卡、卡项商品固定核销时间超时后自动下架
        $this->dao->unShowTimeoutProduct();
        return true;
    }

    /**
     * 处理到期的预售商品状态
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function autoSetPresaleProductStatus()
    {
        $list = $this->dao->getEndPresaleProduct();
        if ($list) {
            foreach ($list as $item) {
                $this->dao->update($item['id'], ['is_show' => $item['presale_status'], 'is_presale_product' => 0, 'presale_start_time' => 0, 'presale_end_time' => 0]);
            }
        }
        return true;
    }

    /**
     * 获取预售列表
     * @param int $uid
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getPresaleList(int $uid, array $where, int $limit = 0)
    {
        if ($limit) {//diy获取条数
            $page = 0;
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $data = $this->dao->getPresaleList($where, $page, $limit);
        if ($data['list']) {
            /** @var StoreProductCategoryServices $storeCategoryService */
            $storeCategoryService = app()->make(StoreProductCategoryServices::class);
            /** @var StoreCouponIssueServices $couponServices */
            $couponServices = app()->make(StoreCouponIssueServices::class);
            /** @var StoreBrandServices $storeBrandServices */
            $storeBrandServices = app()->make(StoreBrandServices::class);
            $brands = $storeBrandServices->getColumn([], 'id,pid', 'id');
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $systemForms = $systemFormServices->getColumn([['id', 'in', array_unique(array_column($data['list'], 'system_form_id'))], ['is_del', '=', 0]], 'id,value', 'id');
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            foreach ($data['list'] as &$item) {
                $item['sales'] = $item['sales'] + $item['ficti'];
                $custom_form = $systemForms[$item['system_form_id']]['value'] ?? [];
                $item['custom_form'] = is_string($custom_form) ? json_decode($custom_form, true) : $custom_form;

                $cateId = $item['cate_id'];
                $cateId = explode(',', $cateId);
                $cateId = array_merge($cateId, $storeCategoryService->cateIdByPid($cateId));
                $cateId = array_diff($cateId, [0]);
                $brandId = [];
                $item['brand_name'] = '';
                if ($item['brand_id']) {
                    $brandId = $brands[$item['brand_id']] ?? [];
                    $item['brand_name'] = $this->productIdByBrandName((int)$item['id'], $item);
                }
                $item['store_label'] = [];
                if ($item['store_label_id']) {
                    $item['store_label'] = $storeProductLabelServices->getLabelCache($item['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                }
                $item['presale_pay_status'] = $this->checkPresaleProductPay((int)$item['id'], $item);
                $counpons = $couponServices->getIssueCouponListNew($uid, ['product_id' => $item['id'], 'cate_id' => $cateId, 'brand_id' => $brandId], 'id,coupon_title,coupon_price,use_min_price', 0, 1, 'coupon_price desc,sort desc,id desc');
                $item['coupon'] = $counpons[0] ?? [];
            }
        }
        return $data;
    }

    /**
     * 判断配送方式
     * @param int $product_id
     * @param int $type 商品类型 0平台 1门店 2供应商
     * @param int $relation_id 门店id
     * @param array $delivery_type 配送方式 delivery_type :1、快递，2、到店核销，3、门店配送
     * @return array
     */
    public function getDeliveryType(int $uid, int $product_id, int $type, int $relation_id, array $delivery_type, $product_type = 0)
    {
        //平台配送
        if (in_array(1, $delivery_type)) {
            $shopOperationType = sys_config('shop_operation_type', 1);
            if ($shopOperationType == 3) {//单店模式，不支持平台配送
                unset($delivery_type[array_search(1, $delivery_type)]);
            }
        }
        //门店总开关
        if (!sys_config('store_func_status', 1)) {
            if (in_array('2', $delivery_type)) unset($delivery_type[array_search('2', $delivery_type)]);
            if (in_array('3', $delivery_type)) unset($delivery_type[array_search('3', $delivery_type)]);
        } else {
            //获取总平台核销配置设置
            $store_self_mention = (bool)sys_config('store_self_mention');
            $store_mention = true;
            $store_delivery = true;
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $entryRules = $storeServices->getStoreIdByEntryRules($uid);
            $store_id = 0;
            $shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
            if (in_array($shop_operation_type, [2, 3])) {
                $store_id = $entryRules['store_id'] ?? 0;
            }
            $relation_id = $relation_id ?: $store_id;
            $type = $relation_id ? 1 : $type;
            //获取门店核销配置
            if ($type == 1 && $relation_id) {
                $storeInfo = $storeServices->get($relation_id);
                $storeInfo = $storeInfo->toArray();
                //门店配送方式1:门店配送+到店自提2门店配送3到店核销
                $storeDeliveryType = $storeInfo['delivery_type'] ?? [];
                $store_mention = in_array(2, $storeDeliveryType);
                $store_delivery = in_array(3, $storeDeliveryType);
                if (in_array(1, $delivery_type) && $product_type == 0) {//门店商品 支持平台配送 验证平台该商品
                    $info = $this->getCacheProductInfo($product_id);
                    if ($info && $info['type'] == 1 && $info['pid']) {
                        $platInfo = $this->getCacheProductInfo((int)$info['pid']);
                        if (!$platInfo || $platInfo['stock'] <= 0) {
                            unset($delivery_type[array_search('1', $delivery_type)]);
                        }
                    }
                }
            }
            //判断当前商品配送方式
            if (in_array('2', $delivery_type) && (!$store_self_mention || !$store_mention)) {
                unset($delivery_type[array_search('2', $delivery_type)]);
            }
            if (in_array('3', $delivery_type) && !$store_delivery) {
                unset($delivery_type[array_search('3', $delivery_type)]);
            }
        }
        return array_merge($delivery_type);
    }

    /**
     * 计算商品实际到手价
     * @param int $uid
     * @param int $id
     * @param string $unique
     * @param int $storeId
     * @param int $cart_num
     * @param int $reservation_time_id
     * @return array|array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function computedProductPayPrice(int $uid, int $id, string $unique = '', int $storeId = 0, int $cart_num = 1, int $reservation_time_id = 0)
    {
        $productInfo = $this->getCacheProductInfo($id);
        if (!$productInfo) {
//            throw new ValidateException('商品不存在');
            return ['promotions' => [], 'coupon' => [], 'deduction' => []];
        }
        $productInfo = is_object($productInfo) ? $productInfo->toArray() : $productInfo;
        /** @var StoreProductAttrValueServices $attrServices */
        $attrServices = app()->make(StoreProductAttrValueServices::class);
        if ($unique) {//传入sku唯一值
            $attrInfo = $attrServices->get(['unique' => $unique, 'type' => 0]);
            if ($attrInfo && $attrInfo['product_id'] != $id) {//平台同步到门店商品unique
                $productInfo = $this->getCacheProductInfo((int)$attrInfo['product_id']);
                if (!$productInfo || $productInfo['pid'] != $id) {//验证商品
                    return ['promotions' => [], 'coupon' => [], 'deduction' => []];
                }
                $id = (int)$attrInfo['product_id'];
                $productInfo = is_object($productInfo) ? $productInfo->toArray() : $productInfo;
            }
        } else {//单规格商品
            if ($storeId) {//查询门店商品
                $storeProductInfo = $this->dao->getOne(['pid' => $id, 'type' => 1, 'relation_id' => $storeId]);
                if ($storeProductInfo) {
                    $productInfo = $storeProductInfo->toArray();
                    $id = $productInfo['id'];
                }
            }
            $attrInfo = $attrServices->getList(['product_id' => $id, 'type' => 0], '*', 0, 1, 'price asc');
            $attrInfo = $attrInfo[0] ?? [];
            $unique = $attrInfo['unique'] ?? '';
        }
        if (!$attrInfo) {
//            throw new ValidateException('商品不存在');
            return ['promotions' => [], 'coupon' => [], 'deduction' => []];
        }
        $attrInfo = is_object($attrInfo) ? $attrInfo->toArray() : $attrInfo;
        $reservationTimeInfo = [];
        //预约商品
        if ($productInfo['product_type'] == 6 && $reservation_time_id) {
            /** @var StoreProductReservationTimeServices $reservationTimeServices */
            $reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
            $timeWhere = ['product_id' => $id, 'sku_unique' => $unique, 'id' => $reservation_time_id];
            $reservationTimeInfo = $reservationTimeServices->getList($timeWhere);
            if (!$reservationTimeInfo) {
                return ['promotions' => [], 'coupon' => [], 'deduction' => []];
            }
        }
        $cartInfo = [];
        $key = $this->getUniqueId((string)$uid);
        $info['id'] = $key;
        $info['type'] = 0;
        $info['store_id'] = 0;
        $info['tourist_uid'] = 0;
        $info['product_type'] = $productInfo['product_type'];
        $info['activity_id'] = 0;
        $info['discount_product_id'] = 0;
        $info['product_id'] = $id;
        $info['product_attr_unique'] = $attrInfo['unique'];
        $info['cart_num'] = $cart_num;
        $info['productInfo'] = $productInfo;
        $info['attrInfo'] = $attrInfo;
        $info['productInfo']['attrInfo'] = $info['attrInfo'];
        $info['productInfo']['reservationTimeInfo'] = $reservationTimeInfo;
        $price = $info['productInfo']['attrInfo']['price'] ?? $info['productInfo']['price'] ?? 0;
        $info['total_price'] = $info['pay_price'] = $info['sum_price'] = $info['truePrice'] = $price;
        $info['integral'] = 0;
        $info['trueStock'] = $info['productInfo']['attrInfo']['stock'] ?? 0;
        $info['costPrice'] = $info['productInfo']['attrInfo']['cost'] ?? 0;
        $cartInfo[] = $info;

        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        //整合购物车商品数据
        [$cartInfo, $valid, $invalid] = $storeCartServices->handleCartList($uid, $cartInfo, [], -1);

        $type = array_unique(array_column($cartInfo, 'type'));
        $price_type = array_unique(array_column($cartInfo, 'price_type'));
        $product_type = array_unique(array_column($cartInfo, 'product_type'));
        $activity_id = array_unique(array_column($cartInfo, 'activity_id'));
        $deduction = ['price_type' => $price_type[0] ?? 0, 'product_type' => $product_type[0] ?? 0, 'type' => $type[0] ?? 0, 'activity_id' => $activity_id[0] ?? 0];
        $promotions = $giveCoupon = $giveCartList = $useCoupon = $giveProduct = [];
        $giveIntegral = $couponPrice = $firstOrderPrice = 0;
        //计算优惠
        $data = $storeCartServices->computedProductPromotion($uid, $valid, 0, 0, true);
        extract($data);

        /** @var StoreOrderComputedServices $computedServices */
        $computedServices = app()->make(StoreOrderComputedServices::class);
        $sumPrice = $computedServices->getOrderSumPrice($valid, 'sum_price');//获取订单原总金额
        $totalPrice = $computedServices->getOrderSumPrice($valid, 'pay_price', false);//获取订单svip、用户等级优惠之后总金额
        $vipPrice = $computedServices->getOrderSumPrice($valid, 'vip_truePrice');//获取订单会员优惠金额
        $servicePrice = $computedServices->getOrderSumPrice($valid, 'service_price');//获取订单预约商品服务费

        $coupon = $useCoupon;

        $cartList = array_merge($valid, $giveCartList);
        $promotionsPrice = 0;
        if ($cartList) {
            foreach ($cartList as $key => $cart) {
                if (isset($cart['promotions_true_price']) && isset($cart['price_type']) && $cart['price_type'] == 'promotions') {
                    $promotionsPrice = bcadd((string)$promotionsPrice, (string)bcmul((string)$cart['promotions_true_price'], (string)$cart['cart_num'], 2), 2);
                }
            }
        }
        $deduction['promotions_price'] = (float)$promotionsPrice;
        $deduction['coupon_price'] = (float)$couponPrice;
        $deduction['coupon_use_min_price'] = (float)($useCoupon['use_min_price'] ?? 0);
        $deduction['first_order_price'] = (float)$firstOrderPrice;
        $deduction['sum_price'] = (float)$sumPrice;
        $deduction['vip_price'] = (float)$vipPrice;
        $deduction['service_price'] = (float)$servicePrice;

        $payPrice = (float)$totalPrice;
        if ($firstOrderPrice < $payPrice) {//首单优惠金额
            $payPrice = bcsub((string)$payPrice, (string)$firstOrderPrice, 2);
        } else {
            $payPrice = 0;
        }
        if ((float)$servicePrice) {//服务费
            $payPrice = bcsub((string)$payPrice, (string)$servicePrice, 2);
        }
        $deduction['pay_price'] = (float)$payPrice;
        if ($promotions) {//优惠活动
            $promotionsList = $promotions;
            unset($promotions);
            foreach ($promotionsList as $key => $item) {
                $promotions[] = [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'title' => $item['title'],
                    'name' => $item['name'],
                    'promotions_type' => $item['promotions_type'],
                    'threshold_type' => $item['threshold_type'],
                    'threshold' => $item['threshold'],
                    'discount_type' => $item['discount_type'],
                    'discount' => $item['discount'],
                    'desc' => $item['desc'],
                    'start_time' => $item['start_time'] ? date('Y-m-d', $item['start_time']) : '',
                    'stop_time' => $item['stop_time'] ? date('Y-m-d', $item['stop_time']) : '',
                    'giveProducts' => $item['giveProducts'] ?? [],
                    'giveCoupon' => $item['giveCoupon'] ?? []
                ];
            }
        }
        return compact('promotions', 'coupon', 'deduction');
    }

    /**
     * 同步库存（已停用直写）
     * 【库存铁律】ERP/外部接口不得直接覆盖 SKU 余额
     * @param array $items
     * @return void
     */
    public function syncStock(array $items)
    {
        throw new AdminException('已停用：外部接口不可直接覆盖库存，请通过「库存管理」入库/出库调整（后续可对接带来源单号的调整单）');
        // 原按条码直接 saveAll stock 逻辑已注释停用
        // return $this->transaction(function () use ($items) { ... });
    }

    /**
     * 计算商品库存
     * @param int $id
     * @return void
     */
    public function calcStockByAttrValue(int $id)
    {
        return $this->transaction(function () use ($id) {
            /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
            $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);

            /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
            $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);

            $stock = $storeProductAttrValueServices->sum(['product_id' => $id], 'stock');
            $res = $this->dao->update($id, ['stock' => $stock]);
            if (!$res) throw new AdminException('修改失败');

            $attrValue = $storeProductAttrValueServices->getColumn(['product_id' => $id], 'stock', 'bar_code');
            $result = $storeProductAttrResultServices->getResult(['product_id' => $id, 'type' => 0]);
            if (!$attrValue || !$result) return;

            foreach ($result['value'] as $k => $value) {
                if (isset($attrValue[$value['bar_code']])) {
                    $result['value'][$k]['stock'] = $attrValue[$value['bar_code']];
                }
            }
            $storeProductAttrResultServices->del($id, 0);
            $storeProductAttrResultServices->setResult($result, $id, 0);
            return true;
        });
    }

    /**
     * 商品设置默认门店商品分类
     * @param int $storeId
     * @param array $where
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeProductAddCate(int $storeId = 0)
    {
        $where = [
            'type' => 1,
            'relation_id' => $storeId,
            'pid' => -1,
            'store_cate_id' => -1
        ];
        $list = $this->dao->getSearchList($where);
        /** @var StoreProductCategoryServices $productCategoryServices */
        $productCategoryServices = app()->make(StoreProductCategoryServices::class);
        foreach ($list as $key => $item) {
            if ($item['store_cate_id']) continue;
            $store_cate_id = $productCategoryServices->getStoreCate($storeId, $item['cate_id']);
            if ($store_cate_id) {
                $this->dao->update($item['id'], ['store_cate_id' => implode(',', $store_cate_id)]);
                $cate_id = array_merge(explode(',', $item['cate_id']), $store_cate_id);
                //商品分类关联
                if ($cate_id) ProductRelationJob::dispatch([$item['id'], $cate_id, 1, (int)($item['is_show'] ?? 0)]);
            }
        }
        return true;
    }

    /**
     * 获取分佣/会员价
     * @param int $id
     * @param int $type 1分佣,2会员价
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function otherInfo(int $id, int $type)
    {
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);

        if (!$id) {
            $info['level_type'] = 1;
            $info['is_brokerage'] = 0;
            $info['brokerage_type'] = 1;
            $info['is_sub'] = 0;
            $info['is_vip'] = 0;
            $data['attrValue'] = [];
        } else {
            $info = $this->dao->get($id, ['id', 'is_brokerage', 'brokerage_type', 'is_sub', 'is_vip', 'level_type']);
            if (!$info) throw new AdminException('商品不存在');
            $data = [];
            $info = $info->toArray();
            if (!$info['level_type']) $info['level_type'] = 1;
            $attrValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $id, 'type' => 0], '*');
            foreach ($attrValue as &$val) {
                $val['level_price'] = $val['level_price'] ? json_decode($val['level_price'], true) : [];
            }
            $data['attrValue'] = $attrValue;
        }
        $data['storeInfo'] = $info;

        if ($type == 1) {
            $data['store_brokerage_ratio'] = bcmul(sys_config('store_brokerage_ratio', 0), 0.01, 2);
            $data['store_brokerage_two'] = bcmul(sys_config('store_brokerage_two', 0), 0.01, 2);
        } else if ($type == 2) {
            /** @var SystemUserLevelServices $systemUserLevelServices */
            $systemUserLevelServices = app()->make(SystemUserLevelServices::class);
            $level_list = $systemUserLevelServices->getWhereLevelList([], 'id,name,grade,discount');
            foreach ($level_list as &$item) {
                $item['discount'] = bcmul($item['discount'], '0.01', 2);
            }
            $data['level_list'] = $level_list;
        } else {
            $data['store_brokerage_ratio'] = bcmul(sys_config('store_brokerage_ratio', 0), 0.01, 2);
            $data['store_brokerage_two'] = bcmul(sys_config('store_brokerage_two', 0), 0.01, 2);
            /** @var SystemUserLevelServices $systemUserLevelServices */
            $systemUserLevelServices = app()->make(SystemUserLevelServices::class);
            $level_list = $systemUserLevelServices->getWhereLevelList([], 'id,name,grade,discount');
            foreach ($level_list as &$item) {
                $item['discount'] = bcmul($item['discount'], '0.01', 2);
            }
            $data['level_list'] = $level_list;
        }
        return $data;
    }

    /**
     * 保存分佣/会员价
     * @param int $id
     * @param int $type 1分佣,2会员价
     * @param array $data
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function otherUpdate(int $id, int $type, array $data)
    {
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);

        $info = $this->dao->get($id);
        if (!$info) throw new AdminException('商品不存在');
        $proData = [];
        if ($type == 1) {
            $proData['is_sub'] = $data['is_sub'];
            $proData['is_brokerage'] = $data['is_brokerage'];
        } else {
            $proData['is_vip'] = $data['is_vip'];
            $proData['level_type'] = $data['level_type'];
        }
        $vipPriceData = [];
        //同步门店商品
        $dataInfo = $this->dao->getProductListByWhere(['pid' => $id, 'is_del' => 0], 'id');
        $storeProductIds = [];
        if ($dataInfo) {
            $storeProductIds = array_unique(array_column($dataInfo, 'id'));
        }
        foreach ($data['attr_value'] as $item) {
            $attrData = [];
            if ($type == 1) {
                $attrData['brokerage'] = $data['is_sub'] ? $item['brokerage'] : 0;
                $attrData['brokerage_two'] = $data['is_sub'] ? $item['brokerage_two'] : 0;
                if (($attrData['brokerage'] + $attrData['brokerage_two']) > $item['price']) {
                    throw new AdminException('一二级返佣相加不能大于商品售价');
                }
            } else {
                $attrData['vip_price'] = $data['is_vip'] ? $item['vip_price'] : 0;
                $vipPriceData[] = $attrData['vip_price'];
                $attrData['level_price'] = isset($item['level_price']) && $item['level_price'] ? json_encode($item['level_price']) : '';
            }
            $storeProductAttrValueServices->update(['id' => $item['id']], $attrData);
            //修改门店商品sku
            if ($storeProductIds && isset($item['suk'])) {
                $sku = $item['suk'];
                foreach ($storeProductIds as $spid) {
                    //门店商品sku
                    $sid = $storeProductAttrValueServices->value(['suk' => $sku, 'product_id' => $spid, 'type' => 0], 'id');
                    if ($sid) {
                        $storeProductAttrValueServices->update(['id' => $sid], $attrData);
                    }
                }
            }
        }
        if ($vipPriceData) {
            $proData['vip_price'] = min($vipPriceData);
        }
        //平台商品
        $this->dao->update($id, $proData);
        //门店商品
        if ($storeProductIds) {
            $this->dao->batchUpdate($storeProductIds, $proData);
        }
        //清空缓存
        $this->dao->cacheTag()->clear();
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $storeProductAttrServices->cacheTag()->clear();
        return true;
    }

    /**
     * 商品导入
     * @param $importData
     * @param $impord_id
     * @param $end
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productImport($importData, $impord_id, $end, $type = 0, $relation_id = 0)
    {
        $productCateServices = app()->make(StoreProductCategoryServices::class);
        $storeBrandServices = app()->make(StoreBrandServices::class);
        $unitServices = app()->make(StoreProductUnitServices::class);
        $productData = $issetProductArr = [];
        $virtualType = ['普通商品' => 0, '卡密/网盘' => 1, '优惠券' => 2, '虚拟商品' => 3, '次卡商品' => 4, '卡项商品' => 5, '预约商品' => 6];
        $productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $barCodeArr = array_unique($productAttrValueServices->getColumn(['type' => 0], 'code', 'id'));
        $barCodeNumberArr = array_unique($productAttrValueServices->getColumn(['type' => 0], 'bar_code', 'id'));
        $unit = $unitServices->getColumn(['status' => 1, 'is_del' => 0], 'name', 'id');
        $error = [];
        $jump1 = 0;
        foreach ($importData as $sku) {
            if ($sku['id'] == null) {
                $jump1 += 1;
                continue;
            }
            $error[$sku['id']][] = $sku;
            if (!isset($productData[$sku['id']])) {
                $productData[$sku['id']]['product_type'] = $virtualType[$sku['product_type']] ?? 0;
                $productData[$sku['id']]['cate_id'] = $productCateServices->getCateId($sku['cate_name_one'], $sku['cate_name_two'], $sku['cate_name_three']);
                $productData[$sku['id']]['store_cate_id'] = [];
                $productData[$sku['id']]['store_name'] = $sku['store_name'];
                $productData[$sku['id']]['brand_id'] = [];
                $productData[$sku['id']]['store_info'] = $sku['store_info'];
                $productData[$sku['id']]['keyword'] = $sku['keyword'];
                $productData[$sku['id']]['unit_name'] = in_array($sku['unit_name'], $unit) ? $sku['unit_name'] : '';
                $productData[$sku['id']]['recommend_image'] = '';
                $productData[$sku['id']]['slider_image'] = explode(';', $sku['slider_image']);
                $productData[$sku['id']]['sort'] = 0;
                $productData[$sku['id']]['ficti'] = $sku['ficti'];
                $productData[$sku['id']]['give_integral'] = $sku['give_integral'] ?? 0;
                $productData[$sku['id']]['is_show'] = 0;
                $productData[$sku['id']]['is_hot'] = 0;
                $productData[$sku['id']]['is_benefit'] = 0;
                $productData[$sku['id']]['is_best'] = 0;
                $productData[$sku['id']]['is_new'] = 0;
                $productData[$sku['id']]['mer_use'] = 0;
                $productData[$sku['id']]['is_postage'] = 0;
                $productData[$sku['id']]['is_good'] = 0;
                $productData[$sku['id']]['description'] = $this->processDescription($sku['description']);
                $productData[$sku['id']]['spec_type'] = $sku['spec_type'] == '多规格' ? 1 : 0;
                $productData[$sku['id']]['video_open'] = $sku['video_link'] != '' ? 1 : 0;
                $productData[$sku['id']]['video_link'] = $sku['video_link'];
                //items
                //attrs
                //attr
                $productData[$sku['id']]['related'] = [];
                $productData[$sku['id']]['recommend'] = [];
                $productData[$sku['id']]['activity'] = ['默认', '秒杀', '砍价', '拼团'];
                $productData[$sku['id']]['coupon_ids'] = [];
                $productData[$sku['id']]['label_id'] = [];
                $productData[$sku['id']]['command_word'] = $sku['command_word'];
                $productData[$sku['id']]['tao_words'] = '';
                $productData[$sku['id']]['is_copy'] = 0;
                $productData[$sku['id']]['delivery_type'] = [1];
                $productData[$sku['id']]['freight'] = 1;
                $productData[$sku['id']]['postage'] = 0;
                $productData[$sku['id']]['temp_id'] = '';
                $productData[$sku['id']]['recommend_list'] = [];
                $productData[$sku['id']]['soure_link'] = '';
                $productData[$sku['id']]['bar_code'] = '';
                $productData[$sku['id']]['code'] = '';
                $productData[$sku['id']]['is_support_refund'] = 1;
                $productData[$sku['id']]['is_presale_product'] = 0;
                $productData[$sku['id']]['presale_time'] = [];
                $productData[$sku['id']]['presale_day'] = 0;
                $productData[$sku['id']]['is_vip_product'] = 0;
                $productData[$sku['id']]['auto_on_time'] = 0;
                $productData[$sku['id']]['auto_off_time'] = 0;
                $productData[$sku['id']]['custom_form'] = [];
                $productData[$sku['id']]['system_form_id'] = 0;
                $productData[$sku['id']]['store_label_id'] = [];
                $productData[$sku['id']]['ensure_id'] = 0;
                $productData[$sku['id']]['specs'] = [];
                $productData[$sku['id']]['specs_id'] = 0;
                $productData[$sku['id']]['is_limit'] = 0;
                $productData[$sku['id']]['limit_type'] = 0;
                $productData[$sku['id']]['limit_num'] = 0;
                $productData[$sku['id']]['presale_status'] = 0;
                $productData[$sku['id']]['applicable_type'] = 1; //适用门店
                //开始新增
                $productData[$sku['id']]['is_advance'] = 1;
                $productData[$sku['id']]['card_cover'] = 1;
                $productData[$sku['id']]['card_cover_image'] = '';
                $productData[$sku['id']]['card_cover_color'] = '';
                $productData[$sku['id']]['card_num'] = 0;
                $productData[$sku['id']]['card_num_type'] = 0;
                $productData[$sku['id']]['advance_time'] = 1;
                $productData[$sku['id']]['sale_time_type'] = 1;
                $productData[$sku['id']]['show_reservation_days_type'] = 1;
                $productData[$sku['id']]['show_reservation_days'] = 1;
                $productData[$sku['id']]['is_cancel_reservation'] = 0;
                $productData[$sku['id']]['reservation_time_type'] =1;
                $productData[$sku['id']]['reservation_time_start'] ='10:00';
                $productData[$sku['id']]['reservation_time_end'] ='22:00';
                $productData[$sku['id']]['reservation_time_interval'] ='60';
                $productData[$sku['id']]['reservation_times'] =['10:00','22:00'];
                $productData[$sku['id']]['reservation_time_data'] =['10:00','22:00'];
                if($productData[$sku['id']]['product_type'] === 5){
                    $relate='[
    {
        "id": 2796,
        "type": 0,
        "product_id": 2444,
        "product_type": 6,
        "suk": "默认",
        "stock": 60,
        "defective_stock": 0,
        "sum_stock": 60,
        "sales": 0,
        "price": "228.00",
        "price_range_status": 0,
        "price_range_min": "0.00",
        "price_range_max": "0.00",
        "settle_price": "0.00",
        "integral": 0,
        "image": "https://qiniu007.cc3798.com/attach/2025/12/8f83e202512310102338067.jpg",
        "unique": "48cd061e",
        "cost": "0.00",
        "bar_code": "",
        "ot_price": "298.00",
        "vip_price": "0.00",
        "weight": "0.00",
        "volume": "0.00",
        "brokerage": "0.00",
        "brokerage_two": "0.00",
        "quota": 0,
        "quota_show": 0,
        "code": "",
        "disk_info": "",
        "write_times": 60,
        "write_valid": 1,
        "write_days": 0,
        "write_start": 0,
        "write_end": 0,
        "level_price": "",
        "is_default_select": 0,
        "is_show": 1,
        "store_name": "焕颜面部+淋巴排毒"
    }
]';
                    $productData[$sku['id']]['related']=json_decode($relate,true);
                }
//                $productData[$sku['id']]['applicable_store_id'] = $sku['applicable_store_id'];
            }
            $detail = [];
            $reservation_time_data=[];
            if($virtualType[$sku['product_type']] == 6){
               $reservation_time_data=[
                   ['show_time'=> "10:00-11:00", 'start'=>"10:00", 'end'=>"11:00", 'stock'=>5],
                   ['show_time'=> "11:00-12:00", 'start'=>"11:00", 'end'=>"12:00", 'stock'=>5],
                   ['show_time'=> "12:00-13:00", 'start'=>"12:00", 'end'=>"13:00", 'stock'=>5],
                   ['show_time'=> "13:00-14:00", 'start'=>"13:00", 'end'=>"14:00", 'stock'=>5],
                   ['show_time'=> "14:00-15:00", 'start'=>"14:00", 'end'=>"15:00", 'stock'=>5],
                   ['show_time'=> "15:00-16:00", 'start'=>"15:00", 'end'=>"16:00", 'stock'=>5],
                   ['show_time'=> "16:00-17:00", 'start'=>"16:00", 'end'=>"17:00", 'stock'=>5],
                   ['show_time'=> "17:00-18:00", 'start'=>"17:00", 'end'=>"18:00", 'stock'=>5],
                   ['show_time'=> "18:00-19:00", 'start'=>"18:00", 'end'=>"19:00", 'stock'=>5],
                   ['show_time'=> "19:00-20:00", 'start'=>"19:00", 'end'=>"20:00", 'stock'=>5],
                   ['show_time'=> "20:00-21:00", 'start'=>"20:00", 'end'=>"21:00", 'stock'=>5],
                   ['show_time'=> "21:00-22:00", 'start'=>"21:00", 'end'=>"22:00", 'stock'=>5],
               ];
            }
            $sku_value = explode(';', $sku['sku_value']);
            if ($sku_value) {
                foreach ($sku_value as $pair) {
                    if (!$pair) continue;
                    $pair_arr = explode('=', $pair);
                    if(count($pair_arr) < 2) throw new AdminException('规格值组合有误！');
                    list($key, $value) = explode('=', $pair);
                    $detail[$key] = $value;
                }
            }


            if ($sku['code'] != '' && in_array($sku['code'], $barCodeArr)) {
                $issetProductArr[] = $sku['id'];
            }
            if ($sku['bar_code'] != '' && in_array($sku['bar_code'], $barCodeNumberArr)) {
                $issetProductArr[] = $sku['id'];
            }
            if ($sku['spec_type'] == '多规格') {
                $productData[$sku['id']]['attrs'][] = [
                    'attr_arr' => explode(',', $sku['sku_name']),
                    'detail' => $detail,
                    'price' => $sku['price'] ?? 0,
                    'settle_price' => $sku['settle_price'] ?? 0,
                    'pic' => $sku['pic'],
                    'ot_price' => $sku['ot_price'] ?? 0,
                    'cost' => $sku['cost'] ?? 0,
                    'stock' => $sku['stock'],
                    'is_show' => 1,
                    'is_default_select' => 0,
                    'is_virtual' => 0,
                    'brokerage' => 0,
                    'brokerage_two' => 0,
                    'vip_price' => 0,
                    'vip_proportion' => 0,
                    'write_valid' => 1,
                    'unique' => '',
                    'weight' => $sku['weight'],
                    'volume' => $sku['volume'],
                    'code' => $sku['code'],
                    'bar_code' => $sku['bar_code'],
                    'reservation_time_data' =>$reservation_time_data,
                ];
            } else {
                $productData[$sku['id']]['attrs'][] = [];
                $productData[$sku['id']]['attr'] = [
                    'attr_arr' => explode(',', $sku['sku_name']),
                    'detail' => $detail,
                    'price' => $sku['price'] ?? 0,
                    'settle_price' => $sku['settle_price'] ?? 0,
                    'pic' => $sku['pic'],
                    'ot_price' => $sku['ot_price'] ?? 0,
                    'cost' => $sku['cost'] ?? 0,
                    'stock' => $sku['stock'],
                    'is_show' => 1,
                    'is_default_select' => 0,
                    'is_virtual' => 0,
                    'brokerage' => 0,
                    'brokerage_two' => 0,
                    'vip_price' => 0,
                    'vip_proportion' => 0,
                    'write_valid' => 1,
                    'unique' => '',
                    'weight' => $sku['weight'],
                    'volume' => $sku['volume'],
                    'code' => $sku['code'],
                    'bar_code' => $sku['bar_code'],
                    'reservation_time_data' =>$reservation_time_data,
                ];
            }
            $items = [];
            $pairs = explode(';', $sku['sku_type_value']);
            if ($pairs) {
                foreach ($pairs as $pair) {
                    if (!$pair) continue;
                    $pair_arr1 = explode('=', $pair);
                    if(count($pair_arr1) < 2) throw new AdminException('规格类型值有误！');
                    // 将每个部分按等号分割成 key 和 value
                    list($key, $values) = explode('=', $pair);
                    // 将 value 部分按逗号分割为数组
                    $detailArray = explode(',', $values);
                    $detail = [];
                    foreach ($detailArray as &$det) {
                        $detail[] = [
                            'value' => $det,
                            'pic' => '',
                        ];
                    }
                    // 重新构建原始数组的结构
                    $items[] = [
                        'value' => $key,
                        'detail' => $detail
                    ];
                }
            }

            $productData[$sku['id']]['items'] = $items;
        }
        $all = count($productData);
        foreach (array_unique($issetProductArr) as $issetProduct) {
            if (isset($productData[$issetProduct])) {
                unset($productData[$issetProduct]);
            }
        }
        $success = count($productData);
        $jump = count($issetProductArr);
        $jump = $jump + $jump1;
        /** @var ImportRecordServices $importServices */
        $importServices = app()->make(ImportRecordServices::class);
        /** @var ImportRecordErrorServices $errorServices */
        $errorServices = app()->make(ImportRecordErrorServices::class);
        $error_sum = 0;
        foreach ($productData as $k => $info) {
            try {
                if (!$this->saveData(0, $info, $type, $relation_id)) {
                    foreach ($error[$k] as $item) {
                        $errorServices->save([
                            'record_id' => $impord_id,
                            'original_data' => json_encode($item),
                            'fail_msg' => '添加失败，请检测导入数据',
                        ]);
                        $error_sum += 1;
                    }
                }
            } catch (\Throwable $e) {
                foreach ($error[$k] as $item) {
                    $errorServices->save([
                        'record_id' => $impord_id,
                        'original_data' => json_encode($item),
                        'fail_msg' => $e->getMessage(),
                    ]);
                    $error_sum += 1;
                }
            }
        }
        //是否最后一批数据
        if ($end) {
            $importServices->update($impord_id, ['status' => $error_sum > 0 ? -1 : 1, 'jump_count' => $jump, 'fail_count' => $error_sum]);
        } else {
            $importServices->update($impord_id, ['status' => -1, 'jump_count' => $jump, 'fail_count' => $error_sum]);
        }
        return ['error_sum' => $error_sum, 'jump' => $jump];
    }

    /**
     * 描述处理商品详情描述，如果包含<p>标签则直接返回原内容；如果不包含，按;分割成数组并构建包含<img>标签的<p>元素
     * @param $description
     * @return string
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2025/5/28
     */
    public function processDescription($description)
    {
        // 检查是否存在<p>标签
        if (strpos($description, '<p>') !== false || strpos($description, '<p ') !== false) {
            // 如果已经包含<p>标签，直接返回原内容
            return $description;
        } else {
            // 如果不包含<p>标签，按;分割成数组
            $parts = explode(';', $description);

            // 过滤空值并去除前后空格
            $parts = array_filter(array_map('trim', $parts));

            // 如果没有有效内容，返回空字符串
            if (empty($parts)) {
                return '';
            }

            // 构建包含<img>标签的<p>元素
            $html = '<p>' . PHP_EOL;
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $html .= "\t<img src=\"" . htmlspecialchars($part) . "\">" . PHP_EOL;
                }
            }
            $html .= '</p>';

            return $html;
        }
    }

    /**
     * 凑单商品获取
     * @param array $where
     * @param int $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \throwable
     */
    public function getgroupPurchaseProductList(array $where, int $uid)
    {
        $where['is_verify'] = 1;
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        $where['is_stock'] = 1;
        $list = [];
        $where['is_vip_product'] = 0;
        $discount = 100;
        $level_name = '';
        if ($uid) {
            /** @var UserServices $user */
            $user = app()->make(UserServices::class);
            $userInfo = $user->getUserCacheInfo($uid);
            $is_vip = $userInfo['is_money_level'] ?? 0;
            $where['is_vip_product'] = $is_vip ? -1 : 0;
            //用户等级是否开启
            /** @var SystemUserLevelServices $systemLevel */
            $systemLevel = app()->make(SystemUserLevelServices::class);
            $levelInfo = $systemLevel->getLevelCache((int)($userInfo['level'] ?? 0));
            if (sys_config('member_func_status', 1) && $levelInfo) {
                $discount = $levelInfo['discount'] ?? 100;
            }
            $level_name = $levelInfo['name'] ?? '';
        }

        if ($where['shipping_type'] == 3 && $where['is_store_delivery_type'] == 2) {
            $where['relation_id'] = $where['store_id'];
            $where['delivery_type'] = 3;
            $where['store_delivery_type'] = 2;
        } else {
            throw new AdminException('配送方式选择有误！');
        }
        unset($where['store_id']);
        if ($where['is_recommend']) {
            $where['min_price'] = bcsub($where['poorDeliveryPrice'],20,2);
            $where['max_price'] = bcadd($where['poorDeliveryPrice'],20,2);
            unset($where['is_recommend'], $where['poorDeliveryPrice']);
        }
        $field = ['id,relation_id,type,pid,delivery_type,store_delivery_type,product_type,store_name,cate_id,image,IFNULL(sales, 0) + IFNULL(ficti, 0) as sales,price,stock,activity,ot_price,spec_type,recommend_image,unit_name,is_vip,vip_price,is_presale_product,is_vip_product,system_form_id,system_form_type,is_presale_product,presale_start_time,presale_end_time,is_limit,limit_num,video_open,video_link,freight,star,store_label_id,brand_id'];
        $list = $this->dao->getSearchList($where, 0, 20, $field, '', ['couponId']);
        if ($list) {
            /** @var MemberCardServices $memberCardService */
            $memberCardService = app()->make(MemberCardServices::class);
            $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price') && sys_config('svip_price_status', 1);
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $systemForms = $systemFormServices->getColumn([['id', 'in', array_unique(array_column($list, 'system_form_id'))], ['is_del', '=', 0]], 'id,value', 'id');
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
            foreach ($list as &$item) {
                $minData = $this->getMinPrice($uid, $item, $discount);
                $item['price_type'] = $minData['price_type'] ?? '';
                $item['level_name'] = $level_name;
                $item['vip_price'] = $item['level_price'] = 0.00;
                if ($item['price_type'] == 'member') {
                    $item['vip_price'] = $minData['vip_price'] ?? 0;
                    if (!$item['is_vip'] || !$vipStatus) {
                        $item['vip_price'] = 0;
                    }
                } else {
                    $item['level_price'] = $minData['vip_price'] ?? 0;
                }
                $custom_form = $systemForms[$item['system_form_id']]['value'] ?? [];
                $item['custom_form'] = is_string($custom_form) ? json_decode($custom_form, true) : $custom_form;
                $item['cart_button'] = $item['product_type'] > 0 || $item['is_presale_product'] || $item['system_form_id'] ? 0 : 1;
                $item['presale_pay_status'] = $this->checkPresaleProductPay((int)$item['id'], $item);
                if (!$item['video_open']) {
                    $item['video_link'] = '';
                }
                $item['store_label'] = [];
                if ($item['store_label_id']) {
                    $item['store_label'] = $storeProductLabelServices->getLabelCache($item['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
                }
                if (isset($item['brand_id']) && $item['brand_id']) {
                    $item['brand_name'] = $this->productIdByBrandName((int)$item['id'], $item);
                }
                $item['activity'] = [];
                if (isset($item['couponId'])) {
                    $item['checkCoupon'] = (bool)count($item['couponId']);
                    unset($item['couponId']);
                } else {
                    $item['checkCoupon'] = false;
                }
            }
        }
        return $list;
    }
}
