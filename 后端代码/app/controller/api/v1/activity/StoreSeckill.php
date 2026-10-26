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
use app\services\activity\seckill\StoreSeckillServices;
use app\services\activity\seckill\StoreSeckillTimeServices;
use app\services\other\QrcodeServices;
use app\services\store\SystemStoreServices;


/**
 * 秒杀商品类
 * Class StoreSeckill
 * @package app\controller\api\activity
 */
class StoreSeckill
{

    protected $services;

    public function __construct(StoreSeckillServices $services)
    {
        $this->services = $services;
    }

	/**
	 * 秒杀商品时间区间
	 * @param StoreSeckillTimeServices $seckillTimeServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function index(StoreSeckillTimeServices $seckillTimeServices)
	{
		$seckillTime = $seckillTimeServices->time_list();
		$seckillTimeIndex = -1;
		$timeCount = count($seckillTime);//总数
		$unTimeCunt = 0;//即将开始
		if ($timeCount) {
			$today = date('Y-m-d');
			$currentHour = date('Hi');
			foreach ($seckillTime as $key => &$value) {
				$start = str_replace(':', '', $value['start_time']);
				$end = str_replace(':', '', $value['end_time']);
				if ($currentHour >= $start && $currentHour <= $end) {
					$value['state'] = '抢购中';
					$value['status'] = 1;
					if ($seckillTimeIndex == -1) $seckillTimeIndex = $key;
				} else if ($currentHour < $start) {
					$value['state'] = '即将开始';
					$value['status'] = 2;
					$unTimeCunt += 1;
				} else if ($currentHour >= $end) {
					$value['state'] = '已结束';
					$value['status'] = 0;
				}
				$value['time'] = $value['start_time'];
				$value['stop'] = strtotime($today. ' '. $value['end_time']);
			}
			//有时间段但是都不在抢购中
			if ($seckillTimeIndex == -1 && $currentHour <= (int)(str_replace(':', '', $seckillTime[$timeCount - 1]['time'] ?? 0))) {
				if ($currentHour < (int)(str_replace(':', '', $seckillTime[0]['time'] ?? 0))) {//当前时间
					$seckillTimeIndex = 0;
				} elseif ($unTimeCunt) {//存在未开始的
					foreach ($seckillTime as $key => $item) {
						if ($item['status'] == 2) {
							$seckillTimeIndex = $key;
							break;
						}
					}
				} else {
					$seckillTimeIndex = $timeCount - 1;
				}
			}
		}
		$data['lovely'] = sys_config('seckill_header_banner');
		if (strstr($data['lovely'], 'http') === false && strlen(trim($data['lovely']))) $data['lovely'] = sys_config('site_url') . $data['lovely'];
		$data['lovely'] = str_replace('\\', '/', $data['lovely']);
		$data['seckillTime'] = $seckillTime;
		$data['seckillTimeIndex'] = $seckillTimeIndex;
		return app('json')->successful($data);
	}

    /**
     * 秒杀商品列表
     * @param Request $request
     * @param $time
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function lst(SystemStoreServices $storeServices, Request $request, $time)
    {
        if (!$time) return app('json')->fail('参数错误');
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$entryRules = $storeServices->getStoreIdByEntryRules($uid);
		$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
		if ($shop_operation_type == 3) {//单店模式不返回
			$storeId = $entryRules['store_id'] ?? 0;
			if ($storeId) {
				$seckillInfo = $this->services->getListByTime((int)$time, ['applicable_store_id' => $storeId]);
			} else {
				$seckillInfo = [];
			}
		} else {
			$seckillInfo = $this->services->getListByTime((int)$time);
		}
        return app('json')->successful(get_thumb_water($seckillInfo, 'mid'));
    }

    /**
     * 秒杀商品详情
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function detail(Request $request, $id)
    {
		if (!$id || !is_numeric($id)) {
			return app('json')->fail('参数错误');
		}
		[$time_id, $time, $status] = $request->getMore([
			['time_id', 0],
			['time', 0],
			['status', 0],
		], true);
		$this->services->setItem('time_id', $time_id)->setItem('time', $time)->setItem('status', $status);
        $data = $this->services->seckillDetail((int)$request->uid(), (int)$id);
		$this->services->reset();
        return app('json')->successful($data);
    }

    /**
     * 获取秒杀小程序二维码
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function code(Request $request, $id, $stop_time = '')
    {
		if (!$id || !is_numeric($id)) {
			return app('json')->fail('参数错误');
		}
        /** @var QrcodeServices $qrcodeService */
        $qrcodeService = app()->make(QrcodeServices::class);
		$uid = (int)$request->uid();
		$name = 'seckill_' . $id . '_' . $uid . '.jpg';
		if ($stop_time) {
			$name = $stop_time . $name;
		}
		$url = $qrcodeService->getRoutineQrcodePath((int)$id, $uid, 1, $name);
        if ($url) {
            return app('json')->success(['code' => $url]);
        } else {
            return app('json')->success(['code' => '']);
        }
    }

	/**
	 * 获取商品详情二维码
	 * @param Request $request
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function detailCode(Request $request, $id)
	{
		if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
		$uid = (int)$request->uid();
		/** @var QrcodeServices $qrcodeService */
		$qrcodeService = app()->make(QrcodeServices::class);
		$time = $request->param('time', '');
		$status = $request->param('status', '');
		if (($configData['share_qrcode'] ?? 0) && request()->isWechat()) {
			$storeInfo['code_base'] = $qrcodeService->getTemporaryQrcode('seckill-' . $id . '-'. $uid . '-' . $time . '-' . $status, $uid)->url;
		} else {
			$storeInfo['code_base'] = $qrcodeService->getWechatQrcodePath((int)$id . '_product_seckill_detail_wap.jpg', '/pages/activity/goods_seckill_details/index?id=' . $id . '&time=' . $time . '&status=' . $status);
		}

		return app('json')->success($storeInfo);
	}

}
