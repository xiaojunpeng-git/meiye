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
use app\model\order\StoreOrderWriteoff;

/**
 * 订单核销
 * Class StoreOrderWriteoffDao
 * @package app\dao\order
 * @method saveAll(array $data)
 */
class StoreOrderWriteoffDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreOrderWriteoff::class;
    }

    /**
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)
            ->where("status",0)
            ->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
                $kw = trim((string)$where['keyword']);
                $query->where(function ($q) use ($kw) {
                    // 原主单订单号（兼容）
                    $q->where('oid', 'in', function ($order) use ($kw) {
                        $order->name('store_order')->field('id')->where('order_id', $kw)->select();
                    })->whereOr('id', 'in', function ($sub) use ($kw) {
                        // 列表展示的核销子单订单号：store_order.order_type=2 且 link_id=核销记录 id
                        $sub->name('store_order')
                            ->where('order_type', 2)
                            ->where('order_id', $kw)
                            ->field('link_id')->select();
                    });
                });
            })->when(isset($where['ordering_store_id']) && $where['ordering_store_id'], function ($query) use ($where) {
                $query->where('oid', 'in', function ($que) use ($where) {
                    $que->name('store_order')->where('store_id', $where['ordering_store_id'])->field(['id'])->select();
                });
            })->when(isset($where['write_off_store_id']) && $where['write_off_store_id'], function ($query) use ($where) {
                $query->where('relation_id', $where['write_off_store_id']);
            })->when(isset($where['user_key']) && $where['user_key'], function ($query) use ($where) {
                $query->where('uid', 'in', function ($que) use ($where) {
                    $que->name('user')->field('uid')->whereLike('real_name|phone', '%' . $where['user_key'] . '%')->select();
                });
            })->when(isset($where['product_name']) && $where['product_name'] !== '' && $where['product_name'] !== null, function ($query) use ($where) {
                $like = '%' . addcslashes((string)$where['product_name'], '%_\\') . '%';
                $query->whereIn('product_id', function ($q) use ($like) {
                    $q->name('store_product')->whereLike('store_name|keyword', $like)->field('id')->select();
                });
            })->when(isset($where['yeji_staff']) && $where['yeji_staff'], function ($query) use ($where) {
                // 与 StoreOrderDao::yeji_shouyi（手艺人员）一致：staff_yeji type=3 + staff_name 模糊；
                // 核销列表展示的手艺人来自 link_id=核销记录 id，故子查询取 link_id 对应 store_order_writeoff.id
                $query->where('id', 'in', function ($que) use ($where) {
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $where['yeji_staff']) . '%';
                    $que->name('staff_yeji')->field('link_id')->where('type', 3)->whereLike('staff_name', $like)->select();
                });
            })->when(isset($where['staff']) && $where['staff'] !== '' && $where['staff'] !== null, function ($query) use ($where) {
                $serviceType = $where['service_type'] ?? '';
                if ($serviceType === '' || $serviceType === null) {
                    $query->where(function ($q) use ($where) {
                        $q->whereLike('staff_id', '%' . $where['staff'] . '%')
                            ->whereOr('staff_id', 'IN', function ($staff) use ($where) {
                                $staff->name('system_store_staff')->field('id')->whereLike('id|staff_name', '%' . $where['staff'] . '%')->select();
                            })->whereOr('staff_id', 'IN', function ($product) use ($where) {
                                $product->name('system_admin')->field('id')->whereLike('id|account|real_name', '%' . $where['staff'] . '%')->select();
                            })->whereOr('staff_id', 'IN', function ($order) use ($where) {
                                $order->name('store_service')->field('uid')->whereLike('id|account|nickname', '%' . $where['staff'] . '%')->select();
                            })->whereOr('staff_id', 'IN', function ($delivery) use ($where) {
                                $delivery->name('delivery_service')->field('id')->whereLike('id|nickname', '%' . $where['staff'] . '%')->select();
                            });
                    });
                } else {
                    switch ((string)$serviceType) {
                        case '1':
                            $query->where('service_type',1)->where('staff_id', 'in', function ($que) use ($where) {
                                $que->name('store_service')->field('uid')->whereLike('id|account|nickname', '%' . $where['staff'] . '%')->select();
                            });
                            break;
                        case '2':
                            $query->where('service_type',2)->where('staff_id', 'in', function ($que) use ($where) {
                                $que->name('system_admin')->field('id')->whereLike('id|account|real_name', '%' . $where['staff'] . '%')->select();
                            });
                            break;
                        case '3':
                            $query->where('service_type',3)->where('staff_id', 'in', function ($que) use ($where) {
                                $que->name('delivery_service')->field('id')->whereLike('id|nickname', '%' . $where['staff'] . '%')->select();
                            });
                            break;
                        default:
                            $query->where('service_type',0)->where('staff_id', 'in', function ($que) use ($where) {
                                $que->name('system_store_staff')->field('id')->whereLike('id|staff_name', '%' . $where['staff'] . '%')->select();
                            });
                    }
                }
            })->when(isset($where['relation_id']) && $where['relation_id'], function ($query) use ($where) {
                $query->where('relation_id', $where['relation_id']);
            })->when(isset($where['product_type']) && $where['product_type'], function ($query) use ($where) {
                if(is_array($where['product_type'])){
                    $query->whereIn('product_type', $where['product_type']);
                }else {
                    $query->where('product_type', $where['product_type']);
                }
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
            })->when(isset($where['cate_ids']) && $where['cate_ids'], function ($query) use ($where){
                //查询某些类别下商品的业绩
                if(!empty($where['cate_ids'])){
                    $query->whereIn("product_id",function ($q) use ($where) {
                        $q->name('store_product_relation')->where("type",1)
                            ->where(function ($q) use ($where){
                                $q->whereIn("relation_id",$where['cate_ids'])->whereOr(function ($d) use ($where){
                                    $d->whereIn("relation_pid",$where['cate_ids']);
                                });
                            })->field(['product_id'])->select();
                    });
                }
            });
    }

    /**
     * 获取核销列表
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
    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, array $with = [])
    {
        return $this->search($where)->field($field)
            ->when($with, function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order('id desc')->select()->toArray();
    }
}
