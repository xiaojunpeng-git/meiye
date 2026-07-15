import request from '@/plugins/request';

/**
 * 保存店员信息
 */
export function postStaff(data, id) {
  return request({
    url: `merchant/staff/save/${id}`,
    method: 'post',
    data,
  });
}

/**
 * 获取店员详情
 */
export function getStaffInfo(id) {
  return request({
    url: `merchant/staff/read/${id}`,
    method: 'get',
  });
}

/**
 * 获取店员角色列表
 */
export function systemRoleList(storeId = 0) {
  return request({
    url: 'merchant/staff/roleList',
    method: 'get',
    params: { store_id: storeId },
  });
}

/**
 * 获取职位列表
 */
export function position() {
  return request({
    url: 'merchant/staff/position',
    method: 'get',
  });
}

/**
 * 获取职级列表
 */
export function positionLevel() {
  return request({
    url: 'merchant/staff/positionLevel',
    method: 'get',
  });
}

/**
 * 获取企微员工列表
 */
export function workMemberList() {
  return request({
    url: 'merchant/staff/workMember/list',
    method: 'get',
  });
}

/**
 * 店员调店
 */
export function staffTransfer(id, data) {
  return request({
    url: `merchant/staff/transfer/${id}`,
    method: 'post',
    data,
  });
}

/**
 * 店员调店记录
 */
export function staffTransferLog(params) {
  return request({
    url: 'merchant/staff/transfer_log',
    method: 'get',
    params,
  });
}

/**
 * 获取店员列表列配置
 */
export function getStaffColumnSetting(params) {
  return request({
    url: 'merchant/staff/column_setting',
    method: 'get',
    params,
  });
}

/**
 * 保存店员列表列配置
 */
export function saveStaffColumnSetting(data) {
  return request({
    url: 'merchant/staff/column_setting',
    method: 'post',
    data,
  });
}
