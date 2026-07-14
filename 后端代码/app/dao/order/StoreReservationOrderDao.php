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
use app\model\order\StoreReservationOrder;

/**
 * 预约单
 * Class StoreReservationOrderDao
 * @package app\dao\order
 */
class StoreReservationOrderDao extends BaseDao
{

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreReservationOrder::class;
    }

	/**
	 * @param array $data
	 * @return \mohe\basic\BaseModel|mixed|\think\Model
	 */
	public function search(array $where = [])
	{
		return parent::search($where)->when(isset($where['search']) && $where['search'], function ($query) use ($where) {
			$query->where(function ($q) use ($where) {
				$q->whereLike('id|oid|reservation_phone|reservation_name', '%' . $where['search'] . '%')->whereOr('oid', 'in', function ($o) use ($where) {
					$o->name('store_order')->whereLike('id|order_id', '%' . $where['search'] . '%')->field(['id'])->select();
				})->whereOr('product_id', 'in', function ($p) use ($where) {
					$p->name('store_product')->whereLike('store_name|keyword', '%' . $where['search'] . '%')->field(['id'])->select();
				});
			});
		})->when(isset($where['reservation_time']) && $where['reservation_time'], function ($query) use ($where) {
			if (is_array($where['reservation_time'])) {
				$query->whereTime('reservation_time', 'between', $where['reservation_time']);
			} else {
				$ts = (int)$where['reservation_time'];
				if ($ts > 0) {
					$day = date('Y-m-d', $ts);
					$query->whereBetween('reservation_time', [
						strtotime($day . ' 00:00:00'),
						strtotime($day . ' 23:59:59'),
					]);
				}
			}
		})->when(isset($where['reservation_phone']) && $where['reservation_phone'], function ($query) use ($where) {
            $query->where('reservation_phone', $where['reservation_phone']);
		})->when(isset($where['teacher_staff_id']) && (int)$where['teacher_staff_id'] > 0, function ($query) use ($where) {
            $staffId = (int)$where['teacher_staff_id'];
            $query->whereRaw("(staff_choose LIKE ? OR staff_choose LIKE ?)", [
                '%"staff_id":' . $staffId . ',%',
                '%"staff_id":' . $staffId . '}%',
            ]);
		})->when(isset($where['teacher_tab']) && $where['teacher_tab'] !== '', function ($query) use ($where) {
            $tab = (int)$where['teacher_tab'];
            $mainTable = $query->getTable();
            if ($tab === 0) {
                $query->whereIn('status', [0, 1]);
            } elseif ($tab === 1) {
                $query->where('status', 2)->whereNotExists(function ($sub) use ($mainTable) {
                    $sub->name('store_product_reply')->alias('r')
                        ->whereRaw("r.oid = {$mainTable}.oid")
                        ->whereRaw("r.unique = {$mainTable}.sku_unique")
                        ->where('r.is_del', 0);
                });
            } elseif ($tab === 2) {
                $query->where('status', 2)->whereExists(function ($sub) use ($mainTable) {
                    $sub->name('store_product_reply')->alias('r')
                        ->whereRaw("r.oid = {$mainTable}.oid")
                        ->whereRaw("r.unique = {$mainTable}.sku_unique")
                        ->where('r.is_del', 0);
                });
            }
        });
	}

	/**
 	* 获取列表
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
        return $this->search($where)->field($field)->when($with, function($query) use ($with) {
			$query->with($with);
		})->when($page && $limit, function($query) use ($page, $limit) {
			$query->page($page, $limit);
		})->when(!$page && $limit, function ($query) use ($limit) {
			$query->limit($limit);
		})->order('id desc')->select()->toArray();
    }



}
