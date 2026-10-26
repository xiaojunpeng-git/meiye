<?php

namespace mohe\services\wechat\orderShipping;

use mohe\exceptions\AdminException;
use mohe\services\wechat\config\MiniProgramConfig;
use EasyWeChat\Kernel\BaseClient as EasyWeChatBaseClient;



/**
 * Class BaseClient
 * @package mohe\services\wechat\orderShipping
 */
class BaseClient extends EasyWeChatBaseClient
{

    public $config;
    public $accessToken;

    const BASE_API = 'https://api.weixin.qq.com/';

    const ORDER = 'wxa/sec/order/';
    const EXPRESS = 'cgi-bin/express/delivery/open_msg/';

    const PATH = '/pages/goods/order_details/index';


	/**
	* @param $result
	* @return mixed
	*/
    private function resultHandle($result)
    {
        if (empty($result)) {
            throw new AdminException('微信接口返回异常');
        }
        if ($result['errcode'] == 0) {
            return $result;
        } else {
            throw  new AdminException("微信接口异常：code = {$result['errcode']} msg = {$result['errmsg']}");
        }
    }

    /**
     * 发货
     * @param $params
     * @return array
     * @throws \EasyWeChat\Core\Exceptions\HttpException
     *
     *
     */
    public function shipping($params)
    {
        return $this->resultHandle($this->httpPostJson(self::ORDER . 'upload_shipping_info', $params));
    }

    /**
     * 合单
     * @param $params
     * @return array
     * @throws \EasyWeChat\Core\Exceptions\HttpException
     *
     *
     */
    public function combinedShipping($params)
    {
        return $this->resultHandle($this->httpPostJson(self::ORDER . 'upload_combined_shipping_info', $params));
    }


    /**
     * 签收消息提醒
     * @param $params
     * @return array
     * @throws \EasyWeChat\Core\Exceptions\HttpException
     *
     *
     */
    public function notifyConfirm($params)
    {
        return $this->resultHandle($this->httpPostJson(self::ORDER . 'notify_confirm_receive', $params));
    }


    /**
     * 查询小程序是否已开通发货信息管理服务
     * @return array
     * @throws \EasyWeChat\Core\Exceptions\HttpException
     *
     *
     */
    public function isManaged()
    {
		/** @var MiniProgramConfig $make */
       	$make = app()->make(MiniProgramConfig::class);
        $params = [
            'appid' => $make->get('appId')
        ];
        return $this->resultHandle($this->httpPostJson(self::ORDER . 'is_trade_managed', $params));
    }

    /**
     * 设置跳转连接
     * @param $path
     * @return array
     * @throws \EasyWeChat\Core\Exceptions\HttpException
     *
     *
     */
    public function setMesJumpPath($path)
    {
        $params = [
            'path' => $path
        ];
        return $this->resultHandle($this->httpPostJson(self::ORDER . 'set_msg_jump_path', $params));
    }

    /**
     * 获取运力id列表get_delivery_list
     * @return array
     * @throws \EasyWeChat\Core\Exceptions\HttpException
     *
     *
     */
    public function getDeliveryList()
    {
        return $this->resultHandle($this->httpPostJson(self::EXPRESS . 'get_delivery_list', []));
    }
}
