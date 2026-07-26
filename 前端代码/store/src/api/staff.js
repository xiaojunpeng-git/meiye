// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import request from '@/plugins/request';
//   店员列表


/**
 *添加店员-店员添加、编辑
 */
export function postStaff(data,id) {
    return request({
        url: `staff/staff/${id}`,
        method: 'post',
		data
    });
}

/**
 *添加店员-详情
 */
export function getStaffInfo(id) {
    return request({
        url: `staff/read/${id}`,
        method: 'get'
    });
}

/**
 * 人员完整详情（含数据权限 scope）
 * @param {number|string} id staff_id
 */
export function getPersonComplete(id) {
    return request({
        url: `staff/person_complete/${id}`,
        method: 'get',
    });
}

/**
 *店员列表-获取列表
 */
export function staffallInfo() {
    return request({
        url: 'staff/staff/all',
        method: 'get',
    });
}


/**
 *店员列表-获取列表
 */
export function staffListInfo(data) {
    return request({
        url: 'staff/staff',
        method: 'get',
		params: data
    });
}

/**
 *店员列表-添加店员
 */
export function staffcreate() {
    return request({
        url: 'staff/staff/create',
        method: 'get'
    });
}


/**
 * 店员列表-编辑
 */
export function staffEditApi (id) {
    return request({
        url: `staff/staff/${id}/edit`,
        method: 'get'
    });
}

/**
 *  员工列表-删除
 */
export function staffDelApi (id) {
    return request({
        url: `staff/staff/${id}`,
        method: 'DELETE'
    });
}


/**
 * 店员列表-状态
 */
export function staffshowApi (id,status) {
    return request({
        url: `staff/staff/set_show/${id}/${status}`,
        method: 'put'
    });
}

/**
 * 添加店员-角色列表
 */
export function systemRoleList (storeId) {
    return request({
        url: `system/roleList`,
        method: 'get',
        params: storeId ? { store_id: storeId } : {},
    });
}

/**
 * 获取店员列表列配置
 */
export function getStaffColumnSetting(params) {
    return request({
        url: 'staff/column_setting',
        method: 'get',
        params,
    });
}

/**
 * 保存店员列表列配置
 */
export function saveStaffColumnSetting(data) {
    return request({
        url: 'staff/column_setting',
        method: 'post',
        data,
    });
}

/**
 * 店员专属客户
 */
export function staffCustomerList(id, params) {
    return request({
        url: `staff/staff/customer/${id}`,
        method: 'get',
        params,
    });
}

/**
 * 店员业绩订单
 */
export function staffPerformanceList(id, params) {
    return request({
        url: `staff/staff/performance/${id}`,
        method: 'get',
        params,
    });
}

/**
 * 添加店员-角色列表
 */
export function position () {
    return request({
        url: `system/position`,
        method: 'get'
    });
}
/**
 * 添加店员-角色列表
 */
export function positionLevel () {
    return request({
        url: `system/positionLevel`,
        method: 'get'
    });
}
/**
 * 添加店员-企业微信员工列表
 */
export function workMemberList (id) {
    return request({
        url: `staff/workMember/list`,
        method: 'get'
    });
}

//   配送员列表



/**
 * 配送员列表-获取列表
 */
export function deliveryListInfo(data) {
    return request({
        url: 'staff/delivery',
        method: 'get',
		params: data
    });
}

/**
 * 配送员列表-登录收银台
 */
export function cashierLogin(id) {
    return request({
        url: `staff/login_cashier/${id}`,
        method: 'get',
    });
}

/**
 *配送员列表-添加店员
 */
export function deliverycreate() {
    return request({
        url: '/staff/delivery/create',
        method: 'get'
    });
}

/**
 * 配送员列表-编辑
 */
export function deliveryEditApi (id) {
    return request({
        url: `/staff/delivery/${id}/edit`,
        method: 'get'
    });
}

/**
 *  配送员列表-删除
 */
export function deliveryDelApi (id) {
    return request({
        url: `/staff/delivery/${id}`,
        method: 'DELETE'
    });
}


/**
 * 店员列表-状态
 */
export function deliveryshowApi (id,status) {
    return request({
        url: `/staff/delivery/set_show/${id}/${status}`,
        method: 'put'
    });
}

/**
 *-详情
 */
export function detailsApi(id) {
    return request({
        url: `staff/staff/${id}`,
        method: 'get'
    });
}

/**
 * @description 会员管理详情中tab选项
 * @param {Number} param id {Number} 用户id
 */
export function infoApi(data) {
    return request({
        url: `staff/info/${data.id}`,
        method: 'get',
        params: data.datas
    });
}



/**
 *-员工-业绩统计
 */
export function staffStatisticsApi(data) {
    return request({
        url: `staff/statistics`,
        method: 'get',
		params: data
    });
}

/**
 *-员工-业绩统计-柱行/饼状图
 */
export function staffStatisticsHeaderApi(data) {
    return request({
        url: `staff/statisticsHeader`,
        method: 'get',
		params: data
    });
}


/**
 *-员工-账单统计图
 */
export function staffDeliveryStatisticsHeaderApi(data) {
    return request({
        url: `staff/delivery/statisticsHeader`,
        method: 'get',
		params: data
    });
}



/**
 *-员工-账单统计-选择配送员
 */
export function staffDeliveryselectApi() {
    return request({
        url: `staff/delivery/get_delivery_select`,
        method: 'get'
    });
}

/**
 *-员工-账单统计-选择配送员
 */
export function deliveryStatisticsApi(data) {
    return request({
        url: `staff/delivery/statistics`,
        method: 'get',
		params: data
    });
}



/**
 *-员工-配送员详情
 */
export function deliveryInfoApi(id,data) {
    return request({
        url: `staff/delivery/info/${id}`,
        method: 'get',
		params:  data
    });
}

/**
 * 店员交易统计导出
 * @param {*} data
 * @returns
 */
export function staffStatisticsExport(data) {
  return request({
      url: `/staff/statistics/export`,
      method: 'get',
      params:  data
  });
}

/**
 * 修改订单关联店员
 * @param {*} data
 * @returns
 */
export function orderStaff(data) {
  return request({
      url: `/staff/order/staff`,
      method: 'get',
      params:  data
  });
}

/**
 * 店员收银台交接班记录
 * @param {*} params
 * @returns
 */
export function shiftListApi(params) {
  return request({
      url: '/staff/shift/list',
      method: 'get',
      params
  });
}

/**
 * 店员交接班时业绩
 * @param {*} staff_id
 * @returns
 */
export function shiftHandoverApi(staff_id) {
  return request({
      url: `/staff/shift/handover/${staff_id}`,
      method: 'get'
  });
}

/**
 * 配送数据统计导出
 * @param {*} params
 * @returns
 */
export function deliveryExportStatisticsApi(params) {
  return request({
      url: '/delivery/export/statistics',
      params
  });
}

/**
 * 员工排班列表
 */
export function getStaffScheduleList(params) {
  return request({
    url: 'schedule/list',
    method: 'get',
    params,
  });
}

/**
 * 保存员工排班
 */
export function saveStaffSchedule(data) {
  return request({
    url: 'schedule/save',
    method: 'post',
    data,
  });
}

/**
 * 删除员工排班
 */
export function deleteStaffSchedule(id) {
  return request({
    url: `schedule/del/${id}`,
    method: 'delete',
  });
}
