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

use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\bargain\StoreBargainUserHelpServices;
use app\services\activity\bargain\StoreBargainUserServices;
use app\Request;
use app\services\order\StoreOrderServices;
use app\services\store\SystemStoreServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\Exception;


/**
 * 砍价商品类
 * Class StoreBargain
 * @package app\controller\api\activity
 */
class StoreBargain
{
    protected $services;

    public function __construct(StoreBargainServices $services)
    {
        $this->services = $services;
    }

    /**
     * 砍价列表顶部图
     * @return mixed
     */
    public function config()
    {
        $lovely = sys_data('routine_lovely') ?? [];//banner图
        $info = isset($lovely[2]) ? $lovely[2] : [];
        return app('json')->successful($info);
    }

	/**
	 * 砍价商品列表
	 * @param SystemStoreServices $storeServices
	 * @param Request $request
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
    public function lst(SystemStoreServices $storeServices, Request $request)
    {
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$entryRules = $storeServices->getStoreIdByEntryRules($uid);
		$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
		if ($shop_operation_type == 3) {//单店模式不返回
			$storeId = $entryRules['store_id'] ?? 0;
			if ($storeId) {
				$bargainList = $this->services->getBargainList(['applicable_store_id' => $storeId]);
			} else {
				$bargainList = [];
			}
		} else {
			$bargainList = $this->services->getBargainList();
		}
        return app('json')->successful(get_thumb_water($bargainList));
    }

    /**
     * 砍价详情和当前登录人信息
     * @param Request $request
     * @param $id
     * @return mixed
     * @throws Exception
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function detail(Request $request, $id)
    {
		if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
        $uid = $request->hasMacro('uid')? (int)$request->uid() : 0;
        $data = $this->services->getBargain($uid, (int)$id);
        return app('json')->successful($data);
    }

    /**
     * 砍价 观看/分享/参与次数
     * @param Request $request
     * @return mixed
     */
    public function share(Request $request)
    {
		[$bargainId] = $request->postMore([
			['bargainId', 0]
		], true);
        /** @var StoreBargainUserHelpServices $bargainUserHelpService */
        $bargainUserHelpService = app()->make(StoreBargainUserHelpServices::class);
        $data['lookCount'] = $this->services->sum([], 'look');// 观看人数
        $data['userCount'] = $bargainUserHelpService->count([]);// 参与人数
        if (!$bargainId) return app('json')->successful($data);
        $this->services->addBargain((int)$bargainId, 'share');
        $data['shareCount'] = $this->services->sum([], 'share');// 分享人数
		/** @var StoreOrderServices $orderServices */
		$orderServices = app()->make(StoreOrderServices::class);
		$data['payCount'] = $orderServices->count(['activity_id' => $bargainId, 'type' => 2]);
        return app('json')->successful($data);
    }

