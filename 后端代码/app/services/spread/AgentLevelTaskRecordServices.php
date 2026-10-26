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

namespace app\services\spread;

use app\dao\spread\AgentLevelTaskRecordDao;
use app\services\BaseServices;


/**
 * 分销等级任务完成记录
 * Class AgentLevelTaskRecordServices
 * @package app\services\agent
 * @mixin AgentLevelTaskRecordDao
 */
class AgentLevelTaskRecordServices extends BaseServices
{
    /**
     * AgentLevelTaskRecordServices constructor.
     * @param AgentLevelTaskRecordDao $dao
     */
    public function __construct(AgentLevelTaskRecordDao $dao)
    {
        $this->dao = $dao;
    }
}
