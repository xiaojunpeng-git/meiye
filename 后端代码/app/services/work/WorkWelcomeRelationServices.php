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


use app\dao\work\WorkWelcomeRelationDao;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;

/**
 * Class WorkWelcomeRelationServices
 * @package app\services\work
 * @mixin WorkWelcomeRelationDao
 */
class WorkWelcomeRelationServices extends BaseServices
{

    use ServicesTrait;

    /**
     * WorkWelcomeRelationServices constructor.
     * @param WorkWelcomeRelationDao $dao
     */
    public function __construct(WorkWelcomeRelationDao $dao)
    {
        $this->dao = $dao;
    }
}
