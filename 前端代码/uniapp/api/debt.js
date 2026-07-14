import request from '@/utils/request.js';

export function debtSummaryApi() {
	return request.get('debt/summary');
}

export function getDebtCashier(orderId) {
	return request.get(`debt/cashier/${orderId}`);
}

export function debtRepayPay(data) {
	return request.post('debt/repay/pay', data);
}
