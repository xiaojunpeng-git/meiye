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

namespace app\dao\activity\bargain;

use app\dao\BaseDao;
use app\model\activity\bargain\StoreBargain;

/**
 * 砍价商品
 * Class StoreBargainDao
 * @package app\dao\activity\bargain
 */
class StoreBargainDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreBargain::class;
    }

    /**
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)
            ->when(isset($where['start_status']) && $where['start_status'] !== '', function ($query) use ($where) {
                $time = time();
                switch ($where['start_status']) {
                    case -1:
                        $query->where('stop_time', '<', $time);
                        break;
                    case 0:
                        $query->where('start_time', '>', $time);
                        break;
                    case 1:
                        $query->where('start_time', '<=', $time)->where('stop_time', '>=', $time);
                        break;
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
     * 获取砍价列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page = 0, int $limit = 0)
    {
        return $this->search($where)->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('sort desc,id desc')->select()->toArray();
    }

    /**
     * 获取活动开启中的砍价id以数组形式返回
     * @param array $ids
     * @param array $field
     * @return array
     */
    public function getBargainIdsArray(array $ids, array $field = [])
    {
        return $this->search(['is_del' => 0, 'status' => 1])->where('start_time', '<=', time())
            ->where('stop_time', '>=', time())->whereIn('product_id', $ids)->column(implode(',', $field), 'product_id');
    }

    /**
     * 根据id获取砍价数据
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function idByBargainList(array $ids, string $field)
    {
        return $this->getModel()->whereIn('id', $ids)->field($field)->select()->toArray();
    }

    /**
     * 正在开启的砍价活动
     * @param int $status
     * @return StoreBargain|\mohe\basic\BaseModel
     */
    public function validWhere(int $status = 1)
    {
        $now = time();
        return $this->getModel()->where('is_del', 0)->where('status', $status)->where('start_time', '<', $now)->where('stop_time', '>', $now);
    }

	/**
	 * 砍价列表
	 * @param array $where
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function bargainList(array $where = [], int $page = 0, int $limit = 0)
    {
        return $this->search(array_merge($where, ['is_del' => 0, 'status' => 1]))
			->with(['getPrice'])
            ->where('start_time', '<=', time())
            ->where('stop_time', '>=', time())
            ->where('product_id', 'IN', function ($query) {
                $query->name('store_product')->where('is_del', 0)->field('id');
            })->when($page && $limit, function ($query) use ($page, $limit) {
				$query->page($page, $limit);
			})->when(!$page && $limit, function ($query) use ($limit) {
				$query->limit($limit);
			})->order('sort DESC,id DESC')->select()->toArray();
    }

    /**
     * 修改砍价状态
     * @param int $id
     * @param string $field
     * @return mixed
     */
    public function addBargain(int $id, string $field)
    {
        return $this->getModel()->where('id', $id)->inc($field, 1)->update();
    }
}
