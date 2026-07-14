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

namespace app\dao\community;

use app\dao\BaseDao;
use app\model\community\Community;
use app\services\community\CommunityRelevanceServices;
use mohe\basic\BaseModel;

/**
 * 社区
 * Class CommunityDao
 * @package app\dao\community
 */
class CommunityDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return Community::class;
    }

    /**
     * @param array $where
     * @param bool $search
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function search(array $where = [], bool $search = false)
    {
        return parent::search($where, $search)
            ->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
                $query->where(function ($query) use ($where) {
                    $query->whereLike('title|id', "%{$where['keyword']}%");
                });
//                    ->whereOr(function ($query) use ($where) {
//                    //商品
//                    $query->whereIn('id', function ($product) use ($where) {
//                        $product->name('community_relevance')->where('type', CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT)->whereIn('right_id',
//                            function ($q) use ($where) {
//                                $q->name('store_product')->whereLike('store_name|keyword', "%{$where['keyword']}%")->field('id');
//                            }
//                        )->field('left_id');
//                    });
//                })->whereOr(function ($query) use ($where) {
//                    //用户
//                    $query->whereIn('relation_id', function ($user) use ($where) {
//                        $user->name('user')->whereLike('nickname', "%{$where['keyword']}%")->field('uid');
//                    });
//                });
            })
            ->when(isset($where['topic_id']) && $where['topic_id'], function ($query) use ($where) {
                $topicIds = is_array($where['topic_id']) ? $where['topic_id'] : [$where['topic_id']];
                $query->whereIn('id', function ($topic) use ($topicIds) {
                    $topic->name('community_relevance')->where('type', CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC)->whereIn('right_id', $topicIds)->field('left_id');
                });
            })
            ->when(isset($where['product_id']) && $where['product_id'], function ($query) use ($where) {
                $productIds = is_array($where['product_id']) ? $where['product_id'] : [$where['product_id']];
                $query->whereIn('id', function ($product) use ($productIds) {
                    $product->name('community_relevance')->where('type', CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT)->whereIn('right_id', $productIds)->field('left_id');
                });
            })
            ->when(isset($where['is_follow']) && $where['is_follow'], function ($query) use ($where) {
                $query->whereIn('community_user_id', function ($product) use ($where) {
                    $product->name('community_relevance')->where('type', CommunityRelevanceServices::TYPE_COMMUNITY_INTEREST)->where('left_id', $where['is_follow'])->field('right_id');
                });
            })
//            ->when(isset($where['order_by_id']) && $where['order_by_id'], function ($query) use ($where) {
//                $query->where('id', 'in', $where['order_by_id'])->orderField('id', $where['order_by_id']);
//            })
            ->when(isset($where['notId']), function ($query) use ($where) {
                $query->where('id', '<>', $where['notId']);
            });
    }

    /**
     * 社区列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @param string $order
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0, array $with = [], string $order = 'sort desc,id desc')
    {
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->when($order, function ($query) use ($order) {
                if ($order == 'rand') {
                    $query->orderRand();
                } else if ($order) {
                    $query->order($order);
                } else {
                    $query->order('sort desc,id desc');
                }
            })->select()->toArray();
    }

    /**
     * @param array $where
     * @param string $field
     * @param int $limit
     * @return array
     * @throws \ReflectionException
     */
    public function getIdColumn(array $where, string $field, int $limit = 0): array
    {
        return $this->search($where)->when($limit, function ($query) use ($limit) {
			$query->limit($limit);
		})->column($field);
    }
}
