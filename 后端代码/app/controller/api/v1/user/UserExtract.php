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
namespace app\controller\api\v1\user;

use app\Request;
use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\user\UserExtractServices;
use mohe\services\wechat\Payment;
use think\facade\Config;

/**
 * 提现类
 * Class UserExtract
 * @package app\controller\api\user
 */
class UserExtract
{
	/**
     * @var UserExtractServices
     */
    protected $services;

    /**
     * UserExtract constructor.
     * @param UserExtractServices $services
     */
    public function __construct(UserExtractServices $services)
    {
        $this->services = $services;
    }

    /**
     * 提现银行
     * @param Request $request
     * @return mixed
     */
    public function bank(Request $request)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($this->services->bank($uid));
    }

	/**
	 * @param Request $request
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function extractList(Request $request)
	{
		$where = $request->getMore([
			['start', 0],
			['stop', 0],
			['keyword', '']
		]);
		if ($where['start'] || $where['stop']) {
			$where['time'] = [$where['start'], $where['stop']];
		}
		unset($where['start'], $where['stop']);
		$uid = (int)$request->uid();
		return app('json')->successful($this->services->userextractList($uid, $where));
	}

	/**
	 * 详情
	 * @param Request $request
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function detail(Request $request)
	{
		[$order_id, $type] = $request->postMore([
			['order_id', ''],
			['type', '1'],
		], true);
		if (!$order_id || !$type) {
			return app('json')->fail('参数错误');
		}

		$info = [];
		switch ($type) {
			//提现
			case 1:
				$info = $this->services->get(['order_id' => $order_id]);
				$info = $info ? $info->toArray() : [];
				if ($info['extract_type'] != 'weixin' || sys_config('pay_wechat_type') != 1) {
					return app('json')->fail('状态错误,请联系客服管理员');
				}

				break;
			case 2:
				$info = app()->make(LuckLotteryRecordServices::class)->get(['order_id' => $order_id]);
				$info = $info ? $info->toArray() : [];
				$prize_info = is_string($info['prize_info']) ? json_decode($info['prize_info'], true) : $info['prize_info'];
				$info['extract_price'] = $prize_info['num'] ?? '0';
				if ($info['type'] != 4 || sys_config('pay_wechat_type') != 1) {
					return app('json')->fail('状态错误,请联系客服管理员');
				}
				break;
		}
		$res = Payment::instance()->application()->v3pay->queryTransferBills($order_id);
		if (isset($res['fail_reason']) && $res['fail_reason'] != '') {
			if ($type == 1) {
				$this->services->changeFail($info['id'], $info, '提现失败，原因：超时未领取');
				$this->services->update($info['id'], ['wechat_state' => 'FAIL']);
			} else {
				app()->make(LuckLotteryRecordServices::class)->update($info['id'], ['wechat_state' => 'FAIL']);
			}
			$info['wechat_state'] = 'FAIL';
			return app('json')->fail('收款失败，超时未领取');
		}
		if ($info['wechat_state'] != 'WAIT_USER_CONFIRM') {
			return app('json')->fail('状态错误,请联系客服管理员');
		}
		$info['mchid'] = sys_config('pay_weixin_mchid');
		$info['wechat_appid'] = sys_config('wechat_appid');
		return app('json')->successful($info);
	}

    /**
     * 提现申请
     * @param Request $request
     * @return mixed
     */
    public function cash(Request $request)
    {
        $extractInfo = $request->postMore([
            ['alipay_code', ''],
            ['extract_type', ''],
            ['money', 0],
            ['name', ''],
            ['bankname', ''],
            ['cardnum', ''],
            ['weixin', ''],
            ['qrcode_url', ''],
			['channel_type', 'weixin'],
        ]);
        $extractType = Config::get('pay.extractType', []);
        if (!in_array($extractInfo['extract_type'], $extractType))
            return app('json')->fail('提现方式不存在');
        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', (float)$extractInfo['money'])) return app('json')->fail('提现金额输入有误');
        if (!$extractInfo['cardnum'] == '')
            if (!preg_match('/^([1-9]{1})(\d{10}|\d{15}|\d{16}|\d{18})$/', $extractInfo['cardnum']))
                return app('json')->fail('银行卡号输入有误');
        if ($extractInfo['extract_type'] == 'alipay') {
            if (!$extractInfo['alipay_code']) return app('json')->fail('请输入支付宝账号');
        } else if ($extractInfo['extract_type'] == 'bank') {
            if (!$extractInfo['cardnum']) return app('json')->fail('请输入银行卡账号');
            if (!$extractInfo['bankname']) return app('json')->fail('请输入开户行信息');
        } else if ($extractInfo['extract_type'] == 'weixin' && sys_config('brokerage_type') == 0) {
            if (!$extractInfo['weixin']) return app('json')->fail('请输入微信账号');
        }
        $uid = (int)$request->uid();
        if ($this->services->cash($uid, $extractInfo))
            return app('json')->successful('申请提现成功!');
        else
            return app('json')->fail('提现失败');
    }
}
