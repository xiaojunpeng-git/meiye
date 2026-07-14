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


namespace mohe\services\delivery;

use mohe\basic\BaseManager;
use think\facade\Config;

/**
 * Class Delivery
 * @package mohe\services\delivery
 * @mixin \mohe\services\delivery\storage\Dada
 * @mixin \mohe\services\delivery\storage\Uupt
 */
class Delivery extends BaseManager
{
    /**
     * 空间名
     * @var string
     */
    protected $namespace = '\\mohe\\services\\delivery\\storage\\';

    /**
     * 设置默认
     * @return mixed
     */
    protected function getDefaultDriver()
    {
        return Config::get('delivery.default', 'dada');
    }
}
