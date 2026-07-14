import request from '@/plugins/request';

/**
 *收银台-获取收银台商品信息
 */
export function orderData(data) {
	return request({
		url: 'report/order_data',
		method: 'get',
		params: data
	});
}
export function receiveColumn(data) {
	return request({
		url: 'report/receiveColumn',
		method: 'get',
		params: data
	});
}
