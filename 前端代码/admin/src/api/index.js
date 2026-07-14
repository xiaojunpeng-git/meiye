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
export function headerApi() {
  return request({
    url: 'home/header',
    method: 'get'
  });
}

/**
 * @description 首页订单图表
 */
export function orderApi(params) {
  return request({
    url: 'home/order',
    method: 'get',
    params
  });
}

/**
 * @description 首页订单图表
 */
export function userApi() {
  return request({
    url: 'home/user',
    method: 'get'
  });
}

/**
 * @description 首页商品交易额排行
 */
export function rankApi() {
  return request({
    url: 'home/rank',
    method: 'get'
  });
}

export function checkAuth() {
  return request({
    url: 'check_auth',
    method: 'get'
  });
}

/**
 * @description 待办事项
 */
export function getJnotice() {
  return request({
    url: 'jnotice',
    method: 'get'
  });
}

/**
 * @description 获取快捷入口
 */
export function getFastMenus() {
  return request({
    url: 'get_fast_menus',
    method: 'get'
  });
}

/**
 * @description 设置快捷入口
 */
export function setFastMenus(data) {
  return request({
    url: 'set_fast_menus',
    method: 'post',
    data
  });
}

/**
 * @description 保存自定义菜单
 */
export function postCustomMenus(data, id) {
  return request({
    url: `system/custom/menus/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 获取单个自定义菜单
 */
export function getCustomMenus(id) {
  return request({
    url: `system/custom/menus/${id}`,
    method: 'get'
  });
}
