<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace mohe\services\express\storage;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use AlibabaCloud\Tea\Exception\TeaError;
use AlibabaCloud\Tea\Utils\Utils\RuntimeOptions;
use mohe\basic\BaseExpress;
use mohe\basic\BaseSmss;
use mohe\exceptions\ApiException;
use mohe\services\HttpService;
use Darabonba\OpenApi\Models\Config as AliConfig;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Log;


/**
 * Class Aliyun
 * @package mohe\services\sms\storage
 */
class Aliyun extends BaseExpress
{

    protected string $appCode = '';

	protected string $api = 'https://wuliu.market.alicloudapi.com/kdi';

    /**
     * @param array $config
     * @return mixed|void
     */
    protected function initialize(array $config = [])
    {
        parent::initialize($config);
        $this->appCode = $config['app_code'] ?? '';
    }

	/**
	 * 查询快递
	 * @param string $num
	 * @param string $com
	 * @param string $type
	 * @return false|mixed
	 */
	public function query(string $num, string $com = '', string $type = '')
	{
		if (!$this->appCode) return false;
		$res = HttpService::getRequest($this->api, ['no' => $num, 'type' => $type], ['Authorization:APPCODE ' . $this->appCode]);
		return json_decode($res, true) ?: false;
	}

	/**
	 * 开通服务
	 * @return mixed
	 */
	public function open(){}


	/**
	 * 电子面单
	 * @return mixed
	 */
	public function dump($data) {}


	/**
	 * 面单模板
	 * @return mixed
	 */
	public function temp(string $com) {}

}
