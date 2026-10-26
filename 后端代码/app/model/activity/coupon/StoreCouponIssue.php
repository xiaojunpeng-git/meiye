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

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 发布优惠券Model
 * Class StoreCouponIssue
 * @package app\model\activity\coupon
 */
class StoreCouponIssue extends BaseModel
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
    protected $name = 'store_coupon_issue';


	/**
	 * 适用门店信息
	 * @param $value
	 * @return string
	 */
	protected function setApplicableStoreIdAttr($value)
	{
		if ($value) {
			return is_array($value) ? implode(',', $value) : $value;
		}
		return '';
	}

	/**
	 * 适用门店信息
	 * @param $value
	 * @return array|false|string[]
	 */
	protected function getApplicableStoreIdAttr($value)
	{
		if ($value) {
			return is_string($value) ? array_map('intval', array_filter(explode(',', $value))) : $value;
		}
		return [];
	}

    /**
     * 用户是否拥有
     * @return \think\model\relation\HasMany
     */
    public function used()
    {
        return $this->hasMany(StoreCouponUser::class, 'cid', 'id')->field('id,cid,uid,start_time,end_time,use_time,status,is_fail');
    }

    /**
     * id
     * @param Model $query
     * @param $value
     */
    public function searchIdAttr($query, $value)
    {
        if (is_array($value))
            $query->whereIn('id', $value);
        else
            $query->where('id', $value);
    }

    /**
     * 优惠券模板搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchCidAttr($query, $value, $data)
    {
        if ($value != '') $query->where('cid', $value);
    }

	/**
	 * type
	 * @param Model $query
	 * @param $value
	 */
	public function searchCategoryAttr($query, $value)
	{
		if ($value) {
			if (is_array($value))
				$query->whereIn('category', $value);
			else
				$query->where('category', $value);
		}
	}

    /**
     * type
     * @param Model $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value != '') {
            if (is_array($value))
                $query->whereIn('type', $value);
            else
                $query->where('type', $value);
        }
    }

    /**
     * 优惠类型搜索器
     * @param Model $query
     * @param $value
     */
    public function searchCouponTypeAttr($query, $value)
    {
        if ($value != '') $query->where('coupon_type', $value);
    }

    /**
     * 来源搜索器
     * @param Model $query
     * @param $value
     */
    public function searchCouponIssueTypeAttr($query, $value)
    {
        if ($value != '') $query->where('coupon_issue_type', $value);
    }

    /**
     * receive_type
     * @param Model $query
     * @param $value
     */
    public function searchReceiveTypeAttr($query, $value)
    {
        if ($value) {
            if (is_array($value))
                $query->whereIn('receive_type', $value);
            else
                $query->where('receive_type', $value);
        }
    }

    /**
     * 优惠券是否不限量
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsPermanentAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_permanent', $value);
    }

    /**
     * 优惠券是否新人券
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsGiveSubscribeAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_give_subscribe', $value);
    }

    /**
     * 优惠券是否满赠
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsFullGiveAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_full_give', $value);
    }

    /**
     * 优惠券状态
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchStatusAttr($query, $value, $data)
    {
        if ($value != '') $query->where('status', $value);
    }

    /**
     * 优惠券是否删除
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsDelAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_del', $value ?? 0);
    }

    /**
     * 优惠券是否审核
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsVerifyAttr($query, $value, $data)
    {
        if ($value !== '') $query->where('is_verify', $value ?? 0);
    }

    /**
     * 关联门店、供应商
     * @param $query
     * @param $value
     * @return void
     */
    public function searchRelationIdAttr($query, $value)
    {
        if ($value) {
            if (is_array($value))
                $query->whereIn('relation_id', $value);
            else
                $query->where('relation_id', $value);
        }
    }
}
