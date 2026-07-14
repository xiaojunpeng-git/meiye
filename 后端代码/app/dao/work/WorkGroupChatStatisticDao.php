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
use app\model\work\WorkGroupChatStatistic;
use mohe\traits\SearchDaoTrait;

/**
 * Class WorkGroupChatStatisticDao
 * @package app\dao\work
 */
class WorkGroupChatStatisticDao extends BaseDao
{

    use SearchDaoTrait;

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return WorkGroupChatStatistic::class;
    }

    /**
     * 获取当前统计详情
     * @param int $chatId
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getToDayInfo(int $chatId)
    {
        return $this->getModel()->whereDay('create_time')->where('group_id', $chatId)->find();
    }
}
