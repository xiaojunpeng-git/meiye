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

namespace app\dao\activity\combination;

use app\dao\BaseDao;
use app\model\activity\combination\StoreCombination;

/**
 * 拼团商品
 * Class StoreCombinationDao
 * @package app\dao\activity\combination
 */
class StoreCombinationDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreCombination::class;
    }

    /**
     * 搜索
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['pinkIngTime']), function ($query) use ($where) {
            $time = time();
            [$startTime, $stopTime] = is_array($where['pinkIngTime']) ? $where['pinkIngTime'] : [$time, $time];
            $query->where('start_time', '<=', $startTime)->where('stop_time', '>=', $stopTime);
        })->when(isset($where['storeProductId']), function ($query) {
            $query->where('product_id', 'IN', function ($query) {
                $query->name('store_product')->where('is_del', 0)->field('id');
            });
        })->when(isset($where['start_status']) && $where['start_status'] !== '', function ($query) use ($where) {
            $time = time();
            switch ($where['start_status']) {
                case -1:
                    $query->where(function ($query) use ($time) {
                        $query->where('stop_time', '<', $time)->whereOr('is_show', 0);
                    });
                    break;
                case 0:
                    $query->where('start_time', '>', $time)->where('is_show', 1);
                    break;
                case 1:
                    $query->where('start_time', '<=', $time)->where('stop_time', '>=', $time)->where('is_show', 1);
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
     * 拼团商品列表
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
        return $this->search($where)->with('getPrice')
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order('sort desc,id desc')->select()->toArray();
    }

    /**
     * 获取正在进行拼团的商品以数组形式返回
     * @param array $ids
     * @param array $field
     * @return array
     */
    public function getPinkIdsArray(array $ids, array $field = [])
    {
        return $this->search(['is_del' => 0, 'is_show' => 1, 'pinkIngTime' => 1])->whereIn('product_id', $ids)->column(implode(',', $field), 'product_id');
    }

	/**
	 * 获取拼团列表
	 * @param array $where
	 * @param string $field
	 * @param int $page
	 * @param int $limit
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function combinationList(array $where, string $field = '*', int $page = 0, int $limit = 0)
	{
		return $this->search($where)->field($field)->with('getPrice')
			->when($page && $limit, function ($query) use ($page, $limit) {
				$query->page($page, $limit);
			})->when(!$page && $limit, function ($query) use ($limit) {
				$query->limit($limit);
			})->order('sort desc,id desc')->select()->toArray();
	}

    /**
     * 根据id获取拼团数据
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function idCombinationList(array $ids, string $field)
    {
        return $this->getModel()->whereIn('id', $ids)->field($field)->select()->toArray();
    }

	/**
	 * 获取一条拼团数据
	 * @param int $id
	 * @param string $field
	 * @return array|\mohe\basic\BaseModel|mixed|\think\Model|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function combinationOne(int $id, string $field = '*')
	{
		$where = ['is_del' => 0,];
		return $this->search($where)->where('id', $id)->with(['getTotal'])->field($field)->order('add_time desc')->find();
	}

    /**
     * 获取一条有效的拼团数据
     * @param int $id
     * @param string $field
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function validCombinationOne(int $id, string $field = '*')
    {
        $where = ['is_show' => 1, 'is_del' => 0, 'pinkIngTime' => true];
        return $this->search($where)->where('id', $id)->with(['getTotal'])->field($field)->order('add_time desc')->find();
    }

    /**
     * 获取推荐拼团
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCombinationHost()
    {
        $where = ['is_del' => 0, 'is_host' => 1, 'is_show' => 1, 'pinkIngTime' => true];
        return $this->search($where)->with(['getTotal'])->order('id desc')->select()->toArray();
    }
}
