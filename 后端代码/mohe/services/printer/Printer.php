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

namespace mohe\services\printer;

use mohe\basic\BaseManager;
use think\facade\Config;
use think\Container;

/**
 * Class Printer
 * @package mohe\services\auth
 * @mixin \mohe\services\printer\storage\YiLianYun
 * @mixin \mohe\services\printer\storage\FeiEYun
 */
class Printer extends BaseManager
{

    /**
     * 空间名
     * @var string
     */
    protected $namespace = '\\mohe\\services\\printer\\storage\\';

    /**
     * @var object
     */
    protected $handleAccessToken;

    /**
     * 默认驱动
     * @return mixed
     */
    protected function getDefaultDriver()
    {
        return Config::get('printer.default', 'yi_lian_yun');
    }

    /**
     * 获取类的实例
     * @param $class
     * @return mixed|void
     */
    protected function invokeClass($class)
    {
        if (!class_exists($class)) {
            throw new \RuntimeException('class not exists: ' . $class);
        }
        $this->getConfigFile();

        if (!$this->config) {
            $this->config = Config::get($this->configFile . '.stores.' . $this->name, []);
        }

        if (!$this->handleAccessToken) {
            $this->handleAccessToken = new AccessToken($this->config, $this->name, $this->configFile);
        }

        $handle = Container::getInstance()->invokeClass($class, [$this->name, $this->handleAccessToken, $this->configFile]);
        $this->config = [];
        return $handle;
    }

}
