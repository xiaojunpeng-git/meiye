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

namespace app\dao\user;


use app\dao\BaseDao;
use app\model\user\UserBrokerageFrozen;

/**
 * 佣金冻结
 * Class UserBrokerageFrozenDao
 * @package app\dao\user
 */
class UserBrokerageFrozenDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return UserBrokerageFrozen::class;
    }


}
