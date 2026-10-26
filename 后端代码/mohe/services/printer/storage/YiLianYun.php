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
namespace mohe\services\printer\storage;

use mohe\basic\BasePrinter;
use app\services\other\CacheServices;

/**
 * Class YiLianYun
 * @package mohe\services\printer\storage
 */
class YiLianYun extends BasePrinter
{

    /**
     * 初始化
     * @param array $config
     * @return mixed|void
     */
    protected function initialize(array $config)
    {

    }

    /**
     * 开始打印
     * @return bool|mixed|string
     * @throws \Exception
     */
    public function startPrinter()
    {
        if (!$this->printerContent) {
            return $this->setError('Missing print');
        }
        $time = time();
		$requestData = [
			'client_id' => $this->accessToken->clientId,
			'access_token' => $this->accessToken->getAccessToken(),
			'machine_code' => $this->accessToken->machineCode,
			'content' => $this->printerContent,
			'origin_id' => 'mohe' . $time,
			'sign' => strtolower(md5($this->accessToken->clientId . $time . $this->accessToken->apiKey)),
			'id' => $this->accessToken->createUuid(),
			'timestamp' => $time
		];
        try {
            $request = $this->accessToken->postRequest($this->accessToken->getApiUrl('print/index'), $requestData);
			$request = is_string($request) ? json_decode($request, true) : $request;
			if (isset($request['error']) && $request['error'] == '18') {//token过期
				$cacheServices = app()->make(CacheServices::class);
				$cacheServices->deleteDbCache('YLY_access_token_'. $this->accessToken->clientId);
				$requestData['access_token'] = $this->accessToken->getAccessToken();
				$request = $this->accessToken->postRequest($this->accessToken->getApiUrl('print/index'), $requestData);
			}
        } catch (\Exception $e) {
            return $this->setError($e->getMessage());
        }
        $this->printerContent = null;
        if ($request === false) {
            return $this->setError('request was aborted');
        }
        $request = is_string($request) ? json_decode($request, true) : $request;
        if (isset($request['error']) && $request['error'] != 0) {
            return $this->setError(isset($request['error_description']) ? $request['error_description'] : 'Accesstoken has expired');
        }
        return $request;
    }

    /**
     * 设置打印内容
     * @param $content
     * @return YiLianYun
     */
    public function setPrinterContent(string $content)
    {
        $this->printerContent = $content;
        return $this;
    }

}
