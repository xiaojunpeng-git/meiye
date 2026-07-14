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

namespace mohe\services;


use mohe\exceptions\ApiException;

/**
 * Class AccessTokenServeService
 * @package mohe\services
 */
class AccessTokenServeService extends HttpService
{
    /**
     * 配置
     * @var string
     */
    protected $account;

    /**
     * @var string
     */
    protected $secret;

    /**
     * @var Cache|null
     */
    protected $cache;

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var string
     */
    protected $cacheTokenPrefix = "_mohe_plat";

    /**
     * @var string
     */
    protected $apiHost = 'http://sms.mohe.net/api/v2/';

    /**
     * 登录接口
     */
    const USER_LOGIN = "user/login";


    /**
     * AccessTokenServeService constructor.
     * @param string $account
     * @param string $secret
     * @param Cache|null $cache
     */
    public function __construct(string $account, string $secret, $cache = null)
    {
        if (!$cache) {
            /** @var CacheService $cache */
            $cache = app()->make(CacheService::class);
        }
        $this->account = $account;
        $this->secret = $secret;
        $this->cache = $cache;
    }

    /**
     * 获取配置
     * @return array
     */
    public function getConfig()
    {
		return [
			'access_key' => $this->account,
			'secret_key' => $this->secret
		];
    }

    /**
     * 获取缓存token
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getToken()
    {
        $accessTokenKey = md5($this->account . '_' . $this->secret . $this->cacheTokenPrefix);
        $cacheToken = $this->cache->get($accessTokenKey);
        if (!$cacheToken) {
            $getToken = $this->getTokenFromServer();
            $this->cache->set($accessTokenKey, $getToken['access_token'], 300);
            $cacheToken = $getToken['access_token'];
        }
        $this->accessToken = $cacheToken;

        return $cacheToken;

    }

    /**
     * 从服务器获取token
     * @return mixed
     */
    public function getTokenFromServer()
    {
		$params = [
			'access_key' => $this->account,
			'secret_key' => $this->secret,
		];
        if (!$this->account || !$this->secret) {
            throw new ApiException('请先登录一号通平台!');
        }
        $response = $this->postRequest($this->get(self::USER_LOGIN), $params);
        $response = json_decode($response, true);
        if (!$response) {
            throw new ApiException('获取token失败');
        }
        if ($response['status'] === 200) {
            return $response['data'];
        } else {
            throw new ApiException($response['msg']);
        }
    }

	/**
	 * 请求
	 * @param string $url
	 * @param array $data
	 * @param string $method
	 * @param bool $isHeader
	 * @param array $header
	 * @return array|mixed
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 */
    public function httpRequest(string $url, array $data = [], string $method = 'POST', bool $isHeader = true, array $header = [])
    {
        if ($isHeader) {
            $this->getToken();
            if (!$this->accessToken) {
                throw new ApiException('配置已更改或token已失效');
            }
            $header = array_merge($header, ['Authorization:Bearer-' . $this->accessToken]);
        }

        $res = $this->request($this->get($url), $method, $data, $header);
        if (!$res) {
            throw new ApiException('平台错误：发生异常，请稍后重试');

        }
        $result = json_decode($res, true) ?: false;
        if (!isset($result['status']) || $result['status'] != 200) {
            throw new ApiException(isset($result['msg']) ? '平台错误：' . $result['msg'] : '平台错误：发生异常，请稍后重试');
        }
        return $result['data'] ?? [];

    }

    /**
     * @param string $apiUrl
     * @return string
     */
    public function get(string $apiUrl = '')
    {
        return $this->apiHost . $apiUrl;
    }
}
