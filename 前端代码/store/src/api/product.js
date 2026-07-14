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
// 商品列表
/**
 *商品列表-获取列表
 */
export function productListInfo(data) {
    return request({
        url: 'product/product',
        method: 'get',
        params: data
    });
}

/**
 * @description 商品列表-- 详情
 */
export function productInfoApi(id) {
    return request({
        url: `product/product/${id}`,
        method: 'get'
    });
}
/**
 *商品列表-获取列表头
 */
export function productHeaderInfo(data) {
    return request({
        url: 'product/type_header',
        method: 'get',
        params: data
    });
}

/**
 *商品列表-商品编辑
 */
// export function productEditApi(id) {
//     return request({
//         url: `product/product/${id}/edit`,
//         method: 'get'
//     });
// }


/**
 *商品列表-商品编辑-保存
 */
export function productsaveApi(id, data) {
    return request({
        url: `product/product/${id}`,
        method: 'put',
        data
    });
}

/**
 *商品列表-商品编辑- 用户标签
 */
export function labelListApi() {
    return request({
        url: 'product/getUserLabel',
        method: 'get'
    });
}

/**
 * @description 添加商品-- 商品标签
 */
export function productStoreLabel() {
    return request({
        url: 'product/product_label',
        method: 'get'
    });
}

/**
 *商品列表-商品评价
 */
export function productReplyApi(params) {
    return request({
        url: `product/reply`,
        method: 'get',
        params
    });
}

/**
 * 商品评论 -- 回复
 */
export function setReplyApi(data, id) {
    return request({
        url: `product/reply/set_reply/${id}`,
        method: 'PUT',
        data
    });
}

/**
 * 商品 -- 上下架
 */
export function setShowApi(id, is_show) {
    return request({
        url: `product/product/set_show/${id}/${is_show}`,
        method: 'PUT'
    });
}

/**
 *商品列表-获取商品规格
 */
export function productAttrsApi(id) {
    return request({
        url: `product/product/attrs/${id}`,
        method: 'get'
    });
}

/**
 *商品列表-提交商品规格库存
 */
export function productSaveStocksApi(data, id) {
    return request({
        url: `product/product/saveStocks/${id}`,
        method: 'PUT',
        data
    });
}

/**
 *商品列表-获取商品规格
 */
export function synchStocks(data) {
    return request({
        url: `/product/product/synchStocks`,
        method: 'post',
        data
    });
}

/**
 *商品列表
 */
export function productList(data) {
    return request({
        url: `/product/product/list`,
        method: 'get',
        params: data
    });
}

/**
 * @description 商品详情里面分类-- cascader
 */
export function cascaderList(type) {
    return request({
        url: `product/category/cascader_list/${type}`,
        method: 'get'
    });
}

/**
 * @description 商品管理-- 提交
 */
export function productAddApi(data) {
    return request({
        url: `product/product/${data.id}`,
        method: 'POST',
        data
    });
}

/**
 * @description 商品管理 -- 生成属性
 * @param {Object} param data {Object} 传值参数
 */
export function generateAttrApi(data, id, type) {
    return request({
        url: `product/generate_attr/${id}/${type}`,
        method: 'POST',
        data
    });
}

/**
 * @description 商品属性 -- 获取规则属性模板
 */
export function productGetRuleApi() {
    return request({
        url: `product/product/get_rule`,
        method: 'get'
    });
}

/**
 * @description 商品 -- 获取运费模板
 */
export function productGetTemplateApi() {
    return request({
        url: `product/product/get_template`,
        method: 'get'
    });
}

/**
 * @description 获取上传参数
 */
export function productGetTempKeysApi(data) {
    return request({
        url: `product/product/get_temp_keys`,
        method: 'get',
		params: data,
    });
}

/**
 * @description 添加商品 -- 检测活动存在
 */
export function checkActivityApi(id) {
    return request({
        url: `product/product/check_activity/${id}`,
        method: 'get'
    });
}

/**
 * @description 商品管理-- 临时保存
 */
export function productCache() {
    return request({
        url: 'product/cache',
        method: 'get'
    });
}

/**
 * @description 商品管理-- 取消临时保存
 */
export function cacheDelete() {
    return request({
        url: 'product/cache',
        method: 'delete'
    });
}

/**
 * @description 商品管理-- 添加商品品牌列表
 */
export function brandList() {
    return request({
        url: `product/brand/cascader_list/2`,
        method: 'get'
    });
}

/**
 * @description 商品分类 -- 添加表单
 * @param {Object} param params {Object} 传值参数
 */
export function productCreateApi() {
    return request({
        url: 'product/category/create',
        method: 'get'
    });
}

/**
 *添加商品-获取所有商品单位列表
 */
export function productAllUnit(id) {
    return request({
        url: `product/get_all_unit`,
        method: 'get'
    });
}

/**
 *添加商品-商品单位添加表单
 */
export function productUnitCreate(id) {
    return request({
        url: `product/unit/create`,
        method: 'get'
    });
}

/**
 * @description 商品添加编辑-- 获取上传视频类型
 */
export function uploadType () {
    return request({
        url: 'file/upload_type',
        method: 'get'
    })
}

