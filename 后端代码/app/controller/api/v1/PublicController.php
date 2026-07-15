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
namespace app\controller\api\v1;


use app\services\activity\combination\StorePinkServices;
use app\services\agent\SystemRegionAgentServices;
use app\services\diy\DiyServices;
use app\services\message\service\StoreServiceServices;
use app\services\other\AgreementServices;
use app\services\other\CityAreaServices;
use app\services\product\product\StoreProductWordsServices;
use app\services\store\DeliveryServiceServices;
use app\services\other\CacheServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductServices;
use app\services\other\ExpressServices;
use app\services\order\StoreOrderServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\system\config\SystemConfigServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\config\SystemStorageServices;
use app\services\user\UserBillServices;
use app\services\user\UserInvoiceServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use app\Request;
use mohe\services\CacheService;
use mohe\services\UploadService;
use mohe\basic\BaseController;

/**
 * 公共类
 * Class PublicController
 * @package app\controller\api
 */
class PublicController extends BaseController
{
    /**
     * 主页获取
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $banner = sys_data('routine_home_banner') ?: [];// 首页banner图
        $menus = sys_data('routine_home_menus') ?: [];// 首页按钮
        $roll = sys_data('routine_home_roll_news') ?: [];// 首页滚动新闻
        $activity = sys_data('routine_home_activity', 3) ?: [];// 首页活动区域图片
        $explosive_money = sys_data('index_categy_images') ?: [];// 首页超值爆款
        $site_name = sys_config('site_name');
        $routine_index_page = sys_data('routine_index_page');
        $info['fastInfo'] = $routine_index_page[0]['fast_info'] ?? '';// 快速选择简介
        $info['bastInfo'] = $routine_index_page[0]['bast_info'] ?? '';// 精品推荐简介
        $info['firstInfo'] = $routine_index_page[0]['first_info'] ?? '';// 首发新品简介
        $info['salesInfo'] = $routine_index_page[0]['sales_info'] ?? '';// 促销单品简介
        $logoUrl = sys_config('routine_index_logo');// 促销单品简介
        if (strstr($logoUrl, 'http') === false && $logoUrl) {
            $logoUrl = sys_config('site_url') . $logoUrl;
        }
        $logoUrl = str_replace('\\', '/', $logoUrl);
        $fastNumber = (int)sys_config('fast_number', 0);// 快速选择分类个数
        $bastNumber = (int)sys_config('bast_number', 0);// 精品推荐个数
        $firstNumber = (int)sys_config('first_number', 0);// 首发新品个数
        $promotionNumber = (int)sys_config('promotion_number', 0);// 首发新品个数

        /** @var StoreProductCategoryServices $categoryService */
        $categoryService = app()->make(StoreProductCategoryServices::class);
        $info['fastList'] = $fastNumber ? $categoryService->byIndexList($fastNumber, 'id,cate_name,pid,pic') : [];// 快速选择分类个数
        /** @var StoreProductServices $storeProductServices */
        $storeProductServices = app()->make(StoreProductServices::class);
        $info['bastList'] = $bastNumber ? $storeProductServices->getRecommendProduct($uid, ['is_best' => 1], $bastNumber) : [];// 精品推荐个数
        $info['firstList'] = $firstNumber ? $storeProductServices->getRecommendProduct($uid, ['is_new' => 1], $firstNumber) : [];// 首发新品个数
        $info['bastBanner'] = sys_data('routine_home_bast_banner') ?? [];// 首页精品推荐图片
        $benefit = $promotionNumber ? $storeProductServices->getRecommendProduct($uid, ['is_benefit' => 1], $promotionNumber) : [];// 首页促销单品
        $lovely = sys_data('routine_home_new_banner') ?: [];// 首发新品顶部图
        $likeInfo = $storeProductServices->getRecommendProduct($uid, ['is_hot' => 1], 3);// 热门榜单 猜你喜欢
        if ($uid) {
            /** @var WechatUserServices $wechatUserService */
            $wechatUserService = app()->make(WechatUserServices::class);
            $subscribe = (bool)$wechatUserService->value(['uid' => $uid], 'subscribe');
        } else {
            $subscribe = true;
        }
        $newGoodsBananr = sys_config('new_goods_bananr');
        $tengxun_map_key = sys_config('tengxun_map_key');
        return app('json')->successful(compact('banner', 'menus', 'roll', 'info', 'activity', 'lovely', 'benefit', 'likeInfo', 'logoUrl', 'site_name', 'subscribe', 'newGoodsBananr', 'tengxun_map_key', 'explosive_money'));
    }


    /**
     * 获取分享配置
     * @return mixed
     */
    public function share()
    {
        $data['img'] = sys_config('wechat_share_img');
        if (strstr($data['img'], 'http') === false) {
            $data['img'] = sys_config('site_url') . $data['img'];
        }
        $data['img'] = str_replace('\\', '/', $data['img']);
        $data['title'] = sys_config('wechat_share_title');
        $data['synopsis'] = sys_config('wechat_share_synopsis');
        return app('json')->successful($data);
    }

    /**
     * 获取网站配置
     * @return mixed
     */
    public function getSiteConfig()
    {
        $data['record_No'] = sys_config('record_No');
        return app('json')->success($data);
    }

    /**
     * 获取个人中心菜单
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function menu_user(Request $request)
    {
        $menusInfo = sys_data('routine_my_menus') ?? [];
        $uid = 0;
        $userInfo = [];
        if ($request->hasMacro('user')) $userInfo = $request->user();
        if ($request->hasMacro('uid')) $uid = (int)$request->uid();

        $vipOpen = sys_config('member_func_status');
        $brokerageFuncStatus = sys_config('brokerage_func_status');
		$storeBrokerageApply = sys_config('store_brokerage_apply');
        $balanceFuncStatus = sys_config('balance_func_status');
        $vipCard = sys_config('member_card_status', 0);
        $svipOpen = (bool)sys_config('member_card_status');
        $userService = $invoiceStatus = $deliveryUser = $isUserPromoter = $userVerifyStatus = $userOrder = $isStaff = $isDelivery = $levelStatus = $isAgent = true;
		$isStoreManager = false;
		$isStoreButlerOrManager = false;
		$canTargetManage = false;

        if ($uid && $userInfo) {
            /** @var StoreServiceServices $storeService */
            $storeService = app()->make(StoreServiceServices::class);
            $userService = $storeService->checkoutIsService(['uid' => $uid, 'status' => 1, 'account_status' => 1]);
            $userOrder = $storeService->checkoutIsService(['uid' => $uid, 'account_status' => 1, 'customer' => 1]);
            /** @var SystemStoreStaffServices $systemStoreStaff */
            $systemStoreStaff = app()->make(SystemStoreStaffServices::class);
            /** @var UserServices $user */
            $user = app()->make(UserServices::class);
            /** @var UserInvoiceServices $userInvoice */
            $userInvoice = app()->make(UserInvoiceServices::class);
            $invoiceStatus = $userInvoice->invoiceFuncStatus(false);
            /** @var DeliveryServiceServices $deliveryService */
            $deliveryService = app()->make(DeliveryServiceServices::class);
            $isUserPromoter = $user->checkUserPromoter($uid, $userInfo);
            try {
                $isStaff = $systemStoreStaff->getStaffInfoByUid($uid);
                $isStaff=$isStaff->toArray();
            } catch (\Throwable $e) {
                $isStaff = false;
            }
            try {
                $isDelivery = $deliveryService->getDeliveryInfoByUid($uid);
            } catch (\Throwable $e) {
                $isDelivery = false;
            }
			$levelStatus = (bool)($userInfo['level_status'] ?? 1);
			/** @var SystemRegionAgentServices $regionAgentServices */
			$regionAgentServices = app()->make(SystemRegionAgentServices::class);
			try {
				$isAgent = $regionAgentServices->userIsRegionAgent($uid);
			} catch (\Throwable $e) {
				$isAgent = false;
			}
			$isStoreManager = is_array($isStaff) && !empty($isStaff)
				&& (int)($isStaff['status'] ?? 0) === 1
				&& (int)($isStaff['is_manager'] ?? 0) === 1;
			$isStoreButlerOrManager = is_array($isStaff) && !empty($isStaff)
				&& (int)($isStaff['status'] ?? 0) === 1
				&& ((int)($isStaff['is_butler'] ?? 0) === 1 || (int)($isStaff['is_manager'] ?? 0) === 1);
			$canTargetManage = $isAgent || $isStoreManager;

        }
        $auth = [];
        $auth['/pages/users/user_vip/index'] = !$vipOpen;
        $auth['/pages/users/user_spread_user/index'] = !$brokerageFuncStatus || !$isUserPromoter || $uid == 0;
		$auth['/pages/users/distributor/apply'] = !$brokerageFuncStatus || $isUserPromoter || $uid == 0 || !$storeBrokerageApply;
        $auth['/pages/users/user_money/index'] = !$balanceFuncStatus;
		$auth['/pages/admin/order/index'] = true;
        $auth['/pages/admin/order_cancellation/index'] = true;
        $auth['/pages/users/user_invoice_list/index'] = !$invoiceStatus;
        $auth['/pages/annex/vip_paid/index'] = !$vipCard || !$svipOpen;
        $auth['/kefu/mobile_list'] = !$userService || $uid == 0;
        $auth['/pages/admin/store/index'] = true;
		// 统一商家端后：买家端不再展示旧工作台/旧用户管理入口（改由悬浮「商家入口」进入）
		$auth['/pages/admin/work/store'] = true;
