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
namespace app\controller\cashier;

use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use think\facade\App;

/**
 * 已发布优惠券管理
 * Class StoreCouponIssue
 * @package app\controller\admin\v1\marketing
 */
class StoreCouponIssue extends AuthController
{
    public function __construct(App $app, StoreCouponIssueServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 获取优惠券列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index(Request $request)
    {
        $where = $request->getMore([
            ['coupon_type', ''], //1:满减券2:折扣券
            ['coupon_issue_type', ''], //来源：0:平台1:门店2:供应商
            ['store_id', '', '', 'relation_id'], //门店id
            ['type', ''], //优惠券类型 0-通用 1-品类券 2-商品券 3-品牌
            ['category', ''], //优惠券种类：1 普通券，2会员券
            ['receive_type', ''], //1 手动领取，3赠送券
            ['status', 1],//是否有效
            ['is_status', 1],//状态
            ['coupon_title', ''], //优惠券名称
            ['receive', ''],
        ]);
        $list = $this->services->getCouponIssueList($where);
        return $this->success($list);
    }

}
