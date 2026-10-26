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
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\combination\StorePinkServices;
use app\services\other\QrcodeServices;
use app\services\store\SystemStoreServices;

/**
 * 拼团类
 * Class StoreCombination
 * @package app\controller\api\activity
 */
class StoreCombination
{
    protected $services;

    public function __construct(StoreCombinationServices $services)
    {
        $this->services = $services;
    }

    /**
     * 拼团列表
     * @return mixed
     */
    public function lst(SystemStoreServices $storeServices, Request $request)
    {
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$entryRules = $storeServices->getStoreIdByEntryRules($uid);
		$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
		if ($shop_operation_type == 3) {//单店模式不返回
			$storeId = $entryRules['store_id'] ?? 0;
			if ($storeId) {
				$list = $this->services->getCombinationList(['applicable_store_id' => $storeId]);
			} else {
				$list = [];
			}
		} else {
			$list = $this->services->getCombinationList();
		}
        return app('json')->successful(get_thumb_water($list, 'mid'));
    }


    /**
     * 拼团商品详情
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function detail(Request $request, $id)
    {
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
		$uid = (int)$request->uid();
		$data = $this->services->combinationDetail($uid, $id);
        return app('json')->successful($data);
    }

	/**
	 * 获取商品海报二维码
	 * @param Request $request
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @author 等风来
	 * @email 136327134@qq.com
	 * @date 2022/11/14
	 */
	public function detailCode(Request $request)
	{
		$id = $request->get('id/d', 0);
        if (!$id) {
            return app('json')->fail('参数错误');
        }
		$uid = (int)$request->uid();
		/** @var QrcodeServices $qrcodeService */
		$qrcodeService = app()->make(QrcodeServices::class);
		if (($configData['share_qrcode'] ?? 0) && request()->isWechat()) {
			$storeInfo['code_base'] = $qrcodeService->getTemporaryQrcode('combination-' . $id . '-' . $uid, $uid)->url;
		} else {
			$storeInfo['code_base'] = $qrcodeService->getWechatQrcodePath($id . '_product_combination_detail_wap.jpg', '/pages/activity/goods_details/index?type=3&id=' . $id);
		}
		return app('json')->success($storeInfo);
	}

    /**
     * 拼团 开团
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function pink(Request $request, $id)
    {
		$uid = (int)$request->uid();
		$user = $request->user();
        $data = $this->services->getPinkInfo($uid, (int)$id, $user->toArray());
        return app('json')->successful($data);
    }

    /**
     * 拼团 取消开团
     * @param Request $request
     * @return mixed
     */
    public function remove(Request $request)
    {
        [$id, $cid] = $request->postMore([
            ['id', 0],
            ['cid', 0],
        ], true);
        if (!$id || !$cid) return app('json')->fail('缺少参数');
        /** @var StorePinkServices $pinkService */
        $pinkService = app()->make(StorePinkServices::class);
        $pinkService->removePink((int)$request->uid(), $cid, $id);
        return app('json')->successful('取消成功');
    }

    /**
     * 获取拼团海报详情
     * @param Request $request
     * @param StorePinkServices $services
     * @param $id
     * @return mixed
     */
    public function posterInfo(Request $request, StorePinkServices $services, $id)
    {
        return app('json')->success($services->posterInfo((int)$id, $request->user()));
    }

    /**
     * 获取秒杀小程序二维码
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function code(Request $request, $id)
    {
        /** @var QrcodeServices $qrcodeService */
        $qrcodeService = app()->make(QrcodeServices::class);
		$uid = (int)$request->uid();
		$name = 'combination_' . $id . '_' . $uid . '.jpg';
		$url = $qrcodeService->getRoutineQrcodePath($id, $uid, 3, $name);
        if ($url) {
            return app('json')->success(['code' => $url]);
        } else {
            return app('json')->success(['code' => '']);
        }
    }

    /**
     * 获取拼团列表轮播图
     */
    public function banner_list()
    {
        $banner = sys_data('combination_banner') ?? [];
        return app('json')->success($banner);
    }
}
