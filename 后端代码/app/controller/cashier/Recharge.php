<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\cashier;

use app\model\yeji\CashType;
use app\Request;
use app\services\user\UserRechargeServices;
use app\services\user\UserServices;
use mohe\services\SystemConfigService;

/**
 * 收银台用户储值
 * Class Recharge
 * @package app\controller\cashier
 */
class Recharge extends AuthController
{
    /**
     * 储值数据
     * @return mixed
     */
    public function rechargeInfo()
    {
        $rechargeQuota = sys_data('user_recharge_quota') ?? [];
        /** @var \app\services\other\StoreGiftConfigServices $giftServices */
        $giftServices = app()->make(\app\services\other\StoreGiftConfigServices::class);
        $quotaIds = array_values(array_filter(array_map(function ($item) {
            return (int)($item['id'] ?? 0);
        }, $rechargeQuota)));
        $configMap = $giftServices->getConfigMap(
            \app\services\other\StoreGiftConfigServices::GIFT_TYPE_RECHARGE,
            $quotaIds
        );
        foreach ($rechargeQuota as $key => $item) {
            $quotaId = (int)($item['id'] ?? 0);
            $config = $configMap[$quotaId] ?? ['product' => [], 'coupon' => []];
            $rechargeQuota[$key]['send_config'] = [
                'product' => $config['product'] ?? [],
                'coupon' => $config['coupon'] ?? [],
            ];
        }
        $data['recharge_quota'] = $rechargeQuota;
        $data['cashier_operator_gift_switch'] = (int)sys_config('cashier_operator_gift_switch', 1);
        $data['cashier_debt_pay_switch'] = (int)sys_config('cashier_debt_pay_switch', 0);
        $recharge_attention = sys_config('recharge_attention');
        $recharge_attention = explode("\n", $recharge_attention);
        $data['recharge_attention'] = $recharge_attention;
        return $this->success($data);
    }

    /**
     * 收银台用户储值
     * @param Request $request
     * @param UserServices $userServices
     * @param UserRechargeServices $userRechargeServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function recharge(Request $request, UserServices $userServices, UserRechargeServices $userRechargeServices)
    {
        [$sendAll,$is_budan,$budan_time,$is_gendan,$gendan_staff_id,$source,$combinationInfo,$remarkInfo,$cashChoose,$real_pay_type,$uid, $price,$give_money,$recharId,$payType, $authCode,$staffChoose,$debtPayAmount] = $request->postMore([
            ['sendAll',[]],
            ['is_budan', 0],
            ['budan_time', ''],
            ['is_gendan', 0],
            ['gendan_staff_id', 0],
            ['source', 0],
            ['combination_info',[]],
            ['remarkInfo',[]],
            ['cash_choose', 0],
            ['real_pay_type', 0],
            ['uid', 0],
            ['price', 0],
            ['give_money', 0],
            ['rechar_id', 0],
            [['pay_type', 'd'], 2], //2=用户扫码支付，3=付款码扫码支付 4=现金支付
            ['auth_code', ''],
            ['staffChoose', []],
            ['debt_pay_amount', 0],
        ], true);
        $debtPayAmount = max(0, (float)$debtPayAmount);
        if (!$authCode && $payType == 3) {
            return $this->fail('缺少付款码二维码CODE');
        }
        if (!$price || $price <= 0) {
            return $this->fail('储值金额不能为0元!');
        }
        $maxPer=SystemConfigService::get('recharge_per');
        if($give_money > 0 && $maxPer > 0){
             $max=bcdiv($maxPer,100,2);
             $per=bcdiv($give_money,$price,2);
             if($max < $per){
                 return $this->fail('赠送金额占比不能超过'.$maxPer."%");
             }
        }
        $storeMinRecharge = sys_config('store_user_min_recharge');
        if ($price < $storeMinRecharge) return $this->fail('储值金额不能低于' . $storeMinRecharge);
        if ($debtPayAmount > 0 && bccomp((string)$debtPayAmount, (string)$price, 2) > 0) {
            return $this->fail('欠款金额不能超过储值金额');
        }
        if ($debtPayAmount > 0 && !(int)sys_config('cashier_debt_pay_switch', 0)) {
            return $this->fail('当前未开启欠款功能');
        }
        if (!$userServices->userExist($uid)) {
            return $this->fail('参数错误');
        }
        if (is_array($combinationInfo) && $combinationInfo !== []) {
            try {
                CashType::validateCombinationInfo($combinationInfo);
            } catch (\think\exception\ValidateException $e) {
                return $this->fail($e->getMessage());
            }
        }
        $re = $userRechargeServices->recharge($uid, $price, $recharId, (int)$payType, 'store', $this->cashierInfo, $authCode,$staffChoose,$give_money,$cashChoose,$remarkInfo,$combinationInfo,$real_pay_type,$source,$is_budan,$budan_time,$sendAll,$is_gendan,$gendan_staff_id,$debtPayAmount);
        if ($re) {
            $msg = $re['msg'];
            unset($re['msg']);
            return $this->success($msg, $re);
        }
        return $this->fail('储值失败');
    }
}
