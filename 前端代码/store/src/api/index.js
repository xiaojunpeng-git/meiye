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
 * @description 首页头部
 */
export function headerApi (data) {
    return request({
        url: 'home/header',
        method: 'get',
		params:data

    });
}

/**
 * @description 首页订单图表
 */
export function orderApi (params) {
    return request({
        url: 'home/order',
        method: 'get',
        params
    });
}

/**
 * @description 首页订单图表
 */
export function userApi () {
    return request({
        url: 'home/user',
        method: 'get'
    });
}

/**
 * @description 首页商品交易额排行
 */
export function rankApi () {
    return request({
        url: 'home/rank',
        method: 'get'
    });
}

export function checkAuth () {
    return request({
        url: 'check_auth',
        method: 'get'
    });
}


export function orderChart (data) {
    return request({
        url: 'home/orderChart',
        method: 'get',
		params: data
    });
}


export function staffApi (data) {
    return request({
        url: 'home/staff',
        method: 'get',
		params: data
    });
}

export function operateApi (data) {
    return request({
        url: 'home/operate',
        method: 'get',
		params: data
    });
}

/** 经营看板-概览 */
export function businessDashboardOverview(data) {
  return request({
    url: 'home/statistics/overview',
    method: 'get',
    params: data
  });
}

/** 经营看板-趋势 */
export function businessDashboardTrend(data) {
  return request({
    url: 'home/statistics/trend',
    method: 'get',
    params: data
  });
}

/** 经营看板-员工排行 */
export function businessDashboardStaffRanking(data) {
  return request({
    url: 'home/statistics/staff-ranking',
    method: 'get',
    params: data
  });
}

/** 经营看板-预约明细 */
export function businessDashboardReservationDetail(data) {
  return request({
    url: 'home/statistics/reservation-detail',
    method: 'get',
    params: data
  });
}

/** 经营看板-新建档明细 */
export function businessDashboardNewProfileDetail(data) {
  return request({
    url: 'home/statistics/new-profile-detail',
    method: 'get',
    params: data
  });
}

/** 经营看板-散客/新客明细 */
export function businessDashboardSourceCustomerDetail(data) {
  return request({
    url: 'home/statistics/source-customer-detail',
    method: 'get',
    params: data
  });
}

/** 经营看板-金额类明细 */
export function businessDashboardMoneyDetail(data) {
  return request({
    url: 'home/statistics/money-detail',
    method: 'get',
    params: data
  });
}
