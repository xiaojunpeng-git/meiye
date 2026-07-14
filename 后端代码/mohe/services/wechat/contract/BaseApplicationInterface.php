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

namespace mohe\services\wechat\contract;

/**
 * Interface BaseApplicationInterface
 * @package mohe\services\wechat\contract
 */
interface BaseApplicationInterface
{

    /**
     * @return mixed
     */
    public static function instance();

    /**
     * @return mixed
     */
    public function application();
}
