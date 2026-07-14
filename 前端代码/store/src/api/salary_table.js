import request from '@/plugins/request';


export function selfCount(data) {
  return request({
    url: 'report/selfCount',
    method: 'get',
    params: data
  });
}
export function selfColumn(data) {
  return request({
    url: 'report/selfColumn',
    method: 'get',
    params: data
  });
}
export function selfList(data) {
  return request({
    url: 'report/selfList',
    method: 'get',
    params: data
  });
}
export function getTable(data) {
  return request({
    url: 'report/getTable',
    method: 'get',
    params: data
  });
}
export function selfSearch(data) {
  return request({
    url: 'report/selfSearch',
    method: 'get',
    params: data
  });
}
export function setTableSalary(data) {
  return request({
    url: 'report/setTableSalary',
    method: 'post',
    params: data
  });
}
export function addTableSalary(data) {
  return request({
    url: 'report/addTableSalary',
    method: 'post',
    params: data
  });
}
