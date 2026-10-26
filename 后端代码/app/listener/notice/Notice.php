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
namespace app\listener\notice;


use app\services\order\StoreOrderRefundServices;
use app\services\message\notice\{
    NoticeSmsService, RoutineTemplateListService, SystemMsgService, WechatTemplateListService
};
use app\services\message\NoticeService;
use app\services\order\StoreOrderCartInfoServices;
use app\services\user\UserServices;
use mohe\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * 订单创建事件
 * Class Create
 * @package app\listener\order
 */
class Notice implements ListenerInterface
{
    /**
     * @var string[]
     */
    protected $userTypeArray = [
        '0' => 'wechat',
        '1' => 'routine',
        '2' => 'wechat',//收银订单 weixinh5
        '3' => 'wechat',//pc
    ];

    public function handle($event): void
    {
        try {
            [$data, $mark] = $event;
            /** @var NoticeService $NoticeService */
            $NoticeService = app()->make(NoticeService::class);
            /** @var WechatTemplateListService $WechatTemplateList */
            $WechatTemplateList = app()->make(WechatTemplateListService::class);
            /** @var RoutineTemplateListService $RoutineTemplateList */
            $RoutineTemplateList = app()->make(RoutineTemplateListService::class);
            /** @var SystemMsgService $SystemMsg */
            $SystemMsg = app()->make(SystemMsgService::class);
            /** @var  NoticeSmsService $NoticeSms */
            $NoticeSms = app()->make(NoticeSmsService::class);
            /** @var StoreOrderCartInfoServices $orderInfoServices */
            $orderInfoServices = app()->make(StoreOrderCartInfoServices::class);
            /** @var UserServices $UserServices */
            $UserServices = app()->make(UserServices::class);
            if ($mark) {
                $WechatTemplateList->setEvent($mark);
                $SystemMsg->setEvent($mark);
                $NoticeSms->setEvent($mark);
                $RoutineTemplateList->setEvent($mark);
                $NoticeService->setEvent($mark);
                switch ($mark) {
                    //绑定推广关系
                    case 'bind_spread_uid':
                        if (isset($data['spreadUid']) && $data['spreadUid']) {
                            if ($UserServices->checkUserPromoter((int)$data['spreadUid'])) {//检测是否是分销员
                                $name = $data['nickname'] ?? '';
                                //站内信
                                $SystemMsg->sendMsg($data['spreadUid'], ['nickname' => $name]);
                                //模板消息小程序订阅消息
                                $RoutineTemplateList->sendBindSpreadUidSuccess($data['spreadUid'], $name);
                            }
                        }
                        break;
                    //支付成功给用户
                    case 'order_pay_success':
                        $pay_price = $data['pay_price'] ?? 0.00;
                        $order_id = $data['order_id'] ?? '';
                        //短信
                        $NoticeSms->sendSms($data['user_phone'], ['order_id' => $order_id, 'pay_price' => $pay_price], 'order_pay_success');
                        $data['total_num'] = isset($data['total_num']) ? $data['total_num'] : 1;
                        //站内信
                        $SystemMsg->sendMsg($data['uid'], ['order_id' => $data['order_id'], 'total_num' => $data['total_num'], 'pay_price' => $data['pay_price']]);
                        $link = '/pages/goods/order_details/index?order_id=' . $order_id;
                        if (isset($data['is_vip_order']) && $data['is_vip_order'] == 1) {
                            $link = '/pages/annex/vip_paid/index';
                        }
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendOrderPaySuccess((int)$data['uid'], $data, $link);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderSuccess((int)$data['uid'], $pay_price, $data['order_id'], $link);
                        break;
                    //发货给用户
                    case 'order_fictitious_success':
                        $orderInfo = $data['orderInfo'];
                        $store_name = $data['storeName'];
                        $datas = $data['data'];
                        $service = app()->make(UserServices::class);
                        $nickname = $service->value(['uid' => $orderInfo['uid']], 'nickname');
                        //短信
                        $order_id = $orderInfo['order_id'];
                        $NoticeSms->sendSms($orderInfo['user_phone'], ['order_id' => $orderInfo['order_id'], 'store_name' => $store_name, 'nickname' => $nickname], 'order_fictitious_success');
                        //站内信
                        $SystemMsg->sendMsg($orderInfo['uid'], ['nickname' => $nickname, 'store_name' => $store_name, 'order_id' => $orderInfo['order_id'], 'delivery_name' => $datas['delivery_name'] ?? '', 'delivery_id' => $datas['delivery_id'] ?? '', 'user_address' => $orderInfo['user_address']]);
                        break;
                    //发货给用户
                    case 'order_deliver_success':
                        $orderInfo = $data['orderInfo'];
                        $store_name = $data['storeName'];
                        $datas = $data['data'];
                        $service = app()->make(UserServices::class);
                        $nickname = $service->value(['uid' => $orderInfo['uid']], 'nickname');
                        //短信
                        $order_id = $orderInfo['order_id'];
                        $NoticeSms->sendSms($orderInfo['user_phone'], ['order_id' => $orderInfo['order_id'], 'store_name' => $store_name, 'nickname' => $nickname], 'order_deliver_success');
                        $isGive = 0;
                        //站内信
                        $SystemMsg->sendMsg($orderInfo['uid'], ['nickname' => $nickname, 'store_name' => $store_name, 'order_id' => $orderInfo['order_id'], 'delivery_name' => $datas['delivery_name'] ?? '', 'delivery_id' => $datas['delivery_id'] ?? '', 'user_address' => $orderInfo['user_address']]);
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderDeliver($orderInfo['uid'], $store_name, $orderInfo, $datas);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderPostage((int)$orderInfo['uid'], $orderInfo, $store_name, $datas, $isGive);
                        break;
                    //发货快递给用户
                    case 'order_postage_success':
                        $orderInfo = $data['orderInfo'];
                        $store_name = $data['storeName'];
                        $datas = $data['data'];
                        $service = app()->make(UserServices::class);
                        $nickname = $service->value(['uid' => $orderInfo['uid']], 'nickname');
                        //短信
                        $order_id = $orderInfo['order_id'];
                        $NoticeSms->sendSms($orderInfo['user_phone'], ['order_id' => $orderInfo['order_id'], 'store_name' => $store_name, 'nickname' => $nickname], 'order_postage_success');
                        $isGive = 1;
                        //站内信
                        $smsdata = ['nickname' => $nickname, 'store_name' => $store_name, 'order_id' => $orderInfo['order_id'], 'delivery_name' => $datas['delivery_name'] ?? '', 'delivery_id' => $datas['delivery_id'] ?? '', 'user_address' => $orderInfo['user_address']];
                        $SystemMsg->sendMsg($orderInfo['uid'], $smsdata);
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendOrderPostage((int)$orderInfo['uid'], $orderInfo, $store_name);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderPostage((int)$orderInfo['uid'], $orderInfo, $store_name, $datas, $isGive);
                        break;
                    //确认收货给用户
                    case 'order_takever':
                        $order = is_object($data['order']) ? $data['order']->toArray() : $data['order'];
                        //模板变量
                        $store_name = substrUTf8($data['storeTitle'], 20, 'UTF-8', '');
                        $order_id = $order['order_id'];
                        $NoticeSms->sendSms($order['user_phone'], ['order_id' => $order_id, 'store_name' => $store_name], 'order_takever');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order['order_id'], 'store_name' => $store_name]);
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendOrderTakeSuccess((int)$order['uid'], $order, $store_name);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderTakeOver((int)$order['uid'], $order, $store_name);
                        break;
                    //改价给用户
                    case 'price_revision':
                        $order = $data['order'];
                        $pay_price = $data['pay_price'];
                        $order['storeName'] = $orderInfoServices->getCarIdByProductTitle((int)$order['id']);
                        //短信
                        $NoticeSms->sendSms($order['user_phone'], ['order_id' => $order['order_id'], 'pay_price' => $pay_price], 'price_revision');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order['order_id'], 'pay_price' => $pay_price]);
                        break;
                    //退款成功
                    case 'order_refund':
                        $datas = $data['data'];
                        $order = $data['order'];
                        $storeName = $orderInfoServices->getCarIdByProductTitle((int)$order['id']);
                        $storeTitle = substrUTf8($storeName, 20, 'UTF-8', '');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order['order_id'], 'pay_price' => $order['pay_price'], 'refund_price' => $datas['refund_price']]);
                        /** @var StoreOrderRefundServices $serviceRefun */
                        $serviceRefun = app()->make(StoreOrderRefundServices::class);
                        $order['order_id'] = $serviceRefun->value(['uid' => $order['uid'], 'store_order_id' => $order['id']], 'order_id');

                        //短信
                        $NoticeSms->sendSms($order['user_phone'], ['order_id' => $order['order_id'], 'refund_price' => $datas['refund_price']], 'order_refund');

                        //模板消息公众号模版消息
                        $WechatTemplateList->sendOrderRefundSuccess((int)$order['uid'], $datas, $order);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderRefundSuccess((int)$order['uid'], $order, $storeTitle);
                        break;
                    //退款未通过
                    case 'send_order_refund_no_status':
                        $order = $data['orderInfo'];
                        $storeName = $orderInfoServices->getCarIdByProductTitle((int)$order['id']);
                        $storeTitle = substrUTf8($storeName, 20, 'UTF-8', '');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order['order_id'], 'pay_price' => $order['pay_price'], 'store_name' => $storeTitle]);
                        //短信
                        $NoticeSms->sendSms($order['user_phone'], ['order_id' => $order['order_id']], 'SEND_ORDER_REFUND_NO_STATUS');
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendOrderRefundNoStatus((int)$order['uid'], $order);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderRefundFail((int)$order['uid'], $order, $storeTitle);
                        break;
                    case 'order_writeoff':
                        $RoutineTemplateList->cikaSuccess($data);
                        break;
                    case 'yue_change':
                        $RoutineTemplateList->yueChange($data);
                        break;
                    //储值余额
                    case 'recharge_success':
                        $order = $data['order'];
                        $now_money = $data['now_money'];
                        //短信
                        $NoticeSms->sendSms($order['phone'], ['price' => $order['price'], 'now_money' => $now_money], 'recharge_success');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order['order_id'], 'price' => $order['price'], 'now_money' => $now_money]);
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendRechargeSuccess((int)$order['uid'], $order);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendRechargeSuccess((int)$order['uid'], $order, $now_money);
                        break;
                    //储值退款
                    case 'recharge_order_refund_status':
                        $datas = $data['data'];
                        $UserRecharge = $data['UserRecharge'];
                        //短信
                        $phone = $UserServices->value(['uid' => $UserRecharge['uid']], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['refund_price' => $datas['refund_price']], 'RECHARGE_ORDER_REFUND_STATUS');
                        }
                        //站内信
                        $SystemMsg->sendMsg($UserRecharge['uid'], ['refund_price' => $datas['refund_price'], 'order_id' => $UserRecharge['order_id'], 'price' => $UserRecharge['price']]);
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendRechargeRefundStatus((int)$UserRecharge['uid'], $datas, $UserRecharge);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendRechargeSuccess((int)$UserRecharge['uid'], $UserRecharge, $UserRecharge['now_money']);
                        break;
                    //积分
                    case 'integral_accout':
                        $order = $data['order'];
                        $order['gain_integral'] = $data['give_integral'];
                        $storeTitle = substrUTf8($data['storeTitle'], 20, 'UTF-8', '');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order['order_id'], 'store_name' => $storeTitle, 'pay_price' => $order['pay_price'], 'gain_integral' => $data['give_integral'], 'integral' => $data['integral']]);
                        //短信
                        $NoticeSms->sendSms($order['user_phone'], ['gain_integral' => $data['give_integral'], 'integral' => $data['integral']], 'integral_accout');
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendUserIntegral($order['uid'], $order, $data);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendUserIntegral($order['uid'], $data['order'], $storeTitle, $data['give_integral'], $data['integral']);
                        break;
                    //佣金
                    case 'order_brokerage':
                        $brokeragePrice = $data['brokeragePrice'];
                        $goodsName = substrUTf8($data['goodsName'], 20, 'UTF-8', '');
                        $goodsPrice = $data['goodsPrice'];
                        $add_time = $data['add_time'];
                        $spread_uid = $data['spread_uid'];
                        $phone = $data['phone'];
                        //站内信
                        $SystemMsg->sendMsg($spread_uid, ['goods_name' => $goodsName, 'goods_price' => $goodsPrice, 'brokerage_price' => $brokeragePrice]);
                        if ($phone) {
                            //短信
                            $NoticeSms->sendSms($phone, ['brokerage_price' => $brokeragePrice], 'order_brokerage');
                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderBrokerageSuccess($spread_uid, $brokeragePrice, $goodsName, $goodsPrice, $add_time);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendOrderBrokerageSuccess($spread_uid, $brokeragePrice, $goodsName);
                        break;
                    case 'revenue_received'://收益到账通知
                        $msg = '您有待收款金额，点击收款';
                        $link = '/pages/users/user_withdrawal/receiving?id=' . $data['order_id'] . '&type=' . $data['type'];
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendRevenueReceivedSuccess($data['uid'], $data['extract_price'], $msg, $link);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendRevenueReceived($data['uid'], $data['extract_price'], $msg, $link);
                        break;
                    //砍价成功
                    case 'bargain_success':
                        $uid = $data['uid'];
                        $bargainInfo = $data['bargainInfo'];
                        $bargainUserInfo = $data['bargainUserInfo'];
                        //站内信
                        $SystemMsg->sendMsg($uid, ['title' => substrUTf8($bargainInfo['title'], 20, 'UTF-8', ''), 'min_price' => $bargainInfo['min_price']]);
                        //短信
                        $phone = $UserServices->value(['uid' => $uid], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['title' => substrUTf8($bargainInfo['title'], 20, 'UTF-8', ''), 'min_price' => $bargainInfo['min_price']], 'BARGAIN_SUCCESS');
                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendBargainSuccess($uid, $bargainInfo, $bargainUserInfo, $uid);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendBargainSuccess($uid, $bargainInfo, $bargainUserInfo, $uid);
                        break;
                    //拼团成功
                    case 'order_user_groups_success':
                        $list = $data['list'];
                        $title = substrUTf8($data['title'], 20, 'UTF-8', '');
                        $nickname = $data['nickname'] ?? '';
                        $url = '/pages/goods/order_details/index?order_id=' . $list['order_id'];
                        //站内信
                        $SystemMsg->sendMsg($list['uid'], ['title' => $title, 'nickname' => $nickname, 'count' => $list['people'], 'pink_time' => date('Y-m-d H:i:s', $list['add_time'])]);
                        //短信
                        $phone = $UserServices->value(['uid' => $list['uid']], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['title' => $title, 'nickname' => $nickname], 'ORDER_USER_GROUPS_SUCCESS');
                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderPinkSuccess($list['uid'], $list, $title);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendPinkSuccess($list['uid'], $title, $nickname, $list['add_time'], $list['people'], $url);
                        break;
                    //取消拼团
                    case 'send_order_pink_clone':
                        $uid = $data['uid'];
                        $pink = $data['pink'];
                        $title = substrUTf8($pink['title'], 20, 'UTF-8', '');
                        //站内信
                        $SystemMsg->sendMsg($uid, ['title' => $title, 'count' => $pink->people]);
                        $phone = $UserServices->value(['uid' => $uid], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['title' => $title], 'SEND_ORDER_PINK_CLONE');
                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderPinkClone($uid, $pink, $pink->title);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendPinkFail($uid, $title, $pink->people, '亲，您的拼团取消，点击查看订单详情', '/pages/goods/order_details/index?order_id=' . $pink->order_id);
                        break;
                    //拼团失败
                    case 'send_order_pink_fial':
                        $uid = $data['uid'];
                        $pink = $data['pink'];
                        $title = substrUTf8($pink['title'], 20, 'UTF-8', '');
                        //站内信
                        $SystemMsg->sendMsg($uid, ['title' => $title, 'count' => $pink->people]);
                        $phone = $UserServices->value(['uid' => $uid], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['title' => $title], 'SEND_ORDER_PINK_FIAL');
                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderPinkFial($uid, $pink, $pink->title);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendPinkFail($uid, $title, $pink->people, '亲，您拼团失败，自动为您申请退款，退款金额为：' . $pink->price, '/pages/goods/order_details/index?order_id=' . $pink->order_id);
                        break;
                    //参团成功
                    case 'can_pink_success':
                        $orderInfo = $data['orderInfo'];
                        $title = substrUTf8($data['title'], 20, 'UTF-8', '');
                        $pink = $data['pink'];
                        $nickname = $UserServices->value(['uid' => $orderInfo['uid']], 'nickname');
                        //站内信
                        $SystemMsg->sendMsg($orderInfo['uid'], ['title' => $title, 'nickname' => $nickname, 'count' => $pink['people'], 'pink_time' => date('Y-m-d H:i:s', $pink['add_time'])]);
                        //短信
                        $NoticeSms->sendSms($orderInfo['user_phone'], ['order_id' => $orderInfo['order_id'], 'title' => $title], 'CAN_PINK_SUCCESS');
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderPinkUseSuccess($orderInfo['uid'], $orderInfo, $title);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendPinkSuccess($orderInfo['uid'], $title, $nickname, $pink['add_time'], $pink['people'], '/pages/goods/order_details/index?order_id=' . $pink['order_id']);
                        break;
                    //开团成功
                    case 'open_pink_success':
                        $orderInfo = $data['orderInfo'];
                        $title = substrUTf8($data['title'], 20, 'UTF-8', '');
                        $pink = $data['pink'];
                        $nickname = $UserServices->value(['uid' => $orderInfo['uid']], 'nickname');
                        //站内信
                        $SystemMsg->sendMsg($orderInfo['uid'], ['title' => $title, 'nickname' => $nickname, 'count' => $pink['people'], 'pink_time' => date('Y-m-d H:i:s', $pink['add_time'])]);
                        //短信
                        $NoticeSms->sendSms($orderInfo['user_phone'], ['title' => $title], 'OPEN_PINK_SUCCESS');
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendOrderPinkOpenSuccess($orderInfo['uid'], $pink, $title);
                        //模板消息小程序订阅消息
                        //$RoutineTemplateList->sendPinkSuccess($orderInfo['uid'], $title, $nickname, $pink['add_time'], $pink['people'], '/pages/goods/order_details/index?order_id=' . $pink['order_id']);
                        $RoutineTemplateList->sendPinkFail($orderInfo['uid'], $title, $pink['people'], '亲，您已开团成功，可以查看详情邀请好友一起参与拼团', '/pages/goods/order_details/index?order_id=' . $pink['order_id']);
                        break;
                    //提现成功
                    case 'user_extract':
                        $extractNumber = $data['extractNumber'];
                        $nickname = $data['nickname'];
                        $uid = (int)$data['uid'];
                        //站内信
                        $SystemMsg->sendMsg($uid, ['extract_number' => $extractNumber, 'nickname' => $nickname, 'date' => date('Y-m-d H:i:s', time())]);
                        //短信
                        $phone = $UserServices->value(['uid' => $uid], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['extract_number' => $extractNumber], 'USER_EXTRACT');
                        }
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendUserExtract($uid, (string)$extractNumber);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendExtractSuccess($uid, $extractNumber, $nickname);
                        break;
                    //提现失败
                    case 'user_balance_change':
                        $extract_number = $data['extract_number'];
                        $message = $data['message'];
                        $uid = $data['uid'];
                        $nickname = $data['nickname'];
                        //站内信
                        $SystemMsg->sendMsg($uid, ['extract_number' => $extract_number, 'nickname' => $nickname, 'date' => date('Y-m-d H:i:s', time()), 'message' => $message]);
                        //短信
                        $phone = $UserServices->value(['uid' => $uid], 'phone');
                        if ($phone) {
                            $NoticeSms->sendSms($phone, ['extract_number' => $extract_number], 'user_balance_change');
                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendExtractFail($uid, $extract_number, $message);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendExtractFail($uid, $message, $extract_number, $nickname);
                        break;
                    //提醒付款给用户
                    case 'order_pay_false':
                        $order = $data['order'];
                        $order_id = $order['order_id'];
                        $order['storeName'] = $orderInfoServices->getCarIdByProductTitle((int)$order['id']);
                        //短信
                        $NoticeSms->sendSms($order['user_phone'], ['order_id' => $order_id], 'ORDER_PAY_FALSE');
                        //站内信
                        $SystemMsg->sendMsg($order['uid'], ['order_id' => $order_id]);
                        break;
                    //申请退款给客服发消息
                    case 'send_order_apply_refund':
                        $order = $data['order'];
                        $store_id = 0;
                        //给门店店员发送消息
                        if ($order['store_id'] != 0 && $order['shipping_type'] != 4) {
                            $store_id = $order['store_id'];
                        }
                        $order['storeName'] = $orderInfoServices->getCarIdByProductTitle((int)$order['id']);
                        //站内信
                        $SystemMsg->kefuSystemSend(['order_id' => $order['order_id']], $store_id);
                        //短信
                        $NoticeSms->sendAdminRefund($order, $store_id);
                        //公众号
//                        $WechatTemplateList->sendAdminNewRefund($order, $store_id);
                        //企业微信通知
                        $NoticeService->EnterpriseWechatSend(['order_id' => $order['order_id']]);
                        break;
                    //新订单给客服
                    case 'admin_pay_success_code':
                        $order = $data;
                        $store_id = 0;
                        //给门店店员发送消息
                        if ($order['store_id'] != 0 && $order['shipping_type'] != 4) {
                            $store_id = $order['store_id'];
                        }
                        if (isset($order['member_type'])) {//付费会员订单
                            $order['storeName'] = '付费会员SVIP';
                        } else {
                            $order['storeName'] = $orderInfoServices->getCarIdByProductTitle((int)$order['id']);
                        }
                        //站内信
                        $SystemMsg->kefuSystemSend(['order_id' => $order['order_id']], $store_id);
                        //短信
                        $NoticeSms->sendAdminPaySuccess($order, $store_id);
                        //公众号
//                        $WechatTemplateList->sendAdminNewOrder($order, $store_id);
                        //企业微信通知
                        $NoticeService->EnterpriseWechatSend(['order_id' => $order['order_id']]);
                        break;
                    //提现申请给客服
                    case 'kefu_send_extract_application':
                        //站内信
                        $SystemMsg->kefuSystemSend($data);
                        //企业微信通知
                        $NoticeService->EnterpriseWechatSend($data);
                        break;
                    //确认收货给客服
                    case 'send_admin_confirm_take_over':
                        $order = $data['order'];
                        $storeTitle = $data['storeTitle'];
                        //站内信
                        $SystemMsg->kefuSystemSend(['storeTitle' => $storeTitle, 'order_id' => $order['order_id']]);
                        //短信
                        $NoticeSms->sendAdminConfirmTakeOver($order);
                        //企业微信通知
                        $NoticeService->EnterpriseWechatSend(['store_title' => $storeTitle, 'order_id' => $order['order_id']]);
                        break;
                    //异地登录通知
                    case 'login_city_error':
                        $phone = $data['phone'];
                        unset($data['phone']);
                        $NoticeSms->sendSms($phone, $data, 'LOGIN_CITY_ERROR');
                        break;
                    //虚拟商品发货通知
                    case 'kami_deliver_goods_code':
                        $order_id = $data['order_id'];
                        $siteUrl = sys_config('site_url');
                        $url = ' ' . $siteUrl . ' ';
                        $value = $data['value'];
                        $phone = $UserServices->value(['uid' => $data['uid']], 'phone');
                        if ($phone) {
                            //短信
                            $NoticeSms->sendSms($phone, ['order_id' => $order_id, 'value' => $value, 'url' => $url], 'KAMI_DELIVER_GOODS_CODE');
                        }
//                        if ($data['is_integral']) {
//                            $templateUrl = $siteUrl . '/pages/points_mall/integral_order_details?order_id=' . $order_id;
//                        } else {
//                            $templateUrl = $siteUrl . '/pages/goods/order_details/index?order_id=' . $order_id;
//                        }
                        //模板消息公众号模版消息
//                        $WechatTemplateList->sendKamiDeliverGoods($data['uid'], $value, $templateUrl);
                        //发送站内信
						$SystemMsg->sendMsg($data['uid'], [
                            'mark' => 'virtual_info',
                            'title' => $data['title'],
                            'content' => $data['content']
                        ]);
                        break;
                    //核销成功提醒 次卡
                    case 'reminder_verification_status':
                        $store_name = $data['store_name'];
                        $phone = $data['phone'];
                        $write_time = date('Y-m-d H:i', time());
                        if ($phone) {
                            //短信
                            $NoticeSms->sendSms($phone, ['store_name' => $store_name, 'write_time' => $write_time], 'REMINDER_VERIFICATION_STATUS');
                        }
                        $SystemMsg->sendMsg((int)$data['uid'], ['store_name' => $store_name, 'write_time' => $write_time]);
                        break;
                    //过期提醒 次卡
                    case 'expiration_reminder':
                        $store_name = $data['store_name'];
                        $phone = $data['phone'];
                        $end_time = $data['end_time'];
                        if ($phone) {
                            //短信
                            $NoticeSms->sendSms($phone, ['store_name' => $store_name, 'end_time' => $end_time], 'EXPIRATION_REMINDER');
                        }
                        $SystemMsg->sendMsg((int)$data['uid'], ['store_name' => $store_name, 'end_time' => $end_time]);
                        break;
                    //临期提醒 次卡
                    case 'reminder_brink_death':
                        $store_name = $data['store_name'];
                        $phone = $data['phone'];
                        $pay_time = $data['pay_time'];
                        $end_time = $data['end_time'];
                        if ($phone) {
                            //短信
                            $NoticeSms->sendSms($phone, ['store_name' => $store_name, 'pay_time' => $pay_time, 'end_time' => $end_time], 'REMINDER_BRINK_DEATH');
                        }
                        $SystemMsg->sendMsg((int)$data['uid'], ['store_name' => $store_name, 'pay_time' => $pay_time, 'end_time' => $end_time]);
                        break;
                    //供应商入驻审核通过
                    case 'supplier_verify_success':
						$uid = $data['uid'] ?? 0;
                        $supplier = $data['system_name'] ?? '';
                        $phone = $data['account'] ?? '';
						$account = $data['account'] ?? $phone;
                        $date = isset($data['add_time']) && $data['add_time'] ? date('Y-m-d H:i', $data['add_time']) : '';
                        $site_name = sys_config('site_name');
                        if ($phone) {
                            $pwd = substr($phone, -6);
                            //短信
                            $NoticeSms->sendSms($phone, ['date' => $date, 'supplier' => $supplier, 'phone' => $account, 'pwd' => $pwd, 'site_name' => $site_name], 'SUPPLIER_VERIFY_SUCCESS');
							//站内信
							if ($uid) $SystemMsg->sendMsg((int)$uid, ['date' => $date, 'supplier' => $supplier, 'phone' => $account, 'pwd' => $pwd, 'site_name' => $site_name]);
                        }
                        break;
                    //供应商入驻审核未通过
                    case 'supplier_verify_fail':
						$uid = $data['uid'] ?? 0;
                        $supplier = $data['system_name'] ?? '';
                        $phone = $data['phone'] ?? '';
                        $date = isset($data['add_time']) && $data['add_time'] ? date('Y-m-d H:i', $data['add_time']) : '';
                        $site_name = sys_config('site_name');
                        if ($phone) {
                            //短信
                            $NoticeSms->sendSms($phone, ['date' => $date, 'supplier' => $supplier, 'site_name' => $site_name], 'SUPPLIER_VERIFY_FAIL');
                        }
						if ($uid) $SystemMsg->sendMsg((int)$uid, ['date' => $date, 'supplier' => $supplier, 'site_name' => $site_name]);
                        break;
                    //用户签到提醒
                    case 'sign_remind_time':
                        $site_name = sys_config('site_name');
                        if ($data['phone']) {
                            //短信
                            $NoticeSms->sendSms($data['phone'], ['site_name' => $site_name], 'SIGN_REMIND_TIME');
                        }
                        //站内信
                        $SystemMsg->sendMsg($data['uid'], ['site_name' => $site_name]);
                        //模板消息小程序订阅消息
                        $RoutineTemplateList->sendSignRemind($data['uid']);
						break;
					//加盟店入驻审核通过
					case 'store_verify_success':
						$uid = $data['uid'] ?? 0;
						$store = $data['system_name'] ?? '';
						$phone = $data['phone'] ?? '';
						$account = $data['account'] ?? $phone;
						$date = isset($data['add_time']) && $data['add_time'] ? date('Y-m-d H:i', $data['add_time']) : '';
						$site_name = sys_config('site_name');
						if ($phone) {
							$pwd = substr($phone, -6);
							//短信
							$NoticeSms->sendSms($phone, ['date' => $date, 'store' => $store, 'phone' => $account, 'pwd' => $pwd, 'site_name' => $site_name], 'STORE_VERIFY_SUCCESS');
							//站内信
							if ($uid) $SystemMsg->sendMsg((int)$uid, ['date' => $date, 'store' => $store, 'phone' => $account, 'pwd' => $pwd, 'site_name' => $site_name]);
						}
						break;
					//加盟店入驻审核未通过
					case 'store_verify_fail':
						$uid = $data['uid'] ?? 0;
						$store = $data['system_name'] ?? '';
						$phone = $data['phone'] ?? '';
						$date = isset($data['add_time']) && $data['add_time'] ? date('Y-m-d H:i', $data['add_time']) : '';
						$site_name = sys_config('site_name');
						if ($phone) {
							//短信
							$NoticeSms->sendSms($phone, ['date' => $date, 'store' => $store, 'site_name' => $site_name], 'STORE_VERIFY_FAIL');
							//站内信
							if ($uid) $SystemMsg->sendMsg((int)$uid, ['date' => $date, 'store' => $store, 'site_name' => $site_name]);
						}
						break;
                    //配送提醒通知
					case 'delivery_reminder':
                        $orderInfo = $data['orderInfo'];
                        $store_name = $data['storeName'];
                        $datas = $data['data'];
                        $dat['order_id'] = $orderInfo['order_id'];
                        $dat['name'] = substrUTf8($store_name, 30, 'UTF-8', '');
                        $dat['sum'] = $orderInfo['total_num'];
                        $dat['address'] = substrUTf8($orderInfo['user_address'], 30, 'UTF-8', '');
						$uid = $datas['delivery_uid'] ?? 0;
						$phone = $datas['delivery_id'] ?? '';
						if ($phone) {
							//短信
							$NoticeSms->sendSms($phone, $dat, 'DELIVERY_REMINDER');
							//站内信
							if ($uid) $SystemMsg->sendMsg((int)$uid, $dat);
						}
                        //模板消息公众号模版消息
                        $WechatTemplateList->sendDeliveryReminder((int)$uid, $dat);
						break;
					//待服务提醒
					case 'reservation_service_reminder':
						$phone = $data['phone'] ?? '';
						$uid = $data['uid'] ?? 0;
						$data['name'] = substrUTf8($data['store_name'], 30, 'UTF-8', '');
						$data['$data'] = substrUTf8($data['reservation_address'], 30, 'UTF-8', '');
						//短信
						$NoticeSms->sendSms($phone, $data, 'RESERVATION_SERVICE_REMINDER');
						//站内信
						$SystemMsg->sendMsg((int)$uid, $data);
						//公众号模版消息
						$WechatTemplateList->sendReservationServiceReminder((int)$uid, $data);
						//小程序订阅消息
						$RoutineTemplateList->sendReservationServiceReminder((int)$uid, $data);
						break;
					case 'yuyue_success':
						if (!empty($data['uid'])) {
							$msg = $data['notice'] ?? [];
							$RoutineTemplateList->sendYuyueSuccess((int)$data['uid'], $msg);
						}
						break;
					case 'yuyue_refuse':
						if (!empty($data['uid'])) {
							$msg = $data['notice'] ?? [];
							$RoutineTemplateList->sendYuyueRefuse((int)$data['uid'], $msg);
							if (!empty($data['technician_uid'])) {
								$RoutineTemplateList->sendYuyueRefuse((int)$data['technician_uid'], $msg);
							}
						}
						break;
					case 'yuyue_customer':
						if (!empty($data['master_uid'])) {
							$msg = $data['notice'] ?? [];
							$RoutineTemplateList->sendYuyueCustomer((int)$data['master_uid'], $msg);
						}
						break;
					case 'yuyue_jindu':
						if (!empty($data['master_uid'])) {
							$msg = $data['notice'] ?? [];
							$RoutineTemplateList->sendYuyueJindu((int)$data['master_uid'], $msg);
						}
						break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('错误' . $e->getMessage());
        }
    }
}