/**
 * @description 添加商品-- 商品标签
 */
export function productAllEnsure() {
    return request({
        url: 'product/all_ensure',
        method: 'get'
    });
}

/**
 * @description 添加商品-- 添加商品标签
 */
export function productLabelAdd() {
    return request({
        url: 'product/label/form',
        method: 'get'
    });
}

/**
 * @description 添加商品-- 添加商品参数
 */
export function productAllSpecs() {
    return request({
        url: 'product/all_specs',
        method: 'get'
    });
}

/**
 * @description 商品属性 -- 列表
 * @param {Object} param params {Object} 传值参数
 */
export function ruleListApi(params) {
    return request({
        url: `product/product/rule`,
        method: 'GET',
        params
    });
}

/**
 * @description 商品属性 -- 添加
 * @param {Number} param id {Number} 属性id
 * @param {Object} param data {Object} 传值参数
 */
export function ruleAddApi(data, id) {
    return request({
        url: `product/product/rule/${id}`,
        method: 'POST',
        data
    });
}

/**
 * @description 商品属性 -- 详情
 * @param {Number} param id {Number} 属性id
 */
export function ruleInfoApi(id) {
    return request({
        url: `product/product/rule/${id}`,
        method: 'get'
    });
}

/**
 * @description 商品管理-- 添加品牌-获取上级分类
 */
export function brandCascader() {
    return request({
        url: 'product/brand/cascader_list',
        method: 'get'
    });
}

/**
 * @description 商品管理-- 提交添加品牌
 */
export function productBrand(data) {
    return request({
        url: 'product/brand',
        method: 'POST',
        data
    });
}

/**
 * @description 商品管理-- 提交编辑品牌
 */
export function productBrandrev(id,data) {
    return request({
        url: `product/brand/${id}`,
        method: 'put',
        data
    });
}

/**
 * @description 保存云端视频附件记录
 * @param {String} param ids {String}
 */
export function videoAttachment (data) {
    return request({
        url: 'file/video_attachment',
        method: 'post',
        data
    });
}

/**
 * @description 自定义表单组件列表
 * @param {String} param ids {String}
 */
export function allSystemForm () {
    return request({
        url: 'system/form/all_system_form',
        method: 'get'
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
 * @description 商品属性 -- 批量上下架
 * @param {Object} param data {Object} 传值对象
 */
export function productShowApi(data) {
    return request({
        url: `product/product/product_show`,
        method: 'put',
        data
    });
}

/**
 * @description 商品属性 -- 批量下架
 * @param {Object} param data {Object} 传值对象
 */
export function productUnshowApi(data) {
    return request({
        url: `product/product/product_unshow`,
        method: 'put',
        data
    });
}

/**
 * 商品批量操作
 * @param {*} data
 * @returns
 */
export function batchProcess(data) {
  return request({
    url: 'product/batch_process',
    method: 'post',
    data
  });
}

/**
 * 商品分类
 * @returns 
 */
export function productCategory(data) {
  return request({
      url: `product/category`,
      method: 'get',
      params: data
  });
}

/**
 * 商品分类修改状态
 * @param {*} id 
 * @param {*} is_show 
 * @returns 
 */
export function categorySetShowApi(id, is_show) {
  return request({
      url: `/product/category/set_show/${id}/${is_show}`,
      method: 'PUT'
  });
}

/**
 * 商品分类编辑表单
 * @param {*} id 
 * @returns 
 */
export function productCategoryEdit(id) {
  return request({
      url: `/product/category/${id}`,
      method: 'get'
  });
}

/**
 * 商品分类新增表单
 * @returns 
 */
export function productCategoryCreate() {
  return request({
      url: '/product/category/create',
      method: 'get'
  });
}

/**
 * 设置门店商品分类
 * @param {*} id 
 * @param {*} data 
 * @returns 
 */
export function setProductCate(id, data) {
  return request({
      url: `product/product/cate/${id}`,
      method: 'post',
      data
  });
}

export function wechatCard() {
  return request({
      url: '/user/wechat/card',
      method: 'get'
  });
}

/**
 * 设置平台同步门店商品调价
 * @param {*} id 
 * @param {*} data 
 * @returns 
 */
export function productSavePrice(id, data) {
  return request({
      url: `product/product/savePrice/${id}`,
      method: 'PUT',
      data
  });
}

/**
 * 商品导入
 * @param {*} data 
 * @returns 
 */
export function importProductImport(data) {
  return request({
    url: `product/product_import`,
    method: "post",
    data,
  });
}

/**
 * 商品导出
 * @param {*} params 
 * @returns 
 */
export function productExportApi(params) {
  return request({
    url: `/export/productImport`,
    method: 'get',
    params
  });
}

/**
 * 获取数据
 * @param {*} id 
 * @returns 
 */
export function productObtainDataApi(id) {
  return request({
    url: `/product/obtain/data/${id}`
  });
}

/**
 * 修改数据
 * @param {*} id 
 * @param {*} data 
 * @returns 
 */
export function productModifyDataApi(id, data) {
  return request({
    url: `/product/modify/data/${id}`,
    method: "post",
    data,
  });
}
