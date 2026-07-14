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

namespace app\dao\product\product;

use app\dao\BaseDao;
use app\model\product\product\StoreProduct;
use app\model\product\product\StoreProductReply;

/**
 *
 * Class StoreProductReplyStoreProductDao
 * @package app\dao\product\product
 */
class StoreProductReplyStoreProductDao extends BaseDao
{
    /**
     * 表别名
     * @var string
     */
    protected $alias = '';

    /**
     * 链表别名
     * @var string
     */
    protected $joinAlis = '';

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreProductReply::class;
    }

    /**
     * 链表模型
     * @return string
     */
    public function setJoinModel(): string
    {
        return StoreProduct::class;
    }

    /**
     * 关联模型
     * @param string $alias
     * @param string $join_alias
     * @return \mohe\basic\BaseModel
     */
    public function getModel(string $alias = 'r', string $join_alias = 'p', $join = 'left')
    {
        $this->alias = $alias;
        $this->joinAlis = $join_alias;
        /** @var StoreProduct $storeProduct */
        $storeProduct = app()->make($this->setJoinModel());
        $table = $storeProduct->getName();
        return parent::getModel()->join($table . ' ' . $join_alias, $alias . '.product_id = ' . $join_alias . '.id', $join)->alias($alias);
    }

    /**
     * 获取评论列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductReplyList(array $where, int $page, int $limit, array $with = [])
    {
        return $this->searchWhere($where)->page($page, $limit)->with($with)->select()->toArray();
    }

    /**
     * 获取评论条数
     * @param array $where
     * @return int
     */
    public function replyCount(array $where)
    {
        return $this->searchWhere($where)->count();
    }

    /**
     * 搜索
     * @param array $where
     * @return \mohe\basic\BaseModel
     */
    public function searchWhere(array $where = [])
    {
        $model = $this->getModel()->where('r.is_del', 0)->withSearch(['time'], ['time' => $where['data'], 'timeKey' => 'r.add_time'])->order('r.add_time desc,r.is_reply asc')->field('r.*,p.store_name,p.image,r.nickname as account,FROM_UNIXTIME(r.add_time,"%Y-%m-%d %H:%i:%s") as add_time');
        if ($where['is_reply'] !== '') $model = $model->where('r.is_reply', $where['is_reply']);
        if (isset($where['type']) && $where['type'] !== '') {
            $model = $model->where('r.type', $where['type']);
        }
		if (isset($where['relation_id'])) {
			if (is_array($where['relation_id'])) {
				if ($where['relation_id']) $model = $model->whereIn('r.relation_id', $where['relation_id']);
			} else {
				if ($where['relation_id'] !== '') $model = $model->where('r.relation_id', $where['relation_id']);
			}
		}
        if (isset($where['store_id'])) {
            if ($where['store_id']) $model = $model->where('r.type', 1)->where('r.relation_id', $where['relation_id']);
        }
        if ($where['product_id']) $model = $model->where('r.product_id', $where['product_id']);
        if ($where['store_name']) $model = $model->where('p.store_name|p.keyword|r.id|r.product_id', 'Like', '%' . $where['store_name'] . '%');
        if ($where['account']) $model = $model->where('r.nickname', 'LIKE', '%' . $where['account'] . '%');
        return $model;
    }

}
