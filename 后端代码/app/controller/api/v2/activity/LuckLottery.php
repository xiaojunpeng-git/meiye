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
namespace app\controller\api\v2\activity;


use app\Request;
use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\activity\lottery\LuckLotteryServices;
use app\services\other\QrcodeServices;
use app\services\wechat\WechatServices;
use app\services\wechat\WechatUserServices;
use mohe\services\CacheService;

/**
 *  抽奖活动
 * Class LuckLottery
 * @package app\controller\api\v1\activity
 */
class LuckLottery
{
    /**
     * @var LuckLotteryServices
     */
    protected $services;

    /**
     * LuckLottery constructor.
     * @param LuckLotteryServices $services
     */
    public function __construct(LuckLotteryServices $services)
    {
        $this->services = $services;
    }

    /**
     * 抽奖活动信息
     * @param Request $request
     * @param $factor
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function LotteryInfo(Request $request, $factor = 1, $id = 0)
    {
        if ($factor && !$id) {
            switch ($factor) {
                case 1:
                    $id = sys_config('points_lottery', 0);
                    break;
                case 3:
                    $id = sys_config('order_pay_lottery', 0);
                    break;
                case 4:
                    $id = sys_config('order_evaluate_lottery', 0);
                    break;
                case 5:
                    $id = sys_config('follow_lottery', 0);
                    break;
            }
        } elseif (!$factor && !$id) {
            return app('json')->fail('参数有误');
        }
        $lottery = $this->services->getLottery($id,'*',['prize'],true);
        if (!$lottery) {
            return app('json')->fail('抽奖活动不存在');
        }
        $lottery = $lottery->toArray();
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $lotteryData = ['lottery' => $lottery];
        $lottery_num = 0;
        if ($uid) {//验证用户抽奖
            $this->services->checkoutUserAuth($uid, (int)$lottery['id'], [], $lottery);
            $lottery_num = $this->services->getLotteryNum($uid, (int)$lottery['id'], [], $lottery);
        }
        $lotteryData['lottery_num'] = $lottery_num;
        if ($factor == 3 && $lotteryData['lottery_num'] < 1) {//下单抽奖，无次数，不返回抽奖数据
            return app('json')->successful('ok', []);
        }
        $all_record = $user_record = [];
        /** @var LuckLotteryRecordServices $lotteryRecordServices */
        $lotteryRecordServices = app()->make(LuckLotteryRecordServices::class);
        if ($lottery['is_all_record'] || $lottery['is_personal_record']) {
            if ($lottery['is_all_record']) {
                $all_record = $lotteryRecordServices->getWinList(['lottery_id' => $lottery['id']]);
            }
            if ($lottery['is_personal_record'] && $uid) {
                $user_record = $lotteryRecordServices->getWinList(['lottery_id' => $lottery['id'], 'uid' => $uid]);
            }
        }
        if ($lottery['factor'] == 1) {//积分抽奖
            $lotteryData['todayCount'] = $lotteryData['totalCount'] = 0;
            if ($uid) {
                $data = $lotteryRecordServices->getLotteryNum($uid, (int)$lottery['id']);
                $lotteryData['todayCount'] = (int)max(bcsub((string)$lottery['lottery_num'], (string)$data['todayCount'], 0), 0);
                $lotteryData['totalCount'] = (int)max(bcsub((string)$lottery['total_lottery_num'], (string)$data['totalCount'], 0), 0);
            }
        } else {
            $lotteryData['totalCount'] = $lotteryData['todayCount'] = $lotteryData['lottery_num'] = (int)$lotteryData['lottery_num'];
        }
        $lotteryData['all_record'] = $all_record;
        $lotteryData['user_record'] = $user_record;
        $lotteryData['cache_time'] = in_array($factor, [3, 4]) ? $this->services->getCacheLotteryExpireTime($uid, $factor == 3 ? 'order' : 'comment') : 0;
        return app('json')->successful('ok', $lotteryData);
    }

    /**
     * 参与抽奖
     * @param Request $request
     * @return mixed
     */
    public function luckLottery(Request $request)
    {
        [$id, $type, $channel_type] = $request->postMore([
            ['id', 0],
            ['type', 0],
            ['channel_type', '']
        ], true);

        $uid = (int)$request->uid();
        $key = 'lucklotter_limit_' . $uid;
        if (CacheService::redisHandler()->get($key)) {
            return app('json')->fail('您求的频率太过频繁,请稍后请求!');
        }
        CacheService::redisHandler()->set('lucklotter_limit_' . $uid, $uid, 1);

        if ($type == 5 && request()->isWechat()) {
            /** @var WechatUserServices $wechat */
            $wechat = app()->make(WechatUserServices::class);
            [$subscribe, $url] = $wechat->isSubScribeGetWechatQrcode($uid, 'luckLottery-' . $uid, $uid);
            if (!$subscribe) {
                return app('json')->successful('请先关注公众号', ['code' => 'subscribe', 'url' => $url]);
            }
        }
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        return app('json')->successful($this->services->luckLottery($uid, $id, $channel_type));
    }

    /**
     * 领取奖品
     * @param Request $request
     * @param LuckLotteryRecordServices $lotteryRecordServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function lotteryReceive(Request $request, LuckLotteryRecordServices $lotteryRecordServices)
    {
        [$id, $name, $phone, $address, $mark] = $request->postMore([
            ['id', 0],
            ['name', ''],
            ['phone', ''],
            ['address', ''],
            ['mark', '']
        ], true);
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        $uid = (int)$request->uid();
        return app('json')->successful($lotteryRecordServices->receivePrize($uid, $id, compact('name', 'phone', 'address', 'mark')) ? '领取成功' : '领取失败');
    }

    /**
     * 获取中奖记录
     * @param Request $request
     * @param LuckLotteryRecordServices $lotteryRecordServices
     * @return mixed
     */
    public function lotteryRecord(Request $request, LuckLotteryRecordServices $lotteryRecordServices)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($lotteryRecordServices->getRecord($uid));
    }

    /**
     * 活动使用id
     * @param $data
     * @return array
     */
    public function getfactorUse()
    {
        $data['points_lottery'] = sys_config('points_lottery', 0);
        $data['order_pay_lottery'] = sys_config('order_pay_lottery', 0);
        $data['order_evaluate_lottery'] = sys_config('order_evaluate_lottery', 0);
        $data['follow_lottery'] = sys_config('follow_lottery', 0);
        return app('json')->successful($data);
    }
}
