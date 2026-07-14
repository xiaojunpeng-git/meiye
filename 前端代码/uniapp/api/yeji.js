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

export function reportDetail(data) {
	return request.get("agent/home/report", data);
}

export function agentYejiRanking(data) {
	return request.get('agent/home/yejiRanking', data);
}

export function agentProjectRanking(data) {
	return request.get('agent/home/projectRanking', data);
}

export function agentDianke(data) {
	return request.get('agent/home/dianke', data);
}

export function yejiRanking(data) {
	return request.get('store/staff/yejiRanking', data);
}

export function orderData(data) {
	return request.get('store/staff/orderData', data);
}
export function dianke(data) {
	return request.get('store/staff/dianke', data);
}
export function detailYeji(data) {
	return request.get('store/staff/detailYeji', data);
}
export function yejiInfo(data) {
	return request.get('store/staff/yejiInfo', data);
}
export function salary(data) {
	return request.get('store/staff/salary', data);
}
export function getAgentHeader(data) {
	return request.get('store/staff/homeStatics', data);
}
