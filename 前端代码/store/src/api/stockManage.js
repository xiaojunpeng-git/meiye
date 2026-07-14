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
 入库管理-添加表单-提交
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAddApi (data) {
    return request({
        url: '/product/inventory/in/order/add',
        method: 'post',
        data
    });
};

/**
 入库管理-添加表单-售后订单查询
 * @param {Object} param data {Object} 传值参数
 */
export function refundInfoApi (data) {
    return request({
        url: `/product/inventory/in/order/refundInfo`,
        method: 'get',
		params: data
    });
};

/**
 入库管理-入库单详情
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryInfoApi (id) {
    return request({
        url: `/product/inventory/in/order/info/${id}`,
        method: 'get'
    });
};

/**
 入库管理-入库单列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryListApi (data) {
    return request({
        url: `/product/inventory/in/order`,
        method: 'get',
		params: data
    });
};

/**
 入库管理-入库单列表-备注
 * @param {Object} param data {Object} 传值参数
 */
export function orderRemarkApi (id) {
    return request({
        url: `/product/inventory/in/order/remark/form/${id}`,
        method: 'get'
    });
};

/**
 入库管理-入库单列表-详情-库存明细列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryDetailApi (data) {
    return request({
        url: `/product/inventory/detail/list`,
        method: 'get',
		params: data
    });
};

/**
 入库管理-入库单列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockInOrderApi (data) {
    return request({
        url: `/export/productStockInOrder`,
        method: 'get',
		params: data
    });
};

/**
 出库管理-添加表单-提交
 * @param {Object} param data {Object} 传值参数
 */
export function outventoryAddApi (data) {
    return request({
        url: '/product/inventory/out/order/add',
        method: 'post',
        data
    });
};

/**
 出库管理-出库单列表
 * @param {Object} param data {Object} 传值参数
 */
export function outventoryListApi (data) {
    return request({
        url: `/product/inventory/out/order`,
        method: 'get',
		params: data
    });
};

/**
 出库管理-出库单列表-备注
 * @param {Object} param data {Object} 传值参数
 */
export function outOrderRemarkApi (id) {
    return request({
        url: `/product/inventory/out/order/remark/form/${id}`,
        method: 'get'
    });
};

/**
 出库管理-出库单列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockOutOrderApi (data) {
    return request({
        url: `/export/productStockOutOrder`,
        method: 'get',
		params: data
    });
};

/**
 库存盘点-添加表单-提交
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryCountApi (id,data) {
    return request({
        url: `/product/inventory/count/save/${id}`,
        method: 'post',
        data
    });
};

/**
 库存盘点-添加盘点单-编辑详情
 * @param {Object} param data {Object} 传值参数
 */
export function productCountInfoApi (id) {
    return request({
        url: `/product/inventory/count/info/${id}`,
        method: 'get'
    });
};

/**
 库存盘点-盘点列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryCountListApi (data) {
    return request({
        url: `/product/inventory/count/list`,
        method: 'get',
		params: data
    });
};

/**
 库存盘点-盘点列表-备注
 * @param {Object} param data {Object} 传值参数
 */
export function countRemarkApi (id) {
    return request({
        url: `/product/inventory/count/remark/form/${id}`,
        method: 'get'
    });
};

/**
 库存盘点-盘点列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockCountApi (data) {
    return request({
        url: `/export/productStockCount`,
        method: 'get',
		params: data
    });
};

/**
 出入库明细-明细列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAttrListApi (data) {
    return request({
        url: `/product/inventory/productAttr/list`,
        method: 'get',
		params: data
    });
};

/**
 出入库明细-明细详情列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAttrInfoApi (data) {
    return request({
        url: `/product/inventory/productAttr/info`,
        method: 'get',
		params: data
    });
};

/**
 出入库明细-明细详情列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAttrDetailsListApi (data) {
    return request({
        url: `/product/inventory/productAttr/order/list`,
        method: 'get',
		params: data
    });
};

/**
 出入库明细-明细详情列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockDetailApi (data) {
    return request({
        url: `/export/productStockDetail`,
        method: 'get',
		params: data
    });
};

/**
 出入库统计-入库统计列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventorystatisticsApi (data) {
    return request({
        url: `/product/inventory/order/statistics`,
        method: 'get',
		params: data
    });
};

/**
 出入库统计-入库统计列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productstatisticsApi (data) {
    return request({
        url: `/export/productStockOrderStatistics`,
        method: 'get',
		params: data
    });
};

/**
 出入库统计-库存统计
 * @param {Object} param data {Object} 传值参数
 */
export function overallStatisticsApi (data) {
    return request({
        url: `/product/inventory/order/overall_statistics`,
        method: 'get',
		params: data
    });
};

/**
 出入库明细-库存统计
 * @param {Object} param data {Object} 传值参数
 */
export function productAttrStatisticsApi (data) {
    return request({
        url: `/product/inventory/productAttr/statistics`,
        method: 'get',
		params: data
    });
};

