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
 *门店权限设置-管理员身份列表
 */
export function systemRole() {
    return request({
        url: 'system/role',
        method: 'get'
    });
}
/**
 *门店校色列表-管理员身份列表
 */
export function systemRoleStatus(id,status) {
    return request({
        url: `system/role/set_status/${id}/${status}`,
        method: 'put'
    });
}
/**
 *门店权限设置-提交管理员身份
 */
export function roleCreatApi(data) {
    return request({
        url: `system/role/${data.id}`,
        method: 'post',
        data
    });
}

/**
 *门店权限设置-添加编辑管理员身份
 */
export function roleInfoApi(id) {
    return request({
        url: `system/role/${id}/edit`,
        method: 'get'
    });
}

/**
 *门店权限设置-菜单列表
 */
export function menuList() {
    return request({
        url: `system/menusList`,
        method: 'get'
    });
}

/**
 *门店权限设置-门店管理员列表
 */
export function systemAdmin(data) {
    return request({
        url: `system/admin`,
        method: 'get',
        params: data
    });
}

/**
 *门店权限设置-门店管理员列表修改状态
 */
export function adminSetStatus(id,status) {
    return request({
        url: `system/admin/set_status/${id}/${status}`,
        method: 'put'
    });
}

/**
 *门店权限设置-添加门店管理员
 */
export function adminCreate() {
    return request({
        url: `system/admin/create`,
        method: 'get'
    });
}

/**
 *门店权限设置-编辑门店管理员
 */
export function adminEdit(id) {
    return request({
        url: `system/admin/${id}/edit`,
        method: 'get'
    });
}

/**
 * @description 门店设置 获取地图key
 */
export function keyApi () {
    return request({
        url: 'config',
        method: 'get'
    });
}

/**
 * @description 门店设置 获取当前登录门店信息
 */
export function storeGetInfoApi () {
    return request({
        url: 'system/store/info',
        method: 'get'
    });
}

/**
 * @description 门店设置 获取当前登录门店信息
 */
export function storeUpdateApi (data) {
    return request({
        url: 'system/store/update',
        method: 'put',
        data
    });
}

/**
 * @description 门店设置 获取省市区街道
 */
export function cityApi (data) {
    return request({
        url: 'city',
        method: 'get',
        params: data
    });
}

/**
 * @description 设置 小票打印、电子面单
 */
export function configEdit (data) {
    return request({
        url: 'system/config/edit_basics',
        method: 'get',
        params: data
    });
}

/**
 * @description 设置 运费模板 -- 列表
 *  @param {Object} param data {Object} 传值参数
 */
export function templatesApi (data) {
    return request({
        url: `setting/shipping_templates/list`,
        method: 'get',
        params: data
    });
}

/**
 * @description 设置 运费模板 -- 提交修改表单；
 */
export function templatesSaveApi (id, data) {
    return request({
        url: `setting/shipping_templates/save/${id}`,
        method: 'post',
        data
    });
}

/**
 * @description 设置 运费模板 -- 提交修改表单；
 */
export function shipTemplatesApi (id) {
    return request({
        url: `setting/shipping_templates/${id}/edit`,
        method: 'get'
    });
}

/**
 * @description 运费模板 获取省市区街道
 */
export function cityData (data) {
    return request({
        url: 'city',
        method: 'get',
        params: data
    });
}

/**
 * 配送订单列表
 * @param {*} type
 * @returns
 */
export function deliveryList(data) {
    return request({
        url: `/order/delivery_order/list`,
        method: 'get',
        params: data
    });
}

/**
 * 取消配送单
 * @param {*} type
 * @returns
 */
export function deliveryCancelForm(id) {
    return request({
        url: `/order/delivery_order/cancelForm/${id}`,
        method: 'get'
    });
}

/**
 * 配送商品类型
 * @param {*} type
 * @returns
 */
export function getBusiness(type) {
    return request({
        url: `/system/store/getBusiness/${type}`,
        method: 'get'
    });
}

export function getConfig(type) {
  return request({
      url: `/system/config/${type}`,
      method: 'get'
  });
}

/**
 * 保存门店配置
 * @param {*} type 
 * @param {*} data 
 * @returns 
 */
