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
use mohe\services\wechat\DefaultConfig;

/**
 * 小程序配置
 * Class MiniProgramConfig
 * @package mohe\services\wechat\config
 */
class MiniProgramConfig implements ConfigHandlerInterface
{

    /**
     * APPid
     * @var string
     */
    protected $appId;

    /**
     * APPsecret
     * @var string
     */
    protected $secret;

	/**
	 * Token
	 * @var string
	 */
	protected $token;

	/**
	 * EncodingAESKey
	 * @var string
	 */
	protected $aesKey;

    /**
     * @var string
     */
    protected $responseType = 'array';

    /**
     * 日志记录
     * @var LogCommonConfig
     */
    protected $logConfig;

    /**
     * http配置
     * @var HttpCommonConfig
     */
    protected $httpConfig;

    /**
     * 是否初始化过
     * @var bool
     */
    protected $init = false;

    /**
     * MiniProgramConfig constructor.
     * @param LogCommonConfig $config
     * @param HttpCommonConfig $commonConfig
     */
    public function __construct(LogCommonConfig $config, HttpCommonConfig $commonConfig)
    {
        $this->logConfig = $config;
        $this->httpConfig = $commonConfig;
    }

    /**
     * 初始化
     */
    protected function init()
    {
        if ($this->init) {
            return;
        }
        $this->init = true;
        $this->appId = $this->appId ?: $this->httpConfig->getConfig(DefaultConfig::MINI_APPID, '');
        $this->secret = $this->secret ?: $this->httpConfig->getConfig('mini.secret', '');
		$this->token = $this->token ?: $this->httpConfig->getConfig('mini.token', '');
		$this->aesKey = $this->aesKey ?: $this->httpConfig->getConfig('mini.key', '');
    }


    /**
     * 获取配置
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->httpConfig->getConfig($key, $default);
    }

    /**
     * 设置
     * @param string $key
     * @param $value
     * @return $this|mixed
     */
    public function set(string $key, $value)
    {
        $this->{$key} = $value;
        return $this;
    }

    /**
     * @param string|null $key
     * @return array|mixed
     */
    public function get(string $key = null)
    {
        $this->init();
        if ('log' === $key) {
            return $this->logConfig->all();
        }
        if ('http' === $key) {
            return $this->httpConfig->all();
        }
        return $this->{$key};
    }

    /**
     * 全部
     * @return array
     */
    public function all(): array
    {
        $this->init();
        return [
            'app_id' => $this->appId,
            'secret' => $this->secret,
			'token' => $this->token,
			'aes_key' => $this->aesKey,
            'response_type' => $this->responseType,
            'log' => $this->logConfig->all(),
            'http' => $this->httpConfig->all()
        ];
    }
}
