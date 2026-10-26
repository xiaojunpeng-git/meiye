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


use app\dao\work\WorkLabelDao;
use app\services\BaseServices;

/**
 * 企业微信标签
 * Class WorkLabelServices
 * @package app\services\\work
 * @mixin WorkLabelDao
 */
class WorkLabelServices extends BaseServices
{

    /**
     * WorkLabelServices constructor.
     * @param WorkLabelDao $dao
     */
    public function __construct(WorkLabelDao $dao)
    {
        $this->dao = $dao;
    }
}
