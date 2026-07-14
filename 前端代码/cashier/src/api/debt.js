import request from '@/plugins/request';

export function debtSummaryApi(params) {
    return request({
        url: 'debt/summary',
        method: 'get',
        params,
    });
}

export function debtFilterStoresApi(uid) {
    return request({
        url: `debt/stores/${uid}`,
        method: 'get',
    });
}

export function debtUserListApi(uid, params) {
    return request({
        url: `debt/user/${uid}`,
        method: 'get',
        params,
    });
}

export function debtRepayListApi(params) {
    return request({
        url: 'debt/repay/list',
        method: 'get',
        params,
    });
}

export function debtReminderApi(params) {
    return request({
        url: 'debt/reminder',
        method: 'get',
        params,
    });
}

export function debtRepayPayApi(data) {
    return request({
        url: 'debt/repay/pay',
        method: 'post',
        data,
    });
}

export function debtRepayCheckApi(data) {
    return request({
        url: 'debt/repay/check',
        method: 'post',
        data,
    });
}

export function debtOrderItemsApi(orderId) {
    return request({
        url: `debt/order/${orderId}`,
        method: 'get',
    });
}
