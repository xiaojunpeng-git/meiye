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
declare (strict_types=1);

namespace app\dao\activity\integral;

use app\dao\BaseDao;
use app\model\activity\integral\StoreIntegral;

/**
 * 积分商品
 * Class StoreIntegralDao
 * @package app\dao\activity\integral
 */
class StoreIntegralDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreIntegral::class;
    }

	public function search(array $where = [])
	{
		return parent::search($where)
			->where('product_id', 'IN', function ($query) {
				$query->name('store_product')->where('is_del', 0)->field('id');
			})
			->when(isset($where['integral_time']) && $where['integral_time'] !== '', function ($query) use ($where) {
				[$startTime, $endTime] = explode('-', $where['integral_time']);
				$query->where('add_time', '>', strtotime($startTime))
					->where('add_time', '<', strtotime($endTime) + 24 * 3600);
			})->when(isset($where['priceOrder']) && $where['priceOrder'] != '', function ($query) use ($where) {
				if ($where['priceOrder'] === 'desc') {
					$query->order("integral,price desc");
				} else {
					$query->order("integral,price asc");
				}
			})->when(isset($where['salesOrder']) && $where['salesOrder'] != '', function ($query) use ($where) {
				if ($where['salesOrder'] === 'desc') {
					$query->order("sales desc");
				} else {
					$query->order("sales asc");
				}
			})->when(isset($where['range']) && $where['range'] != '', function ($query) use ($where) {
				$data = explode('-', $where['range']);
				if (count($data) > 1) {
					$query->where('integral', '>=', $data[0])->where('integral', '<=', $data[1]);
				}
			})->when(isset($where['applicable_store_id']) && $where['applicable_store_id'], function ($query) use ($where) {
				$query->where(function ($d) {
					$d->whereOr(function ($c) {
						$c->whereFindInSet('delivery_type', 2);
					})->whereOr(function ($d) {
						$d->whereFindInSet('delivery_type', 3);
					});
				})->where(function ($a) use ($where) {
					$a->where('applicable_type', 1)->whereOr(function ($b) use ($where) {
						$b->where('applicable_type', 2)->whereFindInSet('applicable_store_id', $where['applicable_store_id']);
					});
				});
			});
	}

	/**
     * 积分商品列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page = 0, int $limit = 0, string $field = '*')
    {
        return $this->search($where)->where('is_del', 0)->with(['getPrice'])
			->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->when(!$page && $limit, function ($query) use ($limit) {
				$query->limit($limit);
			})->field($field)->order('sort desc,id desc')->select()->toArray();
    }

	/**
	 * 获取一条积分商品数据
	 * @param int $id
	 * @param string $field
	 * @param array $with
	 * @return array|\mohe\basic\BaseModel|mixed|\think\Model|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function validProduct(int $id, string $field, array $with = [])
    {
		$where = ['is_show' => 1, 'is_del' => 0];
		return $this->search($where)->where('id', $id)->when($with, function ($query) use ($with) {
			$query->with($with);
		})->field($field)->order('add_time desc')->find();
    }
}
