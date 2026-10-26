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
declare (strict_types=1);

namespace app\services\activity\coupon;

use app\model\activity\coupon\StoreCouponIssue;
use app\services\BaseServices;
use app\dao\activity\coupon\StoreCouponIssueDao;
use app\services\order\StoreCartServices;
use app\services\other\queue\QueueServices;
use app\services\product\brand\StoreBrandServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreServices;
use app\services\user\member\MemberCardServices;
use app\services\user\member\MemberRightServices;
use app\services\user\UserServices;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;
use think\facade\Route as Url;

/**
 *
 * Class StoreCouponIssueServices
 * @package app\services\activity\coupon
 * @mixin StoreCouponIssueDao
 */
class StoreCouponIssueServices extends BaseServices
{

    use OptionTrait;

    public $_couponType = [0 => "通用券", 1 => "品类券", 2 => '商品券'];

    /**
     * StoreCouponIssueServices constructor.
     * @param StoreCouponIssueDao $dao
     */
    public function __construct(StoreCouponIssueDao $dao)
    {
        $this->dao = $dao;
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
            $where['coupon_issue_type'] = 1;
            $where['relation_id'] = $store_id ?: $where['store_id'];
            unset($where['store_id']);
        } elseif (isset($where['supplier_id']) && $where['supplier_id']) {
            $where['coupon_issue_type'] = 2;
            $where['relation_id'] = $where['supplier_id'];
            unset($where['supplier_id']);
        }

        //已发布
        $onsale = $this->dao->getCount(['is_status' => 1] + $where);
        //待审核
        $unVerify = $this->dao->getCount(['is_status' => 0] + $where);
        //审核未通过
        $refuseVerify = $this->dao->getCount(['is_status' => -1] + $where);
        //强制下架
        $removeVerify = $this->dao->getCount(['is_status' => -2] + $where);

