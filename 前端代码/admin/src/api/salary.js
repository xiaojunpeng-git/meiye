import request from '@/plugins/request';

export function salaryColumn(data) {
  return request({
    url: 'report/salaryColumn',
    method: 'get',
    params: data
  });
}
export function salaryList(data) {
  return request({
    url: 'report/salaryList',
    method: 'get',
    params: data
  });
}
export function setSalary(data) {
  return request({
    url: 'report/setSalary',
    method: 'post',
    params: data
  });
}
export function makeFreeze(data) {
  return request({
    url: 'report/makeFreeze',
    method: 'post',
    params: data
  });
}
export function getFreeze(data) {
  return request({
    url: 'report/getFreeze',
    method: 'get',
    params: data
  });
}
export function salaryCount(data) {
  return request({
    url: 'report/salaryCount',
    method: 'get',
    params: data
  });
}
export function agentList(data) {
  return request({
    url: 'report/agentList',
    method: 'get',
    params: data
  });
}
export function editAgent(data) {
  return request({
    url: 'report/editAgent',
    method: 'get',
    params: data
  });
}
export function saveAgent(id, data) {
  return request({
    url: 'report/saveAgent/' + id,
    method: 'post',
    data
  });
}
