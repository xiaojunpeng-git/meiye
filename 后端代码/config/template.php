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

return [
    //默认驱动模式
    'default' => 'wechat',
    //记录发送日志
    'isLog' => true,
    //驱动模式
    'stores' => [
        //微信
        'wechat' => [
            //短信模板id
            'template_id' => [
                //支付成功
//                'ORDER_PAY_SUCCESS' => 'OPENTM418062102',
                'ORDER_PAY_SUCCESS' =>  43216,
                //订单发货提醒(送货)
//                'ORDER_DELIVER_SUCCESS' => 'OPENTM416122303',
                //订单发货提醒(快递)
//                'ORDER_POSTAGE_SUCCESS' => 'OPENTM415939287',
                'ORDER_POSTAGE_SUCCESS' => 42984,
                //订单收货通知
//                'ORDER_TAKE_SUCCESS' => 'OPENTM418528119',
                'ORDER_TAKE_SUCCESS' =>  50439,
                //退款成功通知
//                'ORDER_REFUND_STATUS'=>'OPENTM207284059',
                'ORDER_REFUND_STATUS'=> 48058,
                //拒绝退款通知
                'ORDER_REFUND_FAIL'=> 46232,
                //储值成功通知
//                'RECHARGE_SUCCESS' => 'OPENTM414089457',
                'RECHARGE_SUCCESS' =>  42934,
                //提现成功通知
//                'USER_EXTRACT' => 'OPENTM405876306',
                'USER_EXTRACT' => 51729,
				//收益到账通知
				'REVENUE_RECEIVED' => 54531,

                //配送提醒通知
				'DELIVERY_REMINDER' => 55459,
                //积分到账通知
//                'INTEGRAL_ACCOUT' => 'OPENTM201661503',
                //佣金到账
//                'ORDER_BROKERAGE' => 'OPENTM400590844',
                //砍价成功
//                'BARGAIN_SUCCESS' => 'OPENTM418554923',
                //拼团成功通知,参团成功
//                'ORDER_USER_GROUPS_SUCCESS' => 'OPENTM409367318',
                //取消拼团,拼团失败
//                'ORDER_USER_GROUPS_LOSE'=>'OPENTM418350969',
                //开团成功
//                'OPEN_PINK_SUCCESS' => 'OPENTM410867947',
                //提现失败通知
//                'USER_EXTRACT_FAIL' => 'OPENTM403167119',
                //服务进度提醒
//                'ADMIN_NOTICE' => 'OPENTM415269411',
                //卡密发货提醒
//                'KAMI_DELIVER_GOODS_CODE' => 'OPENTM414876266',

//                //订单生成通知
//                'ORDER_CREATE' => 'OPENTM205213550',
//                //退款进度通知
//                'ORDER_REFUND' => 'OPENTM410119152',
//                //拼团失败通知
//                'SEND_ORDER_PINK_FIAL' => 'OPENTM401113750',
//                //储值退款通知
//                'RECHARGE_ORDER_REFUND_STATUS' => '',
//                //退款申请未通过通知
//                'SEND_ORDER_REFUND_NO_STATUS' => '',
//                //取消拼团提醒
//                'SEND_ORDER_PINK_CLONE' => '',
//                //参团成功提醒
//                'CAN_PINK_SUCCESS' => '',
//                //客服通知提醒
//                'SERVICE_NOTICE' => 'OPENTM204431262',

            ],
        ],
        //订阅消息
        'subscribe' => [
            'template_id' => [
                //绑定推广关系
                'BIND_SPREAD_UID' => 3801,
                //订单支付成功
                'ORDER_PAY_SUCCESS' => 1927,
                //订单发货提醒(快递)
                'ORDER_DELIVER_SUCCESS' => 1458,
                //订单发货提醒(送货)
                'ORDER_POSTAGE_SUCCESS' => 1128,
                //确认收货通知
                'ORDER_TAKE' => 1481,
                //退款通知
                'ORDER_REFUND' => 1451,
                //储值成功
                'RECHARGE_SUCCESS' => 755,
                //积分到账提醒
                'INTEGRAL_ACCOUT' => 335,
                //佣金到账
                'ORDER_BROKERAGE' => 14403,
                //砍价成功
                'BARGAIN_SUCCESS' => 2727,
                //拼团成功
                'PINK_TRUE' => 3098,
                //拼团状态通知
                'PINK_STATUS' => 3353,
                //提现成功通知
                'USER_EXTRACT' => 1470,
                //签到提醒通知
                'SIGN_REMIND_TIME' => 25599,
				//收益到账通知
				'REVENUE_RECEIVED' => 1493,
                //新的充值
                'NEW_RECHARGE_SUCCESS' => 1494,
                //次卡变动通知
                'CIKA_CHUANGE' => 1495,
                //余额变动通知
                'YUE_CHANGE' => 1496,
                //预约：管家接单成功（客户+老师）
                'YUYUE_SUCCESS' => 5378,
                //预约：管家拒绝
                'YUYUE_REFUSE' => 4954,
                //预约：客户下单通知管家
                'YUYUE_CUSTOMER' => 27286,
                //预约：服务进度（快结束）
                'YUYUE_JINDU' => 22985,
                //待服务提醒
                'RESERVATION_SERVICE_REMINDER' => 18205,

//                //订单取消
//                'ORDER_CLONE' => 1134,
//                //核销成功通知
//                'ORDER_WRITE_OFF' => 3116,
//                //新订单提醒
//                'ORDER_NEW' => 1476,
//                //申请退款通知 管理员提醒
//                'ORDER_REFUND_STATUS' => 1468,
            ],
        ],
    ]
];
