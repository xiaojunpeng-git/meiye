import request from '@/plugins/request';

export function debtListApi(params) {
    return request({
        url: 'order/debt/list',
        method: 'get',
        params,
    });
}

export function debtCloseApi(id) {
    return request({
        url: `order/debt/close/${id}`,
        method: 'put',
    });
}

export function debtOrderDetailApi(orderId) {
    return request({
        url: `order/debt/detail/${orderId}`,
        method: 'get',
    });
}

export function debtOrderItemsApi(orderId) {
    return request({
        url: `order/debt/order/${orderId}`,
        method: 'get',
    });
}

export function debtRepayPayApi(data) {
    return request({
        url: 'order/debt/repay/pay',
        method: 'post',
        data,
    });
}

export function debtRepayCheckApi(data) {
    return request({
        url: 'order/debt/repay/check',
        method: 'post',
        data,
    });
}

export function debtUserListApi(uid, params) {
    return request({
        url: `order/debt/user/${uid}`,
        method: 'get',
        params,
    });
}

export function debtRepayListApi(params) {
    return request({
        url: 'order/debt/repay/list',
        method: 'get',
        params,
    });
}
