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
namespace app\controller\api\store\user;

use app\Request;
use app\services\user\UserAddressServices;


/**
 * 用户地址类
 * Class UserAddress
 * @package app\api\controller\store
 */
class UserAddress
{

	protected $services;

	public function __construct(UserAddressServices $services)
	{
		$this->services = $services;
	}


    /**
 	* 地址列表
	* @param Request $request
	* @param $uid
	* @return \think\Response
	*/
    public function address_list(Request $request, $uid)
    {
        $uid = (int)$uid;
		$list = [];
		if ($uid) {
			$list = $this->services->getUserAddressList($uid);
		}
        return app('json')->successful($list);
    }

	/**
 	* 获取默认地址
	* @param Request $request
	* @param $uid
	* @return \think\Response
	* @throws \throwable
	*/
    public function address_default(Request $request, $uid)
    {
        $uid = (int)$uid;
		$defaultAddress = [];
        if ($uid) $defaultAddress = $this->services->getUserDefaultAddressCache($uid);
        if ($defaultAddress) {
            return app('json')->successful('ok', $defaultAddress);
        }
        return app('json')->successful('empty', []);
    }

}
