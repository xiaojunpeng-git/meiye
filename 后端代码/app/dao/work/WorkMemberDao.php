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

namespace app\dao\work;


use app\dao\BaseDao;
use app\model\work\WorkMember;
use mohe\basic\BaseAuth;
use mohe\traits\SearchDaoTrait;

/**
 * 企业微信成员
 * Class WorkMemberDao
 * @package app\dao\work
 */
class WorkMemberDao extends BaseDao
{
    use SearchDaoTrait;

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return WorkMember::class;
    }

    /**
     * 搜索
     * @param array $where
     * @param bool $authWhere
     * @return \mohe\basic\BaseModel
     */
    public function searchWhere(array $where, bool $authWhere = true)
    {
        [$with] = app()->make(BaseAuth::class)->________(array_keys($where), $this->setModel());
        return $this->getModel()->withSearch($with, $where)
            ->when(!empty($where['name']), function ($query) use ($where) {
                $query->where('id|name|mobile', 'like', '%' . $where['name'] . '%');
            })->when(!empty($where['department']), function ($query) use ($where) {
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('work_member_relation')->where('department', $where['department'])->field(['member_id']);
                });
            });
    }
}
