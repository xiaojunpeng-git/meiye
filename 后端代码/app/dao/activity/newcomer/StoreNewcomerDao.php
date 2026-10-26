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

namespace app\dao\activity\newcomer;

use app\dao\BaseDao;
use app\model\activity\newcomer\StoreNewcomer;


/**
 * 新人礼商品
 * Class StoreNewcomerDao
 * @package app\dao\activity\newcomer
 */
class StoreNewcomerDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreNewcomer::class;
    }

    /**
     * 搜索
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    protected function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['storeProductId']), function ($query) {
            $query->where('product_id', 'IN', function ($query) {
                $query->name('store_product')->where('is_show', 1)->where('is_del', 0)->field('id');
            });
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
	* 新人专享商品列表
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
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->when($with, function ($query) use ($with) {
				$query->with($with);
            })->when(isset($where['priceOrder']) && $where['priceOrder'] != '', function ($query) use ($where) {
				if ($where['priceOrder'] === 'desc') {
					$query->order("price desc");
				} else {
					$query->order("price asc");
				}
			})->when(isset($where['salesOrder']) && $where['salesOrder'] != '', function ($query) use ($where) {
				if ($where['salesOrder'] === 'desc') {
					$query->order("sales desc");
				} else {
					$query->order("sales asc");
				}
			})->order('id desc')->select()->toArray();
    }





}
