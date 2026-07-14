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

namespace mohe\services\wechat\config;

use mohe\services\wechat\contract\ConfigHandlerInterface;
use mohe\services\wechat\contract\ServeConfigInterface;
use mohe\services\wechat\DefaultConfig;

/**
 * Http请求配置
 * Class HttpCommonConfig
 * @package mohe\services\wechat\config
 */
class HttpCommonConfig implements ConfigHandlerInterface
{
    /**
     * @var bool[]
     */
    protected $config = [
        'verify' => false,
    ];

    /**
     * @var string
     */
    protected $serve;

    /**
     * @param string $serve
     * @return $this
     */
    public function setServe(string $serve)
    {
        $this->serve = $serve;
        return $this;
    }

    /**
     * 获取服务端实例
     * @return ServeConfigInterface
     */
    public function getServe()
    {
        return app()->make($this->serve);
    }

    /**
     * 直接获取配置
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->getServe()->getConfig(DefaultConfig::value($key), $default);
    }

    /**
     * @param string $key
     * @param $value
     * @return $this|mixed
     */
    public function set(string $key, $value)
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * @param string|null $key
     * @return bool|bool[]|mixed
     */
    public function get(string $key = null)
    {
        if ($key) {
            return $this->config[$key];
        }
        return $this->config;
    }

    /**
     * @return array|bool[]
     */
    public function all(): array
    {
        return $this->config;
    }
}
