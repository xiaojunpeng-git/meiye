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
namespace app\controller\api\v1\diy;


use app\Request;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\activity\newcomer\StoreNewcomerServices;
use app\services\activity\video\VideoServices;
use app\services\diy\DiyServices;
use app\services\product\product\StoreProductRankServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\UserServices;
use app\services\user\UserSignServices;

/**
 * Class Diy
 * @package app\controller\api\v1\diy
 */
class Diy
{
    protected $services;

    public function __construct(DiyServices $services)
    {
        $this->services = $services;
    }

	/**
	 * 获取页面数据
	 * @param Request $request
	 * @param SystemStoreServices $storeServices
	 * @param $id
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getDiy(Request $request, SystemStoreServices $storeServices, $id = 0)
	{
		if (!$id) {//首页
			$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
			$entryRules = $storeServices->getStoreIdByEntryRules($uid, [], true);
			if (isset($entryRules['default']) && $entryRules['default']) {//默认进入制定页面
				$param = parse_url($entryRules['default'])['query'] ?? [];
				$param = explode('=', $param);
				if ($param) $id = (int)end($param);
				if ($id) $this->services->updateCacheDiyVersion();
			}
		}//专题页
		return app('json')->successful($this->services->getDiyInfo((int)$id));
	}

	/**
	 * 获取diy缓存
	 * @param $id
	 * @return \think\Response
	 * @throws \Throwable
	 */
	public function getDiyVersion(Request $request, SystemStoreServices $storeServices, $id = 0)
	{
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$entryRules = $storeServices->getStoreIdByEntryRules($uid, [], true);
		$storeId = $entryRules['store_id'] ?? 0;
		if (!$storeId && $entryRules['default']) {//无推荐门店 && 有默认专题页
			$param = parse_url($entryRules['default'])['query'] ?? [];
			$param = explode('=', $param);
			if ($param) $id = (int)end($param);
			if ($id) $this->services->updateCacheDiyVersion();
		}
		return app('json')->successful(['version' => $this->services->getDiyVersion((int)$id)]);
	}

	/**
 	* 获取diy用户数据
	* @param Request $request
	* @param UserServices $userServices
	* @return mixed
	 */
	public function userInfo(Request $request, UserServices $userServices)
	{
		$uid = (int)$request->uid();
		$userInfo = [];
		if ($uid) {
			$userInfo = $userServices->getUserInfo($uid, 'uid,nickname,phone,avatar,level,integral,now_money,exp,is_money_level,bar_code');
			if ($userInfo) {
				$userInfo = $userInfo->toArray();
				/** @var StoreCouponUserServices $storeCoupon */
        		$storeCoupon = app()->make(StoreCouponUserServices::class);
				$userInfo['coupon_num'] = $storeCoupon->getUserValidCouponCount((int)$uid);
				$userInfo['next_exp'] = 0;
				$userInfo['vip_name'] = '';
				if ($userInfo['level']) {
					/** @var SystemUserLevelServices $systemUserLevel */
					$systemUserLevel = app()->make(SystemUserLevelServices::class);
					$levelList = $systemUserLevel->getList(['is_del' => 0, 'is_show' => 1], 'id,name,exp_num');
					$i = 0;
					foreach ($levelList as &$level) {
						if ($level['id'] == $userInfo['level']) {
							$userInfo['vip_name'] = $level['name'];
						}
						$level['next_exp_num'] = $levelList[$i + 1]['exp_num'] ?? $level['exp_num'];
						$i++;
					}
					$levelList = array_combine(array_column($levelList,'id'), $levelList);
					$userInfo['next_exp'] = $levelList[$userInfo['level']]['next_exp_num'] ?? 0;
				} else {
					/** @var SystemUserLevelServices $systemUserLevel */
					$systemUserLevel = app()->make(SystemUserLevelServices::class);
					$levelList = $systemUserLevel->getList(['is_del' => 0, 'is_show' => 1], 'id,name,exp_num');
					$userInfo['next_exp'] = $levelList[0]['exp_num'] ?? 0;
				}
			}
		}
		return app('json')->success($userInfo);
	}

	/**
 	* 获取diy短视频
	* @param Request $request
	* @param VideoServices $videoServices
	* @return mixed
	 */
	public function videoList(Request $request, VideoServices $videoServices)
	{
		$uid = (int)$request->uid();
		return app('json')->success($videoServices->getDiyVideoList($uid));
	}

	/**
 	* 获取新人礼商品
	* @param Request $request
	* @param StoreNewcomerServices $newcomerServices
	* @return mixed
	 */
	public function newcomerList(Request $request, StoreNewcomerServices $newcomerServices)
	{
		$where = $request->getMore([
            ['priceOrder', ''],
            ['salesOrder', ''],
        ]);
		$uid = (int)$request->uid();
		return app('json')->success($newcomerServices->getDiyNewcomerList($uid, $where));
	}

	/**
	 * 首页diy签到数据
	 * @param Request $request
	 * @return \think\Response
	 */
	public function diySign(Request $request, UserSignServices $services)
	{
		$uid = (int)$request->uid();
		return app('json')->successful($services->homeDiysignData($uid));
	}

	/**
	 * 商品排行榜
	 * @param Request $request
	 * @param StoreProductRankServices $productRankServices
	 * @return \think\Response
	 * @throws \ReflectionException
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \throwable
	 */
	public function productRank(Request $request, StoreProductRankServices $productRankServices, StoreProductServices $storeProductServices)
	{
		[$limit] = $request->getMore([
			['limit', 3]
		], true);
		$uid = 0;
		if ($request->hasMacro('uid')) $uid = (int)$request->uid();
		$data = [];
		$where = $storeProductServices->getWhereByEntryRules($uid);
		$data['sales'] = $productRankServices->getProductRankList($uid, 1, $where, $limit);
		$data['star'] = $productRankServices->getProductRankList($uid, 2, $where, $limit);
		$data['collect'] = $productRankServices->getProductRankList($uid, 3, $where, $limit);
		return app('json')->success($data);
	}

    /**
     * 获取悬浮窗数据
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSuspendedDiy()
    {
        $data = $this->services->getSuspendedDiy();
		$siteUrl = sys_config('site_url');
		if (isset($data['main_after_image']) && $data['main_after_image'] && strpos($data['main_after_image'], 'http') === false) {
			$data['main_after_image'] = $siteUrl . $data['main_after_image'];
		}
		if (isset($data['main_ago_image']) && $data['main_ago_image'] && strpos($data['main_ago_image'], 'http') === false) {
			$data['main_ago_image'] = $siteUrl . $data['main_ago_image'];
		}
		if (isset($data['button']) && $data['button']) {
			foreach ($data['button'] as &$item) {
				if (strpos($item['img'], 'http') === false) {
					$item['img'] =  $siteUrl . $item['img'];
				}
			}
		}
        return app('json')->success($data);
    }
}
