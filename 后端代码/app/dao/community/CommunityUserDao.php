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
use app\model\community\CommunityUser;
use mohe\basic\BaseModel;

/**
 * 社区用户
 * Class CommunityUserDao
 * @package app\dao\community
 */
class CommunityUserDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return CommunityUser::class;
    }

    /**
     * @param array $where
     * @param bool $search
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function search(array $where = [], bool $search = false)
    {
        return parent::search($where, $search)->when(isset($where['name']) && $where['name'], function ($query) use ($where) {
            $query->whereLike('id|nickname', '%' . $where['name'] . '%');
        })->when(isset($where['id']) && $where['id'], function ($query) use ($where) {
            $query->whereIn('id', $where['id']);
        });
    }

    /**
     *
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
    public function getList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0, string $order = 'id DESC')
    {
        return $this->search($where)->field($field)
            ->when($with, function ($query) use ($with) {
                $query->with($with);
            })
            ->when(isset($where['is_community_num']), function ($query) {
                $query->where('community_num', '>', 0);
            })
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order($order)->select()->toArray();
    }

    /**
     * 获取用户信息
     * @param int $uid
     * @return array|mixed
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserInfo(int $uid,int $type)
    {

        $where = [
            'is_del' => 0
        ];
        if ($uid == 0 && $type == 0) {
            $where['type'] = 0;
            $where['uid'] = 0;
        } else {
            $where['type'] = $type;
            $where['relation_id'] = $uid;
        }
        return $this->search($where)->find();
    }

}
