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
use app\model\community\CommunityRelevance;
use app\services\community\CommunityRelevanceServices;
use mohe\basic\BaseModel;
use think\exception\ValidateException;

/**
 * 社区关联
 * Class CommunityTopicDao
 * @package app\dao\community
 */
class CommunityRelevanceDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return CommunityRelevance::class;
    }

    /**
     * @param array $where
     * @param bool $search
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function search(array $where = [], bool $search = false)
    {
        return parent::search($where, $search);
    }

    /**
     * 获取社区内容关联商品
     * @param int $left_id
     * @param array $where
     * @param $page
     * @param $limit
     * @param $condition
     * @return mixed
     */
    public function getCommunityProduct(int $left_id = 0, array $where = [], $page = 0, $limit = 0, $condition = 'right_id')
    {
        return $this->getModel()->alias('r')
            ->join('store_product p', "r.{$condition}=p.id")->where('p.is_verify', 1)->where('p.is_del', 0)->where('p.is_show', 1)
            ->when($left_id, function ($query) use ($where, $left_id) {
                $query->where('r.left_id', $left_id)->where('r.type', CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT);
//                $query->where('p.id', 'in', function ($que) use ($where, $left_id) {
//                    $que->where('r.left_id', $left_id)->where('r.type', CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT)->field(['right_id'])->select();
//                });
            })
            //商品
            ->when(isset($where['type']) && $where['type'], function ($query) use ($where) {
                $query->whereIn('p.type', $where['type']);
            })->when(isset($where['pid']) && $where['pid'], function ($query) use ($where) {
                $query->whereIn('p.pid', $where['pid']);
            })->when(isset($where['relation_id']) && $where['relation_id'], function ($query) use ($where) {
                $query->whereIn('p.relation_id', $where['relation_id']);
            })->when(isset($where['shop_operation_relation_id']) && $where['shop_operation_relation_id'], function ($query) use ($where) {
                $query->where(function ($a) use ($where) {
                    $a->whereIn('p.type', [0, 2])->where(function ($b) use ($where) {
                        $b->whereIn('p.applicable_type', [0, 1])->whereOr(function ($c) use ($where) {
                            $c->where('p.applicable_type', 2)->whereFindInSet('p.applicable_store_id', $where['shop_operation_relation_id']);
                        });
                    });
                })->whereOr(function ($qe) use ($where) {
                    $qe->where('p.type', 1)->where('p.relation_id', $where['shop_operation_relation_id']);
                });
            })->when($limit, function ($query) use ($page, $limit) {
                if ($page) {
                    $query->page($page, $limit);
                } else {
                    $query->limit($limit);
                }
            })->field('p.id,p.price,p.store_name,p.image,p.stock')
            ->select()
            ->toArray();
    }

    public function joinCommunityList(array $where = [], $page = 0, $limit = 0, $condition = 'right_id', $order = 'r.id DESC')
    {
        return $this->joinCommunity($where, $condition)
            ->field('r.*,c.id as c_id,c.type as c_type,c.title,c.image,c.relation_id,c.content_type,c.like_num,c.slider_image')
            ->when($limit, function ($query) use ($page, $limit) {
                if ($page) {
                    $query->page($page, $limit);
                } else {
                    $query->limit($limit);
                }
            })
            ->order($order)
            ->select()
            ->toArray();
    }

    public function joinCommunity(array $where = [], $condition = 'right_id')
    {
        return $this->getModel()->alias('r')
            ->join('community c', "r.{$condition}=c.id")
            ->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
                $query->where(function ($query) use ($where) {
                    $query->whereLike('c.title|c.content', "%{$where['keyword']}%");
                })->whereOr(function ($query) use ($where) {
                    //商品
                    $query->whereIn('c.id', function ($product) use ($where) {
                        $productIds = name('product')->where('store_name|keyword', "%{$where['keyword']}%")->column('id');
                        $product->name('community_relevance')->where('type', CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT)->whereIn('right_id', $productIds)->field('left_id');
                    });
                })->whereOr(function ($query) use ($where) {
                    //用户
                    $query->whereIn('c.relation_id', function ($user) use ($where) {
                        $user->name('user')->where('nickname', "%{$where['keyword']}%")->field('uid');
                    });
                });
            })
            ->where('c.is_verify', 1)->where('c.is_del', 0)->where('c.status', 1)
            //用户
            ->when(isset($where['uid']) && $where['uid'], function ($query) use ($where) {
                $query->where('r.left_id', $where['uid'])->where('r.type', CommunityRelevanceServices::TYPE_COMMUNITY_LIKE);
            })
            //商品
            ->when(isset($where['product_id']) && $where['product_id'], function ($query) use ($where) {
                $query->where('r.right_id', $where['product_id'])->where('r.type', CommunityRelevanceServices::TYPE_COMMUNITY_PRODUCT);
            })
            //话题
            ->when(isset($where['topic_id']) && $where['topic_id'], function ($query) use ($where) {
                $query->where('r.right_id', $where['topic_id'])->where('r.type', CommunityRelevanceServices::TYPE_COMMUNITY_TOPIC);
            });
    }

    /**
     * 添加
     * @param int $leftId
     * @param int $rightId
     * @param string $type
     * @param bool $check
     * @return bool
     */
    public function create(int $leftId, int $rightId, string $type, bool $check = false)
    {
        if ($check && $this->checkHas($leftId, $rightId, $type)) {
            return false;
        }
        $data = [
            'left_id' => $leftId,
            'right_id' => $rightId,
            'type' => $type,
        ];

        try {
            $this->save($data);
            return true;
        } catch (\Exception  $exception) {
            throw new ValidateException('创建失败');
        }
    }

    /**
     * 检测是否存在
     * @param int $leftId
     * @param int $rightId
     * @param string $type
     * @return mixed
     */
    public function checkHas(int $leftId, int $rightId, string $type)
    {
        return $this->search([
            'left_id' => $leftId,
            'right_id' => $rightId,
            'type' => $type,
        ])->find();
    }

    /**
     * 根据左键批量删除
     * @param $leftId
     * @param $type
     * @return mixed
     */
    public function batchDelete($leftId, $type)
    {
        return $this->search([
            'left_id' => $leftId,
            'type' => $type,
        ])->delete();
    }

    /**
     * 条件删除
     * @param int $leftId
     * @param int $rightId
     * @param string $type
     * @return mixed
     */
    public function destory(int $leftId, int $rightId, string $type)
    {
        return $this->search([
            'left_id' => $leftId,
            'right_id' => $rightId,
            'type' => $type,
        ])->delete();
    }

    /**
     * 批量插入
     * @param int $leftId
     * @param array $rightId
     * @param string $type
     * @return void
     */
    public function createMany(int $leftId, array $rightId, string $type)
    {
        if (!empty($rightId)) {
            $res = [];
            foreach ($rightId as $value) {
                $res[] = [
                    'left_id' => $leftId,
                    'right_id' => $value,
                    'type' => $type,
                ];
            }
            if (count($res) > 0) {
                $this->saveAll($res);
            }
        }
    }
}
