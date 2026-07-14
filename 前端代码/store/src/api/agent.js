import request from '@/plugins/request';

//职位列表
export function agentList(data) {
    return request({
        url: 'report/agentList',
        method: 'get',
        params:data
    });
}
//编辑职位
export function editAgent(data) {
    return request({
        url: 'report/editAgent',
        method: 'get',
        params:data
    });
}
//删除职位
export function delAgent(id) {
    return request({
        url: `report/delAgent/${id}`,
        method: 'PUT'
    });
}
