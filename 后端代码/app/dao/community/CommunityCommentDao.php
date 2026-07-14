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
use app\model\community\CommunityComment;
use mohe\basic\BaseModel;

/**
 * 社区评论
 * Class CommunityCommentDao
 * @package app\dao\community
 */
class CommunityCommentDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return CommunityComment::class;
    }

    /**
     * @param array $where
     * @param bool $search
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function search(array $where = [], bool $search = false)
    {
        $keyword = $where['keyword'] ?? '';
        $fieldKey = $where['field_key'] ?? '';
        $fieldKey = in_array($fieldKey, ['user', 'community', 'comment', 'id']) ? $fieldKey : '';
        /** field_key
         *  user
         * community
         * comment
         * id
         */
        return parent::search($where, $search)
            ->when(isset($where['author_uid']), function ($query) use ($where) {
                if ($where['author_uid'] == 0) {
                    $query->where('is_show', 1);
                }

            })
            ->when($keyword !== '' && $fieldKey !== '', function ($query) use ($fieldKey, $keyword) {
                switch ($fieldKey) {
                    case 'user':
                        $site_name = sys_config('site_name');
                        if(strpos($site_name, $keyword) !== false) {
                            $query->where('type', 0)->where('uid',0)->whereOr(function ($query) use ($keyword) {
                                $query->whereIn('uid', function ($query) use ($keyword) {
                                    $query->name('user')->whereLike('nickname', '%' . $keyword . '%')->field('uid');
                                });
                            });
                        } else {
                            $query->whereIn('uid', function ($query) use ($keyword) {
                                $query->name('user')->whereLike('nickname', '%' . $keyword . '%')->field('uid');
                            });
                        }
                        break;
                    case 'community':
                        $query->whereIn('community_id', function ($query) use ($keyword) {
                            $query->name('community')->whereLike('title|content', '%' . $keyword . '%')->field('id');
                        });
                        break;
                    case 'comment':
                        $query->whereLike('content', '%' . $keyword . '%');
                        break;
                    case 'id':
                        $query->where('id', $keyword);
                }

            })->when(isset($where['is_verify']) && $where['is_verify'] !== '', function ($query) use ($where) {
                if (isset($where['author_uid'])) {
                    $query->where(function ($query) use ($where) {
                        if ($where['author_uid'] == 0) {
                            $query->where('is_verify', 1);
                        } else {
                            $query->where(function ($query) use ($where) {
                                // 条件一：uid 等于 author_uid，且 is_verify 为 0 或 1
                                $query->where('uid', $where['author_uid'])
                                    ->whereIn('is_verify', [0, 1]);
                            })->whereOr(function ($query) use ($where) {
                                // 条件二：uid 不等于 author_uid，且 is_verify 为 1
                                $query->where('uid', '<>', $where['author_uid'])
                                    ->where('is_verify', 1);
                            });
                        }
                    });
                } else {
                    $query->where('is_verify', $where['is_verify']);
                }
            });
    }

    public function count($where = []): int
    {
        return $this->search($where)->count();
    }

    /**
     * 评论列表
     * @param array $where
     * @param string $field
     * @param array $with
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0, $order = 'add_time DESC')
    {
        return $this->search($where)->field($field)
            ->when($with, function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order($order)->select()->toArray();
    }

}