        return [
            ['is_status' => 1, 'name' => '已发布', 'count' => $onsale],
            ['is_status' => 0, 'name' => '待审核', 'count' => $unVerify],
            ['is_status' => -1, 'name' => '审核未通过', 'count' => $refuseVerify],
            ['is_status' => -2, 'name' => '强制下架', 'count' => $removeVerify]
        ];
    }

    /**
     * 获取已发布列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCouponIssueList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_del'] = 0;
        $list = $this->dao->getList($where, $page, $limit);
        foreach ($list as &$item) {
            if (!$item['coupon_time']) {
                $item['coupon_time'] = ceil(($item['end_use_time'] - $item['start_use_time']) / '86400');
            }
            $format = $this->getFormat($item['coupon_issue_type'], $item['relation_id']);
            $item['author'] = $format['author'] ?? '';
            $item['author_image'] = $format['author_image'] ?? '';
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 来源
     * @param int $type
     * @param int $relation_id
     * @return string[]
     */
    public function getFormat(int $type, int $relation_id)
    {
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        if ($type == 1) {//门店
            $storeInfo = $storeServices->get($relation_id);
            if (!$storeInfo) return [];
            $data['author'] = $storeInfo['name'] ?? '';
            $data['author_image'] = $storeInfo['image'] ?? '';
        } else {//平台
            $data['author'] = '平台';
            $data['author_image'] = sys_config('wap_login_logo');
        }
        return $data;
    }

    /**
     * 获取会员优惠券列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getMemberCouponIssueList(array $where, int $page = 0, int $limit = 0, array $with = [])
    {
        return $this->dao->getValidList($where, '*', $page, $limit, $with);
    }

    /**
     * 新增优惠券
     * @param $data
     * @return bool
     */
    public function saveCoupon($data, $id, $coupon_issue_type)
    {
        $data['coupon_issue_type'] = $coupon_issue_type;
        $data['start_use_time'] = strtotime((string)$data['start_use_time']);
        $data['end_use_time'] = strtotime((string)$data['end_use_time']);
        $data['start_time'] = strtotime((string)$data['start_time']);
        $data['end_time'] = strtotime((string)$data['end_time']);
        $data['title'] = $data['coupon_title'];
        $data['remain_count'] = $data['total_count'];
        if ($id) {
            unset($data['coupon_issue_type'], $data['relation_id']);
            $res = $this->dao->update($id, $data);
        } else {
            $data['add_time'] = time();
            $res = $this->dao->save($data);
            if ($data['product_id'] !== '' && $res) {
                $productIds = explode(',', $data['product_id']);
                $couponData = [];
                foreach ($productIds as $product_id) {
                    $couponData[] = ['product_id' => $product_id, 'coupon_id' => $res->id];
                }
                /** @var StoreCouponProductServices $storeCouponProductService */
                $storeCouponProductService = app()->make(StoreCouponProductServices::class);
                $storeCouponProductService->saveAll($couponData);
            }
        }
        if (!$res) throw new AdminException($id ? '编辑优惠券失败' : '添加优惠券失败!');
        return true;
    }


	/**
	 * 修改状态
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function createForm(int $id)
    {
        $issueInfo = $this->dao->get($id);
        if (-1 == $issueInfo['status'] || 1 == $issueInfo['is_del']) throw new ValidateException('状态错误,无法修改');
        $f = [Form::radio('status', '是否开启：', $issueInfo['status'])->options([['label' => '开启', 'value' => 1], ['label' => '关闭', 'value' => 0]])];
        return create_form('状态修改', $f, $this->url('/marketing/coupon/released/status/' . $id), 'PUT');
    }

    /**
     * 领取记录
     * @param int $id
     * @return array
     */
    public function issueLog(int $id)
    {
        $coupon = $this->dao->get($id);
        if (!$coupon) {
            throw new ValidateException('优惠券不存在');
        }
        if ($coupon['category'] != 2) {
            /** @var StoreCouponIssueUserServices $storeCouponIssueUserService */
            $storeCouponIssueUserService = app()->make(StoreCouponIssueUserServices::class);
            return $storeCouponIssueUserService->issueLog(['issue_coupon_id' => $id]);
        } else {//会员券
            /** @var StoreCouponUserServices $storeCouponUserService */
            $storeCouponUserService = app()->make(StoreCouponUserServices::class);
            return $storeCouponUserService->issueLog(['cid' => $id]);
        }

    }

    /**
     * 收银台下单赠送给用户
     */
    public function orderPayGetCoupon($orderInfo,$type="order_get")
    {
        $sendAll=$orderInfo['send_all'] ?? '';
        if(empty($sendAll)){
              return true;
        }
        $sendAll=json_decode($sendAll,true);
        $sendCoupon=$sendAll['coupon'] ?? [];
        if(empty($sendCoupon)){
            return true;
        }
        $uid=$orderInfo['uid'];
        $couponData = [];
        $issueUserData = [];
        $time = time();
        /** @var StoreCouponIssueUserServices $issueUser */
        $issueUser = app()->make(StoreCouponIssueUserServices::class);
        foreach ($sendCoupon as $item) {
            $coupon=StoreCouponIssue::where("id",$item['id'])->find();
            for($i=1;$i<=$item['num'];$i++) {
                $data['cid'] = $item['id'];
                $data['uid'] = $uid;
                $data['oid'] = $orderInfo['id'];
                $data['coupon_title'] = $item['store_name'];
                $data['coupon_price'] = $coupon['coupon_price'];
                $data['use_min_price'] = $coupon['use_min_price'];
                $data['add_time'] = $time;
                $data['start_time'] = $item['begin_time'];
                $data['end_time'] = $item['end_time'];
                $data['type'] = $type;
                $issue = [];
                $issue['uid'] = $uid;
                $issue['issue_coupon_id'] = $item['id'];
                $issue['add_time'] = $time;
                $issueUserData[] = $issue;
                $couponData[] = $data;
                if (!$coupon['is_permanent']) {
                    $remain_count = $coupon['remain_count'] > 0 ? $coupon['remain_count'] - 1 : 0;
                    $this->dao->update($item['id'], ['remain_count' => $remain_count]);
                }
                unset($data);
                unset($issue);
            }
        }
        if ($couponData) {
            /** @var StoreCouponUserServices $storeCouponUser */
            $storeCouponUser = app()->make(StoreCouponUserServices::class);
            if (!$storeCouponUser->saveAll($couponData)) {
                throw new AdminException('发劵失败');
            }
        }
        if ($issueUserData) {
            if (!$issueUser->saveAll($issueUserData)) {
                throw new AdminException('发劵失败');
            }
        }
        return $couponData;
    }
    /**
     * 保存赠送给用户优惠券
     * @param int $uid
     * @param array $couponList
     * @param string $type
     * @return array
     */
    public function saveGiveCoupon(int $uid, array $couponList, string $type = 'get')
    {
        if (!$uid || !$couponList) {
            return [];
        }
        $couponData = [];
        $issueUserData = [];
        $time = time();
        /** @var StoreCouponIssueUserServices $issueUser */
        $issueUser = app()->make(StoreCouponIssueUserServices::class);
        foreach ($couponList as $item) {
            $data['cid'] = $item['id'];
            $data['uid'] = $uid;
            $data['coupon_title'] = $item['title'];
            $data['coupon_price'] = $item['coupon_price'];
            $data['use_min_price'] = $item['use_min_price'];
            $data['add_time'] = $time;
            if ($item['coupon_time']) {
                $data['start_time'] = $time;
                $data['end_time'] = $data['add_time'] + $item['coupon_time'] * 86400;
            } else {
                $data['start_time'] = $item['start_use_time'];
                $data['end_time'] = $item['end_use_time'];
            }
            $data['type'] = $type;
            $issue['uid'] = $uid;
            $issue['issue_coupon_id'] = $item['id'];
            $issue['add_time'] = $time;
            $issueUserData[] = $issue;
            $couponData[] = $data;
            if (!$item['is_permanent']) {
                $remain_count = $item['remain_count'] > 0 ? $item['remain_count'] - 1 : 0;
                $this->dao->update($item['id'], ['remain_count' => $remain_count]);
            }
            unset($data);
            unset($issue);
        }
        if ($couponData) {
            /** @var StoreCouponUserServices $storeCouponUser */
            $storeCouponUser = app()->make(StoreCouponUserServices::class);
            if (!$storeCouponUser->saveAll($couponData)) {
                throw new AdminException('发劵失败');
            }
        }
        if ($issueUserData) {
            if (!$issueUser->saveAll($issueUserData)) {
                throw new AdminException('发劵失败');
            }
        }
        return $couponData;
    }

    /**
     * 新人礼赠送优惠券
     * @param int $uid
     */
    public function newcomerGiveCoupon(int $uid)
    {
        if (!sys_config('newcomer_status')) {
            return false;
        }
        $status = sys_config('register_coupon_status');
        if (!$status) {//未开启
            return true;
        }
        $couponIds = sys_config('register_give_coupon', []);
        if (!$couponIds) {
            return true;
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo($uid);
        if (!$userInfo) {
            return false;
        }
        //不是新用户
        if (!$userServices->checkUserIsNew($uid)) {
            return false;
        }
        $couponList = $this->dao->getGiveCoupon([['id', 'IN', $couponIds]]);
        if ($couponList) {
            $this->saveGiveCoupon($uid, $couponList, 'newcomer');
        }
        return true;
    }

    /**
     * 会员卡激活赠送优惠券
     * @param int $uid
     */
    public function levelGiveCoupon(int $uid)
    {
        $status = sys_config('level_activate_status');
        if (!$status) {//是否需要激活
            return true;
        }
        $status = sys_config('level_coupon_status');
        if (!$status) {//未开启
            return true;
        }
        $couponIds = sys_config('level_give_coupon', []);
        if (!$couponIds) {
            return true;
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserInfo($uid);
        if (!$userInfo) {
            return true;
        }
        $couponList = $this->dao->getGiveCoupon([['id', 'IN', $couponIds]]);
        if ($couponList) {
            $this->saveGiveCoupon($uid, $couponList, 'activate_level');
        }
        return true;
    }

    /**
     * 关注送优惠券
     * @param int $uid
     */
    public function userFirstSubGiveCoupon(int $uid)
    {
        $couponList = $this->dao->getGiveCoupon(['receive_type' => 2]);
        if ($couponList) {
            $this->saveGiveCoupon($uid, $couponList, 'user_first');
        }
        return true;
    }

    /**
     * 订单金额达到预设金额赠送优惠卷
     * @param $uid
     */
    public function userTakeOrderGiveCoupon($uid, $total_price)
    {
        $couponList = $this->dao->getGiveCoupon([['is_full_give', '=', 1], ['full_reduction', '<=', $total_price]]);
        if ($couponList) {
            $res = [];
            foreach ($couponList as $item) {
                if ($total_price >= $item['full_reduction']) {
                    $res[] = $item;
                }
            }
            $this->saveGiveCoupon($uid, $res);
        }
        return true;
    }

    /**
     * 下单之后赠送
     * @param $uid
     */
    public function orderPayGiveCoupon($uid, $coupon_issue_ids)
    {
        if (!$coupon_issue_ids) return [];
        $couponList = $this->dao->getGiveCoupon([['id', 'IN', $coupon_issue_ids]]);
        $couponData = [];
        if ($couponList) {
            $couponData = $this->saveGiveCoupon($uid, $couponList, 'order');
        }
        return $couponData;
    }

    /**
     * 获取商品关联
     * @param int $uid
     * @param int $product_id
     * @param string $field
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductCouponList(int $uid, int $product_id, string $field = '*', int $limit = 0)
    {
        if ($limit) {
            $page = 1;
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        /** @var StoreProductServices $storeProductService */
        $storeProductService = app()->make(StoreProductServices::class);
        /** @var StoreProductCategoryServices $storeCategoryService */
        $storeCategoryService = app()->make(StoreProductCategoryServices::class);

        $productInfo = $storeProductService->getCacheProductInfo($product_id);
        $cateId = explode(',', $productInfo['cate_id']);
        $cateId = array_merge($cateId, $storeCategoryService->cateIdByPid($cateId));
        $cateId = array_unique(array_diff($cateId, [0]));
        $brandIds = [];
        if ($productInfo['brand_id']) {
            /** @var StoreBrandServices $storeBrandServices */
            $storeBrandServices = app()->make(StoreBrandServices::class);
            $brandInfo = $storeBrandServices->get((int)$productInfo['brand_id'], ['id', 'pid']);
            if ($brandInfo) {
                $brandIds = $brandInfo->toArray();
                $brandIds = array_diff($brandIds, [0]);
            }
        }
        $where = ['product_id' => $productInfo['pid'] ?: $product_id, 'cate_id' => $cateId, 'brand_id' => $brandIds];
        $list = $this->dao->getIssueCouponListNew($uid, $where, $field, $page, $limit);

        //门店ID
        $store_id = $productInfo['type'] == 1 ? ($productInfo['relation_id'] ?? 0) : 0;
        $result = [];
        foreach ($list as &$item) {
            if ($store_id && isset($item['applicable_type']) && isset($item['applicable_store_id']) && isset($item['coupon_issue_type']) && isset($item['relation_id'])) {
                $applicable_store_id = is_array($item['applicable_store_id']) ? $item['applicable_store_id'] : explode(',', $item['applicable_store_id']);
                //活动不适用该门店
                if ($item['applicable_type'] == 0 || ($item['applicable_type'] == 2 && !in_array($store_id, $applicable_store_id)) || ($item['coupon_issue_type'] == 1 && $item['relation_id'] != $store_id)) {
                    continue;
                }
            }
            $item['coupon_price'] = floatval($item['coupon_price']);
            $item['use_min_price'] = floatval($item['use_min_price']);
            $item['is_use'] = $uid && isset($item['used']) && count($item['used']) > 0;
            $item['use_status'] = 1;
            if (isset($item['used']) && count($item['used']) > 0 && $uid) {
                $status = array_column($item['used'], 'status');
                if (count($status) > 0 && in_array('未使用', $status)) {
                    $item['use_status'] = 1;
                } else {
                    $item['use_status'] = 0;
                }
            }
            if (!$item['end_time']) {
                $item['start_time'] = '';
                $item['end_time'] = '不限时';
            } else {
                $item['start_time'] = date('Y/m/d', $item['start_time']);
                $item['end_time'] = $item['end_time'] ? date('Y/m/d', $item['end_time']) : date('Y/m/d', time() + 86400);
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 获取用户已获得的后台赠送优惠券
     * @param int $uid
     * @param int $product_id
     * @param string $field
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getIssueCouponListReceive(int $uid, int $product_id, string $field = '*', int $limit = 0)
    {
        /** @var StoreProductServices $storeProductService */
        $storeProductService = app()->make(StoreProductServices::class);
        /** @var StoreProductCategoryServices $storeCategoryService */
        $storeCategoryService = app()->make(StoreProductCategoryServices::class);

        $productInfo = $storeProductService->getCacheProductInfo($product_id);
        $cateId = explode(',', $productInfo['cate_id']);
        $cateId = array_merge($cateId, $storeCategoryService->cateIdByPid($cateId));
        $cateId = array_unique(array_diff($cateId, [0]));
        $brandIds = [];
        if ($productInfo['brand_id']) {
            /** @var StoreBrandServices $storeBrandServices */
            $storeBrandServices = app()->make(StoreBrandServices::class);
            $brandInfo = $storeBrandServices->get((int)$productInfo['brand_id'], ['id', 'pid']);
            if ($brandInfo) {
                $brandIds = $brandInfo->toArray();
                $brandIds = array_diff($brandIds, [0]);
            }
        }
        $where = ['product_id' => $productInfo['pid'] ?: $product_id, 'cate_id' => $cateId, 'brand_id' => $brandIds];
        $list = $this->dao->getIssueCouponListReceive($uid, $where, $field, 0, $limit);

        //门店ID
        $store_id = $productInfo['type'] == 1 ? ($productInfo['relation_id'] ?? 0) : 0;
        $result = [];
        foreach ($list as $key => &$item) {
            if ($store_id && isset($item['applicable_type']) && isset($item['applicable_store_id']) && isset($item['coupon_issue_type']) && isset($item['relation_id'])) {
                $applicable_store_id = is_array($item['applicable_store_id']) ? $item['applicable_store_id'] : explode(',', $item['applicable_store_id']);
                //活动不适用该门店
                if ($item['applicable_type'] == 0 || ($item['applicable_type'] == 2 && !in_array($store_id, $applicable_store_id)) || ($item['coupon_issue_type'] == 1 && $item['relation_id'] != $store_id)) {
                    continue;
                }
            }
            $item['coupon_price'] = floatval($item['coupon_price']);
            $item['use_min_price'] = floatval($item['use_min_price']);
            $item['is_use'] = $uid && isset($item['used']) && count($item['used']) > 0;
            $item['use_status'] = 1;
            if (isset($item['used']) && count($item['used']) > 0 && $uid) {
                $status = array_column($item['used'], 'status');
                if (count($status) > 0 && in_array('未使用', $status)) {
                    $item['use_status'] = 1;
                } else {
                    $item['use_status'] = 0;
                }
            } else {
                continue;
            }
            if (!$item['end_time']) {
                $item['start_time'] = '';
                $item['end_time'] = '不限时';
            } else {
                $item['start_time'] = date('Y/m/d', $item['start_time']);
                $item['end_time'] = $item['end_time'] ? date('Y/m/d', $item['end_time']) : date('Y/m/d', time() + 86400);
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 获取优惠券列表
     * @param int $uid
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getIssueCouponList(int $uid, array $where, string $field = '*', bool $is_product = false)
    {
        [$page, $limit] = $this->getPageValue();
        /** @var StoreProductServices $storeProductService */
        $storeProductService = app()->make(StoreProductServices::class);
        /** @var StoreProductCategoryServices $storeCategoryService */
        $storeCategoryService = app()->make(StoreProductCategoryServices::class);
        /** @var StoreBrandServices $storeBrandServices */
        $storeBrandServices = app()->make(StoreBrandServices::class);
        $typeId = 0;
        $cateId = 0;
        $bandId = 0;
        $store_id = 0;
        $product_id = 0;
        if ($where['product_id'] == 0) {
//            if ($where['type'] == -1) { // PC端获取优惠券
//                $list = $this->dao->getIssueCouponListNew($uid, $where, $field);
//            } else {
            $list = $this->dao->getIssueCouponListNew($uid, $where, $field, $page, $limit);
//                if (!$list) $list = $this->dao->getIssueCouponListNew($uid, ['type' => 1], $field, $page, $limit);
//                if (!$list) $list = $this->dao->getIssueCouponListNew($uid, ['type' => 2], $field, $page, $limit);
//                if (!$list) $list = $this->dao->getIssueCouponListNew($uid, ['type' => 3], $field, $page, $limit);
//            }
        } else {
            $productInfo = $storeProductService->getOne(['id' => $where['product_id']], 'id,type,relation_id,cate_id,brand_id');
            if ($productInfo) {
                //门店ID
                $store_id = $productInfo['type'] == 1 ? ($productInfo['relation_id'] ?? 0) : 0;
                $product_id = $productInfo['pid'] ?: $where['product_id'];
                $cateId = $productInfo['cate_id'];
                if ($cateId) {
                    $cateId = explode(',', $cateId);
                    $cateId = array_merge($cateId, $storeCategoryService->cateIdByPid($cateId));
                    $cateId = array_diff($cateId, [0]);
                }
                if ($productInfo['brand_id']) {
                    /** @var StoreBrandServices $storeBrandServices */
                    $storeBrandServices = app()->make(StoreBrandServices::class);
                    $brandInfo = $storeBrandServices->get((int)$productInfo['brand_id'], ['id', 'pid']);
                    if ($brandInfo) {
                        $bandId = $brandInfo->toArray();
                        $bandId = array_diff($bandId, [0]);
                    }
                }
            }

            if ($where['type'] == -1) { // PC端获取优惠券
                $list = $this->dao->getIssueCouponListNew($uid, ['product_id' => $product_id, 'cate_id' => $cateId, 'brand_id' => $bandId], $field);
            } else {
                $coupon_where = ['type' => $where['type']];
                if ($where['type'] == 1) {
                    $coupon_where['cate_id'] = $cateId;
                } elseif ($where['type'] == 2) {
                    $coupon_where['product_id'] = $where['product_id'];
                } elseif ($where['type'] == 3) {
                    $coupon_where['brand_id'] = $where['brand_id'];
                }
                $list = $this->dao->getIssueCouponListNew($uid, $coupon_where, $field, $page, $limit);
            }
        }
        $result = [];
        if ($list) {
            $limit = 3;
            $field = ['id', 'image', 'store_name', 'price', 'IFNULL(sales,0) + IFNULL(ficti,0) as sales'];
            $category = $storeCategoryService->getColumn([], 'pid,cate_name', 'id');
            $brands = $storeBrandServices->getColumn([], 'id,pid', 'id');
            foreach ($list as &$item) {
                if ($store_id && isset($item['applicable_type']) && isset($item['applicable_store_id']) && isset($item['coupon_issue_type']) && isset($item['relation_id'])) {
                    $applicable_store_id = is_array($item['applicable_store_id']) ? $item['applicable_store_id'] : explode(',', $item['applicable_store_id']);
                    //活动不适用该门店
                    if ($item['applicable_type'] == 0 || ($item['applicable_type'] == 2 && !in_array($store_id, $applicable_store_id)) || ($item['coupon_issue_type'] == 1 && $item['relation_id'] != $store_id)) {
                        continue;
                    }
                }
                $item['coupon_price'] = floatval($item['coupon_price']);
                $item['top_discount_price'] = floatval($item['top_discount_price']);
                $item['use_min_price'] = floatval($item['use_min_price']);
                $item['is_use'] = false;
                if (isset($item['used']) && $uid && count($item['used']) > 0) {
                    $item['is_use'] = (count($item['used']) < $item['quantity_count'] || $item['is_claimed']) ? false : true;
                    $item['quantity_count'] = $item['quantity_count'] - count($item['used']);
                }
                $item['start_time'] = $item['end_time'] = '';
                if ($item['coupon_time'] && !$item['is_use']) {//领取几天内使用
                    foreach ($item['used'] as $value) {
                        if (isset($value['end_time']) && isset($value['start_time'])) {
                            $item['start_time'] = $value['start_time'] ? date('Y/m/d', $value['start_time']) : '';
                            $item['end_time'] = $value['end_time'] ? date('Y/m/d', $value['end_time']) : '';
                            continue;
                        }
                    }
                } else {//指定时间段使用
                    $item['start_time'] = $item['start_use_time'] ? date('Y/m/d', $item['start_use_time']) : '';
                    $item['end_time'] = $item['end_use_time'] ? date('Y/m/d', $item['end_use_time']) : '';
                }
                if (!$is_product) {//不需要返回优惠券使用商品
                    $result[] = $item;
                    continue;
                }

                $product_where = ['is_vip_product' => 0, 'is_verify' => 1, 'pid' => 0, 'is_show' => 1, 'is_del' => 0];
                $product_where['use_min_price'] = $item['use_min_price'];
                switch ($item['type']) {
                    case 0:
                        break;
                    case 1://品类券
                        $product_where['salesOrder'] = 'desc';
                        $category_type = ($category[$item['category_id'] ?? 0]['pid'] ?? 0) == 0 ? 1 : 2;
                        if ($category_type == 1) {
                            $product_where['cid'] = $item['category_id'];
                        } else {
                            $product_where['sid'] = $item['category_id'];
                        }
                        break;
                    case 2://商品劵
                        $product_where['salesOrder'] = 'desc';
                        $product_where['ids'] = $item['product_id'];
                        break;
                    case 3://品牌券
                        $product_where['salesOrder'] = 'desc';
                        $product_where['brand_id'] = $brands[$item['brand_id']] ?? [];
                        break;
                    default:
                        $item['products'] = [];
                }
                $item['products'] = get_thumb_water($storeProductService->getSearchList($product_where, 0, $limit, $field, $item['type'] == 0 ? 'rand' : '', []));

                $result[] = $item;
            }
        }
        $data['list'] = $result;
        $relation_id = 0;
        if (isset($where['relation_id']) && $where['relation_id'] > 0) {
            $relation_id = $where['relation_id'];
        }
        $data['count'] = $this->dao->getIssueCouponCount($product_id, $cateId, $bandId, $relation_id);
        return $data;
    }

    /**
     * 给用户发送优惠券
     * @param $id
     * @param $user
     * @param bool $more
     * @param string $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function issueUserCoupon($id, $user, bool $more = true, string $type = 'get')
    {
        $issueCouponInfo = $this->dao->getInfo((int)$id);
        $uid = $user['uid'];
        if (!$issueCouponInfo) {
            throw new ValidateException('领取的优惠劵已领完或已过期!');
        }
        if ($issueCouponInfo['remain_count'] <= 0 && !$issueCouponInfo['is_permanent']) {
            throw new ValidateException('抱歉优惠券已经领取完了！');
        }
        //领取付费会员券
        if ($issueCouponInfo['category'] == 2) {
            /** @var MemberRightServices $memberRightService */
            $memberRightService = app()->make(MemberRightServices::class);
            if (!$memberRightService->getMemberRightStatus("coupon")) {
                throw new ValidateException('暂时无法领取!');
            }
            $userServices = app()->make(UserServices::class);
            if (!$userServices->checkUserIsSvip($uid)) {
                throw new ValidateException('请先购买付费会员后领取!');
            }
        }
        /** @var StoreCouponIssueUserServices $issueUserService */
        $issueUserService = app()->make(StoreCouponIssueUserServices::class);
        /** @var StoreCouponUserServices $couponUserService */
        $couponUserService = app()->make(StoreCouponUserServices::class);
        //是否多次领取
//        if (!$more) {
//            if ($issueUserService->getOne(['uid' => $uid, 'issue_coupon_id' => $id])) {
//                throw new ValidateException('已领取过该优惠劵!');
//            }
//        }
        if ($issueCouponInfo->remain_count <= 0 && !$issueCouponInfo->is_permanent) throw new ValidateException('抱歉优惠券已经领取完了！');
        if (!$issueCouponInfo['is_claimed'] && $type == 'get' && $issueCouponInfo['receive_type'] == 1) {
            $user_quantity_count = $issueUserService->effectiveClaimCount((int)$uid, (int)$id);
            if ($user_quantity_count >= $issueCouponInfo['quantity_count']) {
                throw new ValidateException('抱歉该优惠券您不能再领取了!');
            }
        }
        $userCounpon = $this->transaction(function () use ($issueUserService, $uid, $id, $couponUserService, $issueCouponInfo, $type) {
            $issueUserService->save(['uid' => $uid, 'issue_coupon_id' => $id, 'add_time' => time()]);
            $res = $couponUserService->addUserCoupon($uid, $issueCouponInfo, $type);
            if ($issueCouponInfo['total_count'] > 0) {
                $issueCouponInfo['remain_count'] -= 1;
                $issueCouponInfo->save();
            }
            return $res;
        });
        return $userCounpon;
    }

    /**
     * 会员发放优惠期券
     * @param $id
     * @param $uid
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function memberIssueUserCoupon($id, $uid)
    {
        $issueCouponInfo = $this->dao->getInfo((int)$id);
        if ($issueCouponInfo) {
            /** @var StoreCouponIssueUserServices $issueUserService */
            $issueUserService = app()->make(StoreCouponIssueUserServices::class);
            /** @var StoreCouponUserServices $couponUserService */
            $couponUserService = app()->make(StoreCouponUserServices::class);
            if ($issueCouponInfo->remain_count >= 0 || $issueCouponInfo->is_permanent) {
                $this->transaction(function () use ($issueUserService, $uid, $id, $couponUserService, $issueCouponInfo) {
                    //$issueUserService->save(['uid' => $uid, 'issue_coupon_id' => $id, 'add_time' => time()]);
                    $couponUserService->addMemberUserCoupon($uid, $issueCouponInfo, "send");
                    // 如果会员劵需要限制数量时打开
                    if ($issueCouponInfo['total_count'] > 0) {
                        $issueCouponInfo['remain_count'] -= 1;
                        $issueCouponInfo->save();
                    }
                });
            }

        }

    }

    /**
     * 用户优惠劵列表
     * @param int $uid
     * @param $types
     * @return array
     */
    public function getUserCouponList(int $uid, $types)
    {
        /** @var StoreCouponUserServices $storeConponUser */
        $storeConponUser = app()->make(StoreCouponUserServices::class);
        return $storeConponUser->getUserCounpon($uid, $types);
    }

    /**
     * 后台发送优惠券
     * @param $coupon
     * @param $user
     * @return bool
     */
    public function setCoupon($coupon, $user, $redisKey, $queueId, $is_single = false)
    {
        if ((!$redisKey || !$queueId || !$user) && !$is_single) return false;
        $data = [];
        $issueData = [];
        /** @var StoreCouponUserServices $storeCouponUser */
        $storeCouponUser = app()->make(StoreCouponUserServices::class);
        /** @var StoreCouponIssueUserServices $storeCouponIssueUser */
        $storeCouponIssueUser = app()->make(StoreCouponIssueUserServices::class);
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $i = 0;
        $time = time();
        $remainCount = 0;
        if (!$coupon['is_permanent']) {
            $count = count($user);
            $remain_count = $this->dao->value(['id' => $coupon['id']], 'remain_count');
            if ($remain_count < $count) {
                $user = array_slice($user, 0, $remain_count);
            } else {
                $remainCount = $remain_count - $count;
            }
        }
        foreach ($user as $k => $v) {
            $data[$i]['cid'] = $coupon['id'];
            $data[$i]['uid'] = $v;
            $data[$i]['coupon_title'] = $coupon['title'];
            $data[$i]['coupon_price'] = $coupon['coupon_price'];
            $data[$i]['use_min_price'] = $coupon['use_min_price'];
            $data[$i]['add_time'] = $time;
            if ($coupon['coupon_time']) {
                $data[$i]['start_time'] = $time;
                $data[$i]['end_time'] = $time + $coupon['coupon_time'] * 86400;
            } else {
                $data[$i]['start_time'] = $coupon['start_use_time'];
                $data[$i]['end_time'] = $coupon['end_use_time'];
            }
            $data[$i]['type'] = 'send';
            $issueData[$i]['uid'] = $v;
            $issueData[$i]['issue_coupon_id'] = $coupon['id'];
            $issueData[$i]['add_time'] = time();
            $i++;
        }
        $res = $this->transaction(function () use ($data, $issueData, $storeCouponUser, $storeCouponIssueUser) {
            $res1 = $res2 = true;
            if ($data) {
                $res1 = $storeCouponUser->saveAll($data);
            }
            if ($issueData) {
                $res2 = $storeCouponIssueUser->saveAll($issueData);
            }
            return $res1 && $res2;
        });
        if (!$res) {
            //发券失败后将队列状态置为失败
            $queueService->setQueueFail($queueId['id'], $redisKey);
        } else {
            if (!$coupon['is_permanent']) {
                $this->dao->update($coupon['id'], ['remain_count' => $remainCount]);
            }
            if ($is_single) return true;
            //发券成功的用户踢出集合
            $queueService->doSuccessSremRedis($user, $redisKey, $queueId['type']);
        }
        return true;
    }

    /**
     * 获取下单可使用的优惠券列表
     * @param int $uid
     * @param $cartId
     * @param bool $new
     * @param int $shipping_type
     * @param int $store_id
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function beUsableCouponList(int $uid, $cartId, bool $new, int $shipping_type = 1, int $store_id = 0, int $is_store_delivery_type = 0)
    {
        /** @var StoreCartServices $services */
        $services = app()->make(StoreCartServices::class);
        $cartGroup = $services->getUserProductCartListV1($uid, $cartId, $new, [], $shipping_type, $store_id,0,false,$is_store_delivery_type);
        if (isset($cartGroup['deduction']['activity_id']) && !$cartGroup['deduction']['activity_id'] && isset($cartGroup['deduction']['type']) && $cartGroup['deduction']['type'] != 6) {
            /** @var StoreCouponUserServices $coupServices */
            $coupServices = app()->make(StoreCouponUserServices::class);
            return $coupServices->getUsableCouponList($uid, $cartGroup, $store_id);
        } else {
            return [];
        }
    }

    /**获取单个优惠券类型
     * @param array $where
     * @return mixed
     */
    public function getOne(array $where)
    {
        if (!$where) throw new AdminException('参数缺失!');
        return $this->dao->getOne($where);

    }

    /**
     * 俩时间相差月份
     * @param $date1
     * @param $date2
     * @return float|int
     */
    public function getMonthNum($date1, $date2)
    {
        $date1_stamp = strtotime($date1);
        $date2_stamp = strtotime($date2);
        [$date_1['y'], $date_1['m']] = explode("-", date('Y-m', $date1_stamp));
        [$date_2['y'], $date_2['m']] = explode("-", date('Y-m', $date2_stamp));
        return abs($date_1['y'] - $date_2['y']) * 12 + $date_2['m'] - $date_1['m'];
    }

    /**
     * 给会员发放优惠券
     * @param $uid
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function sendMemberCoupon($uid, $couponId = 0)
    {
        if (!$uid) return false;
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        /** @var StoreCouponUserServices $couponUserService */
        $couponUserService = app()->make(StoreCouponUserServices::class);
        /** @var MemberRightServices $memberRightService */
        $memberRightService = app()->make(MemberRightServices::class);
        /** @var MemberCardServices $memberCardService */
        $memberCardService = app()->make(MemberCardServices::class);
        //看付费会员是否开启
        $isOpenMember = $memberCardService->isOpenMemberCardCache();
        if (!$isOpenMember) return false;
        $userInfo = $userService->getUserInfo((int)$uid);
        //看是否会员过期
        if ($userInfo['is_ever_level'] == 0 && $userInfo['is_money_level'] > 0 && $userInfo['overdue_time'] < time()) {
            return false;
        }
        //看是否开启会员送券
        $isSendCoupon = $memberRightService->getMemberRightStatus("coupon");
        if (!$isSendCoupon) return false;
        if ($userInfo && (($userInfo['is_money_level'] > 0) || $userInfo['is_ever_level'] == 1)) {
            $monthNum = $this->getMonthNum(date('Y-m-d H:i:s', time()), date('Y-m-d H:i:s', $userInfo['overdue_time']));
            if ($couponId) {//手动点击领取
                $couponWhere['id'] = $couponId;
                $couponInfo = $this->getMemberCouponIssueList($couponWhere);
            } else {//主动批量发放
                $couponWhere['status'] = 1;
                $couponWhere['category'] = 2;
                $couponWhere['is_del'] = 0;
                $couponInfo = $this->getMemberCouponIssueList($couponWhere);
            }
            if ($couponInfo) {
                $couponIds = array_column($couponInfo, 'id');
                $couponUserMonth = $couponUserService->memberCouponUserGroupBymonth(['uid' => $uid, 'couponIds' => $couponIds]);
                $getTime = array();
                if ($couponUserMonth) {
                    $getTime = array_column($couponUserMonth, 'num', 'time');
                }
                // 判断这个月是否领取过,而且领全了
                //if (in_array(date('Y-m', time()), $getTime)) return false;
                $timeKey = date('Y-m', time());
                if (array_key_exists($timeKey, $getTime) && $getTime[$timeKey] == count($couponIds)) return false;
                //判断是否领完所有月份
                if (count($getTime) >= $monthNum && (array_key_exists($timeKey, $getTime) && $getTime[$timeKey] == count($couponIds)) && $userInfo['is_ever_level'] != 1 && $monthNum > 0) return false;
                foreach ($couponInfo as $cv) {
                    //看之前是否手动领取过某一张，领取过就不再领取。
                    $couponUser = $couponUserService->getUserCounponByMonth(['uid' => $uid, 'cid' => $cv['id']]);
                    if (!$couponUser) {
                        $this->memberIssueUserCoupon($cv['id'], $uid);
                    }
                }

            }
        }
        return true;
    }

    /**
     * 获取今日新增优惠券
     * @param int $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTodayCoupon(int $uid)
    {
        $receive_type = [1, 4];
        if ($uid) {//用户登录 && 不是付费会员
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            if (!$userService->checkUserIsSvip($uid)) {
                $receive_type = [1];
            }
        }
        $list = $this->dao->getTodayCoupon($uid, $receive_type);
        foreach ($list as $key => &$item) {
            $item['start_time'] = $item['start_time'] ? date('Y/m/d', $item['start_time']) : 0;
            $item['end_time'] = $item['end_time'] ? date('Y/m/d', $item['end_time']) : 0;
            $item['coupon_price'] = floatval($item['coupon_price']);
            $item['use_min_price'] = floatval($item['use_min_price']);
            if (isset($item['used']) && $item['used']) {
                unset($list[$key]);
            }
        }
        return array_merge($list);
    }

    /**
     * 获取新人券
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getNewCoupon()
    {
        $list = $this->dao->getNewCoupon();
        foreach ($list as &$item) {
            $item['start_time'] = $item['start_time'] ? date('Y/m/d', $item['start_time']) : 0;
            $item['end_time'] = $item['end_time'] ? date('Y/m/d', $item['end_time']) : 0;
            $item['coupon_price'] = floatval($item['coupon_price']);
            $item['use_min_price'] = floatval($item['use_min_price']);
        }
        return $list;
    }

    /**
     * 获取可以使用优惠券
     * @param int $uid
     * @param array $cartList
     * @param array $promotions
     * @param int $store_id
     * @param bool $isMax
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCanUseCoupon(int $uid, array $cartList = [], array $promotions = [], int $store_id = 0, bool $isMax = true)
    {
        $where = ['receive_type' => 1, 'category' => 1];
        //传入是付费会员 获取付费会员券
        $isVip = $this->getItem('isVip', 0);
        if ($uid && !$isVip) {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            $isVip = $userService->checkUserIsSvip($uid);
        }
        if ($isVip) {
            unset($where['category']);
        }
        /** @var StoreCouponUserServices $coupServices */
        $coupServices = app()->make(StoreCouponUserServices::class);
        //用户持有有效券
        $userCoupons = $coupServices->getUserAllCoupon($uid);
        if ($userCoupons) {
            $where['not_id'] = array_column($userCoupons, 'cid');
        }
        $where['relation_id'] = [0, $store_id];
        $counpons = $this->dao->getValidList($where, '*', 0, 0, ['used' => function ($query) use ($uid) {
            $query->where(['uid' => $uid]);
        }]);
        //用户已经领取的 && 没有领取的
        $counpons = array_merge($counpons, $userCoupons);
        $result = [];
        if ($counpons) {
            $promotionsList = [];
            if ($promotions) {
                $promotionsList = array_combine(array_column($promotions, 'id'), $promotions);
            }
            //验证是否适用门店
            $isApplicableStore = function ($couponInfo) use ($store_id) {
//                if (isset($couponInfo['applicable_type']) && isset($couponInfo['applicable_store_id'])  && isset($couponInfo['coupon_issue_type']) && isset($couponInfo['relation_id'])) {
//                    $applicable_store_id = is_array($couponInfo['applicable_store_id']) ? $couponInfo['applicable_store_id'] : explode(',', $couponInfo['applicable_store_id']);
//                    if (!$store_id && in_array($couponInfo['coupon_issue_type'],[1,2]) || $store_id && ($couponInfo['applicable_type'] == 0 || ($couponInfo['applicable_type'] == 2 && !in_array($store_id, $applicable_store_id)) || ($couponInfo['coupon_issue_type'] == 1 && $couponInfo['relation_id'] != $store_id))) {
//                        return false;
//                    }
//                }
                return true;
            };
            $isOverlay = function ($cart) use ($promotionsList) {
                $productInfo = $cart['productInfo'] ?? [];
                if (!$productInfo) {
                    return false;
                }
                //门店独立商品 不使用优惠券
//                $isBranchProduct = isset($productInfo['type']) && isset($productInfo['pid']) && $productInfo['type'] == 1 && !$productInfo['pid'];
//                if ($isBranchProduct) {
//                    return false;
//                }
                if (isset($cart['promotions_id']) && $cart['promotions_id']) {
                    foreach ($cart['promotions_id'] as $key => $promotions_id) {
                        $promotions = $promotionsList[$promotions_id] ?? [];
                        if ($promotions && $promotions['promotions_type'] != 4) {
                            $overlay = is_string($promotions['overlay']) ? explode(',', $promotions['overlay']) : $promotions['overlay'];
                            if (!in_array(5, $overlay)) {
                                return false;
                            }
                        }
                    }
                }
                return true;
            };
            /** @var StoreProductCategoryServices $storeCategoryServices */
            $storeCategoryServices = app()->make(StoreProductCategoryServices::class);
            /** @var StoreBrandServices $storeBrandServices */
            $storeBrandServices = app()->make(StoreBrandServices::class);
            foreach ($counpons as $coupon) {
                if (isset($coupon['used'])) {
					$isValid = false;
					foreach ($coupon['used'] as $item) {//是否存在有效的
						if ($item['start_time']<= time() && $item['end_time'] >= time()) {
							$isValid = true;
							continue;
						}
					}
					if (!$isValid) {
						if (count($coupon['used']) >= $coupon['quantity_count'] && !$coupon['is_claimed']) {//不能再次领取了
							continue;
						} else {//领取的是过期无效，重新领取
							$coupon['used'] = [];
						}
					}
                }
                // 已经使用过的优惠券不展示（收银台规则）
                if (isset($coupon['used']) && $coupon['used']) {
                    $hasUsed = false;
                    foreach ($coupon['used'] as $u) {
                        if (!empty($u['use_time'])) {
                            $hasUsed = true;
                            break;
                        }
                    }
                    if ($hasUsed) {
                        continue;
                    }
                }
                if (!$isApplicableStore($coupon)) {//不适用门店跳过
                    continue;
                }
                $price = 0;
                $count = 0;
                if (isset($coupon['coupon_applicable_type'])) {//已经领取 有效优惠券
                    $coupon['type'] = $coupon['coupon_applicable_type'];
                    $coupon['used'][] = ['id' => $coupon['id'], 'cid' => $coupon['cid']];
                }
                //&& in_array($promotions['promotions_type'], $overlay)
                switch ($coupon['type']) {
                    case 0:
                        foreach ($cartList as $cart) {
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                            $count++;
                        }
                        break;
                    case 1://品类券
                        $cateGorys = $storeCategoryServices->getAllById((int)$coupon['category_id']);
                        if ($cateGorys) {
                            $cateIds = array_column($cateGorys, 'id');
                            foreach ($cartList as $cart) {
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['cate_id']) && array_intersect(explode(',', $cart['productInfo']['cate_id']), $cateIds) || isset($cart['productInfo']['store_cate_id']) && array_intersect(explode(',', $cart['productInfo']['store_cate_id']), $cateIds)) {
                                    $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                                    $count++;
                                }
                            }
                        }
                        break;
                    case 2://商品券
                        foreach ($cartList as $cart) {
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $product_id = isset($cart['productInfo']['id']) && $cart['productInfo']['id'] ? $cart['productInfo']['id'] : ($cart['product_id'] ?? 0);
                            if ($product_id && in_array($product_id, explode(',', $coupon['product_id']))) {
                                $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                                $count++;
                            }
                        }
                        break;
                    case 3://品牌券
                        $brands = $storeBrandServices->getAllById((int)$coupon['brand_id']);
                        if ($brands) {
                            $brandIds = array_column($brands, 'id');
                            foreach ($cartList as $cart) {
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['brand_id']) && in_array($cart['productInfo']['brand_id'], $brandIds)) {
                                    $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                                    $count++;
                                }
                            }
                        }
                        break;
                }
                $coupon['coupon_price'] = floatval($coupon['coupon_price']);
                $coupon['use_min_price'] = floatval($coupon['use_min_price']);
                $coupon['can_use'] = false;
                $coupon['true_coupon_price'] = 0;
                $coupon['unuse_reason'] = '';

                if (!$count) {
                    $coupon['unuse_reason'] = '不符合使用条件';
                    $result[] = $coupon;
                    continue;
                }
                if ($coupon['use_min_price'] > $price) {
                    $coupon['unuse_reason'] = '未满足使用门槛';
                    $result[] = $coupon;
                    continue;
                }

                // 可用：计算优惠金额
                $coupon['can_use'] = true;
                if ($coupon['coupon_type'] == 1) {
                    $couponPrice = $coupon['coupon_price'] > $price ? $price : $coupon['coupon_price'];
                } else {
                    if ($coupon['coupon_price'] <= 0) {//0折
                        $couponPrice = $price;
                    } else if ($coupon['coupon_price'] >= 100) {
                        $couponPrice = 0;
                    } else {
                        $truePrice = (float)bcmul((string)$price, bcdiv((string)$coupon['coupon_price'], '100', 2), 2);
                        $couponPrice = (float)bcsub((string)$price, (string)$truePrice, 2);
                    }
                    if ($coupon['top_discount_price'] > 0 && $couponPrice > $coupon['top_discount_price']) {
                        $couponPrice = $coupon['top_discount_price'];
                    }
                }
                $coupon['true_coupon_price'] = $couponPrice;
                $result[] = $coupon;
            }
        }
        if ($result) {
            $canUse = array_column($result, 'can_use');
            $trueCouponPrice = array_column($result, 'true_coupon_price');
            array_multisort($canUse, SORT_DESC, $trueCouponPrice, SORT_DESC, $result);
        }
        //最优的一个
        if ($isMax) {
//            $useCoupon = $result[0] ?? [];
//            return $useCoupon;
            return []; //不使用最优
        }
        return $result;
    }

    /**
     * 审核表单
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
        return create_form($is_verify == 1 ? '内容审核' : '强制下架', $f, Url::buildUrl('/coupon/set_verify/' . $id), 'post');
    }
}
