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

namespace app\dao\order;


use app\dao\BaseDao;
use app\model\order\StoreDebt;
use app\model\order\StoreOrder;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;

/**
 * 订单
 * Class StoreOrderDao
 * @package app\dao\order
 */
class StoreOrderDao extends BaseDao
{

    /**
     * 限制精确查询字段
     * @var string[]
     */
    protected $withField = ['uid', 'order_id', 'real_name', 'user_phone', 'title', 'total_num'];

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreOrder::class;
    }

    /**
     * 订单搜索
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        if (isset($where['real_name'])) {
            $where['real_name'] = trim($where['real_name']);
        }
        $searchOrderId = isset($where['search_order_id']) ? trim((string) $where['search_order_id']) : '';
        $searchVerifyCode = isset($where['search_verify_code']) ? trim((string) $where['search_verify_code']) : '';
        $searchProduct = isset($where['search_product']) ? trim((string) $where['search_product']) : '';
        $searchUser = isset($where['search_user']) ? trim((string) $where['search_user']) : '';
        unset($where['search_order_id'], $where['search_verify_code'], $where['search_product'], $where['search_user']);
        // “旧卡升级”筛选：前端可能传 link_type=card_upgrade_old（不属于 order_type 枚举）
        $cardUpgradeOld = false;
        if (isset($where['link_type'])) {
            if (is_array($where['link_type'])) {
                if (in_array('card_upgrade_old', $where['link_type'], true)) {
                    $cardUpgradeOld = true;
                    $where['link_type'] = array_values(array_filter($where['link_type'], function ($v) {
                        return $v !== 'card_upgrade_old';
                    }));
                }
            } elseif ($where['link_type'] === 'card_upgrade_old') {
                $cardUpgradeOld = true;
                $where['link_type'] = '';
            }
        }
        // 服务对象筛选：先取出再手动 where，避免被通用 search 忽略/丢弃
        $serviceObject = $where['service_object'] ?? '';
        unset($where['service_object']);
        // pid 可能是数组（例如 [0,-2]），单独处理成 whereIn
        $pidFilter = $where['pid'] ?? null;
        if (is_array($pidFilter)) unset($where['pid']);
        $isDel = isset($where['is_del']) && $where['is_del'] !== '' && $where['is_del'] != -1;
        $realName = $where['real_name'] ?? '';
        $fieldKey = $where['field_key'] ?? '';
        $fieldKey = $fieldKey == 'all' ? '' : $fieldKey;
        $deliveryType = $where['deliveryType'] ?? '';
        unset($where['deliveryType']);
        return parent::search($where)->when(is_array($pidFilter), function ($query) use ($pidFilter) {
            $query->whereIn('pid', $pidFilter);
        })->when($serviceObject !== '', function ($query) use ($serviceObject) {
            if ($serviceObject === '朋友') {
                // 朋友：含旧数据——主单 service_object 未同步，但自动核销的核销记录/核销子单已是朋友
                $query->where(function ($q) use ($serviceObject) {
                    $q->where('service_object', $serviceObject)
                        ->whereOr('id', 'in', function ($sub) use ($serviceObject) {
                            $sub->name('store_order_writeoff')
                                ->where('status', 0)
                                ->where('service_object', $serviceObject)
                                ->field('oid');
                        })
                        ->whereOr('id', 'in', function ($sub) use ($serviceObject) {
                            $sub->name('store_order')
                                ->where('order_type', 2)
                                ->where('is_system_del', 0)
                                ->where('service_object', $serviceObject)
                                ->where('link_order', '>', 0)
                                ->field('link_order');
                        })
                        ->whereOr(function ($q2) use ($serviceObject) {
                            $q2->where('order_type', 2)->where('service_object', $serviceObject);
                        })
                        ->whereOr('id', 'in', function ($sub) {
                            $sub->name('store_order_cart_info')
                                ->where('cart_type', 0)
                                ->where(function ($cq) {
                                    $cq->whereLike('cart_info', '%"service_object":"朋友"%')
                                        ->whereOr('cart_info', 'like', '%"service_object": "朋友"%');
                                })
                                ->field('oid');
                        });
                });
            } else {
                // 本人：含空值；排除「主单未标朋友、但核销侧已是朋友」的旧数据（应只在搜朋友时出现）
                $query->where(function ($q) use ($serviceObject) {
                    $q->where(function ($q2) use ($serviceObject) {
                        $q2->where('service_object', $serviceObject)
                            ->whereOr('service_object', '')
                            ->whereOrNull('service_object');
                    })->whereNotIn('id', function ($sub) {
                        $sub->name('store_order_writeoff')
                            ->where('status', 0)
                            ->where('service_object', '朋友')
                            ->field('oid');
                    })->whereNotIn('id', function ($sub) {
                        $sub->name('store_order')
                            ->where('order_type', 2)
                            ->where('is_system_del', 0)
                            ->where('service_object', '朋友')
                            ->where('link_order', '>', 0)
                            ->field('link_order');
                    })->whereNotIn('id', function ($sub) {
                        $sub->name('store_order_cart_info')
                            ->where('cart_type', 0)
                            ->where(function ($cq) {
                                $cq->whereLike('cart_info', '%"service_object":"朋友"%')
                                    ->whereOr('cart_info', 'like', '%"service_object": "朋友"%');
                            })
                            ->field('oid');
                    });
                });
            }
        })->when($isDel, function ($query) use ($where) {
            $query->where('is_del', $where['is_del']);
        })->when(isset($where['is_kuadian']) && $where['is_kuadian'] == 1, function ($query) use ($where) {
            $query->where("kua_store",">",0);
        })->when(isset($where['plat_type']) && in_array($where['plat_type'], [-1, 0, 1, 2]), function ($query) use ($where) {
            switch ($where['plat_type']) {
                case -1://所有
                    break;
                case 0://平台
                    $query->where('store_id', 0)->where('supplier_id', 0);
                    break;
                case 1://门店
                    $query->where('store_id', '>', 0);
                    break;
                case 2://供应商
                    $query->where('supplier_id', '>', 0);
                    break;
            }
        })->when(isset($where['yeji_staff']) && $where['yeji_staff'], function ($query) use ($where) {
            $query->where('id', 'in', function ($que) use ($where) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $where['yeji_staff']) . '%';
                $que->name('staff_yeji')->field('order_id')->whereIn("type",[1,2])->whereLike('staff_name', $like)->select();
            });
        })->when(isset($where['yeji_shouyi']) && $where['yeji_shouyi'], function ($query) use ($where) {
            // type=3 劳动业绩：staff_yeji.order_id 多为「原单」；核销生成的子单 order_type=2，id 不等于原单，
            // 需同时按 link_id（核销记录 id）命中子单，否则订单列表搜「手艺人员」筛不出核销类型订单。
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $where['yeji_shouyi']) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereIn('id', function ($que) use ($like) {
                    $que->name('staff_yeji')->field('order_id')->where('type', 3)->whereLike('staff_name', $like)->select();
                })->whereOr(function ($q2) use ($like) {
                    $q2->where('order_type', 2)->whereIn('link_id', function ($que) use ($like) {
                        $que->name('staff_yeji')->field('link_id')->where('type', 3)->whereLike('staff_name', $like)->select();
                    });
                });
            });
        })->when(isset($where['is_coupon']), function ($query) {
            $query->where('coupon_id', '>', 0);
        })->when(isset($where['not_recharge']) && !empty($where['not_recharge']), function ($query) use ($where){
            if($where['not_recharge'] == 1){
                 $query->where(function($sub){
                     $sub->where("cash_choose","<>",10)->whereOr("order_type",2);
                 });
            }
        })->when(isset($where['not_auto']) && !empty($where['not_auto']), function ($query) use ($where){
            if($where['not_auto'] == 1){
                $query->where("is_auto",'<>',1);
            }
        })->when(isset($where['search_type']) && $where['search_type'] == 2, function ($query) {
            //搜索有效卡
            //1、有剩余次数  2、结束时间未结束
            $query->where('is_debt_repay', 0)->where("card_upgrade_use_oid",0)->where('refund_status',"<>",2)->where('id', 'in', function ($subQuery)  {
                $subQuery->name('store_order_cart_info')
                    ->where("write_surplus_times",">",0)
                    ->where(function ($quetwo){
                        $quetwo->where("write_end",0)->whereOr("write_end",">",time());
                    })->field(['oid'])->select();
            });
        })->when(isset($where['staff_id']) && $where['staff_id'], function ($query) use ($where) {
            $query->where('staff_id', $where['staff_id']);
        })->when($cardUpgradeOld, function ($query) {
            $query->where('card_upgrade_use_oid', '>', 0);
        })->when(isset($where['link_type']) && $where['link_type'] != '', function ($query) use ($where) {
            if(is_array($where['link_type'])){
                if(!empty($where['link_type'])) {
                    $query->whereIn('order_type', $where['link_type']);
                }
            }else{
                $query->where('order_type', $where['link_type']);
            }
        })->when(isset($where['cash_choose']) && $where['cash_choose'] != '', function ($query) use ($where) {
            if (is_array($where['cash_choose'])) {
                if(!empty($where['cash_choose'])) {
                    $query->whereIn("order_type", [0, 1])->where(function ($q) use ($where) {
                        $q->whereIn('cash_choose', $where['cash_choose'])->whereOr("id", "in", function ($d) use ($where) {
                            $d->name('combination_order')->whereIn('cash_choose', $where['cash_choose'])->field('order_id');
                        });
                    });
                }
            } else {
                $query->whereIn("order_type",[0,1])->where(function ($q) use ($where){
                    $q->where('cash_choose', $where['cash_choose'])->whereOr("id","in",function ($d) use ($where){
                        $d->name('combination_order')->where('cash_choose', $where['cash_choose'])->field('order_id');
                    });
                });
            }
        })->when(isset($where['source']) && $where['source'] != '', function ($query) use ($where) {
            if(is_array($where['source'])){
                if(!empty($where['source'])){
                    $query->whereIn('source', $where['source']);
                }
            }else{
                $query->where('source', $where['source']);
            }
        })->when(isset($where['clerk_id']) && $where['clerk_id'], function ($query) use ($where) {
            $query->where('clerk_id', $where['clerk_id']);
        })->when(isset($where['status']) && $where['status'] === 'debt', function ($query) {
            $query->where('paid', 1)
                ->where('is_del', 0)
                ->where('is_user_del', 0)
                ->whereIn('id', function ($sub) {
                    $sub->name('store_debt')
                        ->where('status', StoreDebt::STATUS_PENDING)
                        ->whereRaw('(total_debt - repaid_debt) > 0')
                        ->field('order_id');
                });
        })->when(isset($where['status']) && $where['status'] !== '' && $where['status'] !== 'debt', function ($query) use ($where) {
            switch ((int)$where['status']) {
                case 0://未支付
                    $query->where('paid', 0)->where('status', 0)->where('refund_status', 0)->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 1://已支付 未发货
                    $query->where('paid', 1)->whereIn('status', [0, 4])->whereIn('refund_status', [0, 3])->whereIn('shipping_type', [1, 3, 4])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 2://已支付  待收货
                    $query->where('paid', 1)->whereIn('status', [1, 5])->whereIn('shipping_type', [1, 3])->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 3:// 已支付  已收货  待评价
                    $query->where('paid', 1)->where('status', 2)->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 4:// 交易完成
                    $query->where('paid', 1)->where('status', 3)->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 5://已支付  待核销
                    $query->where('paid', 1)->whereIn('status', [0, 1, 5])->whereIn('refund_status', [0, 3])->where('shipping_type', 2)->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 6://已支付 已核销 没有退款
                    $query->where('paid', 1)->where('status', 2)->whereIn('refund_status', [0, 3])->where('shipping_type', 2)->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 7://已支付 部分发货
                    $query->where('paid', 1)->where('status', 4)->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 8://已支付 核销订单
                    $query->where('paid', 1)->whereIn('status', [0, 1, 2, 5])->whereIn('refund_status', [0, 3])->where('shipping_type', 2)->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 9://已配送
                    $query->where('paid', 1)->whereIn('status', [2, 3])->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case 10://已支付  待收货+待核销
                    $query->where('paid', 1)->where(function ($query) {
                        $query->where(function ($q) {
                            $q->whereIn('status', [1, 5])->whereIn('shipping_type', [1, 3]);
                        })->whereOr(function ($e) {
                            $e->whereIn('status', [0, 5])->where('shipping_type', 2);
                        });
                    })->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case -1://退款中
                    $query->where('paid', 1)->whereIn('refund_status', [1, 4])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case -2://已退款
                    $query->where('paid', 1)->where('refund_status', 2)->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case -3://退款
                    $query->where('paid', 1)->whereIn('refund_status', [1, 2, 4])->where('is_del', 0)->where('is_user_del', 0);
                    break;
                case -4://已删除
                    $query->where('is_del', 1);
                    break;
            }
        })->when(isset($where['type']) && $where['type'] !== '', function ($query) use ($where) {
            switch ($where['type']) {
                case 0://普通
                case 1://秒杀
                case 2://砍价
                case 3://拼团
                case 4://积分订单
                case 5://套餐
                case 6://预售
                case 7://新人专享
                case 8://抽奖
                case 9://拼单
                case 10://桌码
                case 11://卡项
                case 12://预约
                    $query->where('type', (int)$where['type']);
                    break;
                case 105://核销订单
                    $query->where('is_debt_repay', 0)->whereIn('shipping_type', [2, 4])->where('verify_code', '<>', '');
                    break;
                case 106://收银台订单
                    $query->where('shipping_type', 4);
                    break;
                case 107://配送订单
                    $query->where('shipping_type', 3)->where('store_delivery_type', 2);
                    break;
            }
        })->when(isset($where['order_type']) && $where['order_type'] !== '', function ($query) use ($where) {
            switch ($where['order_type']) {
                case 105://核销订单
                    $query->where('is_debt_repay', 0)->whereIn('shipping_type', [2, 4])->where('verify_code', '<>', '');
                    break;
                case 106://收银台订单
                    $query->where('shipping_type', 4);
                    break;
                case 107://配送订单
                    $query->where('shipping_type', 3)->where('store_delivery_type', 2);
                    break;
                case 108://线下订单
                    $query->whereIn('shipping_type', [1, 2, 3]);
                    break;
            }
        })->when(isset($where['pay_type']) && !empty($where['pay_type']), function ($query) use ($where) {
            if(is_array($where['pay_type'])){
                if(!empty($where['pay_type'])) {
                    if (!in_array("cika", $where['pay_type'])) {
                        $query->whereIn('pay_type', $where['pay_type'])->whereIn("order_type", [0, 1]);
                    } else {
                        $query->where(function ($q) use ($where) {
                            $q->whereIn('pay_type', $where['pay_type'])->whereOr("order_type", 2);
                        });
                    }
                }
            }else {
                switch ($where['pay_type']) {
                    case 1:
                        $query->where('pay_type', 'weixin');
                        break;
                    case 2:
                        $query->where('pay_type', 'yue');
                        break;
                    case 3:
                        $query->where('pay_type', 'offline');
                        break;
                    case 4:
                        $query->where('pay_type', 'alipay');
                        break;
                    case 5:
                        $query->where('pay_type', 'integral');
                        break;
                }
            }
        })->when(isset($where['active_pay']) && $where['active_pay'] !== '' && $where['active_pay'] != -1, function ($query) use ($where) {
            $query->where('pay_type', 'combination')->where('id', 'in', function ($sub) use ($where) {
                $sub->name('combination_order')->where('active_pay', (int)$where['active_pay']);
                $paySubType = trim((string)($where['pay_sub_type'] ?? ''));
                if ($paySubType !== '' && (int)$where['active_pay'] === 3) {
                    if ($paySubType === 'balance') {
                        // 余额支付：active_pay=3 且非旧卡升级（兼容 pay_sub_type 为空的历史数据）
                        $sub->whereRaw("(pay_sub_type IS NULL OR pay_sub_type <> 'card_upgrade')");
                    } else {
                        $sub->where('pay_sub_type', $paySubType);
                    }
                }
                $combCash = $where['combination_cash_choose'] ?? '';
                if ($combCash !== '' && (int)$where['active_pay'] === 2) {
                    $sub->where('cash_choose', (int)$combCash);
                }
                $sub->field('order_id');
            });
        })->when($deliveryType != '', function ($query) use ($where,$deliveryType) {
            switch ($deliveryType) {
                case 1:
                    $query->where('delivery_type', 'express');
                    break;
                case 2:
                    $query->where('delivery_type', 'send');
                    break;
                case 3:
                    $query->where('delivery_type', 'city_delivery')->whereOr('delivery_type', 'city_delivery_uu');
                    break;
                case 4:
                    $query->where('delivery_type', 'city_delivery')->whereOr('delivery_type', 'city_delivery_dd');
                    break;
                case 5:
                    $query->where('delivery_type', 'fictitious');
                    break;
                case 6:
                    $query->where('delivery_type', '');
                    break;
            }
        })->when($realName && $fieldKey && in_array($fieldKey, $this->withField), function ($query) use ($where, $realName, $fieldKey) {
            if ($fieldKey !== 'title') {
                $query->where(trim($fieldKey), trim($realName));
            } else {
                $query->where('id', 'in', function ($que) use ($where) {
                    $que->name('store_order_cart_info')->whereIn('product_id', function ($q) use ($where) {
                        $q->name('store_product')->whereLike('store_name|keyword', '%' . $where['real_name'] . '%')->field(['id'])->select();
                    })->field(['oid'])->select();
                });
            }
        })->when($searchOrderId !== '', function ($query) use ($searchOrderId) {
            $like = '%' . addcslashes($searchOrderId, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('order_id', 'like', $like)
                    ->whereOr('debt_repay_origin_order_id', 'in', function ($sub) use ($like) {
                        $sub->name('store_order')->whereLike('order_id', $like)->field('id');
                    })
                    ->whereOr('id', 'in', function ($sub) use ($like) {
                        $sub->name('store_order')->whereLike('order_id', $like)
                            ->where('debt_repay_origin_order_id', '>', 0)
                            ->field('debt_repay_origin_order_id');
                    })
                    ->whereOr('debt_repay_origin_order_id', 'in', function ($sub) use ($like) {
                        $sub->name('store_order')->whereLike('order_id', $like)
                            ->where('debt_repay_origin_order_id', '>', 0)
                            ->field('debt_repay_origin_order_id');
                    });
            });
        })->when($searchVerifyCode !== '', function ($query) use ($searchVerifyCode) {
            // 核销订单号：仅核销子单 order_type=2 的 order_id（含 is_auto=1 自动核销）
            $like = '%' . addcslashes($searchVerifyCode, '%_\\') . '%';
            $query->where('order_type', 2)->where('order_id', 'like', $like);
        })->when($searchProduct !== '', function ($query) use ($searchProduct) {
            // 普通订单：购物车明细商品；核销相关：以核销记录 store_order_writeoff.product_id 为准（子单 cart 未必能反映单次核销商品）
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $searchProduct) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereIn('id', function ($que) use ($like) {
                    $que->name('store_order_cart_info')->whereIn('product_id', function ($sub) use ($like) {
                        $sub->name('store_product')->whereLike('store_name|keyword', $like)->field(['id'])->select();
                    })->field(['oid'])->select();
                })->whereOr(function ($q2) use ($like) {
                    $q2->where('order_type', 2)->whereIn('link_id', function ($wo) use ($like) {
                        $wo->name('store_order_writeoff')->where('status', 0)
                            ->whereIn('product_id', function ($p) use ($like) {
                                $p->name('store_product')->whereLike('store_name|keyword', $like)->field(['id'])->select();
                            })->field(['id'])->select();
                    });
                })->whereOr(function ($q3) use ($like) {
                    $q3->whereIn('id', function ($wo) use ($like) {
                        $wo->name('store_order_writeoff')->where('status', 0)
                            ->whereIn('product_id', function ($p) use ($like) {
                                $p->name('store_product')->whereLike('store_name|keyword', $like)->field(['id'])->select();
                            })->field(['oid'])->select();
                    });
                });
            });
        })->when($searchUser !== '', function ($query) use ($searchUser) {
            // search_user=0：仅查游客订单；纯数字手机号需同时匹配 user.phone / user_phone，不能只做 UID 精确查
            if ($searchUser === '0') {
                $query->where('uid', 0);
            } else {
                $query->where(function ($que) use ($searchUser) {
                    if (preg_match('/^\d+$/', $searchUser)) {
                        $que->whereOr('uid', (int)$searchUser);
                    }
                    $que->whereOr('uid', 'in', function ($q) use ($searchUser) {
                        $q->name('user')->whereLike('nickname|uid|phone', '%' . $searchUser . '%')->field(['uid'])->select();
                    })->whereOr('uid', 'in', function ($q) use ($searchUser) {
                        $q->name('user_address')->whereLike('real_name|uid|phone', '%' . $searchUser . '%')->field(['uid'])->select();
                    })->whereOr(function ($q) use ($searchUser) {
                        $q->whereLike('real_name|user_phone', '%' . $searchUser . '%');
                    });
                });
            }
        })->when(isset($where['take_time']) && $where['take_time'] != '', function ($query) use ($where, $realName, $fieldKey) {
            $query->where('id', 'in', function ($que) use ($where) {
                [$startTime, $endTime] = explode('-', $where['take_time']);
                $startTime = trim($startTime) ? strtotime($startTime) : 0;
                $endTime = trim($endTime) ? strtotime($endTime) : 0;
                if ($startTime && $endTime) {
                    if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
                        $endTime = $endTime + 86400;
                    }
                }
                $que->name('store_order_status')->whereBetween('change_time', [$startTime, $endTime])->whereIn('change_type', ['user_take_delivery', 'take_delivery', ''])->field(['oid'])->select();
            });
        })->when($realName && !$fieldKey && $searchOrderId === '' && $searchVerifyCode === '' && $searchProduct === '' && $searchUser === '', function ($query) use ($where) {
            $query->where(function ($que) use ($where) {
                $que->where('order_id|real_name|user_phone|verify_code|id',$where['real_name'])
                    ->whereOr('order_id', 'like', '%' . addcslashes($where['real_name'], '%_\\') . '%')
                    ->whereOr('uid', 'in', function ($q) use ($where) {
                        $q->name('user')->whereLike('nickname|uid|phone', '%' . $where['real_name'] . '%')->field(['uid'])->select();
                    })->whereOr('uid', 'in', function ($q) use ($where) {
                        $q->name('user_address')->whereLike('real_name|uid|phone', '%' . $where['real_name'] . '%')->field(['uid'])->select();
                    })->whereOr('id', 'in', function ($que) use ($where) {//订单商品
                        $que->name('store_order_cart_info')->whereIn('product_id', function ($q) use ($where) {
                            $q->name('store_product')->whereLike('store_name|keyword', '%' . $where['real_name'] . '%')->field(['id'])->select();
                        })->field(['oid'])->select();
                    })->whereOr('link_order', 'in', function ($que) use ($where) {//关联单：按订单号模糊找主单 id
                        $like = '%' . addcslashes((string)$where['real_name'], '%_\\') . '%';
                        $que->name('store_order')->whereLike('order_id', $like)->field(['id'])->select();
                    })->whereOr('id', 'in', function ($que) use ($where) {//预约单
                        $que->name('store_reservation_order')->whereLike('order_id|oid|verify_code|reservation_name|reservation_phone', '%' . $where['real_name'] . '%')->field(['oid'])->select();
                    })->whereOr('activity_id', 'in', function ($que) use ($where) {
                        $que->name('store_seckill')->whereLike('title|info', '%' . $where['real_name'] . '%')->field(['id'])->select();
                    })->whereOr('activity_id', 'in', function ($que) use ($where) {
                        $que->name('store_bargain')->whereLike('title|info', '%' . $where['real_name'] . '%')->field(['id'])->select();
                    })->whereOr('activity_id', 'in', function ($que) use ($where) {
                        $que->name('store_combination')->whereLike('title|info', '%' . $where['real_name'] . '%')->field(['id'])->select();
                    });
            });
        })->when(isset($where['interval_price_min']) && $where['interval_price_min'] > 0 && isset($where['interval_price_max']) && $where['interval_price_max'] > 0, function ($query) use ($where) {
            $query->where('pay_price','between',[$where['interval_price_min'],$where['interval_price_max']])->where('is_del',0);
        })->when(isset($where['unique']), function ($query) use ($where) {
            $query->where('unique', $where['unique']);
        })->when(isset($where['is_remind']), function ($query) use ($where) {
            $query->where('is_remind', $where['is_remind']);
        })->when(isset($where['refundTypes']) && $where['refundTypes'] != '', function ($query) use ($where) {
            switch ((int)$where['refundTypes']) {
                case 1://申请中
                    $query->whereIn('refund_type', [0, 1, 2]);
                    break;
                case 2://待退货
                    $query->where('refund_type', 4);
                    break;
                case 3://退款中
                    $query->where('refund_type', 5);
                    break;
                case 4://已退款
                    $query->where('refund_type', 6);
                    break;
                case 5://处理中
                    $query->whereIn('refund_type', [0, 1, 2, 4, 5]);
                    break;
            }
        })->when(isset($where['is_refund']) && $where['is_refund'] !== '', function ($query) use ($where) {
            if ($where['is_refund'] == 1) {
                $query->where('refund_status', 2);
            } else {
                $query->where('refund_status', 0);
            }
        })->when(isset($where['date_range']) && !empty($where['date_range']), function ($query) use ($where) {
               $range=explode("-",$where['date_range']);
               $range[0]=strtotime($range[0]);
               $range[1]=strtotime($range[1]." 23:59:59");
               $query->whereBetween("add_time",$range);
        })->when(isset($where['date_range_time']) && !empty($where['date_range_time']), function ($query) use ($where) {
            $query->whereBetween("add_time",[strtotime($where['date_range_time'][0]),strtotime($where['date_range_time'][1])]);
        })->when(isset($where['agent_time']) && $where['agent_time'], function ($query) use ($where) {
            $query->where(function ($query) use ($where) {
                $validDates = array_filter($where['agent_time'], function($date) {
                    // 仅保留YYYY-MM-DD格式的有效日期（原有逻辑不变）
                    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
                });
                if(!empty($validDates)) {
                    $dateStr = "'" . implode("','",$validDates) . "'";
                    $query->whereRaw("FROM_UNIXTIME(add_time, '%Y-%m-%d') IN ({$dateStr})");
                }
            });
        });
    }

    /**
     * 获取用户最新的未支付订单信息
     * @param array $where
     * @param string $field
     * @param array $with
     * @return array|\mohe\basic\BaseModel|mixed|\think\Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getUserNotPayOrderDetail(array $where, string $field, array $with = [])
    {
        return $this->search($where)->with($with)->field($field)->order('id DESC')->find();
    }

    /**
     * 获取某一个月订单数量
     * @param array $where
     * @param string $month
     * @return int
     */
    public function getMonthCount(array $where, string $month)
    {
        return $this->search($where)->whereMonth('add_time', $month)->count();
    }

    /**
     * 获取某一个月订单金额
     * @param array $where
     * @param string $month
     * @param string $field
     * @return float
     */
    public function getMonthMoneyCount(array $where, string $month, string $field)
    {
        return $this->search($where)->whereMonth('add_time', $month)->sum($field);
    }

    /**
     * 获取购买历史用户
     * @param int $storeId
     * @param int $staffId
     * @param int $limit
     * @return mixed
     */
    public function getOrderHistoryList(int $storeId, int $staffId, array $uid = [], int $limit = 20)
    {
        return $this->search(['store_id' => $storeId, 'staff_id' => $staffId])->when($uid, function ($query) use ($uid) {
            $query->whereNotIn('uid', $uid);
        })->where('uid', '<>', 0)->with('user')->limit($limit)
            ->group('uid')->order('add_time', 'desc')->field(['uid', 'store_id', 'staff_id'])->select()->toArray();
    }

    /**
     * 订单搜索列表
     * @param array $where
     * @param array $field
     * @param int $page
     * @param int $limit
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, array $field, int $page = 0, int $limit = 0, array $with = [])
    {
        return $this->search($where)->field($field)
			->when($with, function ($query) use ($with) {
				$query->with($with);
			})->when($page && $limit, function ($query) use ($page, $limit) {
            	$query->page($page, $limit);
        	})->order('pay_time DESC,id DESC')->select()->toArray();
    }

    /**
     * 获取待核销的订单列表
     * @param array $where
     * @param array|string[] $field
     * @return mixed
     */
    public function getUnWirteOffList(array $where, array $field = ['*'])
    {
        return $this->search($where)->field($field)->where('paid', 1)->where('is_debt_repay', 0)->whereIn('status', [0, 1, 5])->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_system_del', 0)
            ->where(function ($query) {
                $query->where('shipping_type', 2)->whereOr('delivery_type', 'send');
            })->order('pay_time DESC,id DESC')->select()->toArray();
    }

    /**
     * 订单搜索列表
     * @param array $where
     * @param array $field
     * @param int $page
     * @param int $limit
     * @param array $with
     * @param string $order
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderList(array $where, array $field, int $page = 0, int $limit = 0, array $with = [], string $order = 'add_time DESC,id DESC')
    {
        return $this->search($where)->field($field)->with(array_merge(['user', 'spread', 'refund'], $with))->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(!$page && $limit, function ($query) use ($limit) {
            $query->limit($limit);
        })->order($order)->select()->toArray();
    }


    /**
     * 聚合查询
     * @param array $where
     * @param string $field
     * @param string $together
     * @return int
     */
    public function together(array $where, string $field, string $together = 'sum')
    {
        if (!in_array($together, ['sum', 'max', 'min', 'avg'])) {
            return 0;
        }
        return $this->search($where)->{$together}($field);
    }

    /**
     * 查找指定条件下的订单数据以数组形式返回
     * @param array $where
     * @param string $field
     * @param string $key
     * @param string $group
     * @return array
     */
    public function column(array $where, string $field, string $key = '', string $group = '')
    {
        return $this->search($where)->when($group, function ($query) use ($group) {
            $query->group($group);
        })->column($field, $key);
    }

    /**
     * 获取订单id下没有删除的订单数量
     * @param array $ids
     * @return int
     */
    public function getOrderIdsCount(array $ids)
    {
        return $this->getModel()->whereIn('id', $ids)->where('is_del', 0)->count();
    }

    /**
     * 获取一段时间订单统计数量、金额
     * @param array $where
     * @param array $time
     * @param string $timeType
     * @param bool $is_pid
     * @param string $countField
     * @param string $sumField
     * @param string $groupField
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderAddTimeList(array $where, array $time, string $timeType = "week", bool $is_pid = true, string $countField = '*', string $sumField = 'pay_price', string $groupField = 'add_time')
    {
        return $this->search($where)->when($is_pid, function ($query) {
            $query->where('pid', '>=', 0);
        })->where('paid', 1)->where('is_user_del', 0)->where('is_system_del', 0)
            ->where(isset($where['timekey']) && $where['timekey'] ? $where['timekey'] : 'add_time', 'between time', $time)
            ->when($timeType, function ($query) use ($timeType, $countField, $sumField, $groupField) {
                switch ($timeType) {
                    case "hour":
                        $timeUnix = "%H";
                        break;
                    case "day" :
                        $timeUnix = "%Y-%m-%d";
                        break;
                    case "week" :
                        $timeUnix = "%w";
                        break;
                    case "month" :
                        $timeUnix = "%d";
                        break;
                    case "weekly" :
                        $timeUnix = "%W";
                        break;
                    case "year" :
                        $timeUnix = "%Y-%m";
                        break;
                    default:
                        $timeUnix = $timeType;
                        break;
                }
                $query->field("FROM_UNIXTIME(`" . $groupField . "`,'$timeUnix') as day,count(" . $countField . ") as count,sum(`" . $sumField . "`) as price");
                $query->group('day');
            })->order('add_time asc')->select()->toArray();
    }

    /**
     * 统计总数上期
     * @param array $where
     * @param array $time
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function preTotalFind(array $where, array $time, string $sumField = 'pay_price', string $groupField = 'add_time')
    {
        return $this->getModel()->where($where)->where('pid', '>=', 0)->where('paid', 1)->whereIn('refund_status', [0, 3])->where('is_del', 0)->where('is_system_del', 0)
            ->where($groupField, 'between time', $time)
            ->field("count(*) as count,sum(`" . $sumField . "`) as price")
            ->find();
    }

    /**
     * 新订单ID
     * @param $status
     * @param int $store_id
     * @return array
     */
    public function newOrderId($status, int $store_id = 0)
    {
        return $this->search(['status' => $status, 'is_remind' => 0])->where('store_id', $store_id)->column('order_id', 'id');
    }


    /**
     * 总销售额
     * @param $time
     * @return float
     */
    public function totalSales($time)
    {
        return $this->search(['pid' => 0, 'paid' => 1, 'is_del' => 0, 'refund_status' => [0, 3], 'time' => $time ?: 'today', 'timekey' => 'add_time'])->sum('pay_price');
    }

    /**
     * 获取特定时间内订单量
     * @param $time
     * @return int
     */
    public function totalOrderCount($time)
    {
        return $this->search(['pid' => 0, 'paid' => 1, 'is_del' => 0, 'refund_status', [0, 3], 'time' => $time ?: 'today', 'timeKey' => 'add_time'])->count();
    }

    /**
     * 获取订单详情
     * @param string $key
     * @param int $uid
     * @param array $with
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserOrderDetail(string $key, int $uid = 0, array $with = [])
    {
        $str = substr($key, 0, 4);
        if (strpos($str, '_') !== false) {//二次支付订单
            $key = ltrim($key, $str);
        }
        $where = ['order_id|id|unique' => $key];
        if ($uid) $where['uid'] = $uid;
        return $this->getOne($where, '*', $with);
    }

    /**
     * 获取用户推广订单
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStairOrderList(array $where, string $field, int $page, int $limit, array $with = [])
    {
        return $this->search($where)->with($with)->field($field)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('id DESC')->select()->toArray();
    }

    /**
     * 订单每月统计数据
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getOrderDataPriceCount(array $where, array $field, int $page, int $limit)
    {
        return $this->search($where)
            ->field($field)->group("FROM_UNIXTIME(add_time, '%Y-%m-%d')")
            ->order('add_time DESC')->page($page, $limit)->select()->toArray();
    }

    /**送达记录
     * @param array $where
     * @param array $field
     * @param int $page
     * @param int $limit
     * @return mixed
     */
    public function getOrderDataPriceList(array $where, array $field, int $page, int $limit)
    {
        return $this->search($where)
            ->field($field)->group("FROM_UNIXTIME(delivery_time, '%Y-%m-%d')")
            ->order('delivery_time DESC')->page($page, $limit)->select()->toArray();
    }

    /**
     * 获取当前时间到指定时间的支付金额 管理员
     * @param $start
     * @param $stop
     * @param int $store_id
     * @return mixed
     */
    public function chartTimePrice($start, $stop, int $store_id = 0)
    {
        return $this->search(['pid' => 0, 'is_del' => 0, 'paid' => 1, 'refund_status' => [0, 3], 'is_system_del' => 0])
            ->where('store_id', $store_id)
            ->where('add_time', '>=', $start)
            ->where('add_time', '<', $stop)
            ->field('sum(pay_price) as price,FROM_UNIXTIME(add_time, \'%m-%d\') as time')
            ->group("FROM_UNIXTIME(add_time, '%%m-%d')")
            ->order('add_time ASC')->select()->toArray();
    }

    /**
     * 获取当前时间到指定时间的支付订单数 管理员
     * @param $start
     * @param $stop
     * @param int $store_id
     * @return mixed
     */
    public function chartTimeNumber($start, $stop, int $store_id = 0)
    {
        return $this->search(['pid' => 0, 'is_del' => 0, 'paid' => 1, 'refund_status' => [0, 3]])
            ->where('store_id', $store_id)
            ->where('add_time', '>=', $start)
            ->where('add_time', '<', $stop)
            ->field('count(id) as num,FROM_UNIXTIME(add_time, \'%m-%d\') as time')
            ->group("FROM_UNIXTIME(add_time, '%%m-%d')")
            ->order('add_time ASC')->select()->toArray();
    }

    /**
     * 获取用户已购买此活动商品的个数
     * @param $uid
     * @param $type
     * @param $activity_id
     * @return int
     */
    public function getBuyCount($uid, $type, $activity_id): int
    {
        return $this->getModel()
                ->where('uid', $uid)
                ->where('type', $type)
                ->where('activity_id', $activity_id)
                ->whereIn('pid', [0, -1])
                ->where(function ($query) {
                    $query->where('paid', 1)->whereOr(function ($query1) {
                        $query1->where('paid', 0)->where('is_del', 0);
                    });
                })->value('sum(total_num)') ?? 0;
    }

    /**
     * 获取没有支付的订单列表
     * @param int $page
     * @param int $limit
     * @return \mohe\basic\BaseModel
     */
    public function getOrderUnPaid(int $page = 0, int $limit = 0)
    {
        return $this->getModel()
            ->where(['pid' => 0, 'paid' => 0, 'is_del' => 0, 'status' => 0, 'refund_status' => 0])
            ->where('pay_type', '<>', 'offline')
            ->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            });
    }


    /**
     * 用户趋势数据
     * @param $time
     * @param $type
     * @param $timeType
     * @return mixed
     */
    public function getTrendData($time, $type, $timeType, $str)
    {
        return $this->getModel()->when($type != '', function ($query) use ($type) {
            $query->where('channel_type', $type);
        })->where('paid', 1)->where('pid', '>=', 0)->where(function ($query) use ($time) {
            if ($time[0] == $time[1]) {
                $query->whereDay('pay_time', $time[0]);
            } else {
                $time[1] = date('Y/m/d', strtotime($time[1]) + 86400);
                $query->whereTime('pay_time', 'between', $time);
            }
        })->field("FROM_UNIXTIME(pay_time,'$timeType') as days,$str as num")
            ->group('days')->select()->toArray();
    }

    /**
     * 用户地域数据
     * @param $time
     * @param $userType
     * @return mixed
     */
    public function getRegion($time, $userType)
    {
        return $this->getModel()->when($userType != '', function ($query) use ($userType) {
            $query->where('channel_type', $userType);
        })->where('pid', '>=', 0)->where('store_id', 0)->where(function ($query) use ($time) {
            if ($time[0] == $time[1]) {
                $query->whereDay('pay_time', $time[0]);
            } else {
                $time[1] = date('Y/m/d', strtotime($time[1]) + 86400);
                $query->whereTime('pay_time', 'between', $time);
            }
        })->field('pay_price as payPrice,substring_index(user_address, " ", 1) as province')->select()->toArray();
    }

	/**
	 * 商品趋势
	 * @param array $where
	 * @param array $time
	 * @param string $timeType
	 * @param string $field
	 * @param string $str
	 * @param string $orderStatus
	 * @return mixed
	 */
    public function getProductTrend(array $where, array $time, string $timeType, string $field, string $str, string $orderStatus = '')
    {
        return $this->search($where)->where(function ($query) use ($field, $orderStatus) {
            if ($field == 'pay_time') {
                $query->where('paid', 1);
            } elseif ($field == 'refund_reason_time') {
                $query->where('paid', 1)->where('refund_status', '>', 0);
            } elseif ($field == 'add_time') {
                if ($orderStatus == 'pay') {
                    $query->where('paid', 1)->where('pid', '>=', 0)->where('refund_status', 0);
                } elseif ($orderStatus == 'refund') {
                    $query->where('paid', 1)->where('pid', '>=', 0)->where('refund_status', '>', 0);
                } elseif ($orderStatus == 'coupon') {
                    $query->where('paid', 1)->where('pid', '>=', 0)->where('coupon_id', '>', 0);
                }
            }
        })->where('pid', '>=', 0)
		->where(function ($query) use ($time, $field) {
            if ($time[0] == $time[1]) {
                $query->whereDay($field, $time[0]);
            } else {
                $time[1] = date('Y/m/d', strtotime($time[1]) + 86400);
                $query->whereTime($field, 'between', $time);
            }
        })->field("FROM_UNIXTIME($field,'$timeType') as days,$str as num")->group('days')->select()->toArray();
    }


    /**
     * 按照支付时间统计支付金额
     * @param array $where
     * @param string $sumField
     * @return mixed
     */
    public function getDayTotalMoney(array $where, string $sumField)
    {
        return $this->search($where)
            ->when(isset($where['timeKey']), function ($query) use ($where) {
                $query->whereBetweenTime('pay_time', $where['timeKey']['start_time'], $where['timeKey']['end_time']);
            })
            ->sum($sumField);
    }

    /**
     * 时间段订单数统计
     * @param array $where
     * @param string $countField
     * @return int
     */
    public function getDayOrderCount(array $where, string $countField = "*")
    {
        return $this->search($where)
            ->when(isset($where['timeKey']), function ($query) use ($where) {
                $query->whereBetweenTime('pay_time', $where['timeKey']['start_time'], $where['timeKey']['end_time']);
            })
            ->count($countField);
    }

    /**
     * 时间分组订单付款金额统计
     * @param array $where
     * @param string $sumField
     * @return mixed
     */
    public function getDayGroupMoney(array $where, string $sumField, string $group)
    {
        return $this->search($where)
            ->when(isset($where['timeKey']), function ($query) use ($where, $sumField, $group) {
                $query->whereBetweenTime('pay_time', $where['timeKey']['start_time'], $where['timeKey']['end_time']);
                if ($where['timeKey']['days'] == 1) {
                    $timeUinx = "%H";
                } elseif ($where['timeKey']['days'] == 30) {
                    $timeUinx = "%Y-%m-%d";
                } elseif ($where['timeKey']['days'] == 365) {
                    $timeUinx = "%Y-%m";
                } elseif ($where['timeKey']['days'] > 1 && $where['timeKey']['days'] < 30) {
                    $timeUinx = "%Y-%m-%d";
                } elseif ($where['timeKey']['days'] > 30 && $where['timeKey']['days'] < 365) {
                    $timeUinx = "%Y-%m";
                } else {
                    $timeUinx = "%Y-%m";
                }
                $query->field("sum($sumField) as number,FROM_UNIXTIME($group, '$timeUinx') as time");
                $query->group("FROM_UNIXTIME($group, '$timeUinx')");
            })
            ->order('pay_time ASC')->select()->toArray();
    }

    /**
     * 时间分组订单数统计
     * @param array $where
     * @param string $sumField
     * @return mixed
     */
    public function getOrderGroupCount(array $where, string $sumField = "*")
    {
        return $this->search($where)
            ->when(isset($where['timeKey']), function ($query) use ($where, $sumField) {
                $query->whereBetweenTime('pay_time', $where['timeKey']['start_time'], $where['timeKey']['end_time']);
                if ($where['timeKey']['days'] == 1) {
                    $timeUinx = "%H";
                } elseif ($where['timeKey']['days'] == 30) {
                    $timeUinx = "%Y-%m-%d";
                } elseif ($where['timeKey']['days'] == 365) {
                    $timeUinx = "%Y-%m";
                } elseif ($where['timeKey']['days'] > 1 && $where['timeKey']['days'] < 30) {
                    $timeUinx = "%Y-%m-%d";
                } elseif ($where['timeKey']['days'] > 30 && $where['timeKey']['days'] < 365) {
                    $timeUinx = "%Y-%m";
                } else {
                    $timeUinx = "%Y-%m";
                }
                $query->field("count($sumField) as number,FROM_UNIXTIME(pay_time, '$timeUinx') as time");
                $query->group("FROM_UNIXTIME(pay_time, '$timeUinx')");
            })
            ->order('pay_time ASC')->select()->toArray();
    }

    /**
     * 时间段支付订单人数
     * @param $where
     * @return mixed
     */
    public function getPayOrderPeople($where)
    {
        return $this->search($where)
            ->when(isset($where['timeKey']), function ($query) use ($where) {
                $query->whereBetweenTime('pay_time', $where['timeKey']['start_time'], $where['timeKey']['end_time']);
            })
            ->field('uid')
            ->distinct(true)
            ->select()->toArray();
    }

    /**
     * 时间段分组统计支付订单人数
     * @param $where
     * @return mixed
     */
    public function getPayOrderGroupPeople($where)
    {
        return $this->search($where)
            ->when(isset($where['timeKey']), function ($query) use ($where) {
                $query->whereBetweenTime('pay_time', $where['timeKey']['start_time'], $where['timeKey']['end_time']);
                if ($where['timeKey']['days'] == 1) {
                    $timeUinx = "%H";
                } elseif ($where['timeKey']['days'] == 30) {
                    $timeUinx = "%Y-%m-%d";
                } elseif ($where['timeKey']['days'] == 365) {
                    $timeUinx = "%Y-%m";
                } elseif ($where['timeKey']['days'] > 1 && $where['timeKey']['days'] < 30) {
                    $timeUinx = "%Y-%m-%d";
                } elseif ($where['timeKey']['days'] > 30 && $where['timeKey']['days'] < 365) {
                    $timeUinx = "%Y-%m";
                } else {
                    $timeUinx = "%Y-%m";
                }
                $query->field("count(distinct uid) as number,FROM_UNIXTIME(pay_time, '$timeUinx') as time");
                $query->group("FROM_UNIXTIME(pay_time, '$timeUinx')");
            })
            ->order('pay_time ASC')->select()->toArray();
    }

	/**
	 * 获取区域门店订单统计排行
	 * @param array $where
	 * @param string $orderBy
	 * @return mixed
	 */
	public function getAgentStoreOrderRanking(array $where, string $orderBy = 'pay_price DESC', $field = 'pay_price', bool $validCashAmount = false)
	{
		if ($validCashAmount && $field === 'cash_pay_price') {
			$sumField = 'SUM(' . \app\services\order\ValidCashOrderServices::buildAmountExpr('', 'cash_pay_price') . ')';
		} else {
			$sumField = "SUM($field)";
		}
		return $this->search($where)->with(['storeInfo' => function ($query) {
			$query->bind(['name']);
		}])->field("store_id,id,count(id) as order_number,count(distinct uid) as user_number,{$sumField} as pay_price")->group('store_id')->order($orderBy)->select()->toArray();
	}


    /**
     * 获取批量打印电子面单数据
     * @param array $where
     * @param array $ids
     * @param string $filed
     * @param int $store_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderDumpData(array $where, array $ids = [], $filed = "*", int $store_id = 0)
    {
        $where['pid'] = 0;
        $where['status'] = 1;
        $where['is_system_del'] = 0;
        $where['store_id'] = $store_id;
        return $this->search($where)->when($ids, function ($query) use ($ids) {
            $query->whereIn('id', $ids);
        })->field($filed)->with(['pink'])->select()->toArray();
    }

    /**
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderListByWhere(array $where, $field = "*")
    {
        return $this->search($where)->field($field)->select()->toArray();
    }

    /**
     * 批量修改订单
     * @param array $ids
     * @param array $data
     * @param string|null $key
     * @return \mohe\basic\BaseModel
     */
    public function batchUpdateOrder(array $ids, array $data, ?string $key = null)
    {
        return $this->getModel()::whereIn(is_null($key) ? $this->getPk() : $key, $ids)->update($data);
    }

    /**
     * 获取拆单之后的子订单
     * @param int $id
     * @param string $field
     * @param int $type 1:不包含自己2：包含自己
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSonOrder(int $id, string $field = '*', int $type = 1)
    {
        return in_array($type, [1, 2]) ? $this->getModel()::field($field)->when($type, function ($query) use ($id, $type) {
            if ($type == 1) {
                $query->where('pid', $id);
            } else {
                $query->where('pid', $id)->whereOr('id', $id);
            }
        })->select()->toArray() : [];
    }

    /**
     * 查询退款订单
     * @param $where
     * @param $page
     * @param $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRefundList($where, $page = 0, $limit = 0)
    {
        $model = $this->getModel()
            ->where('is_system_del', 0)
            ->where('paid', 1)
            ->when(isset($where['store_id']), function ($query) use ($where) {
                $query->where('store_id', $where['store_id']);
            })->when(isset($where['refund_type']) && $where['refund_type'] !== '', function ($query) use ($where) {
                if ($where['refund_type'] == 0) {
                    $query->where('refund_type', '>', 0);
                } else {
                    $query->where('refund_type', $where['refund_type']);
                }
            })->when(isset($where['not_pid']), function ($query) {
                $query->where('pid', '<>', -1);
            })->when($where['order_id'] != '', function ($query) use ($where) {
                $query->where('order_id', $where['order_id']);
            })->when(is_array($where['refund_reason_time']), function ($query) use ($where) {
                $query->whereBetween('refund_reason_time', [strtotime($where['refund_reason_time'][0]), strtotime($where['refund_reason_time'][1]) + 86400]);
            })->with(array_merge(['user', 'spread']));
        $count = $model->count();
        $list = $model->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('refund_reason_time desc')->select()->toArray();
        return compact('list', 'count');
    }

    /**
     * 订单搜索列表
     * @param array $where
     * @param array $field
     * @param int $page
     * @param int $limit
     * @param array $with
     * @param string $order
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getOutOrderList(array $where, array $field, int $page = 0, int $limit = 0, array $with = [], string $order = 'add_time DESC,id DESC'): array
    {
        return $this->search($where)->field($field)->with($with)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order($order)->select()->toArray();
    }

    /**
     * 门店线上支付订单详情
     * @param int $store_id
     * @param int $uid
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function payCashierOrder(int $store_id, int $uid)
    {
        return $this->getModel()->where('uid', $uid)->where('store_id', $store_id)->where('paid', 0)->where('is_del', 0)->where('is_system_del', 0)
            ->where('shipping_type', 4)
            ->order('add_time desc,id desc')
            ->find();
    }

    /**
     * 商品趋势
     * @param $time
     * @param $timeType
     * @param $field
     * @param $str
     * @param $orderStatus
     * @return mixed
     */
    public function getOrderStatistics($where, $time, $timeType, $field, $str, $orderStatus = '')
    {
        return $this->getModel()->where($where)->where(function ($query) use ($field, $orderStatus) {
            if ($field == 'pay_time') {
                $query->where('paid', 1);
            } elseif ($field == 'refund_reason_time') {
                $query->where('paid', 1)->where('refund_status', '>', 0);
            } elseif ($field == 'add_time') {
                if ($orderStatus == 'pay') {
                    $query->where('paid', 1)->where('pid', '>=', 0)->whereIn('refund_status', [0, 3]);
                } elseif ($orderStatus == 'refund') {
                    $query->where('paid', 1)->where('pid', '>=', 0)->where('refund_type', 6);
                }
            }
        })->where(function ($query) use ($time, $field) {
            if ($time[0] == $time[1]) {
                $query->whereDay($field, $time[0]);
            } else {
                $time[1] = date('Y/m/d', (!is_numeric($time[1]) ? strtotime($time[1]) : $time[1]) + 86400);
                $query->whereTime($field, 'between', $time);
            }
        })->where('is_del', 0)->where('is_system_del', 0)
            ->field("FROM_UNIXTIME($field,'$timeType') as days,$str as num")->group('days')->select()->toArray();
    }

    /**
     * 获取活动订单列表：
     * @param int $id
     * @param int $type 0:普通、1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、6:预售、7:新人礼
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function activityStatisticsOrder(int $id, int $type = 1, array $where = [], int $page = 0, int $limit = 0)
    {
        return $this->search($where)->where('pid', 'in', [0, -1])->where('paid', 1)->where('type', $type)->where('activity_id', $id)
            ->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->field(['order_id', 'real_name', 'status', 'pay_price', 'total_num', 'add_time', 'pay_time', 'paid', 'shipping_type', 'refund_status', 'is_del', 'is_system_del'])->select()->toArray();
    }


    /**
     * 秒杀参与人统计
     * @param int $id
     * @param string $keyword
     * @param int $page
     * @param int $limit
     * @return mixed
     */
    public function seckillPeople(int $id, string $keyword, int $page = 0, int $limit = 0)
    {
        return $this->getModel()
            ->when($id != 0, function ($query) use ($id) {
                $query->where('type', 1)->where('activity_id', $id);
            })->when($keyword != '', function ($query) use ($keyword) {
                $query->where('real_name|uid|user_phone', 'like', '%' . $keyword . '%');
            })->where('pid', 'in', [0, -1])->where('paid', 1)->field([
                'real_name',
                'uid',
                'SUM(total_num) as goods_num',
                'COUNT(id) as order_num',
                'SUM(pay_price) as total_price',
                'add_time'
            ])->group('uid')->order("add_time desc")->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }

    /**
     * @param int $pid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSubOrderNotSendList(int $pid)
    {
        return $this->getModel()->where('pid', $pid)->where('status', 1)->select()->toArray();
    }

    /**
     * @param int $pid
     * @param int $order_id
     * @return int
     * @throws \think\db\exception\DbException
     */
    public function getSubOrderNotSend(int $pid, int $order_id)
    {
        return $this->getModel()->where('pid', $pid)->where('status', 0)->where('id', '<>', $order_id)->count();
    }

    /**
     * @param int $pid
     * @param int $order_id
     * @return int
     * @throws \think\db\exception\DbException
     */
    public function getSubOrderNotTake(int $pid, int $order_id)
    {
        return $this->getModel()->where('pid', $pid)->where('status', 1)->where('id', '<>', $order_id)->count();
    }

    /**
     * @param $uids
     * @param $field
     * @return array
     */
    public function getUserOrderSum($uids, $field)
    {
        //查询uids的field字段的和并以uid分组
        $res = $this->getModel()->whereIn('uid', $uids)
            ->whereIn('refund_status', [0, 3])
            ->where('paid', 1)
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->where('pid', '>=', 0)
            ->field("uid,SUM($field) as num")
            ->group('uid')->select()->toArray();
        //uid为键，num为值的一维数组
        return array_column($res, 'num', 'uid');
    }
}
