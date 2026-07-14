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

namespace app\model\store;


use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 配送员
 * Class DeliveryService
 * @package app\model\store
 */
class DeliveryService extends BaseModel
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
    protected $name = 'delivery_service';

    /**
     * @var bool
     */
    protected $updateTime = false;


    protected function getAddTimeAttr($value)
    {
        if ($value) return date('Y-m-d H:i:s', $value);
        return $value;
    }

    /**
     * 用户名一对多关联
     * @return mixed
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->field(['uid', 'nickname'])->bind([
            'nickname' => 'nickname'
        ]);
    }

    /**
     * uid搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        $query->where('uid', $value);
    }

	/**
	 * 商户搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('type', $value);
		} else {
			if ($value !== '' || $value !== -1) $query->where('type', $value);
		}
	}

	/**
	 * 关联门店ID、供应商ID搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchRelationIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value);
		} else {
			if ($value !== '' || $value !== -1) $query->where('relation_id', $value);
		}
	}

	/**
	 * 供应商
	 * @param Model $query
	 * @param $value
	 */
	public function searchSupplierIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value)->where('type', 2);
		} else {
			if ($value !== '') $query->where('relation_id', $value)->where('type', 2);
		}
	}

	/**
	 * 门店
	 * @param Model $query
	 * @param $value
	 */
	public function searchStoreIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value)->where('type', 1);
		} else {
			if ($value !== '') $query->where('relation_id', $value)->where('type', 1);
		}
	}

    /**
     * status搜索器
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        $query->where('status', $value);
    }

    /**
     * customer
     * @param Model $query
     * @param $value
     */
    public function searchCustomerAttr($query, $value)
    {
        $query->where('customer', $value);
    }

    /**
     * 用户昵称搜索器
     * @param Model $query
     * @param $value
     */
    public function searchNicknameAttr($query, $value)
    {
        $query->whereLike('nickname', '%' . $value . '%');
    }

    public function searchPhoneAttr($query, $value)
    {
        $query->where('phone', $value);
    }

    /**
     * 是否删除
     * @param $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_del', $value);
        }
    }
}
