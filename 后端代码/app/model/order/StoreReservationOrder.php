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

namespace app\model\order;

use app\model\activity\combination\StorePink;
use app\model\product\sku\StoreProductVirtual;
use app\model\store\DeliveryService;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\supplier\SystemSupplier;
use app\model\user\User;
use app\model\user\UserBrokerage;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 *  预约单Model
 * Class StoreReservationOrder
 * @package app\model\order
 */
class StoreReservationOrder extends BaseModel
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
    protected $name = 'store_reservation_order';

    protected $insert = ['add_time'];

    /**
     * 更新时间
     * @var bool | string | int
     */
    protected $updateTime = false;

    /**
     * 创建时间修改器
     * @return int
     */
    protected function setAddTimeAttr($time = 0)
    {
		if ($time) return $time;
        return time();
    }

    /**
     * 自定义表单信息
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function setReservationInfoAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value) : $value;
        }
        return '';
    }

    /**
     * 自定义表单信息
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getReservationInfoAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 手艺人（预约阶段暂存，开始服务后写入业绩表）
     */
    protected function setStaffChooseAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        }
        return '';
    }

    protected function getStaffChooseAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

	/**
	 * 服务凭证获取器
	 * @param $value
	 * @return array|mixed
	 */
	public function getServiceImagesAttr($value)
	{
		return is_string($value) && $value ? json_decode($value, true) : [];
	}


    /**
     * 一对一关联用户表
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid', false)->field(['uid', 'avatar', 'nickname', 'phone', 'now_money', 'integral', 'delete_time'])->bind([
            'avatar' => 'avatar',
            'nickname' => 'nickname',
            'phone' => 'phone',
            'now_money' => 'now_money',
            'integral' => 'integral',
            'delete_time' => 'delete_time'
        ]);
    }


    /**
     * 门店一对一关联
     * @return \think\model\relation\HasOne
     */
    public function store()
    {
        return $this->hasOne(SystemStore::class, 'id', 'store_id')->hidden(['bank_code,bank_address', 'alipay_account', 'alipay_qrcode_url', 'wechat', 'wechat_qrcode_url']);
    }

	/**
	 * 一对一关联供应商
	 * @return \think\model\relation\HasOne
	 */
	public function storeInfo()
	{
		return $this->hasOne(SystemStore::class, 'id', 'store_id')->field(['id', 'name', 'phone']);
	}

    /**
     * 订单关联门店店员
     * @return \think\model\relation\HasOne
     */
    public function storeStaff()
    {
        return $this->hasOne(SystemStoreStaff::class, 'id', 'staff_id')->field(['id', 'uid', 'store_id', 'staff_name'])->bind([
            'staff_uid' => 'uid',
            'staff_store_id' => 'store_id',
            'clerk_name' => 'staff_name'
        ]);
    }

    /**
     * 订单关联店员
     * @return \think\model\relation\HasOne
     */
    public function staff()
    {
        return $this->hasOne(SystemStoreStaff::class, 'uid', 'clerk_id')->field(['id', 'uid', 'store_id', 'staff_name'])->bind([
            'staff_uid' => 'uid',
            'staff_store_id' => 'store_id',
            'clerk_name' => 'staff_name'
        ]);
    }

    /**
     * 店员关联用户
     * @return \think\model\relation\HasOne
     */
    public function staffUser()
    {
        return $this->hasOne(User::class, 'uid', 'staff_uid')->field(['uid', 'nickname'])->bind([
            'clerk_name' => 'nickname'
        ]);
    }

    /**
     *  关联配送员
     * @return \think\model\relation\HasOne
     */
    public function deliveryService()
    {
        return $this->hasOne(DeliveryService::class, 'uid', 'delivery_uid')->field(['uid', 'nickname'])->bind([
            'delivery_name' => 'nickname'
        ]);
    }

	/**
	 * 一对一关联
	 * 商品评论关联订单
	 * @return \think\model\relation\HasOne
	 */
	public function cartInfo()
	{
		return $this->hasOne(StoreOrderCartInfo::class, 'id', 'cart_info_id')->bind(['cart_info']);
	}

	/**
	 * @param Model $query
	 * @param $value
	 */
	public function searchIdAttr($query, $value)
	{
		if ($value) {
			if (is_array($value)) {
				$query->whereIn('id', $value);
			} else {
				$query->where('id', $value);
			}
		}
	}

	/**
	 * @param Model $query
	 * @param $value
	 */
	public function searchOidAttr($query, $value)
	{
		if ($value) {
			if (is_array($value)) {
				$query->whereIn('oid', $value);
			} else {
				$query->where('oid', $value);
			}
		}
	}

	/**
	 * @param Model $query
	 * @param $value
	 */
	public function searchProductIdAttr($query, $value)
	{
		if ($value) {
			if (is_array($value)) {
				$query->whereIn('product_id', $value);
			} else {
				$query->where('product_id', $value);
			}
		}
	}

	/**
	 * @param Model $query
	 * @param $value
	 */
	public function searchSkuUniqueAttr($query, $value)
	{
		if ($value) {
			if (is_array($value)) {
				$query->whereIn('sku_unique', $value);
			} else {
				$query->where('sku_unique', $value);
			}
		}
	}

	/**
	 * @param Model $query
	 * @param $value
	 */
	public function searchCartInfoIdAttr($query, $value)
	{
		if ($value) {
			if (is_array($value)) {
				$query->whereIn('cart_info_id', $value);
			} else {
				$query->where('cart_info_id', $value);
			}
		}
	}

    /**
     * 订单ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchOrderIdAttr($query, $value)
    {
        $query->where('order_id', $value);
    }

    /**
     * 用户ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
		if ($value) {
			if (is_array($value))
				$query->whereIn('uid', $value);
			else
				$query->where('uid', $value);
		}
    }


    /**
     * 核销码搜索器
     * @param Model $query
     * @param $value
     */
    public function searchVerifyCodeAttr($query, $value)
    {
        if ($value !== '') $query->where('verify_code', $value);
    }

    /**
     * 支付状态搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

    /**
     * 是否删除搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsSystemDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_system_del', $value);
    }


    /**
     * 门店ID
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '') {
            if ($value == -1) {//所有门店
                $query->where('store_id', '>', 0);
            } else {
				if (is_array($value)) {
					$query->whereIn('store_id', $value);
				} else {
					$query->where('store_id', $value);
				}
            }
        }
    }

    /**
     * 门店店员ID
     * @param $query
     * @param $value
     */
    public function searchStaffIdAttr($query, $value)
    {
        if ($value) $query->where('staff_id', $value);
    }

	/**
	 * 服务店员ID
	 * @param $query
	 * @param $value
	 */
	public function searchServiceStaffIdAttr($query, $value)
	{
		if ($value) $query->where('service_staff_id', $value);
	}


	/**
	 * 预约类型
	 * @param $query
	 * @param $value
	 */
	public function searchReservationTypeAttr($query, $value)
	{
		if ($value !== '') $query->where('reservation_type', $value);
	}

	/**
	 * 预约时间ID类型
	 * @param $query
	 * @param $value
	 */
	public function searchReservationTimeIdAttr($query, $value)
	{
		if ($value !== '') $query->where('reservation_time_id', $value);
	}

	/**
	 * 状态搜索器
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchStatusAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('status', $value);
		} else {
			if ($value !== '') $query->where('status', $value);
		}
	}


}
