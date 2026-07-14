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
use app\model\community\CommunityTopic;
use mohe\basic\BaseModel;

/**
 * 社区话题
 * Class CommunityTopicDao
 * @package app\dao\community
 */
class CommunityTopicDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return CommunityTopic::class;
    }

    /**
     * 话题列表
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
    public function getList(array $where, string $field = '*', array $with = [], int $page = 0, int $limit = 0)
    {
        return $this->search($where)->field($field)
            ->when($with, function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->order('sort desc,id desc')->select()->toArray();
    }


}
