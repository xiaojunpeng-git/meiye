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
export function searchFieldList(data) {
  return request({
    url: 'report/searchFieldList',
    method: 'get',
    params: data
  });
}
export function searchFieldSave(data) {
  return request({
    url: 'report/searchFieldSave',
    method: 'post',
    data
  });
}
export function searchFieldDelete(id) {
  return request({
    url: 'report/searchFieldDelete/' + id,
    method: 'delete'
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
