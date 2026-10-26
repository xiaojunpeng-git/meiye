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
    //默认短信发送模式
    'default' => 'yihaotong',
    //单个手机每日发送上限
    'maxPhoneCount' => 10,
    //验证码每分钟发送上线
    'maxMinuteCount' => 20,
    //单个IP每日发送上限
    'maxIpCount' => 50,
    //驱动模式
    'stores' => [
        //一号通
        'yihaotong' => [
            //短信模板id
            'template_id' => [
                //验证码自定义时效
                'VERIFICATION_CODE_TIME' => 538393,
                //验证码
                'VERIFICATION_CODE' => 518076,
                //支付成功
                'PAY_SUCCESS_CODE' => 520268,
                //发货提醒
                'DELIVER_GOODS_CODE' => 520269,
                //卡密发货提醒
                'KAMI_DELIVER_GOODS_CODE' => 849210,
                //确认收货提醒
                'TAKE_DELIVERY_CODE' => 520271,
                //管理员下单提醒
                'ADMIN_PLACE_ORDER_CODE' => 520272,
                //管理员退货提醒
                'ADMIN_RETURN_GOODS_CODE' => 520274,
                //管理员支付成功提醒
                'ADMIN_PAY_SUCCESS_CODE' => 520273,
                //管理员确认收货
                'ADMIN_TAKE_DELIVERY_CODE' => 520422,
                //改价提醒
                'PRICE_REVISION_CODE' => 528288,
                //订单未支付
                'ORDER_PAY_FALSE' => 528116,
                //异地登录通知
                'LOGIN_CITY_ERROR' => 687193,
                //储值余额
                'RECHARGE_SUCCESS' => 811355,
                //储值退款
                'RECHARGE_ORDER_REFUND_STATUS' => 811356,
                //核销成功提醒 次卡
                'REMINDER_VERIFICATION_STATUS' => 947454,
                //过期提醒 次卡
                'EXPIRATION_REMINDER' => 947455,
                //临期提醒 次卡
                'REMINDER_BRINK_DEATH' => 947456,

                //退款成功通知
                'ORDER_REFUND_STATUS'=> 979010,
                //积分到账通知
                'INTEGRAL_ACCOUT' => 979009,
                //佣金到账
                'ORDER_BROKERAGE' => 979008,
                //砍价成功
                'BARGAIN_SUCCESS' => 979007,
                //拼团成功通知
                'ORDER_USER_GROUPS_SUCCESS' => 979006,
                //开团成功
                'OPEN_PINK_SUCCESS' => 979005,
                //提现成功通知
                'USER_EXTRACT' => 979003,
                //提现失败通知
                'USER_EXTRACT_FAIL' => 979002,
                //退款申请未通过通知
                'SEND_ORDER_REFUND_NO_STATUS' => 979001,
                //取消拼团提醒
                'SEND_ORDER_PINK_CLONE' => 979000,
                //参团成功提醒
                'CAN_PINK_SUCCESS' => 978999,
                //拼团失败通知
                'SEND_ORDER_PINK_FIAL' => 979431,
				//供应商入驻审核通过通知
				'SUPPLIER_VERIFY_SUCCESS' => 984128,
				//供应商入驻审核未通过通知
				'SUPPLIER_VERIFY_FAIL' => 984127,
                //用户签到通知
                'SIGN_REMIND_TIME' => 980181,
				//加盟店入驻审核通过通知
				'STORE_VERIFY_SUCCESS' => 1019953512,
				//加盟店入驻审核未通过通知
				'STORE_VERIFY_FAIL' => 1019953502,
                //配送提醒通知
                'DELIVERY_REMINDER' => 1020349588,
                //管理员下单提醒
                'ADMIN_ORDER_UID' => 1866853,
            ],
            'sms_account' => '',
            'sms_token' => ''
        ],
        //阿里云
        'aliyun' => [
            'template_id' => [
                //验证码
                'VERIFICATION_CODE' => '',
                //支付成功
                'PAY_SUCCESS_CODE' => '',
                //发货提醒
                'DELIVER_GOODS_CODE' => '',
                //确认收货提醒
                'TAKE_DELIVERY_CODE' => '',
                //管理员下单提醒
                'ADMIN_PLACE_ORDER_CODE' => '',
                //管理员退货提醒
                'ADMIN_RETURN_GOODS_CODE' => '',
                //管理员支付成功提醒
                'ADMIN_PAY_SUCCESS_CODE' => '',
                //管理员确认收货
                'ADMIN_TAKE_DELIVERY_CODE' => '',
                //改价提醒
                'PRICE_REVISION_CODE' => '',
                //订单未支付
                'ORDER_PAY_FALSE' => '',
            ],
            'aliyun_SignName' => '',
            'aliyun_AccessKeyId' => '',
            'aliyun_AccessKeySecret' => '',
            'aliyun_RegionId' => '',
        ]
    ]
];
