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
use mohe\services\HttpService;
use think\exception\ValidateException;
use think\facade\Config;
use mohe\services\CacheService;

/**
 * Class BaseExpress
 * @package mohe\basic
 */
abstract class BaseExpress extends BaseStorage
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

    }


    /**
     * 开通服务
     * @return mixed
     */
    abstract public function open();

    /**物流追踪
     * @return mixed
     */
    abstract public function query(string $num, string $com = '');

    /**电子面单
     * @return mixed
     */
    abstract public function dump($data);

    /**快递公司
     * @return mixed
     */
    //abstract public function express($type, $page, $limit);

    /**面单模板
     * @return mixed
     */
    abstract public function temp(string $com);
}
