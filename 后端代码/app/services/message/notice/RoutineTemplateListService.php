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

namespace app\services\message\notice;

use app\jobs\notice\template\TemplateJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\product\product\StoreProduct;
use app\model\store\SystemStore;
use app\services\message\NoticeService;
use think\facade\Log;


/**
 * 小程序模板消息消息队列
 * Class RoutineTemplateJob
 * @package mohe\jobs
 */
class RoutineTemplateListService extends NoticeService
{

    /**
     * 判断是否开启权限
     * @var bool
     */
    private $isopend = true;

    /**
     * 是否开启权限
     * @param string $mark
     * @return $this
     */
    public function isOpen(string $mark)
    {
        $this->isopend = $this->noticeInfo['is_routine'] === 1;
        return $this;

    }

    /**
     * 发送模板消息
     * @param string $tempCode 模板消息常量名称
     * @param int $uid
     * @param array $data 模板内容
     * @param string $link 跳转链接
     * @param string|null $color 文字颜色
     * @return bool|mixed
     */
    public function sendTemplate(string $tempCode, int $uid, array $data, string $link = null, string $color = null)
    {
        try {
            $this->isopend = $this->noticeInfo['is_routine'] === 1;
            if ($this->isopend && $uid) {
                $openid = $this->getOpenidByUid($uid, 'routine');
				if ($openid) {
					//放入队列执行
					TemplateJob::dispatchDo('doJob', ['subscribe', $openid, $tempCode, $data, $link, $color]);
				}
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return true;
        }
    }

	/**
	 * 确认收货
	 * @param int $uid
	 * @param array $order
	 * @param string $title
	 * @return bool|mixed
	 */
    public function sendOrderTakeOver(int $uid, array $order, string $title)
    {
        return $this->sendTemplate('ORDER_TAKE', $uid, [
            'thing1' => $order['order_id'],
			'thing2' => mb_substr_str($title, 20),
            'date5' => date('Y-m-d H:i:s', time()),
        ], '/pages/goods/order_details/index?order_id=' . $order['order_id']);
    }

	/**
	 * 发货
	 * @param int $uid
	 * @param array $order
	 * @param string $storeTitle
	 * @param array $data
	 * @param int $isGive
	 * @return bool|mixed
	 */
    public function sendOrderPostage(int $uid, array $order, string $storeTitle, array $data, int $isGive = 0)
    {
		if ($isGive) {//快递发货
			return $this->sendTemplate('ORDER_DELIVER_SUCCESS', $uid, [
				'character_string2' => $data['delivery_id'] ?? '',
				'thing1' => mb_substr_str($data['delivery_name'] ?? '', 20),
				'time3' => date('Y-m-d H:i:s', time()),
				'thing5' => mb_substr_str($storeTitle, 20),
			], '/pages/goods/order_details/index?order_id=' . $order['order_id']);
		} else {//同城配送
			return $this->sendTemplate('ORDER_POSTAGE_SUCCESS', $uid, [
				'thing8' => mb_substr_str($storeTitle, 20),
				'character_string1' => $order['order_id'],
				'name4' => mb_substr_str($data['delivery_name'] ?? '', 10, '...', 1),
				'phone_number10' => $data['delivery_id'] ?? ''
			], '/pages/goods/order_details/index?order_id=' . $order['order_id']);
		}
    }

    /**
     * 储值金额退款
     * @param $uid
     * @param $UserRecharge
     * @param $now_money
     * @return bool|mixed
     */
    public function sendRechargeSuccess($uid, $UserRecharge, $now_money)
    {
        $storeName=SystemStore::where("id",$UserRecharge['store_id'])->value("name");
        return $this->sendTemplate('NEW_RECHARGE_SUCCESS', (int)$uid, [
            'thing3' => $storeName,
            'amount1' => $UserRecharge['price'],
            'amount2' => $UserRecharge['give_price'],
            'amount5' => $now_money,
            'time9' => date('Y-m-d H:i:s', time()),
        ], '/pages/users/user_bill/index?type=2');
    }

    /**
     * 次卡变动通知
     */
    public function cikaSuccess($writeoff){
        $productName=StoreProduct::where("id",$writeoff['product_id'])->value("store_name");
        $storeName=SystemStore::where("id",$writeoff['relation_id'])->value("name");
        $surplusTimes=StoreOrderCartInfo::where("id",$writeoff['order_cart_id'])->value("write_surplus_times");
        return $this->sendTemplate('CIKA_CHUANGE', (int)$writeoff['uid'], [
            'thing1' => $storeName,
            'thing5' => $productName,
            'thing2' => $writeoff['writeoff_num'],
            'number3' => $surplusTimes,
            'time4' => date('Y-m-d H:i:s', time()),
        ], '/pages/admin/writeRecordList/index?id='.$writeoff['oid']);
    }
    /**
     * 余额变动通知
     */
    public function yueChange($data){
        $order=StoreOrder::where("id",$data['link_id'])->find();
        $storeName=SystemStore::where("id",$order['store_id'])->value("name");
        $orderId=$order['order_id'] ?? 0;
        return $this->sendTemplate('YUE_CHANGE', (int)$data['uid'], [
            'thing1' => $storeName,
            'amount2' => $data['number'],
            'amount3' => $data['balance'],
            'time4' => date('Y-m-d H:i:s', time()),
            'character_string12' => $order['order_id'] ?? '',
        ], '/pages/goods/order_details/index?order_id='.$orderId);
    }
	/**
	 * 订单退款成功发送消息
	 * @param int $uid
	 * @param array $order
	 * @param string $storeTitle
	 * @return bool|mixed
	 */
    public function sendOrderRefundSuccess(int $uid, array $order, string $storeTitle)
    {
        return $this->sendTemplate('ORDER_REFUND', (int)$uid, [
            'thing1' => '已成功退款',
			'thing2' => mb_substr_str($storeTitle, 20),
            'amount3' => $order['pay_price'],
            'character_string6' => $order['order_id']
        ], '/pages/goods/order_after_details/index?order_id=' . $order['order_id'] . '&isReturen=1');
    }

    /**
     * 订单退款失败
     * @param $uid
     * @param $order
     * @param $storeTitle
     * @return bool|mixed
     */
    public function sendOrderRefundFail($uid, $order, $storeTitle)
    {
        return $this->sendTemplate('ORDER_REFUND', (int)$uid, [
            'thing1' => '退款失败',
			'thing2' => $storeTitle,
            'amount3' => $order['pay_price'],
            'character_string6' => $order['order_id']
        ], '/pages/goods/order_after_details/index?order_id=' . $order['order_id'] . '&isReturen=1');
    }

    /**
     * 用户申请退款给管理员发送消息
     * @param $uid
     * @param $order
     * @return bool|mixed
     */
    public function sendOrderRefundStatus($uid, $order)
    {
        $data['character_string4'] = $order['order_id'];
        $data['date5'] = date('Y-m-d H:i:s', time());
        $data['amount2'] = $order['pay_price'];
        $data['phrase7'] = '申请退款中';
        $data['thing8'] = '请及时处理';
        return $this->sendTemplate('ORDER_REFUND_STATUS', (int)$uid, $data);
    }

    /**
     * 砍价成功通知
     * @param $uid
     * @param array $bargain
     * @param array $bargainUser
     * @param int $bargainUserId
     * @return bool|mixed
     */
    public function sendBargainSuccess($uid, $bargain = [], $bargainUser = [], $bargainUserId = 0)
    {
		$data['thing1'] = mb_substr_str($bargain['title'], 20);
        $data['amount2'] = $bargain['min_price'];
        $data['thing3'] = '恭喜您，已经砍到最低价了';
        return $this->sendTemplate('BARGAIN_SUCCESS', (int)$uid, $data, '/pages/activity/goods_bargain_details/index?id=' . $bargain['id'] . '&bargain=' . $bargainUserId);
    }

	/**
	 * 订单支付成功发送模板消息
	 * @param $uid
	 * @param $pay_price
	 * @param $orderId
	 * @param $link
	 * @return bool|mixed
	 */
    public function sendOrderSuccess($uid, $pay_price, $orderId, $link)
    {
        if ($orderId == '') return true;
        $data['character_string1'] = $orderId;
        $data['amount2'] = $pay_price . '元';
        $data['date3'] = date('Y-m-d H:i:s', time());
        return $this->sendTemplate('ORDER_PAY_SUCCESS', (int)$uid, $data, $link);
    }

	/**
	 * 收益到账通知
	 * @param $uid
	 * @param $extract_price
	 * @param $msg
	 * @param $link
	 * @return bool|mixed
	 */
	public function sendRevenueReceived($uid, $extract_price, $msg, $link)
	{
		$data['amount3'] = $extract_price . '元';
		$data['thing4'] = $msg;
		$data['time9'] = date('Y-m-d H:i:s', time());
		return $this->sendTemplate('REVENUE_RECEIVED', (int)$uid, $data, $link);

	}

    /**
     * 会员订单支付成功发送消息
     * @param $uid
     * @param $pay_price
     * @param $orderId
     * @return bool|mixed
     */
    public function sendMemberOrderSuccess($uid, $pay_price, $orderId)
    {
        if ($orderId == '') return true;
        $data['character_string1'] = $orderId;
        $data['amount2'] = $pay_price . '元';
        $data['date3'] = date('Y-m-d H:i:s', time());
        return $this->sendTemplate('ORDER_PAY_SUCCESS', (int)$uid, $data, '/pages/annex/vip_paid/index');
    }

    /**
     * 提现失败
     * @param $uid
     * @param $msg
     * @param $extract_number
     * @param $nickname
     * @return bool|mixed
     */
    public function sendExtractFail($uid, $msg, $extract_number, $nickname)
    {
		return $this->sendTemplate('USER_EXTRACT', (int)$uid, [
			'thing1' => mb_substr_str('提现失败：' . $msg, 20),
			'amount2' => $extract_number . '元',
			'thing3' => mb_substr_str($nickname, 20),
			'date4' => date('Y-m-d H:i:s', time())
		], '/pages/users/user_spread_money/index?type=2');
    }

    /**
     * 提现成功
     * @param $uid
     * @param $extract_number
     * @param $nickname
     * @return bool|mixed
     */
    public function sendExtractSuccess($uid, $extract_number, $nickname)
    {
        return $this->sendTemplate('USER_EXTRACT', (int)$uid, [
            'thing1' => '提现成功',
            'amount2' => $extract_number . '元',
			'thing3' => mb_substr_str($nickname, 20),
            'date4' => date('Y-m-d H:i:s', time())
        ], '/pages/users/user_spread_money/index?type=2');
    }

    /**
     * 拼团成功通知
     * @param $uid
     * @param $pinkTitle
     * @param $nickname
     * @param $pinkTime
     * @param $count
     * @param string $link
     * @return bool|mixed
     */
    public function sendPinkSuccess($uid, $pinkTitle, $nickname, $pinkTime, $count, string $link = '')
    {
		return $this->sendTemplate('PINK_TRUE', (int)$uid, [
			'thing1' => mb_substr_str($pinkTitle, 20),
			'name3' => mb_substr_str($nickname, 10, '...', 1),
			'date5' => date('Y-m-d H:i:s', $pinkTime),
			'number2' => $count
		], $link);
    }

    /**
     * 拼团状态通知
     * @param $uid
     * @param $pinkTitle
     * @param $count
     * @param $remarks
     * @param $link
     * @return bool|mixed
     */
    public function sendPinkFail($uid, $pinkTitle, $count, $remarks, $link)
    {
		return $this->sendTemplate('PINK_STATUS', (int)$uid, [
			'thing2' => mb_substr_str($pinkTitle, 20),
			'thing1' => mb_substr_str($count, 20),
			'thing3' => mb_substr_str($remarks, 20)
		], $link);
    }

    /**
     * 赠送积分消息提醒
     * @param $uid
     * @param $order
     * @param $storeTitle
     * @param $gainIntegral
     * @param $integral
     * @return bool|mixed
     */
    public function sendUserIntegral($uid, $order, $storeTitle, $gainIntegral, $integral)
    {
        if (!$order || !$uid) return true;
        if (is_string($order['cart_id']))
            $order['cart_id'] = json_decode($order['cart_id'], true);
        return $this->sendTemplate('INTEGRAL_ACCOUT', (int)$uid, [
            'character_string2' => $order['order_id'],
			'thing3' => mb_substr_str($storeTitle, 20),
            'amount4' => $order['pay_price'],
            'number5' => $gainIntegral,
            'number6' => $integral
        ], '/pages/users/user_integral/index');
    }


    /**
     * 获得推广佣金发送提醒
     * @param string $uid
     * @param string $brokeragePrice
     * @param string $goods_name
     * @return bool|mixed
     */
    public function sendOrderBrokerageSuccess(string $uid, string $brokeragePrice, string $goods_name)
    {
        return $this->sendTemplate('ORDER_BROKERAGE', $uid, [
			'thing2' => mb_substr_str($goods_name, 20),
            'amount4' => $brokeragePrice . '元',
            'time1' => date('Y-m-d H:i:s', time())
        ], '/pages/users/user_spread_user/index');
    }

    /**
     * 绑定推广关系发送消息提醒
     * @param string $uid
     * @param string $userName
     * @return bool|mixed
     */
    public function sendBindSpreadUidSuccess(string $uid, string $userName)
    {
        return $this->sendTemplate('BIND_SPREAD_UID', $uid, [
			'name3' => mb_substr_str($userName . "加入您的团队", 10, '...', 1),
            'date4' => date('Y-m-d H:i:s', time())
        ], '/pages/users/user_spread_user/index');
    }

    /**
     * 用户签到发送消息提醒
     * @param int $uid
     * @return bool|mixed
     */
    public function sendSignRemind(int $uid)
    {
        return $this->sendTemplate('SIGN_REMIND_TIME', $uid, [
            'thing3' => '每日签到',
            'thing2' => '今天还没有签到哟！'
        ], '/pages/users/user_sgin/index');
    }

	/**
	 * 待服务通知
	 * @param $uid
	 * @param $data
	 * @return bool|mixed
	 */
	public function sendReservationServiceReminder(int $uid,array $data)
	{
		return $this->sendTemplate('RESERVATION_SERVICE_REMINDER', $uid, [
			'thing5' => $data['store_name'],
			'time9' => $data['reservation_time'] . ' '. $data['service_time'],
			'thing1' => $data['reservation_name'],
			'phone_number2' => $data['reservation_phone'],
			'thing3' => $data['reservation_address'],
		], '/pages/admin/reservation_details/index?id='. $data['id']);
	}

    /**
     * 管家接受预约 — 通知客户与老师
     */
    public function sendYuyueSuccess(int $uid, array $msg)
    {
        $this->sendTemplate('YUYUE_SUCCESS', $uid, [
            'thing6' => $msg['store_name'],
            'phone_number7' => $msg['store_phone'],
            'date2' => $msg['time'],
            'thing24' => $msg['work_name'],
        ], '/pages/goods/reservation_list/index');
        if (!empty($msg['technician_uid'])) {
            $this->sendTemplate('YUYUE_SUCCESS', (int)$msg['technician_uid'], [
                'thing6' => $msg['store_name'],
                'phone_number7' => $msg['store_phone'],
                'date2' => $msg['time'],
                'thing24' => $msg['work_name'],
            ], '/pages/admin/staff_center/order/index');
        }
        return true;
    }

    public function sendYuyueRefuse(int $uid, array $msg)
    {
        $reason = trim((string)($msg['refuse_reason'] ?? ''));
        return $this->sendTemplate('YUYUE_REFUSE', $uid, [
            'phrase5' => '拒绝',
            'thing3' => $msg['work_name'],
            'thing4' => $msg['store_name'],
            'date2' => $msg['time'],
            'thing6' => $reason !== '' ? $reason : '无',
        ], '/pages/goods/reservation_list/index');
    }

    /**
     * 客户预约后通知管家
     */
    public function sendYuyueCustomer(int $uid, array $msg)
    {
        $remark = trim((string)($msg['remark'] ?? ''));
        return $this->sendTemplate('YUYUE_CUSTOMER', $uid, [
            'thing1' => $msg['customer_name'],
            'thing2' => $msg['work_name'],
            'time3' => $msg['time'],
            'thing5' => $remark !== '' ? $remark : '无',
        ], '/pages/admin/reservation_list/index');
    }

    public function sendYuyueJindu(int $uid, array $msg)
    {
        return $this->sendTemplate('YUYUE_JINDU', $uid, [
            'thing1' => $msg['work_name'],
            'thing2' => $msg['real_name'],
            'thing10' => $msg['jindu'],
            'thing3' => '房间：' . ($msg['remark'] ?? ''),
        ], '/pages/admin/reservation_list/index');
    }

}
