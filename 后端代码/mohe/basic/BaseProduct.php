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

namespace mohe\basic;

use mohe\services\AccessTokenServeService;

/**
 * Class BaseProduct
 * @package mohe\basic
 */
abstract class BaseProduct extends BaseStorage
{

    /**
     * access_token
     * @var null
     */
    protected $accessToken = NULL;

	/**
	 * @param string $name
	 * @param AccessTokenServeService $accessTokenServeService
	 * @param string $configFile
	 * @param array $config
	 */
    public function __construct(string $name, AccessTokenServeService $accessTokenServeService, string $configFile = '', array $config = [])
    {
        $this->accessToken = $accessTokenServeService;
		$this->name = $name;
		$this->configFile = $configFile;
		$this->initialize($config);
    }

    /**
     * 初始化
     * @param array $config
     * @return mixed|void
     */
    protected function initialize(array $config = [])
    {
//        parent::initialize($config);
    }

    /**
     * 开通服务
     * @return mixed
     */
    abstract public function open();

    /**复制商品
     * @return mixed
     */
    abstract public function goods(string $url);
}
