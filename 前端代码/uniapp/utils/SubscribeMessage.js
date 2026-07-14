// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import {
	SUBSCRIBE_MESSAGE
} from '../config/cache.js';

export function auth() {
	let tmplIds = {};
	let messageTmplIds = uni.getStorageSync(SUBSCRIBE_MESSAGE);
	tmplIds = messageTmplIds ? JSON.parse(messageTmplIds) : {};
	return tmplIds;
}

/**
 * 预约消息提醒（客户预约 / 接单结果）
 */
export function openYuyueSubscribe() {
	return subscribe([
		'O_JpZKMwhDDQc_Ng0oXJAmfgCWDbLGjHuiYCtpPJN4w',
		'dbmg-zck-LupxVMj4jZhKl8DUen_QG5oL0Gcein5f30',
		'Ii46zFR21t8SXNXPC50y7KuBwL39EvzOtQ_GtwHyRKg'
	]);
}

/**
 * 管家接单订阅
 */
export function openGuanjiaSubscribe() {
	return subscribe([
		'HXvetRlNVyt-1TjVUEuScZOUGqqoUgRPvUCvTzxv5WI',
		'dbmg-zck-LupxVMj4jZhKl8DUen_QG5oL0Gcein5f30'
	]);
}

function navigateByType(url, type = 'navigateTo') {
	if (type === 'redirectTo') {
		uni.redirectTo({ url });
	} else if (type === 'switchTab') {
		uni.switchTab({ url });
	} else {
		uni.navigateTo({ url });
	}
}

/**
 * 跳转前请求客户/老师预约订阅（须在用户点击事件中调用）
 */
export function goWithYuyueSubscribe(url, type = 'navigateTo') {
	// #ifdef MP
	return openYuyueSubscribe().finally(() => navigateByType(url, type));
	// #endif
	// #ifndef MP
	navigateByType(url, type);
	return Promise.resolve();
	// #endif
}

/**
 * 跳转前请求管家预约订阅（须在用户点击事件中调用）
 */
export function goWithGuanjiaSubscribe(url, type = 'navigateTo') {
	// #ifdef MP
	return openGuanjiaSubscribe().finally(() => navigateByType(url, type));
	// #endif
	// #ifndef MP
	navigateByType(url, type);
	return Promise.resolve();
	// #endif
}

/**
 * 支付成功后订阅消息id
 * 订阅  确认收货通知 订单支付成功  新订单管理员提醒
 */
export function openPaySubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.order_pay_success,
		tmplIds.order_deliver_success,
		tmplIds.order_postage_success,
	]);
}

/**
 *  充值通知、消费通知、退款通知
 */
export function openSelfSubscribe() {
	return subscribe([
		'nsLLYSlNv5fydo0rgNMtmHPv6oFLrvoJPW3bEt-O_zw',
		'wbK4H_hUdLeXlpdEMpAcjUtzlamWrXc3B9JNSY3gIcU',
		'79U-cWBDS9wueyn-Ccj1FyomQpJnwYs7W7dFWHDuXsc'
	]);
}
/**
 * 收益消息订阅
 * 成功 和 失败 消息
 */
export function openReceivedSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.revenue_received
	]);
}

/**
 * 订单相关订阅消息
 * 送货 发货 取消订单
 */
export function openOrderSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.order_take,
		tmplIds.integral_accout,
		tmplIds.order_brokerage
	]);
}

/**
 * 提现消息订阅
 * 成功 和 失败 消息
 */
export function openExtrctSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.user_extract,
		tmplIds.revenue_received
	]);
}

/**
 * 拼团成功
 */
export function openPinkSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.pink_true,
		tmplIds.pink_status
	]);
}

/**
 * 砍价成功
 */
export function openBargainSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.bargain_success
	]);
}

/**
 * 订单退款
 */
export function openOrderRefundSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.order_refund
	]);
}

/**
 * 充值成功
 */
export function openRechargeSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.recharge_success
	]);
}

/**
 * 签到订阅
 */
export function openSignSubscribe() {
	let tmplIds = auth();
	return subscribe([
		tmplIds.sign_remind_time
	]);
}

/**
 * 调起订阅界面
 * array tmplIds 模板id
 */
export function subscribe(subscrip443tionmessagee502call) {
	 let weChat = wx;
	return new Promise((reslove, reject) => {
		weChat.requestSubscribeMessage({
			tmplIds: subscrip443tionmessagee502call,
			success(res) {
				return reslove(res);
			},
			fail(res) {
				return reslove(res);
			}
		})
	});
}
