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

use app\services\activity\promotions\StorePromotionsServices;
use app\services\BaseServices;
use app\services\product\brand\StoreBrandServices;
use app\services\user\UserServices;
use app\dao\activity\coupon\StoreCouponUserDao;
use app\services\product\category\StoreProductCategoryServices;
use think\exception\ValidateException;

/**
 * Class StoreCouponUserServices
 * @package app\services\activity\coupon
 * @mixin StoreCouponUserDao
 */
class StoreCouponUserServices extends BaseServices
{

    /**
     * StoreCouponUserServices constructor.
     * @param StoreCouponUserDao $dao
     */
    public function __construct(StoreCouponUserDao $dao)
    {
        $this->dao = $dao;
    }


    /**
     * 获取列表
     * @param array $where
     * @return array
     */
    public function issueLog(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, 'uid,add_time', ['userInfo'], $page, $limit);
        foreach ($list as &$item) {
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 获取列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function systemPage(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', ['issue'], $page, $limit);
        $count = 0;
        if ($list) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $userAll = $userServices->getColumn([['uid', 'IN', array_column($list, 'uid')]], 'uid,nickname', 'uid');
            foreach ($list as &$item) {
                if (isset($userAll[$item['uid']])) {
                    $item['nickname'] = $userAll[$item['uid']]['nickname'] ?? '';
                } else {
                    $userInfo = $userServices->getUserWithTrashedInfo($item['uid']);
                    $item['nickname'] = $userInfo['nickname'];
                }
            }
            $count = $this->dao->count($where);
        }
        return compact('list', 'count');
    }

