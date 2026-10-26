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


use app\dao\work\WorkChannelLimitDao;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;

/**
 * Class WorkChannelLimitServices
 * @package app\services\\work
 * @mixin WorkChannelLimitDao
 */
class WorkChannelLimitServices extends BaseServices
{

    use ServicesTrait;

    /**
     * WorkChannelLimitServices constructor.
     * @param WorkChannelLimitDao $dao
     */
    public function __construct(WorkChannelLimitDao $dao)
    {
        $this->dao = $dao;
    }
}
