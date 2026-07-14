import request from '@/plugins/request';

// 职位列表
export function positionList(data) {
  return request({
    url: 'position/positionList',
    method: 'get',
    params: data
  });
}
// 编辑职位
export function editPosition(data) {
  return request({
    url: 'position/editPosition',
    method: 'get',
    params: data
  });
}
// 删除职位
export function delPosition(id) {
  return request({
    url: `position/delPosition/${id}`,
    method: 'PUT'
  });
}
export function positionSetStatus(data) {
  return request({
    url: `position/set_status/${data.id}/${data.status}`,
    method: 'get'
  });
}
export function positionLevelSetStatus(data) {
  return request({
    url: `position/set_status_level/${data.id}/${data.status}`,
    method: 'get'
  });
}
// 职级列表
export function positionLevelList(data) {
  return request({
    url: 'position/positionLevelList',
    method: 'get',
    params: data
  });
}
// 编辑职级
export function editPositionLevel(data) {
  return request({
    url: 'position/editPositionLevel',
    method: 'get',
    params: data
  });
}
// 删除职级
export function delPositionLevel(id) {
  return request({
    url: `position/delPositionLevel/${id}`,
    method: 'PUT'
  });
}
