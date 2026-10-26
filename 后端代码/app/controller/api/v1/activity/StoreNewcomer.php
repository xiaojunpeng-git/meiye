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
namespace app\controller\api\v1\activity;


use app\Request;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\activity\newcomer\StoreNewcomerServices;
use app\services\other\CacheServices;
use app\services\user\UserServices;
use mohe\services\SystemConfigService;


/**
 * 新人商品类
 * Class StoreNewcomer
 * @package app\controller\api\activity
 */
class StoreNewcomer
{

    protected $services;

    public function __construct(StoreNewcomerServices $services)
    {
        $this->services = $services;
    }

	/**
 	* 新人大礼包弹窗
	* @param Request $request
	* @return mixed
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	 */
	public function getGift(Request $request)
	{
		$data = [];
		$uid = (int)$request->uid();
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$userInfo = $userServices->getUserInfo($uid);
		//新用户
		if ($userInfo && $userInfo['add_time'] == $userInfo['last_time'] && $this->services->checkUserNewcomer($uid, $userInfo)) {
			$data = $this->services->getNewCustomerGift($uid);
		}
		return app('json')->success($data);
	}

	/**
	* 新人礼信息
	* @param Request $request
	* @return mixed
	 */
	public function getInfo(Request $request)
	{
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$data = $this->services->getNewCustomerGift($uid);
		if ($data) {
			/** @var CacheServices $cache */
			$cache = app()->make(CacheServices::class);
			$data['newcomer_agreement'] = $cache->getDbCache('newcomer_agreement', '');
		}
		return app('json')->success($data);
	}

    /**
 	* 新人商品列表
	* @param Request $request
	* @return mixed
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	 */
    public function lst(Request $request)
    {
		$uid = (int)$request->uid();
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$userInfo = $userServices->getUserInfo($uid);
		$status = sys_config('newcomer_status');
		$data = [];
		//新用户
		if ($status && $userInfo && $this->services->checkUserNewcomer($uid, $userInfo)) {
			$data = $this->services->getCustomerProduct([], 'id,type,product_id,relation_id,product_type,price', ['product' => function ($query) {
				$query->field('id,image,store_name,stock,sales,ot_price');
			}]);
		}
        return app('json')->successful(get_thumb_water($data, 'mid'));
    }

    /**
     * 秒杀商品详情
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function detail(Request $request, $id)
    {
		if (!$id) return app('json')->fail('缺少参数');
		$uid = (int)$request->uid();
        $data = $this->services->newcomerDetail($uid, (int)$id);
        return app('json')->success($data);
    }
}
