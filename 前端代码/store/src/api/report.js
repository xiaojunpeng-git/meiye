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

export function businessReportCatalog () {
  return request({ url: 'report/unified/catalog', method: 'get' });
}

export function businessReportQuery (data) {
  return request({ url: 'report/unified/query', method: 'get', params: data });
}

export function businessReportExport (data) {
  return request({ url: 'report/unified/export', method: 'get', params: data });
}
