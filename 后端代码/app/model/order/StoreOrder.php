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
use app\services\order\ValidCashOrderServices;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 *  订单Model
 * Class StoreOrder
 * @package app\model\order
 */
class StoreOrder extends BaseModel
{
    use ModelTrait;

    /**
     * 支付类型
     * @var string[]
     */
    protected $pay_type = [
        1 => 'weixin',
        2 => 'yue',
        3 => 'offline',
        4 => 'alipay'
    ];

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_order';

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
    protected function setCustomFormAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value) : $value;
        }
        return '';
    }

    public function searchNotYueAttr($query, $value)
    {
         if($value == 1){
               $query->where("pay_type","<>",'yue');
         }
    }
    public function searchNotOldAttr($query, $value)
    {
         if($value == 1){
               $query->where("cash_choose","<>",'9');
         }
    }

    /**
     * 有效现金订单：排除旧卡录入（含组合支付明细 cash_choose=9）
     */
    public function searchValidCashOnlyAttr($query, $value)
    {
        if ((int)$value !== 1) {
            return;
        }
        ValidCashOrderServices::applyScope($query);
        ValidCashOrderServices::applyHasValidCash($query);
    }
    /**
     * 自定义表单信息
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getCustomFormAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 时间搜索
     * @param $query
     * @param $value
     */
    public function searchDeliveryTimeAttr($query, $value)
    {
        $this->searchTimeAttr($query, $value, ['timeKey' => 'delivery_time']);
    }

    /**
     * 优惠活动赠送信息
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getPromotionsGiveAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 优惠活动赠送优惠券
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getGiveCouponAttr($value)
    {
        if ($value) {
            return is_string($value) ? explode(',', $value) : $value;
        }
        return [];
    }

	/**
	 * 获取卡密信息
	 * @param $value
	 * @param $data
	 * @return mixed
	 */
	protected function getVirtualInfoAttr($value)
	{
		if ($value) {
			return is_string($value) ? json_decode($value, true) : $value;
		}
		return [];
	}

    /**
     * 一对多关联查询子订单
     * @return \think\model\relation\HasMany
     */
    public function split()
    {
        return $this->hasMany(StoreOrder::class, 'pid', 'id');
    }

    /**
     * 一对一关联用户表
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid', false)->field(['uid', 'avatar', 'nickname', 'phone', 'now_money','ben_money','give_money','integral', 'delete_time'])->bind([
            'avatar' => 'avatar',
            'nickname' => 'nickname',
            'phone' => 'phone',
            'now_money' => 'now_money',
            'ben_money' => 'ben_money',
            'give_money' => 'give_money',
            'integral' => 'integral',
            'delete_time' => 'delete_time'
        ]);
    }

    /**
     * 一对多关联退款订单
     * @return \think\model\relation\hasMany
     */
    public function refund()
    {
        return $this->hasMany(StoreOrderRefund::class, 'store_order_id', 'id')->where('refund_type', '<>', 3)->where('is_cancel', 0);
    }

    /**
     * 一对多关联订单优惠详情
     * @return \think\model\relation\hasMany
     */
    public function promotions()
    {
        return $this->hasMany(StoreOrderPromotions::class, 'oid', 'id');
    }

    /**
     * 一对一关联上级用户信息
     * @return \think\model\relation\HasOne
     */
    public function spread()
    {
        return $this->hasOne(User::class, 'uid', 'spread_uid')->field(['uid', 'nickname'])->bind([
            'spread_nickname' => 'nickname'
        ]);
    }

    /**
     * 一对一拼团获取状态
     * @return \think\model\relation\HasOne
     */
    public function pink()
    {
        return $this->hasOne(StorePink::class, 'id', 'pink_id')->field(['id', 'order_id_key', 'status'])->bind([
            'pinkStatus' => 'status'
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
	 * 一对一关联供应商
	 * @return \think\model\relation\HasOne
	 */
	public function supplier()
	{
		return $this->hasOne(SystemSupplier::class, 'id', 'supplier_id')->field(['id', 'supplier_name'])->bind([
			'supplier_name'
		]);
	}

	/**
	 * 一对一关联供应商
	 * @return \think\model\relation\HasOne
	 */
	public function supplierInfo()
	{
		return $this->hasOne(SystemSupplier::class, 'id', 'supplier_id')->field(['id', 'supplier_name', 'name', 'phone', 'email']);
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
     * 关联订单发票
     * @return \think\model\relation\HasOne
     */
    public function invoice()
    {
        return $this->hasOne(StoreOrderInvoice::class, 'order_id', 'id');
    }

    /**
     * 关联分佣表
     * @return \think\model\relation\HasOne
     */
    public function brokerage()
    {
        return $this->hasOne(UserBrokerage::class, 'link_id', 'id');
    }

    /**
     * 关联卡密
     * @return \think\model\relation\HasMany
     */
    public function virtual()
    {
        return $this->hasMany(StoreProductVirtual::class, 'order_id', 'order_id')->where('order_type', 1);
    }

    /**
     * 关联订单记录
     * @return \think\model\relation\HasMany
     */
    public function orderStatus()
    {
        return $this->hasMany(StoreOrderStatus::class, 'oid', 'id');
    }

	/**
     * 关联配送订单记录
     * @return \think\model\relation\hasOne
     */
    public function deliveryOrder()
    {
        return $this->hasOne(StoreDeliveryOrder::class, 'oid', 'id');
    }

    /**
     * 购物车ID修改器
     * @param $value
     * @return false|string
     */
    protected function setCartIdAttr($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    /**
     * 购物车获取器
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getCartIdAttr($value, $data)
    {
        return $value ? json_decode($value, true) : [];
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
     * 活动ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchActivityIdAttr($query, $value)
    {
        if ($value !== '') $query->where('activity_id', $value);
    }

    /**
     * 父类ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchPidAttr($query, $value)
    {
        if ($value === 0) {
            $query->where('pid', '>=', 0);
        } else {
            if (is_array($value)) {
                $query->whereIn('pid', $value);
            } else {
                if($value == -2){
//                    $query->whereIn('pid', [0,-2]);
                    $query->where(function ($query1) {
                        $query1->where('pid', '>=', 0)->whereOr('pid',-2);
                    });
                }else if($value == -3){
                    $query->where(function ($query1) {
                        $query1->where('pid', '>=', 0)->whereOr('pid',-2);
                    });
                }else {
                    $query->where('pid', $value);
                }
            }
        }
    }

    /**
     * 没拆分订单 与子订单(0:为拆分订单-1：已拆分主订单 >0 :拆分后子订单)
     * @param Model $query
     * @param $value
     */
    public function searchNotPidAttr($query, $value)
    {
        $query->where('pid', '<>', -1);
    }

    /**
     * @param Model $query
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
     * 支付方式搜索器
     * @param $query
     * @param $value
     */
    public function searchPayTypeAttr($query, $value)
    {
        if (is_array($value)) {
            if(!empty($value)) {
                if (!in_array("cika", $value)) {
                    $query->whereIn('pay_type', $value)->whereIn("order_type", [0, 1]);
                } else {
                    $query->where(function ($q) use ($value) {
                        $q->whereIn('pay_type', $value)->whereOr("order_type", 2);
                    });
                }
            }
        } else {
            if ($value !== '') {
                $pay_type = $this->pay_type;
                if($value == 'cika'){
                    $query->where("order_type",2);
                }else {
                    if (in_array($value, array_keys($pay_type)) && $type = $pay_type[$value] ?? '') {
                        $query->where('pay_type', $type);
                    } else {
                        $query->where('pay_type', $value);
                    }
                }
            }
        }
    }

    /**
     * 不等于余额支付
     * @param $query
     * @param $value
     */
    public function searchPayTypeNoAttr($query, $value)
    {
        $query->where('pay_type', "<>", $value);
    }

    /**
     * 订单id或者用户名搜索器
     * @param $query
     * @param $value
     */
    public function searchOrderIdRealNameAttr($query, $value)
    {
        $query->where('order_id|real_name', $value);
    }

    /**
     * 用户ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if (is_array($value))
            $query->whereIn('uid', $value);
        else
            $query->where('uid', $value);
    }

    /**
     * 支付状态搜索器
     * @param Model $query
     * @param $value
     */
    public function searchPaidAttr($query, $value)
    {
		if (in_array($value, [0, 1])) {
			$query->where('paid', $value);
		}
    }

    /**
     * 退款状态搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchRefundStatusAttr($query, $value, $data)
    {
        if ($value !== '') {
            if (is_array($value)) {
                $query->whereIn('refund_status', $value);
            } else {
                $query->where('refund_status', $value);
            }
        }
    }

    /**
     * 退款状态搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchRefundStatusInAttr($query, $value)
    {
        $query->whereIn('refund_status', $value);
    }

    /**
     * 是否是拼团订单
     * @param Model $query
     * @param $value
     */
    public function searchPinkIdAttr($query, $value)
    {
        $query->where('pink_id', $value);
    }

    /**
     * 核销码搜索器
     * @param Model $query
     * @param $value
     */
    public function searchVerifyCodeAttr($query, $value)
    {
        $query->where('verify_code', $value);
    }

    /**
     * 是否删除搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value != '') $query->where('is_del', $value);
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
	 * 用户是否取消搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchIsUserDelAttr($query, $value)
	{
		if ($value !== '') $query->where('is_user_del', $value);
	}

    /**
     * 退款状态搜索器
     * @param $query
     * @param $value
     */
    public function searchRefundTypeAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('refund_type', $value);
        } else {
            if ($value == -1) {
                $query->where('refund_type', 'in', '0,3');
            } else {
                if ($value == 0 || $value == '') {
                    $query->where('refund_type', '<>', 0);
                } else {
                    $query->where('refund_type', $value);
                }
            }
        }
    }

    /**
     * 用户来源
     * @param Model $query
     * @param $value
     */
    public function searchChannelTypeAttr($query, $value)
    {
        if ($value != '') $query->where('channel_type', $value);
    }

    /**
     * 退款id搜索器
     * @param Model $query
     * @param $value
     */
    public function searchRefundIdAttr($query, $value)
    {
        if ($value) {
            $query->where('id', 'in', $value);
        }
    }

    /**
     * 上级｜上上级推广人
     * @param $query
     * @param $value
     */
    public function searchSpreadOrUidAttr($query, $value)
    {
        if ($value) $query->where('spread_uid|spread_two_uid', $value);
    }

    /**
     * 上级推广人
     * @param $query
     * @param $value
     */
    public function searchSpreadUidAttr($query, $value)
    {
        if ($value) $query->where('spread_uid', $value);
    }

    /**
     * 上上级推广人
     * @param $query
     * @param $value
     */
    public function searchSpreadTwoUidAttr($query, $value)
    {
        if ($value) $query->where('spread_two_uid', $value);
    }

    /**
     * 门店ID
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        if (!empty($value)) {
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
	 * 商品类型
	 * @param $query
	 * @param $value
	 */
	public function searchProductTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->where('product_type', $value);
		} else {
			if ($value !== ''){
                if($value == 0){
                    $query->where('order_type', 0)->where('product_type', $value);
                }else{
                    $query->where('product_type', $value);
                }
            }
		}
	}

    /**
     * 配送方式
     * @param $query
     * @param $value
     */
    public function searchShippingTypeAttr($query, $value)
    {
		if (is_array($value)) {
			if ($value) $query->where('shipping_type', $value);
		} else {
			if ($value !== '') $query->where('shipping_type', $value);
		}
    }

    /**
     * 配送员UID
     * @param $query
     * @param $value
     */
    public function searchDeliveryUidAttr($query, $value)
    {
        if ($value) $query->where('delivery_uid', $value);
    }

    /**
     * 配送类型
     * @param $query
     * @param $value
     */
    public function searchDeliveryTypeAttr($query, $value)
    {
        if ($value) $query->where('delivery_type', $value);
    }

    /**
     * 供应商ID
     * @param $query
     * @param $value
     */
    public function searchSupplierIdAttr($query, $value)
    {
        if ($value !== '') {
            if ($value == -1) {
                $query->where('supplier_id', '>', 0);
            } else {
				if (is_array($value)) {
					$query->whereIn('supplier_id', $value);
				} else {
					$query->where('supplier_id', $value);
				}
            }
        }
    }

    /**
     * 支付渠道
     * @param $query
     * @param $value
     */
    public function searchIsChannelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_channel', $value);
    }

    /**
     * 供应商统计
     * @param $query
     * @param $value
     */
    public function searchSupplierAttr($query, $value)
    {
        if ($value !== '') $query->where('supplier_id', '>', 0);
    }

    /**
     * 时间段搜索器
     * @param Model $query
     * @param $value
     */
    public function searchPayTimeAttr($query, $value)
    {
        if ($value) {
            $timeKey = 'pay_time';
			if (is_array($value)) {
				$startTime = $value[0] ?? 0;
				$endTime = $value[1] ?? 0;
				if ($startTime || $endTime) {
					try {
						date('Y-m-d', $startTime);
					} catch (\Throwable $e) {
						$startTime = strtotime($startTime);
					}
					try {
						date('Y-m-d', $endTime);
					} catch (\Throwable $e) {
						$endTime = strtotime($endTime);
					}
					if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
						$endTime = $endTime + 86400;
					}
					$query->whereBetween($timeKey, [$startTime, $endTime]);
				}
			} else {
				if (strstr($value, '-') !== false) {
					[$startTime, $endTime] = explode('-', $value);
					$startTime = trim($startTime) ? strtotime($startTime) : 0;
					$endTime = trim($endTime) ? strtotime($endTime) : 0;
					if ($startTime && $endTime) {
						if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
							$endTime = $endTime + 86400;
						}
						$query->whereBetween($timeKey, [$startTime, $endTime]);
					} else if (!$startTime && $endTime) {
						$query->whereTime($timeKey, '<', $endTime + 86400);
					} else if ($startTime && !$endTime) {
						$query->whereTime($timeKey, '>=', $startTime);
					}
				}
			}

        }
    }

}
