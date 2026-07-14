
import request from '@/plugins/request';

export function yejiList(data) {
    return request({
        url: '/order/yejiList',
        method: 'get',
        params: data
    });
};

export function yejiRange(data) {
    return request({
        url: '/order/yejiRange',
        method: 'get',
        params: data
    });
};

export function setRange(data) {
    return request({
        url: '/order/setRange',
        method: 'get',
        params: data
    });
};
export function setCommission(data) {
    return request({
        url: '/order/setCommission',
        method: 'get',
        params: data
    });
};
export function yejiCommission(data) {
    return request({
        url: '/order/yejiCommission',
        method: 'get',
        params: data
    });
};
export function yejiColumn(data) {
    return request({
        url: '/order/yejiColumn',
        method: 'get',
        params: data
    });
};

export function staffallList(data) {
    return request({
        url: '/order/allList',
        method: 'post',
        data
    });
}
export function saveYeji(data) {
    return request({
        url: 'order/save_yeji',
        method: 'post',
        data
    });
}
export function getYeji(data) {
    return request({
        url: 'order/get_yeji',
        method: 'post',
        data
    });
}
export function getCartYeji(data) {
    return request({
        url: 'order/get_cart_yeji',
        method: 'post',
        data
    });
}
export function getRemak(data) {
    return request({
        url: 'order/get_remark_info',
        method: 'post',
        data
    });
}
export function saveRemark(data) {
    return request({
        url: 'order/saveRemark',
        method: 'post',
        data
    });
}
export function saveSource(data) {
    return request({
        url: 'order/saveSource',
        method: 'post',
        data
    });
}
export function saveGendan(data) {
    return request({
        url: 'order/saveGendan',
        method: 'post',
        data
    });
}
