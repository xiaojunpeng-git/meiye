import request from '@/utils/request.js';

/** 商家入口权限与身份上下文 */
export function merchantAccess(data) {
	return request.get('merchant/access', data || {});
}

/** 切换当前商家身份/门店 */
export function merchantContextSwitch(data) {
	return request.post('merchant/context/switch', data || {});
}

export function merchantHome(data) {
	return request.get('merchant/home', data || {});
}

export function merchantCustomerSegments(data) {
	return request.get('merchant/customer/segments', data || {});
}

export function merchantCustomerMineSummary(data) {
	return request.get('merchant/customer/mine/summary', data || {});
}

export function merchantCustomerCreate(data) {
	return request.post('merchant/customer/create', data || {});
}

export function merchantCustomerList(data) {
	return request.get('merchant/customer/list', data || {});
}

export function merchantCustomerDetail(uid, data) {
	return request.get(`merchant/customer/detail/${uid}`, data || {});
}

export function merchantCustomerOrders(data) {
	return request.get('merchant/customer/orders', data || {});
}

export function merchantCustomerUpdate(data) {
	return request.post('merchant/customer/update', data || {});
}

export function merchantDataBusiness(data) {
	return request.get('merchant/data/business', data || {});
}

export function merchantDataCustomer(data) {
	return request.get('merchant/data/customer', data || {});
}

export function merchantDataStaffStats(data) {
	return request.get('merchant/data/staff/statistics', data || {});
}

/** 本人业绩概览（服务端解析 staff_id，勿传 staff_id） */
export function merchantYejiSelf(data) {
	return request.get('merchant/yeji/self', data || {});
}

/** 本人业绩明细（服务端强制本人，传 staff_id 会被拒绝） */
export function merchantYejiSelfDetail(data) {
	return request.get('merchant/yeji/self/detail', data || {});
}

export function merchantDebtList(data) {
	return request.get('merchant/debt/list', data || {});
}
