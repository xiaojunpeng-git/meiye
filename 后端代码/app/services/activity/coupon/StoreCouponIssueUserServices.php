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

use app\services\BaseServices;
use app\dao\activity\coupon\StoreCouponIssueUserDao;
use think\facade\Db;

/**
 * Class StoreCouponIssueUserServices
 * @package app\services\activity\coupon
 * @mixin StoreCouponIssueUserDao
 */
class StoreCouponIssueUserServices extends BaseServices
{

    /**
     * StoreCouponIssueUserServices constructor.
     * @param StoreCouponIssueUserDao $dao
     */
    public function __construct(StoreCouponIssueUserDao $dao)
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
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * Counts effective claims while retaining append-only V3 void history.
     */
    public function effectiveClaimCount(int $uid, int $issueCouponId): int
    {
        return (int)Db::name('store_coupon_issue_user')->alias('iu')
            ->where('iu.uid', $uid)->where('iu.issue_coupon_id', $issueCouponId)
            ->whereNotExists(function ($query): void {
                $query->name('cashier_v3_recharge_gift_coupon_issue_mapping')->alias('gm')
                    ->whereRaw('gm.coupon_issue_user_id=iu.id')
                    ->where('gm.status', 'voided')
                    ->field('gm.id');
            })->count('iu.id');
    }
}
