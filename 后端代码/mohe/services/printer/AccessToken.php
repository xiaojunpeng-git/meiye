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


use app\services\other\CacheServices;
use mohe\services\HttpService;
use think\facade\Config;
use think\helper\Str;

/**
 *
 * Class AccessToken
 * @package mohe\services\printer
 */
class AccessToken extends HttpService
{

    /**
     * token
     * @var array
     */
    protected $accessToken;

    /**
     * 请求接口
     * @var string
     */
    protected $apiUrl;

    /**
     * @var string
     */
    protected $clientId;

    /**
     * 终端号码
     * @var string
     */
    protected $machineCode;

    /**
     * 开发者id
     * @var string
     */
    protected $partner;

    /**
     * 驱动类型
     * @var string
     */
    protected $name;

    /**
     * 配置文件名
     * @var string
     */
    protected $configFile;

    /**
     * api key
     * @var string
     */
    protected $apiKey;


	/**
	 * 飞鹅云SN
	 * @var string
	 */
	protected $feySn;

	/**
	 * 飞鹅云UYEK
	 * @var string
	 */
	protected $feyUkey;

	/**
	 * 打印数量
	 * @var int|mixed
	 */
	protected $times;

	/**
	 * 飞鹅云USER
	 * @var string
	 */
	protected $feyUser;

    public function __construct(array $config = [], string $name = '', string $configFile = '')
    {
        $this->clientId = $config['clientId'] ?? null;
        $this->apiKey = $config['apiKey'] ?? null;
        $this->partner = $config['partner'] ?? null;
        $this->machineCode = $config['terminal'] ?? null;
		$this->feyUser = $config['feyUser'] ?? null;
		$this->feyUkey = $config['feyUkey'] ?? null;
		$this->feySn = $config['feySn'] ?? null;
		$this->times = $config['print_num'] ?? 1;
        $this->name = $name;
        $this->configFile = $configFile;
        $this->apiUrl = Config::get($this->configFile . '.stores.' . $this->name . '.apiUrl', 'https://open-api.10ss.net/');
    }

    /**
     * 获取token
     * @return mixed|null|string
     * @throws \Exception
     */
    public function getAccessToken()
    {
        if (isset($this->accessToken[$this->name . '_' . $this->clientId])) {
            return $this->accessToken[$this->name . '_' . $this->clientId];
        }

        $action = 'get' . Str::studly($this->name) . 'AccessToken';
        if (method_exists($this, $action)) {
            return $this->{$action}();
        } else {
            throw new \RuntimeException(__CLASS__ . '->' . $action . '(),Method not worn in');
        }
    }

    /**
     * 获取易联云token
     * @return mixed|null|string
     * @throws \Exception
     */
    protected function getYiLianYunAccessToken()
    {
        /** @var CacheServices $cacheServices */
        $cacheServices = app()->make(CacheServices::class);
        $this->accessToken[$this->name . '_' . $this->clientId] = $cacheServices->getDbCache('YLY_access_token_'. $this->clientId, function () {
            $request = self::postRequest($this->apiUrl . 'oauth/oauth', [
                'client_id' => $this->clientId,
                'grant_type' => 'client_credentials',
                'sign' => strtolower(md5($this->clientId . time() . $this->apiKey)),
                'scope' => 'all',
                'timestamp' => time(),
                'id' => $this->createUuid(),
            ]);
            $request = json_decode($request, true);
            $request['error'] = $request['error'] ?? 0;
            $request['error_description'] = $request['error_description'] ?? '';
            if ($request['error'] == 0 && $request['error_description'] == 'success') {
                return $request['body']['access_token'] ?? '';
            }
            return '';
        },86400);
        if (!$this->accessToken[$this->name . '_' . $this->clientId])
            throw new \Exception('获取access_token获取失败');

        return $this->accessToken[$this->name . '_' . $this->clientId];
    }

    /**
     * 获取请求链接
     * @return string
     */
    public function getApiUrl(string $url = '')
    {
        return $url ? $this->apiUrl . $url : $this->apiUrl;
    }

    /**
     * 生成UUID4
     * @return string
     */
    public function createUuid()
    {
        mt_srand();
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    /**
     * 获取属性
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if (in_array($name, ['clientId', 'apiKey', 'accessToken', 'partner', 'terminal', 'machineCode', 'feyUser', 'feyUkey', 'feySn', 'times'])) {
            return $this->{$name};
        }
    }
}