    /**
     * 获取用户优惠券
     * @param int $id
     * @param int $status
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserCouponList(int $id, int $status = -1)
    {
        [$page, $limit] = $this->getPageValue();
        $where = ['uid' => $id];
        /** @var StoreCouponIssueServices $couponIssueServices */
        $couponIssueServices = app()->make(StoreCouponIssueServices::class);
        if ($status != -1) $where['status'] = $status;
        $list = $this->dao->getList($where, '*', ['issue'], $page, $limit);
        foreach ($list as &$item) {
            $item['_add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['_end_time'] = date('Y-m-d H:i:s', $item['end_time']);
            $item['_use_time'] = '---';
            if ($item['use_time']) {
                $item['_use_time'] = date('Y-m-d H:i:s', $item['use_time']);
            }
            if (!$item['coupon_time']) {
                $item['coupon_time'] = ceil(($item['end_use_time'] - $item['start_use_time']) / '86400');
            }
            $format = $couponIssueServices->getFormat($item['coupon_issue_type'], $item['relation_id']);
            $item['author'] = $format['author'] ?? '';
            $item['author_image'] = $format['author_image'] ?? '';
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 恢复优惠券
     * @param int $id
     * @return bool|mixed
     */
    public function recoverCoupon(int $id)
    {
        $status = $this->dao->value(['id' => $id], 'status');
        if ($status) return $this->dao->update($id, ['status' => 0, 'use_time' => 0]);
        else return true;
    }

    /**
     * 过期优惠卷失效
     * @param int $uid
     * @return void
     */
    public function checkInvalidCoupon(int $uid = 0)
    {
        $this->dao->update([['uid', '=', $uid], ['end_time', '<', time()], ['status', '=', '0']], ['status' => 2]);
    }

    /**
     * 获取用户有效优惠劵数量
     * @param int $uid
     * @return int
     */
    public function getUserValidCouponCount(int $uid)
    {
        $this->checkInvalidCoupon($uid);
        return $this->dao->getCount(['uid' => $uid, 'status' => 0]);
    }

    /**
     * 下单页面显示可用优惠券
     * @param int $uid
     * @param array $cartGroup
     * @param int $store_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUsableCouponList(int $uid, array $cartGroup, int $store_id = 0)
    {
        $userCoupons = $this->dao->getUserAllCoupon($uid);
        $result = [];
        if ($userCoupons) {
            $cartInfo = $cartGroup['valid'];
            $promotions = $cartGroup['promotions'] ?? [];
            $promotionsList = [];
            if ($promotions) {
                $promotionsList = array_combine(array_column($promotions, 'id'), $promotions);
            }
            //验证是否适用门店
            $isApplicableStore = function ($couponInfo) use ($store_id) {
//                if (isset($couponInfo['applicable_type']) && isset($couponInfo['applicable_store_id']) && isset($couponInfo['coupon_issue_type']) && isset($couponInfo['relation_id'])) {
//                    $applicable_store_id = is_array($couponInfo['applicable_store_id']) ? $couponInfo['applicable_store_id'] : explode(',', $couponInfo['applicable_store_id']);
//                    if (!$store_id && in_array($couponInfo['coupon_issue_type'], [1, 2]) || $store_id && ($couponInfo['applicable_type'] == 0 || ($couponInfo['applicable_type'] == 2 && !in_array($store_id, $applicable_store_id)) || ($couponInfo['coupon_issue_type'] == 1 && $couponInfo['relation_id'] != $store_id))) {
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
//				$isBranchProduct = isset($productInfo['type']) && isset($productInfo['pid']) && $productInfo['type'] == 1 && !$productInfo['pid'];
//				if ($isBranchProduct) {
//					return false;
//				}
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
            foreach ($userCoupons as $coupon) {
                if (!$isApplicableStore($coupon)) {//不适用门店跳过
                    continue;
                }
                $price = 0;
                $count = 0;
                switch ($coupon['coupon_applicable_type']) {
                    case 0:
                        foreach ($cartInfo as $cart) {
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                            $count++;
                        }
                        break;
                    case 1://品类券
                        $cateGorys = $storeCategoryServices->getAllById((int)$coupon['category_id']);
                        if ($cateGorys) {
                            $cateIds = array_column($cateGorys, 'id');
                            foreach ($cartInfo as $cart) {
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['cate_id']) && array_intersect(explode(',', $cart['productInfo']['cate_id']), $cateIds) || isset($cart['productInfo']['store_cate_id']) && array_intersect(explode(',', $cart['productInfo']['store_cate_id']), $cateIds)) {
                                    $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                                    $count++;
                                }
                            }
                        }
                        break;
                    case 2://商品
                        foreach ($cartInfo as $cart) {
                            if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                            $product_id = isset($cart['productInfo']['id']) && $cart['productInfo']['id'] ? $cart['productInfo']['id'] : ($cart['product_id'] ?? 0);
                            if ($product_id && in_array($product_id, explode(',', $coupon['product_id']))) {
                                $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                                $count++;
                            }
                        }
                        break;
                    case 3://品牌
                        $brands = $storeBrandServices->getAllById((int)$coupon['brand_id']);
                        if ($brands) {
                            $brandIds = array_column($brands, 'id');
                            foreach ($cartInfo as $cart) {
                                if (!$isOverlay($cart) || (isset($cart['cart_type']) && $cart['cart_type'] > 0)) continue;
                                if (isset($cart['productInfo']['brand_id']) && in_array($cart['productInfo']['brand_id'], $brandIds)) {
                                    $price = bcadd((string)$price, (string)$cart['pay_price'], 2);
                                    $count++;
                                }
                            }
                        }
                        break;
                }
                if ($count && $coupon['use_min_price'] <= $price) {
                    $coupon['start_time'] = $coupon['start_time'] ? date('Y/m/d', $coupon['start_time']) : date('Y/m/d', $coupon['add_time']);
                    $coupon['add_time'] = date('Y/m/d', $coupon['add_time']);
                    $coupon['end_time'] = date('Y/m/d', $coupon['end_time']);
                    $coupon['title'] = $coupon['coupon_title'];
                    $coupon['type'] = $coupon['coupon_applicable_type'];
                    $coupon['use_min_price'] = floatval($coupon['use_min_price']);
                    $coupon['coupon_price'] = floatval($coupon['coupon_price']);
                    $result[] = $coupon;
                }
            }
        }
        return $result;
    }

    /**
     * 用户领取优惠券
     * @param $uid
     * @param $issueCouponInfo
     * @param string $type
     * @return mixed
     */
    public function addUserCoupon($uid, $issueCouponInfo, string $type = 'get')
    {
        $data = [];
        $data['cid'] = $issueCouponInfo['id'];
        $data['uid'] = $uid;
        $data['coupon_title'] = $issueCouponInfo['title'];
        $data['coupon_price'] = $issueCouponInfo['coupon_price'];
        $data['use_min_price'] = $issueCouponInfo['use_min_price'];
        $data['add_time'] = time();
        if ($issueCouponInfo['coupon_time']) {
            $data['start_time'] = $data['add_time'];
            $data['end_time'] = $data['add_time'] + $issueCouponInfo['coupon_time'] * 86400;
        } else {
            $data['start_time'] = $issueCouponInfo['start_use_time'];
            $data['end_time'] = $issueCouponInfo['end_use_time'];
        }
        $data['type'] = $type;
        return $this->dao->save($data);
    }

    /**会员领取优惠券
     * @param $uid
     * @param $issueCouponInfo
     * @param string $type
     * @return mixed
     */
    public function addMemberUserCoupon($uid, $issueCouponInfo, $type = 'get')
    {
        $data = [];
        $data['cid'] = $issueCouponInfo['id'];
        $data['uid'] = $uid;
        $data['coupon_title'] = $issueCouponInfo['title'];
        $data['coupon_price'] = $issueCouponInfo['coupon_price'];
        $data['use_min_price'] = $issueCouponInfo['use_min_price'];
        $data['add_time'] = time();
        $data['start_time'] = strtotime(date('Y-m-d 00:00:00', time()));
        $data['end_time'] = strtotime(date('Y-m-d 23:59:59', strtotime('+30 day')));
        $data['type'] = $type;
        return $this->dao->save($data);
    }

    /**
     * 获取用户已领取的优惠卷
     * @param int $uid
     * @param $type
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserCounpon($uid, $types, $type)
    {
        $where = [];
        $where['uid'] = $uid;
        switch ($types) {
            case 1:
                $where['status'] = 1;
                break;
            case 2:
                $where['status'] = 2;
                break;
            default:
                $where['status'] = 0;
                break;
        }
        $where['issue_type'] = $type;
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getCouponListByOrder($where, 'status ASC,add_time DESC', $page, $limit);
        /** @var StoreProductCategoryServices $categoryServices */
        $categoryServices = app()->make(StoreProductCategoryServices::class);
        $category = $categoryServices->getColumn([], 'pid,cate_name', 'id');
        /** @var StoreBrandServices $storeBrandServices */
        $storeBrandServices = app()->make(StoreBrandServices::class);
        $brand = $storeBrandServices->getColumn([], 'id,pid,brand_name', 'id');
        foreach ($list as &$item) {
            $item['applicable_type'] = $item['coupon_applicable_type'];
            if ($item['category_id'] && isset($category[$item['category_id']])) {
                $item['category_type'] = $category[$item['category_id']]['pid'] == 0 ? 1 : 2;
                $item['category_name'] = $category[$item['category_id']]['cate_name'];
            } else {
                $item['category_type'] = '';
                $item['category_name'] = '';
            }
            if ($item['brand_id'] && isset($brand[$item['brand_id']])) {
                $item['category_name'] = $brand[$item['brand_id']]['brand_name'];
            } else {
                $item['brand_name'] = '';
            }
        }
        return $list ? $this->tidyCouponList($list) : [];
    }

    /**
     * 我的优惠券数量
     * @param int $uid
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function getUserCounponNum(int $uid): array
    {
        $data['not_used'] = $this->dao->count(['uid' => $uid, 'status' => 0]); // 未使用
        $data['used'] = $this->dao->count(['uid' => $uid, 'status' => 1]);// 已使用
        $data['expired'] = $this->dao->count(['uid' => $uid, 'status' => 2]);// 已过期
        return $data;
    }

    /**
     * 格式化优惠券
     * @param $couponList
     * @return mixed
     */
    public function tidyCouponList($couponList)
    {
        $time = time();
        foreach ($couponList as &$coupon) {
            if ($coupon['status'] == '已使用') {
                $coupon['_type'] = 0;
                $coupon['_msg'] = '已使用';
                $coupon['pc_type'] = 0;
                $coupon['pc_msg'] = '已使用';
            } else if ($coupon['status'] == '已过期') {
                $coupon['is_fail'] = 1;
                $coupon['_type'] = 0;
                $coupon['_msg'] = '已过期';
                $coupon['pc_type'] = 0;
                $coupon['pc_msg'] = '已过期';
            } else if ($coupon['end_time'] < $time) {
                $coupon['is_fail'] = 1;
                $coupon['_type'] = 0;
                $coupon['_msg'] = '已过期';
                $coupon['pc_type'] = 0;
                $coupon['pc_msg'] = '已过期';
            } else if ($coupon['start_time'] > $time) {
                $coupon['_type'] = 0;
                $coupon['_msg'] = '未开始';
                $coupon['pc_type'] = 1;
                $coupon['pc_msg'] = '未开始';
            } else {
                if ($coupon['start_time'] + 3600 * 24 > $time) {
                    $coupon['_type'] = 2;
                    $coupon['_msg'] = '立即使用';
                    $coupon['pc_type'] = 1;
                    $coupon['pc_msg'] = '可使用';
                } else {
                    $coupon['_type'] = 1;
                    $coupon['_msg'] = '立即使用';
                    $coupon['pc_type'] = 1;
                    $coupon['pc_msg'] = '可使用';
                }
            }
            $coupon['add_time'] = $coupon['_add_time'] = $coupon['start_time'] ? date('Y/m/d', $coupon['start_time']) : date('Y/m/d', $coupon['add_time']);
            $coupon['end_time'] = $coupon['_end_time'] = date('Y/m/d', $coupon['end_time']);
            $coupon['use_min_price'] = floatval($coupon['use_min_price']);
            $coupon['coupon_price'] = floatval($coupon['coupon_price']);
        }
        return $couponList;
    }

    /**
     * 获取会员优惠券列表
     * @param $uid
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getMemberCoupon($uid)
    {
        if (!$uid) return [];
        [$page, $limit] = $this->getPageValue();
        if (!$page && !$limit) {
            $page = 0;
            $limit = 4;
        }
        /** @var StoreCouponIssueServices $couponIssueService */
        $couponIssueService = app()->make(StoreCouponIssueServices::class);
        $couponWhere['category'] = 2;
        $couponInfo = $couponIssueService->getMemberCouponIssueList($couponWhere, $page, $limit, ['used' => function ($query) use ($uid) {
            $query->where('uid', $uid);
        }]);
        if ($couponInfo) {
            $time = time();
            foreach ($couponInfo as $k => &$coupon) {
                $coupon['type_name'] = $couponIssueService->_couponType[$coupon['type']];
                $coupon['is_use'] = false;
                if (isset($coupon['used']) && $uid && count($coupon['used']) > 0) {
                    $coupon['is_use'] = (count($coupon['used']) < $coupon['quantity_count'] || $coupon['is_claimed']) ? false : true;
                }
                if (isset($coupon['used']) && count($coupon['used'])) {
                    foreach ($coupon['used'] as &$item) {
                        if ($item['status'] == '已使用') {
                            $item['_type'] = 0;
                            $item['_msg'] = '已使用';
                            $item['pc_type'] = 0;
                            $item['pc_msg'] = '已使用';
                        } else if ($item['status'] == '已过期') {
                            $item['is_fail'] = 1;
                            $item['_type'] = 0;
                            $item['_msg'] = '已过期';
                            $item['pc_type'] = 0;
                            $item['pc_msg'] = '已过期';
                        } else if ($item['end_time'] < $time) {
                            $item['is_fail'] = 1;
                            $item['_type'] = 0;
                            $item['_msg'] = '已过期';
                            $item['pc_type'] = 0;
                            $item['pc_msg'] = '已过期';
                        } else if ($item['start_time'] > $time) {
                            $item['_type'] = 0;
                            $item['_msg'] = '未开始';
                            $item['pc_type'] = 1;
                            $item['pc_msg'] = '未开始';
                        } else {
                            if ($item['start_time'] + 3600 * 24 > $time) {
                                $item['_type'] = 2;
                            } else {
                                $item['_type'] = 1;
                            }
                            $item['_msg'] = '立即使用';
                            $item['pc_type'] = 1;
                            $item['pc_msg'] = '可使用';
                        }
                        $item['end_time'] = $item['_end_time'] = date('Y/m/d', $item['end_time']);
                        $item['_add_time'] = $item['start_time'] ? date('Y/m/d', $item['start_time']) : date('Y/m/d', $coupon['add_time']);
                    }
                }

                $coupon['add_time'] = date('Y/m/d', $coupon['add_time']);
                $coupon['start_use_time'] = date('Y/m/d', $coupon['start_use_time']);
                $coupon['end_use_time'] = date('Y/m/d', $coupon['end_use_time']);
                $coupon['use_min_price'] = floatval($coupon['use_min_price']);
                $coupon['coupon_price'] = floatval($coupon['coupon_price']);
            }
        }
        return $couponInfo ?: [];
    }

