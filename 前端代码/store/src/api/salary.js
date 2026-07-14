import request from '@/plugins/request';

export function salaryColumn(data) {
    return request({
        url: 'report/salaryColumn',
        method: 'get',
        params:data
    });
}
export function salaryList(data) {
    return request({
        url: 'report/salaryList',
        method: 'get',
        params:data
    });
}
export function setSalary(data) {
    return request({
        url: 'report/setSalary',
        method: 'post',
        params:data
    });
}
export function getFreeze(data) {
    return request({
        url: 'report/getFreeze',
        method: 'get',
        params: data
    });
}
