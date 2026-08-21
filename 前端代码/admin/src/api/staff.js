import request from '@/plugins/request';

/**
 * 保存店员信息（人员完整保存：档案+岗位+数据权限）
 * @param {object} data
 * @param {number|string} id staff_id；无店直属编辑可为 employee_id；新建传 0
 * @param {object} [headers] 须含 X-Request-Token / Request-Token（与 body.request_token 一致）
 */
export function postStaff(data, id, headers = {}) {
  return request({
    url: `merchant/staff/save/${id}`,
    method: 'post',
    data,
    headers,
  });
}

/**
 * 人员完整保存（组织工作台专用）：路径参数始终为 employee_id，
 * 不复用 staff/save/:id，避免 employee_id 与 staff_id 数值碰撞。
 */
export function postPersonComplete(data, employeeId, headers = {}) {
  return request({
    url: `merchant/staff/person_complete/save/${employeeId}`,
    method: 'post',
    data,
    headers,
  });
}

/**
 * 人员完整详情（含岗位与数据权限）
 * @param {number|string} id employee_id（总部 personComplete 路径参数）
 * @param {object} [params] 可选 { staff_id }
 */
export function getPersonComplete(id, params = {}) {
  return request({
    url: `merchant/staff/person_complete/${id}`,
    method: 'get',
    params,
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
 * 获取门店端员工功能权限（三态覆盖）。
 */
export function getStaffFeaturePermissions(staffId) {
  return request({
    url: `merchant/staff/${staffId}/feature-permissions`,
    method: 'get',
  });
}

/**
 * 保存门店端员工功能权限（三态覆盖）。
 */
export function saveStaffFeaturePermissions(staffId, data) {
  return request({
    url: `merchant/staff/${staffId}/feature-permissions`,
    method: 'put',
    data,
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