    /**
     * 根据月分组看会员发放优惠券情况
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function memberCouponUserGroupBymonth(array $where)
    {
        return $this->dao->memberCouponUserGroupBymonth($where);
    }

    /**
     * 会员券失效
     * @param $coupon_user
     * @return false|mixed
     */
    public function memberCouponIsFail($coupon_user)
    {
        if (!$coupon_user) return false;
        if ($coupon_user['use_time'] == 0) {
            return $this->dao->update($coupon_user['id'], ['is_fail' => 1, 'status' => 2]);
        }

    }

    /**
     * 根据id查询会员优惠劵
     * @param int $id
     * @param string $filed
     * @param array $with
     * @return array|false|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCouponUserOne(int $id, string $filed = '', array $with = [])
    {
        if (!$id) return false;
        return $this->dao->getOne(['id' => $id], $filed, $with);
    }

    /**根据时间查询用户优惠券
     * @param array $where
     * @return array|bool|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserCounponByMonth(array $where)
    {
        if (!$where) return false;
        return $this->dao->getUserCounponByMonth($where);
    }

    /**
     * 检查付费会员是否领取了会员券
     * @param $uid
     * @param $vipCouponIds
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkHave($uid, $vipCouponIds)
    {
        $list = $this->dao->getVipCouponList($uid);
        $have = [];
        foreach ($list as $item) {
            if ($vipCouponIds && in_array($item['cid'], $vipCouponIds)) {
                $have[$item['cid']] = true;
            } else {
                $have[$item['cid']] = false;
            }
        }
        return $have;
    }

    /**
     * 使用优惠券验证
     * @param int $couponId
     * @param int $uid
     * @param array $cartInfo
     * @param array $promotions
     * @param int $store_id
     * @return bool
     */
    public function useCoupon(int $couponId, int $uid, array $cartInfo, array $promotions = [], int $store_id = 0)
    {
        if (!$couponId || !$uid || !$cartInfo) {
            return true;
        }
        /** @var StorePromotionsServices $promotionsServices */
        $promotionsServices = app()->make(StorePromotionsServices::class);
        [$couponInfo, $couponPrice] = $promotionsServices->useCoupon($couponId, $uid, $cartInfo, $promotions, $store_id);
        if ($couponInfo) {
            if ($this->dao->useCoupon($couponId, $uid) !== 1) {
                throw new ValidateException('优惠券状态已变化，请重新选择优惠券。');
            }
        }
        return true;
    }
}
