import { merchantRequest as request } from '@/utils/request.js';
import { HTTP_REQUEST_URL, HEADER } from '@/config/app';

/** 员工档案账号登录。该令牌只用于商家端，不写入会员登录态。 */
export function merchantEmployeeLogin(data) {
	return new Promise((resolve, reject) => {
		uni.request({
			url: HTTP_REQUEST_URL + '/api/merchant/login',
			method: 'POST',
			header: Object.assign({}, HEADER),
			data: data || {},
			success: (res) => {
				if (res.data && res.data.status === 200) resolve(res.data);
				else reject((res && res.data) || { msg: '员工账号登录失败' });
			},
			fail: () => reject({ msg: '员工账号登录请求失败' }),
		});
	});
}

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

export function merchantTrainingDocuments(data) {
	return request.get('merchant/training/document/list', data || {});
}

/** 受控下载：返回文件流；前端用带 token 的 downloadFile 拉取 */
export function merchantTrainingDocumentDownloadUrl(id, data) {
	const q = data || {};
	const parts = [];
	Object.keys(q).forEach((k) => {
		if (q[k] === undefined || q[k] === null || q[k] === '') return;
		parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(q[k])}`);
	});
	const qs = parts.length ? `?${parts.join('&')}` : '';
	return `merchant/training/document/download/${id}${qs}`;
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

/** 共享客情服务的手机端只读任务工作台。 */
export function merchantCustomerCareWorkbench(data) {
	return request.get('merchant/customer-care/workbench', data || {});
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

/** 店级现金业绩明细（与 homeStatics 现金项同口径） */
export function merchantMetricCashDetail(data) {
	return request.get('merchant/metric/cash/detail', data || {});
}

/** 店级实收业绩明细（逐店 max(0,现金−分成) 再求和，与 homeStatics 同口径） */
export function merchantMetricActualDetail(data) {
	return request.get('merchant/metric/actual/detail', data || {});
}

/** 店级消耗金额明细（activeYeji + 旧店耗卡） */
export function merchantMetricConsumeDetail(data) {
	return request.get('merchant/metric/consume/detail', data || {});
}

export function merchantDebtList(data) {
	return request.get('merchant/debt/list', data || {});
}

/** 商家预约列表（active_store_id + scope；员工仅本人） */
export function merchantReservationList(data) {
	return request.get('merchant/reservation/list', data || {});
}

/** 商家预约状态统计（与 list 同范围） */
export function merchantReservationStatistics(data) {
	return request.get('merchant/reservation/statistics', data || {});
}

/** 商家预约详情（scope + 当前店 + 员工本人校验） */
export function merchantReservationDetail(id, data) {
	return request.get(`merchant/reservation/detail/${id}`, data || {});
}

/** 商家预约房间列表 */
export function merchantReservationTables(data) {
	return request.get('merchant/reservation/tables', data || {});
}

/** 商家预约接单 */
export function merchantReservationConfirm(id, data) {
	return request.post(`merchant/reservation/confirm/${id}`, data || {});
}

/** 商家预约拒绝 */
export function merchantReservationRefuse(id, data) {
	return request.post(`merchant/reservation/refuse/${id}`, data || {});
}

/** 商家预约修改（店长） */
export function merchantReservationUpdate(id, data) {
	return request.post(`merchant/reservation/update/${id}`, data || {});
}

/** 商家预约开始/结束服务 */
export function merchantReservationServiceSet(id, data) {
	return request.post(`merchant/reservation/service/set/${id}`, data || {});
}
