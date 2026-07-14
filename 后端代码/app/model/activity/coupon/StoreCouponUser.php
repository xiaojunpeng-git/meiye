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

namespace app\model\activity\coupon;

use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 优惠券发放Model
 * Class StoreCouponUser
 * @package app\model\activity\coupon
 */
class StoreCouponUser extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_coupon_user';

    /**
     * 获取类型
     * @var string[]
     */
    protected $gainType = [
        'send' => '后台发放',
        'get' => '手动领取',
        'newcomer' => '新人礼赠送',
        'activate_level' => '会员卡激活赠送',
        'user_first' => '用户注册赠送',
        'order' => '下单赠送',
        'luck_lottery' => '抽奖赠送'
    ];

    /**
     * 类型获取器
     * @param $value
     * @return string
     */
    public function getTypeAttr($value)
    {
        return $this->gainType[$value] ?? '';
    }

    /**
     * 使用状态
     * @var string[]
     */
    protected $statusType = [0 => '未使用', 1 => '已使用', 2 => '已过期'];

    /**
     * 状态获取器
     * @param $value
     * @return string
     */
    public function getStatusAttr($value)
    {
        return $this->statusType[$value];
    }

    /**
     * @return \think\model\relation\HasOne
     */
    public function issue()
    {
        return $this->hasOne(StoreCouponIssue::class, 'id', 'cid')->field(['id', 'end_use_time', 'start_use_time', 'coupon_type', 'top_discount_price', 'category', 'type', 'coupon_time', 'product_id', 'category_id', 'brand_id', 'receive_type', 'applicable_type', 'applicable_store_id', 'coupon_issue_type', 'relation_id', 'is_verify', 'rule'])->bind([
            'coupon_applicable_type' => 'type',
            'coupon_time' => 'coupon_time',
            'rule',
            'category',
            'product_id',
            'category_id',
            'brand_id',
            'receive_type',
            'coupon_type',
            'top_discount_price',
            'start_use_time',
            'end_use_time',
            'is_verify',
            'coupon_issue_type' => 'coupon_issue_type',
            'relation_id',
            'applicable_type',
            'applicable_store_id'
        ]);
    }

    /**
     * 获取领取人名称头像
     * @return \think\model\relation\HasOne
     */
    public function userInfo()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->field('uid,nickname,avatar')->bind(['nickname', 'avatar']);
    }

    /**
     * id
     * @param Model $query
     * @param $value
     */
    public function searchIdAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value))
                $query->whereIn('id', $value);
            else
                $query->where('id', $value);
        }
    }

    /**
     * 优惠券ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchCidAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value))
                $query->whereIn('cid', $value);
            else
                $query->where('cid', $value);
        }

    }

    /**
     * 用户ID搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchUidAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('uid', $value);
    }

    /**
     * 优惠券名称搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchCouponTitleAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('coupon_title', 'like', '%' . $value . '%');
    }

    /**
     * 获取方式搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchTypeAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('type', $value);
    }

    /**
     * 用户ID搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchStatusAttr($query, $value, $data)
    {
        if ($value !== '' && $value != -1) {
            if (is_array($value)) {
                $query->whereIn('status', $value);
            } else {
                $query->where('status', $value);
            }
        }
    }


    /**
     * 是否失效
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsFailAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_fail', $value);
    }

    /**
     * 是否在使用期限内搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchTimeAttr($query, $value, $data)
    {
        $query->whereTime('add_time', '>=', $value)->whereTime('end_time', '<=', $value);
    }
}
