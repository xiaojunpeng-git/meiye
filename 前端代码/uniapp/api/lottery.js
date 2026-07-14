// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import request from "@/utils/request.js";

/**
 * 获取抽奖详情信息
 * 
 */
export function getLotteryData(type,id) {
	return request.get(`v2/lottery/info/${type}/${id}`,{},{
		noAuth: true
	});
}

/**
 * 参与抽奖
 * 
 */
export function startLottery(data) {
	return request.post(`v2/lottery`, data);
}

/**
 * 领奖
 * 
 */
export function receiveLottery(data) {
	return request.post(`v2/lottery/receive`, data);
}

/**
 * 获取中奖记录
 * 
 */
export function getLotteryList(data) {
	return request.get(`v2/lottery/record`, data);
}

/**
 * 活动使用id
 */
export function lotteryUseApi() {
	return request.get('v2/lottery/use');
}