    /**
     * 砍价开启
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function start(Request $request)
    {
        [$bargainId] = $request->postMore([
            ['bargainId', 0]
        ], true);
		if (!$bargainId) return app('json')->fail('参数错误');
        $uid = (int)$request->uid();
        if ((int)sys_config('bargain_subscribe') && request()->isWechat()) {
			/** @var WechatUserServices $wechat */
			$wechat = app()->make(WechatUserServices::class);
            [$subscribe, $url] = $wechat->isSubScribeGetWechatQrcode($uid, 'bargain-' . $bargainId . '-' . $uid, $uid);
            if (!$subscribe && $url) {
                return app('json')->successful('请先关注公众号', ['code' => 'subscribe', 'url' => $url]);
            }
        }
        return app('json')->success($this->services->setBargain($uid, (int)$bargainId));
    }

    /**
     * 砍价 帮助好友砍价
     * @param Request $request
     * @return mixed
     * @throws Exception
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function help(Request $request)
    {
        [$bargainId, $bargainUserUid] = $request->postMore([
            ['bargainId', 0],
            ['bargainUserUid', 0]
        ], true);
        $uid = (int)$request->uid();
        if (!$bargainId ||!$bargainUserUid) return app('json')->fail('参数错误');
        if ((int)sys_config('bargain_subscribe') && request()->isWechat()) {
			/** @var WechatUserServices $wechat */
			$wechat = app()->make(WechatUserServices::class);
			[$subscribe, $url] = $wechat->isSubScribeGetWechatQrcode($uid, 'bargain-' . $bargainId . '-' . $bargainUserUid, $bargainUserUid);
            if (!$subscribe) {
                return app('json')->successful('请先关注公众号', ['code' => 'subscribe', 'url' => $url]);
            }
        }
        return app('json')->success($this->services->setHelpBargain($uid, (int)$bargainId, (int)$bargainUserUid));
    }

    /**
     * 砍价 砍掉金额
     * @param Request $request
     * @return mixed
     */
    public function help_price(Request $request)
    {
        [$bargainId, $bargainUserUid] = $request->postMore([
            ['bargainId', 0],
            ['bargainUserUid', 0]
        ], true);

        /** @var StoreBargainUserHelpServices $bargainUserHelp */
        $bargainUserHelp = app()->make(StoreBargainUserHelpServices::class);
        $price = $bargainUserHelp->getPrice((int)$request->uid(), (int)$bargainId, (int)$bargainUserUid);
        return app('json')->successful($price);
    }

    /**
     * 砍价 砍价帮总人数、剩余金额、进度条、已经砍掉的价格
     * @param Request $request
     * @return mixed
     * @throws \think\exception\DbException
     */
    public function help_count(Request $request)
    {
        [$bargainId, $bargainUserUid] = $request->postMore([
            ['bargainId', 0],
            ['bargainUserUid', 0]
        ], true);
        /** @var StoreBargainUserServices $bargainUserService */
        $bargainUserService = app()->make(StoreBargainUserServices::class);
        $data = $bargainUserService->helpCount((int)$request->uid(), (int)$bargainId, (int)$bargainUserUid);
        return app('json')->successful($data);
    }


    /**
     * 砍价 砍价帮
     * @param Request $request
     * @return mixed
     * @throws \think\exception\DbException
     */
    public function help_list(Request $request)
    {
        [$bargainId, $bargainUserUid] = $request->postMore([
            ['bargainId', 0],
            ['bargainUserUid', 0],
        ], true);
        /** @var StoreBargainUserServices $bargainUser */
        $bargainUser = app()->make(StoreBargainUserServices::class);
        $bargainUserTableId = $bargainUser->getBargainUserTableId($bargainId, $bargainUserUid);

        /** @var StoreBargainUserHelpServices $bargainUserHelp */
        $bargainUserHelp = app()->make(StoreBargainUserHelpServices::class);
        [$page, $limit] = $this->services->getPageValue();
        $storeBargainUserHelp = $bargainUserHelp->getHelpList($bargainUserTableId, $page, $limit);
        return app('json')->successful($storeBargainUserHelp);
    }

    /**
     * 砍价 开启砍价用户信息
     * @param Request $request
     * @return mixed
     * @throws \think\exception\DbException
     */
    public function start_user(Request $request)
    {
        [$bargainId, $bargainUserUid] = $request->postMore([
            ['bargainId', 0],
            ['bargainUserUid', 0],
        ], true);
        if (!$bargainId || !$bargainUserUid) return app('json')->fail('参数错误');
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserCacheInfo((int)$bargainUserUid);
        if (!$userInfo) {
            return app('json')->fail('用户信息获取失败');
        }
        return app('json')->successful(['nickname' => $userInfo['nickname'], 'avatar' => $userInfo['avatar']]);
    }


    /**
     * 砍价列表(已参与)
     * @param Request $request
     * @return mixed
     */
    public function user_list(Request $request)
    {
        $uid = $request->uid();
        /** @var StoreBargainUserServices $bargainUser */
        $bargainUser = app()->make(StoreBargainUserServices::class);
        $bargainUser->editBargainUserStatus($uid);// 判断过期砍价活动
        $list = $bargainUser->getBargainUserAll($uid);
        if (count($list)) return app('json')->successful(get_thumb_water($list));
        else return app('json')->successful([]);
    }

    /**
     * 砍价取消
     * @param Request $request
     * @return mixed
     */
    public function user_cancel(Request $request)
    {
        [$bargainId] = $request->postMore([
			['bargainId', 0]
		], true);
        if (!$bargainId) return app('json')->fail('参数错误');
        /** @var StoreBargainUserServices $bargainUser */
        $bargainUser = app()->make(StoreBargainUserServices::class);
        $res = $bargainUser->cancelBargain($bargainId, $request->uid());
        if ($res) return app('json')->successful('取消成功');
        else return app('json')->successful('取消失败');
    }

    /**
     * 获取分享海报信息
     * @param Request $request
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function posterInfo(Request $request, $bargainId)
    {
        return app('json')->success($this->services->posterInfo((int)$bargainId, $request->user()));
    }
}
