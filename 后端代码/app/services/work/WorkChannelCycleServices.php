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

namespace app\services\work;


use app\dao\work\WorkChannelCycleDao;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;

/**
 * 渠道码周期规则
 * Class WorkChannelCycleServices
 * @package app\services\work
 * @mixin WorkChannelCycleDao
 */
class WorkChannelCycleServices extends BaseServices
{

    use ServicesTrait;

    /**
     * WorkChannelCycleServices constructor.
     * @param WorkChannelCycleDao $dao
     */
    public function __construct(WorkChannelCycleDao $dao)
    {
        $this->dao = $dao;
    }
}