export function submitConfig(type, data) {
  return request({
      url: `/system/config/${type}`,
      method: 'post',
      data
  });
}

/**
 * 获取餐桌座位数列表
 * @returns 
 */
export function getTableSeatsList() {
  return request({
      url: `/table/seats/list`,
      method: 'get'
  });
}

/**
 * 添加、编辑餐桌座位数
 * @param {*} id 
 * @param {*} params 
 * @returns 
 */
export function addSeats(id, params) {
  return request({
      url: `/table/add/seats/${id}`,
      method: 'get',
      params
  });
}

/**
 * 获取桌码分类列表
 * @param {*} params 
 * @returns 
 */
export function getTableCateList(params) {
  return request({
      url: `/table/cate/list`,
      method: 'get',
      params
  });
}

/**
 * 添加、编辑桌码分类
 * @param {*} id 
 * @param {*} params 
 * @returns 
 */
export function addTableCate(id, params) {
  return request({
      url: `/table/add/cate/${id}`,
      method: 'get',
      params
  });
}

/**
 * 获取桌码列表
 * @param {*} params 
 * @returns 
 */
export function getTableQRCodeList(params) {
  return request({
      url: `/table/qrcodes/list`,
      method: 'get',
      params
  });
}

/**
 * 桌码添加、编辑
 * @param {*} id 
 * @param {*} data 
 * @returns 
 */
export function addTableQRCode(id, data) {
  return request({
      url: `/table/add/qrcode/${id}`,
      method: 'post',
      data
  });
}

/**
 * 获取收银台菜单列表
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function cashierMenusList() {
    return request({
        url: `/system/cashierMenusList`,
        method: 'get'
    });
}

/**
 * 桌码操作启用
 * @param {*} id 
 * @param {*} params 
 * @returns 
 */
export function updateTableUsing(id, params) {
  return request({
      url: `/table/update/using/${id}`,
      method: 'get',
      params
  });
}

/**
 * diy系统表单信息（详情）
 * @param {*} type
 * @returns
 */
export function systemFormInfo(id,data) {
    return request({
        url: `/system/form/info/${id}`,
        method: 'get',
        params: data
    });
}

/**
 * 获取表单配置
 * @param type
 */
export function getNewFormBuildRuleApi(type) {
    return request({
        url: 'system/config/edit_new_build/'+type,
        method: 'get',
    });
}

/**
 * 获取打印机列表
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function getPrinterList(data) {
    return request({
        url: `/system/printer/list`,
        method: 'get',
		params: data
    });
}

/**
 * 获取打印机是否开启
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function postPrinterStatus(data) {
    return request({
        url: `/system/printer/status/${data.id}/${data.status}`,
        method: 'post'
    });
}

/**
 * 获取打印机保存编辑
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function postPrinter(id,data) {
    return request({
        url: `/system/printer/${id}`,
        method: 'post',
		data
    });
}

/**
 * 获取打印机信息
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function postPrinterInfo(id) {
    return request({
        url: `/system/printer/info/${id}`,
        method: 'get'
    });
}

/**
 * @description 门店-添加门店
 * data--地址转id
 */
export function getResolveCity (data) {
    return request({
        url: `resolve/city`,
        method: 'get',
		params: data
    })
}

/**
 * 获取小票配置
 * @param {*} id 
 * @param {*} data 
 * @returns 
 */
export function printContent(id, data) {
  return request({
      url: `/print/content/${id}`,
      method: 'get',
      params: data
  })
}

/**
 * 保存小票配置
 * @param {*} id 
 * @param {*} data 
 * @returns 
 */
export function printSaveContent(id, data) {
  return request({
      url: `/print/save_content/${id}`,
      method: 'post',
      data
  });
}

/**
 * 获取同城配送设置
 * @returns 
 */
export function deliveryConfigApi() {
  return request({
      url: '/delivery/status/config',
  });
}

/**
 * 获取配送配置
 * @returns 
 */
export function deliveryDetailApi() {
  return request({
      url: '/delivery/config/detail',
  });
}

/**
 * 修改门店配送设置
 * @param {*} data 
 * @returns 
 */
export function deliveryConfigUpdateApi(data) {
  return request({
      url: '/delivery/config/update',
      method: 'post',
      data
  });
}
