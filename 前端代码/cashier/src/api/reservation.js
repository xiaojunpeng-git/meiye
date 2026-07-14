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

/**
 *预约单列表
 */
export function getReservationOrder(data) {
    return request({
        url: 'reservation/order/list',
        method: 'get',
		params: data
    });
}

/**
 * 预约服务人员列表（按门店）
 */
export function getReservationStaffList(data) {
    return request({
        url: 'reservation/staff/list',
        method: 'get',
        params: data
    });
}

/**
 * 员工已被占用时段
 */
export function getStaffAvailableTime(data) {
    return request({
        url: 'reservation/staff/available_time',
        method: 'get',
        params: data
    });
}

/**
 * 手艺人预约时段冲突
 */
export function getStaffReservationConflicts(data) {
    return request({
        url: 'reservation/staff/conflicts',
        method: 'get',
        params: data
    });
}

/**
 * 指定时段已被占用的手艺人
 */
export function getBusyStaffAtTime(data) {
    return request({
        url: 'reservation/staff/busy',
        method: 'get',
        params: data
    });
}

/**
 * 格式化员工下拉选项
 */
export function formatReservationStaffOptions(list = []) {
    return list.map((item) => ({
        value: item.id || item.staff_id,
        label: item.staff_name,
    }));
}

/**
 *服务人员列表（旧接口，预约请用 getReservationStaffList）
 */
export function getAllStaffList(params = {}) {
    return getReservationStaffList(params).then((res) => {
        const list = (res.data && res.data.list) || [];
        res.data = list.map((item) => ({
            value: item.id || item.staff_id,
            label: item.staff_name || '',
        }));
        return res;
    });
}

/**
 *预约订单导出
 */
export function getExportOrder(data) {
    return request({
        url: 'export/reservation/order',
        method: 'get',
		params: data
    });
}

/**
 *预约单详情
 */
export function getOrderDetail(id) {
    return request({
        url: `reservation/order/detail/${id}`,
        method: 'get'
    });
}

/**
 *预约时间段
 */
export function getReservationTime(id) {
    return request({
        url: `reservation/order/product_time/${id}`,
        method: 'get',
    });
}

/**
 *预约单修改
 */
export function postOrderUpdate(id,data) {
    return request({
        url: `reservation/order/update/${id}`,
        method: 'post',
		data
    });
}

/**
 *预约单设置状态（开始、结束服务）
 */
export function postOrderService(id,data) {
    return request({
        url: `reservation/order/service/set/${id}`,
        method: 'post',
		data
    });
}

/**
 *预约单取消
 */
export function postOrderCancel(id) {
    return request({
        url: `reservation/order/cancel/${id}`,
        method: 'post'
    });
}

/**
 * 创建预约单
 */
export function postReservationCreate(id, data) {
    return request({
        url: `reservation/order/create/${id}`,
        method: 'post',
        data
    });
}

/**
 * 未购项目创建预约
 */
export function postGuestReservationCreate(data) {
    return request({
        url: 'reservation/order/guest/create',
        method: 'post',
        data
    });
}

/**
 * 确认预约
 */
export function postOrderConfirm(id, data = {}) {
    return request({
        url: `reservation/order/confirm/${id}`,
        method: 'post',
        data
    });
}

/**
 * 门店房间列表
 */
export function getReservationTableList() {
    return request({
        url: 'reservation/table/list',
        method: 'get',
    });
}

/**
 * 拒绝预约
 */
export function postOrderRefuse(id, data) {
    return request({
        url: `reservation/order/refuse/${id}`,
        method: 'post',
        data
    });
}

/**
 * 获取用户可预约的已购项目（卡项/项目剩余次数）
 */
export function getUserPurchasedRemainItems(uid) {
    return request({
        url: `reservation/order/purchased_items/${uid}`,
        method: 'get',
    });
}

/**
 * 获取订单预约信息
 */
export function getOrderReservationInfo(id, params) {
    return request({
        url: `reservation/order/order_info/${id}`,
        method: 'get',
        params
    });
}

/**
 * 获取商品预约时段
 */
export function getGoodsReservationTime(params) {
    return request({
        url: 'reservation/order/goods_time',
        method: 'get',
        params
    });
}

/**
 * 看板数据
 * @param {*} data
 * @returns
 */
export function reservationNoticeBoard(data) {
  return request({
      url: 'reservation/notice/board',
      method: 'get',
      params: data
  });
}

/**
 * 看板配置
 * @param {*} type
 * @returns
 */
export function noticeBoardConfig(type) {
  return request({
      url: `config/${type}`,
      method: 'get'
  });
}

/**
 * 看板配置保存
 * @param {*} type
 * @param {*} data
 * @returns
 */
export function postNoticeBoardConfig(type, data) {
  return request({
      url: `config/${type}`,
      method: 'post',
      data
  });
}

/**
 * @description 获取省市区街道
 */
export function cityApi (data) {
    return request({
        url: 'city',
        method: 'get',
        params: data
    });
}