//        $auth['/pages/admin/distribution/index'] = $uid == 0 || !$isDelivery;
        $auth['/pages/store_spread/index'] =  $uid == 0 || !$isStaff;
		$auth['/pages/admin/work/index'] = true;
		$auth['/pages/admin/user/list'] = true;
		$auth['/pages/admin/agent/index'] =  $uid == 0 || !$isAgent;
		$auth['/pages/admin/target/management/index'] = $uid == 0 || !$canTargetManage;
        foreach ($menusInfo as $key => &$value) {
            $value['pic'] = set_file_url($value['pic'] ?? '');
            $value['url'] = $value['url'] ?? '';
            if (isset($auth[$value['url']]) && $auth[$value['url']]) {
                unset($menusInfo[$key]);
                continue;
            }
			//配送员
			if ($value['url'] == '/pages/admin/distribution/index' && ($uid == 0 || !$isDelivery)) {
				unset($menusInfo[$key]);
				continue;
			}
            if ($value['url'] == '/kefu/mobile_list') {
                $value['url'] = sys_config('site_url') . $value['url'];
                if ($request->isRoutine()) {
                    $value['url'] = str_replace('http://', 'https://', $value['url']);
                }
            }
			//用户等级 未激活地址改为激活页面
			if ($value['url'] == '/pages/users/user_vip/index' && !$levelStatus) {
				$value['url'] = '/pages/annex/vip_grade_active/index';
			}
        }
        /** @var SystemConfigServices $systemConfigServices */
        $systemConfigServices = app()->make(SystemConfigServices::class);
        $bannerInfo = $systemConfigServices->getSpreadBanner() ?? [];
        $my_banner = sys_data('routine_my_banner');
        $routine_contact_type = sys_config('routine_contact_type', 0);
        /** @var DiyServices $diyServices */
        $diyServices = app()->make(DiyServices::class);
		$diy_data = $diyServices->getCacheDiyData('member');
		if (isset($diy_data['agentMenu']['list']) && $diy_data['agentMenu']['list']) {
			foreach ($diy_data['agentMenu']['list'] as $key => &$item) {
				if ((isset($auth[$item['url']]) && $auth[$item['url']])) {
					unset($diy_data['agentMenu']['list'][$key]);
					continue;
				}
			}
			unset($item);
			$diy_data['agentMenu']['list'] = array_merge($diy_data['agentMenu']['list']);
		}
		$targetMenuUrl = '/pages/admin/target/management/index';
		$agentStatisticsUrl = '/pages/admin/agent/index';
		$targetMenuPic = '';
		if (isset($diy_data['agentMenu']['list']) && $diy_data['agentMenu']['list']) {
			foreach ($diy_data['agentMenu']['list'] as $agentMenuItem) {
				if (($agentMenuItem['url'] ?? '') === $agentStatisticsUrl && !empty($agentMenuItem['pic'])) {
					$targetMenuPic = set_file_url($agentMenuItem['pic']);
					break;
				}
			}
		}
		if (!$targetMenuPic) {
			$targetMenuPic = 'https://qiniu007.cc3798.com/attach/2026/01/7b500202601302232438752.png';
		}
		if ($canTargetManage && $isAgent) {
			if (!isset($diy_data['agentMenu']['list'])) {
				$diy_data['agentMenu']['list'] = [];
			}
			$hasTargetMenu = false;
			foreach ($diy_data['agentMenu']['list'] as $key => &$agentMenuItem) {
				if (($agentMenuItem['url'] ?? '') === $targetMenuUrl) {
					$agentMenuItem['pic'] = $targetMenuPic;
					unset($agentMenuItem['icon']);
					$hasTargetMenu = true;
				}
			}
			unset($agentMenuItem);
			if (!$hasTargetMenu) {
				$diy_data['agentMenu']['list'][] = [
					'name' => '目标管理',
					'pic' => $targetMenuPic,
					'url' => $targetMenuUrl,
				];
			}
		}
		if (isset($diy_data['storeMenu']['list']) && $diy_data['storeMenu']['list']) {
			try {
				$isStoreDelivery = $deliveryService->getDeliveryInfoByUid($uid, 1);
			} catch (\Throwable $e) {
				$isStoreDelivery = false;
			}
			foreach ($diy_data['storeMenu']['list'] as $key => &$item) {
				//配送员
				if ((isset($auth[$item['url']]) && $auth[$item['url']]) || ($item['url'] == '/pages/admin/distribution/index' && ($uid == 0 || !$isStoreDelivery))) {
					unset($diy_data['storeMenu']['list'][$key]);
					continue;
				}
				if ($item['url'] == '/kefu/mobile_list') {
					$item['url'] = sys_config('site_url') . $item['url'];
					if ($request->isRoutine()) {
						$item['url'] = str_replace('http://', 'https://', $item['url']);
					}
				}
			}
			unset($item);
			$diy_data['storeMenu']['list'] = array_merge($diy_data['storeMenu']['list']);
            if(is_array($isStaff) && !empty($isStaff) && $isStaff['status'] == 1){
                $menusOne=[
                    'name'=>'个人业绩',
                    'pic'=>'https://qiniu007.cc3798.com/attach/2026/01/7b500202601302232438752.png',
                    'url'=>'/pages/admin/yeji/staff',
                ];
                if($isStaff['is_manager'] == 1) {
                    $menusOne = [
                        'name' => '门店业绩',
                        'pic' => 'https://qiniu007.cc3798.com/attach/2026/01/7b500202601302232438752.png',
                        'url' => '/pages/admin/yeji/store',
                    ];
                }
                $diy_data['storeMenu']['list'][]=$menusOne;
                // 旧「用户管理→/pages/admin/user/list」已停用：买家端 storeMenu 不再注入；客户能力走统一商家端
                $menusOne=[
                    'name'=>'拼团管理',
                    'pic'=>'https://qiniu007.cc3798.com/attach/2026/02/38273202602122337378410.png',
                    'url'=>'/pages/activity/goods_combination/index',
                ];
                $diy_data['storeMenu']['list'][]=$menusOne;
                if ($canTargetManage && $isStoreManager && !$isAgent) {
                    $hasTargetMenu = false;
                    foreach ($diy_data['storeMenu']['list'] as &$storeMenuItem) {
                        if (($storeMenuItem['url'] ?? '') === $targetMenuUrl) {
                            $storeMenuItem['pic'] = $targetMenuPic;
                            unset($storeMenuItem['icon']);
                            $hasTargetMenu = true;
                        }
                    }
                    unset($storeMenuItem);
                    if (!$hasTargetMenu) {
                        $diy_data['storeMenu']['list'][] = [
                            'name' => '目标管理',
                            'pic' => $targetMenuPic,
                            'url' => $targetMenuUrl,
                        ];
                    }
                }
            }
		}
		// 预约管理仅管家可从门店工作台进入，个人中心不展示该入口；旧用户管理路由一并剔除
		if (isset($diy_data['storeMenu']['list']) && is_array($diy_data['storeMenu']['list'])) {
			foreach ($diy_data['storeMenu']['list'] as $key => $storeMenuItem) {
				$url = (string)($storeMenuItem['url'] ?? '');
				if ($url === '/pages/admin/reservation_list/index' || $url === '/pages/admin/user/list' || $url === '/pages/admin/user/index') {
					unset($diy_data['storeMenu']['list'][$key]);
				}
			}
			$diy_data['storeMenu']['list'] = array_values($diy_data['storeMenu']['list']);
		}
		if (isset($diy_data['merMenu']['list']) && $diy_data['merMenu']['list']) {
			try {
				$isPlatDelivery = $deliveryService->getDeliveryInfoByUid($uid, 0, 0);
			} catch (\Throwable $e) {
				$isPlatDelivery = false;
			}
			foreach ($diy_data['merMenu']['list'] as $key => &$item) {
				//配送员
				if ((isset($auth[$item['url']]) && $auth[$item['url']]) || ($item['url'] == '/pages/admin/distribution/index' && ($uid == 0 || !$isPlatDelivery))) {
					unset($diy_data['merMenu']['list'][$key]);
					continue;
				}
				if ($item['url'] == '/kefu/mobile_list') {
					$item['url'] = sys_config('site_url') . $item['url'];
					if ($request->isRoutine()) {
						$item['url'] = str_replace('http://', 'https://', $item['url']);
					}
				}
			}
			unset($item);
			$diy_data['merMenu']['list'] = array_merge($diy_data['merMenu']['list']);
		}
		if (isset($diy_data['menu']['list']) && $diy_data['menu']['list']) {
			foreach ($diy_data['menu']['list'] as $key => &$item) {
				if (isset($auth[$item['url']]) && $auth[$item['url']]) {
					unset($diy_data['menu']['list'][$key]);
					continue;
				}
				//用户等级 未激活地址改为激活页面
				if ($item['url'] == '/pages/users/user_vip/index' && !$levelStatus) {
					$item['url'] = '/pages/annex/vip_grade_active/index';
				}
			}
			$diy_data['menu']['list'] = array_merge($diy_data['menu']['list']);
		}
		// 商家入口已收口至统一商家端，买家「我的」不再输出三组旧商家菜单
		if (!isset($diy_data['merMenu']) || !is_array($diy_data['merMenu'])) {
			$diy_data['merMenu'] = [];
		}
		if (!isset($diy_data['storeMenu']) || !is_array($diy_data['storeMenu'])) {
			$diy_data['storeMenu'] = [];
		}
		if (!isset($diy_data['agentMenu']) || !is_array($diy_data['agentMenu'])) {
			$diy_data['agentMenu'] = [];
		}
		$diy_data['merMenu']['list'] = [];
		$diy_data['storeMenu']['list'] = [];
		$diy_data['agentMenu']['list'] = [];
		return app('json')->successful(['routine_my_menus' => array_merge($menusInfo), 'routine_my_banner' => $my_banner, 'routine_spread_banner' => $bannerInfo, 'routine_contact_type' => $routine_contact_type, 'diy_data' => $diy_data]);
	}

	/**
	 * 个人中心数据
	 * @param Request $request
	 * @param UserServices $services
	 * @param StoreOrderServices $orderServices
	 * @return \think\Response
	 */
	public function menu_user_data(Request $request, UserServices $services, StoreOrderServices $orderServices)
	{
		$uid = 0;
		$userInfo = [];
		$commission = [];
		$order = [];
		$not_pay_order = [];
		if ($request->hasMacro('user')) $userInfo = $request->user();
		if ($request->hasMacro('uid')) $uid = (int)$request->uid();
		if (!$uid && !$userInfo)  return app('json')->successful(['commission' => $commission, 'order' => $order, 'not_pay_order' => $not_pay_order]);
		if ($userInfo['is_promoter']) {
			$commission['brokerage_price'] = (int)$userInfo['brokerage_price'];
			$uids = $services->getColumn(['spread_uid' => $uid], 'uid');
			$commission['number'] = (int)count($uids);
			$commission['order_num'] = (int)$orderServices->getCount([['uid', 'in', $uids], ['pid', '=', 0],['paid', '=', 1], ['is_del', '=', 0]]);
		}
		/** @var StoreServiceServices $storeService */
		$storeService = app()->make(StoreServiceServices::class);
		$userOrder = $storeService->checkoutIsService(['uid' => $uid, 'account_status' => 1, 'status' => 1, 'customer' => 1]);
		$order['user_order'] = $userOrder;
		if($userOrder) {
			$where = ['pid' => [0, -1], 'paid' => 1, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];
			$order['price'] = (float)$orderServices->together($where, 'pay_price','sum');
			$order['num'] = (int)$orderServices->count($where);
			$default_where = [
				'pid' => [0, -1],
				'status' => 1,
				'is_system_del' => 0
			];
			$order['consignment'] = (int)$orderServices->count($default_where);
		}
		$not_pay_order = $orderServices->getUserNotPayOrder($uid);

		return app('json')->successful(['commission' => $commission, 'order' => $order, 'not_pay_order' => $not_pay_order]);
	}

	/**
	 * 搜索热词：用户搜索记录
	 * @param StoreProductWordsServices $wordsServices
	 * @return \think\Response
	 */
	public function hotKeywords(StoreProductWordsServices $wordsServices)
	{
		[$page, $limit] = $wordsServices->getPageValue();
		return app('json')->success($wordsServices->getList(['is_show' => 1, 'is_del' => 0], 'id,name,color,bg_color,border_color,icon', $page, $limit));
	}

	/**
	 * 搜索关键字关联
	 * @param Request $request
	 * @param StoreProductServices $services
	 * @return \think\Response
	 */
	public function searchWords(Request $request, StoreProductServices $services)
	{
		[$keyword] = $request->getMore([
			[['keyword', 's'], ''],
		], true);
		$list = [];
		if (!empty($keyword) && $keyword !== '%') {
			$where = ['is_vip_product' => 0, 'is_verify' => 1, 'pid' => 0, 'is_show' => 1, 'is_del' => 0, 'store_name' => $keyword, 'field_key' => 'store_name'];
			$where = array_merge($where, $services->getWhereByEntryRules());
			$list = $services->getSearchList($where, 0, 10, ['id', 'store_name'], 'sort desc,id desc', []);
		}
		return app('json')->success(compact('keyword', 'list'));
	}


    /**
     * 图片上传
     * @param Request $request
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function upload_image(Request $request, SystemAttachmentServices $services)
    {
        $data = $request->postMore([
            ['filename', 'file'],
        ]);
        if (!$data['filename']) return app('json')->fail('参数有误');
        if (CacheService::has('start_uploads_' . $request->uid()) && CacheService::get('start_uploads_' . $request->uid()) >= 100) return app('json')->fail('非法操作');
        $upload = UploadService::init();
        $info = $upload->to('store/comment')->validate()->move($data['filename']);
        if ($info === false) {
            return app('json')->fail($upload->getError());
        }
        $res = $upload->getUploadInfo();
        $services->attachmentAdd($res['name'], $res['size'], $res['type'], $res['dir'], $res['thumb_path'], 1, (int)sys_config('upload_type', 1), $res['time'], 3);
        if (CacheService::has('start_uploads_' . $request->uid()))
            $start_uploads = (int)CacheService::get('start_uploads_' . $request->uid());
        else
            $start_uploads = 0;
        $start_uploads++;
        CacheService::set('start_uploads_' . $request->uid(), $start_uploads, 86400);
        $res['dir'] = path_to_url($res['dir']);
        if (strpos($res['dir'], 'http') === false) $res['dir'] = sys_config('site_url') . $res['dir'];
        return app('json')->successful('图片上传成功!', ['name' => $res['name'], 'url' => $res['dir']]);
    }

    /**
     * 上传视频
     * @param Request $request
     * @param SystemAttachmentServices $services
     * @return \think\Response
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function uploadVideo(Request $request, SystemAttachmentServices $services)
    {
        $data = $request->postMore([
            ['filename', 'file'],
        ]);
        if (!$data['filename']) return app('json')->fail('参数有误');
        $uid = (int)$request->uid();
        if (CacheService::has('start_uploads_' . $uid) && CacheService::get('start_uploads_' . $uid) >= 100) return app('json')->fail('非法操作');
        $upload = UploadService::init();
        $info = $upload->to('store/video')->validate()->move($data['filename']);
        if ($info === false) {
            return app('json')->fail($upload->getError());
        }
        $res = $upload->getUploadInfo();
        $services->attachmentAdd($res['name'], $res['size'], $res['type'], $res['dir'], $res['thumb_path'], 1, (int)sys_config('upload_type', 1), $res['time'], 3, 2);
        if (CacheService::has('start_uploads_' . $uid))
            $start_uploads = (int)CacheService::get('start_uploads_' . $uid);
        else
            $start_uploads = 0;
        $start_uploads++;
        CacheService::set('start_uploads_' . $uid, $start_uploads, 86400);
        $res['dir'] = path_to_url($res['dir']);
        if (strpos($res['dir'], 'http') === false) $res['dir'] = sys_config('site_url') . $res['dir'];
        return app('json')->successful('视频上传成功!', ['name' => $res['name'], 'url' => $res['dir']]);
    }

    /**
     * 物流公司
     * @return mixed
     */
    public function logistics(Request $request, ExpressServices $services)
    {
        [$status] = $request->getMore([
            ['status', ''],
        ], true);
        if ($status == 1) $data['status'] = $status;
        $data['is_show'] = 1;
        $expressList = $services->expressList($data);
        return app('json')->successful($expressList ?? []);
    }

    /**
     * 反向解析地址
     * @param Request $request
     * @return mixed
     */
    public function geoLbscoder(Request $request)
    {
        [$data] = $request->getMore([
            ['location', '']
        ], true);
		if (!$data) {
			return app('json')->fail('请先检查定位权限或地图配置：获取到经纬度数据');
		}
        $data = explode(',', $data);
		$lat = $data[0] ?? '';
		$lng = $data[1] ?? '';
		if (count($data) != 2 || !$lat || !$lng || !checkCoordinates($lng, $lat)) {
			return app('json')->fail('经纬度参数错误');
		}
		$key = sys_config('tengxun_map_key', '');
		if (!$key) {
			return app('json')->fail('请先在：设置->系统设置->第三方接口：地图配置，配置地图KEY');
		}
		$res = geoLbscoder($lat, $lng);
		if ($res) {
			return app('json')->success($res);
		} else {
			return app('json')->fail('经纬度解析失败');
		}
    }

    /**
     * 短信购买异步通知
     *
     * @param Request $request
     * @return mixed
     */
    public function sms_pay_notify(Request $request)
    {
        [$order_id, $price, $status, $num, $pay_time, $attach] = $request->postMore([
            ['order_id', ''],
            ['price', 0.00],
            ['status', 400],
            ['num', 0],
            ['pay_time', time()],
            ['attach', 0],
        ], true);
        if ($status == 200) {
            return app('json')->successful();
        }
        return app('json')->fail();
    }

    /**
     * 记录用户分享
     * @param Request $request
     * @param UserBillServices $services
     * @return mixed
     */
    public function user_share(Request $request, UserBillServices $services)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($services->setUserShare($uid));
    }

    /**
     * 获取图片base64
     * @param Request $request
     * @return mixed
     */
    public function get_image_base64(Request $request)
    {
        [$imageUrl, $codeUrl] = $request->postMore([
            ['image', ''],
            ['code', ''],
        ], true);
		if ($imageUrl !== '' && !(preg_match('/.*(\.png|\.jpg|\.jpeg|\.gif)$/', $imageUrl) || strpos($imageUrl, 'https://mp.weixin.qq.com/cgi-bin/showqrcode') !== false || strpos($imageUrl, 'https://thirdwx.qlogo.cn/mmopen') !== false)) {
			return app('json')->success(['code' => false, 'image' => false]);
		}
		if ($codeUrl !== '' && !(preg_match('/.*(\.png|\.jpg|\.jpeg|\.gif)$/', $codeUrl) || strpos($codeUrl, 'https://mp.weixin.qq.com/cgi-bin/showqrcode') !== false || strpos($codeUrl, 'https://thirdwx.qlogo.cn/mmopen') !== false)) {
			return app('json')->success(['code' => false, 'image' => false]);
		}
		$imageUrlHost = $imageUrl ? (parse_url($imageUrl)['host'] ?? $imageUrl) : $imageUrl;
		$codeUrlHost = $codeUrl ? (parse_url($codeUrl)['host'] ?? $codeUrl) : $codeUrl;

		/** @var SystemStorageServices $systemStorageServices */
		$systemStorageServices = app()->make(SystemStorageServices::class);
		$domainArr = $systemStorageServices->getColumn([], 'domain');
		$domainArr = array_merge($domainArr, [$request->host(), 'mp.weixin.qq.com', 'thirdwx.qlogo.cn']);
		$domainArr = array_unique(array_diff($domainArr, ['']));
		if (count($domainArr)) {
			$domainArr = array_map(function ($item) {
				return str_replace(['https://', 'http://'], '', $item);
			}, $domainArr);
		}
		if ($domainArr && (($imageUrlHost && !in_array($imageUrlHost, $domainArr)) || ($codeUrlHost && !in_array($codeUrlHost, $domainArr)))) {
			return app('json')->success(['code' => false, 'image' => false]);
		}
        try {
            $code = CacheService::get($codeUrl, function () use ($codeUrl) {
                $codeTmp = $code = $codeUrl ? image_to_base64($codeUrl) : false;
                if (!$codeTmp) {
                    $codeUrl = explode('?', $codeUrl)[0] ?? $codeUrl;
                    $putCodeUrl = put_image($codeUrl);
                    $code = $putCodeUrl ? image_to_base64(public_path() . $putCodeUrl) : false;
                    $code ?? unlink(public_path() . $putCodeUrl);
                }
                return $code;
            });
            $image = CacheService::get($imageUrl, function () use ($imageUrl) {
                $imageTmp = $image = $imageUrl ? image_to_base64($imageUrl) : false;
                if (!$imageTmp) {
                    $imageUrl = explode('?', $imageUrl)[0] ?? $imageUrl;
                    $putImageUrl = put_image($imageUrl);
                    $image = $putImageUrl ? image_to_base64(public_path() . $putImageUrl) : false;
                    $image ?? unlink(public_path() . $putImageUrl);
                }
                return $image;
            });
            return app('json')->successful(compact('code', 'image'));
        } catch (\Exception $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * 门店列表
     * @param Request $request
     * @param SystemStoreServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function store_list(Request $request, SystemStoreServices $services)
    {
        [$latitude, $longitude, $product_id, $type, $is_store, $store_id] = $request->getMore([
            ['latitude', ''],
            ['longitude', ''],
            ['product_id', 0],
			['type', 0],//商品类型 0：普通 1：秒杀
            ['is_store', 1],    //前端传值为 1|商城配送 2|门店核销 3|门店配送
            ['store_id', 0],    //选择门店ID
        ], true);
		if (!checkCoordinates($longitude, $latitude)) {
			return app('json')->fail('参数错误');
		}
        //判断是否门店核销
		$deliveryType = $is_store == 2 ? [1, 3] : '';
		$list = [];
		//开启门店
		if (sys_config('store_func_status', 1)) {
			$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
			$where = ['delivery_type' => $deliveryType, 'is_del' => 0, 'is_show' => 1];
			$field = ['id', 'name', 'phone', 'image', 'latitude', 'longitude', 'address', 'detailed_address', 'is_show', 'delivery_type', 'day_time', 'day_start', 'day_end', 'valid_range'];
			$entryRules = $services->getStoreIdByEntryRules($uid, ['latitude' => $latitude, 'longitude' => $longitude]);
			$entryStoreId = $entryRules['store_id'] ?? 0;
			$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
			if ($entryStoreId) {
				$storeInfo = $services->get($entryStoreId, ['id', 'is_alone']);
				if ($storeInfo && $storeInfo['is_alone']) {//进入的是隔离门店
					$where['id'] = $entryStoreId;
				}
			}
			if ($shop_operation_type == 1) {//多门店+平台模式
				if (!isset($where['id'])) {
					$ids = $services->getRegionStoreIds($entryStoreId);
					if ($ids) $where['ids'] = $ids;
					//去除隔离门店
					$where['is_alone'] = 0;
				}
			} else if (in_array($shop_operation_type, [2, 3])) {
				$store_id = $entryRules['store_id'] ?? 0;
			}
			$list = $services->getStoreList($where, $field, $latitude, $longitude, (int)$product_id, [], (int)$type, (int)$store_id);
		}
        $data['list'] = $list;
        $data['tengxun_map_key'] = sys_config('tengxun_map_key');
        return app('json')->successful($data);
    }

    /**
     * 查找城市数据
     * @param Request $request
     * @return mixed
     */
    public function city_list(Request $request, CityAreaServices $services)
    {
        return app('json')->successful($services->getAllCityList());
    }

    /**
     * 获取拼团数据
     * @return mixed
     */
    public function pink(Request $request, StorePinkServices $pink, UserServices $user)
    {
		[$type] = $request->getMore([
            ['type', 1],
        ], true);
		$where = ['is_refund' => 0];
		if ($type == 1) {
			$where['status'] = 2;
		}
        $data['pink_count'] = $pink->getCount($where);
        $uids = array_flip($pink->getColumn($where, 'uid'));
        if (count($uids)) {
            mt_srand();
            $uids = array_rand($uids, count($uids) < 3 ? count($uids) : 3);
        }
        $data['avatars'] = $uids ? $user->getColumn(is_array($uids) ? [['uid', 'in', $uids]] : ['uid' => $uids], 'avatar') : [];
        return app('json')->successful($data);
    }

    /**
     * 复制口令接口
     * @return mixed
     */
    public function copy_words()
    {
        $data['words'] = sys_config('copy_words');
        return app('json')->successful($data);
    }

    /**
     * 生成口令关键字
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function copy_share_words(Request $request)
    {
        [$productId] = $request->getMore([
            ['product_id', ''],
        ], true);
        /** @var StoreProductServices $productService */
        $productService = app()->make(StoreProductServices::class);
        $keyWords['key_words'] = $productService->getProductWords($productId);
        return app('json')->successful($keyWords);
    }

    /**
     * 获取底部导航
     * @param DiyServices $services
     * @param string $template_name
     * @return mixed
     */
    public function getNavigation(DiyServices $services, string $template_name = '')
    {
        return app('json')->success($services->getNavigation($template_name));
    }

    /**
     * 获取用户协议内容
     * @return mixed
     */
    public function getUserAgreement(Request $request, $type = 1)
    {
        /** @var CacheServices $cache */
        $cache = app()->make(CacheServices::class);
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        $content = $cache->getDbCache($type, '');
        $uid = $request->uid() ?? 0;
        $userInfo = $userService->get($uid);
        $name = $userInfo['nickname'] ?? '';
        $avatar = $userInfo['avatar'] ?? '';
        return app('json')->success(compact('content', 'uid', 'name', 'avatar'));
    }

    /**
     * 统计代码
     * @return array|string
     */
    public function getScript()
    {
        return sys_config('system_statistics', '');
    }

    /**
     * 首页开屏广告
     * @return mixed
     */
    public function getOpenAdv()
    {
        /** @var CacheServices $cache */
        $cache = app()->make(CacheServices::class);
        $data = $cache->getDbCache('open_adv', '');
		$data['time'] = (float)($data['time'] ?? 3);
		$data['interval_time'] = (float)($data['interval_time'] ?? 24);
        return app('json')->success($data);
    }

    /**
     * 用户注销
     * @param Request $request
     * @return mixed
     */
    public function cancelUser(Request $request)
    {
        $uid = $request->uid();
        if (!$uid) return app('json')->fail('用户不存在');
        event('user.cancelUser', [$uid]);
        return app('json')->success('注销成功');
    }

    /**
     * 获取版权
     * @return mixed
     */
    public function getCopyright()
    {
        try {
            $copyright = $this->__z6uxyJQ4xYa5ee1mx5();
        } catch (\Throwable $e) {
            $copyright = [
						'copyrightContext' => '',
						'copyrightImage' => '',
					];
        }
		$copyright['record_No'] = sys_config('record_No');
		$copyright['version'] = get_mohe_version();
		$copyright['routine_contact_type'] = sys_config('routine_contact_type');
		$copyright['store_user_agreement'] = (int)sys_config('store_user_agreement');
		$copyright['routine_auth_type'] = sys_config('routine_auth_type', []);
		$copyright['wechat_auth_switch'] = (int)in_array(1, $copyright['routine_auth_type']);//微信登录开关
		$copyright['phone_auth_switch'] = (int)in_array(2, $copyright['routine_auth_type']);//手机号登录开关
		$copyright['site_name'] = sys_config('site_name', '');
		$copyright['site_logo'] = sys_config('wap_login_logo', '');
		$copyright['wechat_status'] = sys_config('wechat_appid') && sys_config('wechat_appsecret');
        return $this->success($copyright);
    }

	/**
 	* 登录页面获取logo以及版本全新
	* @return mixed
 	*/
	public function getLogo()
	{
		try {
            $copyright = $this->__z6uxyJQ4xYa5ee1mx5();
        } catch (\Throwable $e) {
            $copyright = [
						'copyrightContext' => '',
						'copyrightImage' => '',
					];
        }
		$copyright['record_No'] = sys_config('record_No');
		$copyright['version'] = get_mohe_version();
		$logo = sys_config('wap_login_logo');
        if (strstr($logo, 'http') === false && $logo) $logo = sys_config('site_url') . $logo;
		$copyright['site_name'] = sys_config('site_name');
		$copyright['logo_url'] = str_replace('\\', '/', $logo);
		$copyright['store_user_agreement'] = (int)sys_config('store_user_agreement');
		$copyright['cross_store_verification'] = (int)sys_config('cross_store_verification', 1);
		$copyright['mobile_reservation_switch'] = (int)sys_config('mobile_reservation_switch', 0);
		$copyright['routine_auth_type'] = sys_config('routine_auth_type', []);
		$copyright['wechat_auth_switch'] = (int)in_array(1, $copyright['routine_auth_type']);//微信登录开关
		$copyright['phone_auth_switch'] = (int)in_array(2, $copyright['routine_auth_type']);//手机号登录开关
		$copyright['wechat_status'] = sys_config('wechat_appid') && sys_config('wechat_appsecret');
        return app('json')->successful($copyright);
	}

    /**
     * 分销说明
     * @param Request $request
     * @param $type
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAgreement(Request $request, $type = 1)
    {
        /** @var AgreementServices $agreementService */
        $agreementService = app()->make(AgreementServices::class);
        $member_explain = $agreementService->getAgreementBytype($type);
        return app('json')->success(compact('member_explain'));
    }

	/**
	 * 获取系统版本号
	 * @return mixed
	 */
	public function getVersion()
	{
		return app('json')->success([
			'version' => get_mohe_version()
		]);
	}
}
