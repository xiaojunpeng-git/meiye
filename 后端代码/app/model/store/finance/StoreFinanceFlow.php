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

namespace app\model\store\finance;

use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 流水
 * Class StoreFinanceFlow
 * @package app\model\store\finance
 */
class StoreFinanceFlow extends BaseModel
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
    protected $name = 'store_finance_flow';

    /**
     * 一对一关联用户表
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->field(['uid', 'nickname'])->bind([
            'user_nickname' => 'nickname',
        ]);
    }

    /**
     * 一对一关联店员
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function systemStoreStaff()
    {
        return $this->hasOne(SystemStoreStaff::class, 'id', 'staff_id')->field(['id', 'staff_name'])->bind([
            'staff_name' => 'staff_name'
        ]);
    }

	/**
	 * 一对一关联店员
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function systemStore()
	{
		return $this->hasOne(SystemStore::class, 'id', 'store_id');
	}

    /**
     * id搜索器
     * @param $query
     * @param $value
     */
    public function searchIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('id', $value);
        } else {
            $query->where('id', $value);
        }
    }

    /**
     * 门店id搜索器
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
		if (is_array($value)) {
			if ($value) $query->whereIn('store_id', $value);
		} else {
			if ($value !== '') $query->where('store_id', $value);
		}
    }

    /**
     * 用户id
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if ($value) $query->where('uid', $value);
    }

    /**
     * 用户id
     * @param Model $query
     * @param $value
     */
    public function searchTradeTypeAttr($query, $value)
    {
        if ($value) $query->where('trade_type', $value);
    }

    /**
     * 排除type
     * @param Model $query
     * @param $value
     */
    public function searchNoTypeAttr($query, $value)
    {
        if ($value) {
            if (is_array($value)) {
                $query->whereNotIn('type', $value);
            } else {
                $query->where('type', '<>', $value);
            }
        }
    }

    /**
     * 店员id
     * @param Model $query
     * @param $value
     */
    public function searchStaffIdAttr($query, $value)
    {
        if ($value) {
            if ($value == -1) {//所有店员
                $query->where('staff_id', '>', 0);
            } else {
                $query->where('staff_id', $value);
            }
        }
    }

    /**
     * 交易单号
     * @param Model $query
     * @param $value
     */
    public function searchOrderIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('order_id', 'LIKE', "%$value%");
        }
    }

    /**
     * 关联订单号
     * @param Model $query
     * @param $value
     */
    public function searchLinkIdAttr($query, $value)
    {
        if ($value !== '') $query->where('link_id', $value);
    }

    /**
     * 支出获取
     * @param Model $query
     * @param $value
     */
    public function searchPmAttr($query, $value)
    {
        if ($value !== '') $query->where('pm', $value);
    }

    /**
     * 类型
     * @param Model $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value) {
            if (is_array($value)) {
                $query->where('type', 'in', $value);
            } else {
                $query->where('type', $value);
            }
        }
    }

    /**
     * 支付类型
     * @param Model $query
     * @param $value
     */
    public function searchPayTypeAttr($query, $value)
    {
        if ($value !== '') $query->where('pay_type', $value);
    }

    /**
     * 删除
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

}
