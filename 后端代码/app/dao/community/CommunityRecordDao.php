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
use app\model\community\CommunityRecord;

/**
 * 社区记录
 * Class CommunityRecordDao
 * @package app\dao\community
 */
class CommunityRecordDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return CommunityRecord::class;
    }

    /**
     * @param array $where
     * @param array $field
     * @param int $page
     * @param int $limit
     * @param array $with
     * @return array|\mohe\basic\BaseModel[]|\think\Collection|\think\Model[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, array $field = ['*'], int $page = 0, int $limit = 0, array $with = [])
    {
        return $this->search($where)
            ->when($limit && $page, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->when($limit && !$page, function ($query) use ($limit) {
                $query->limit($limit);
            })
            ->field($field)
            ->order('add_time DESC')
            ->select()->toArray();
    }
}
