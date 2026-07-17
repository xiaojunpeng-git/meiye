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
import Setting from '@/setting';
import util from '@/libs/util';

/**
 入库管理-添加表单-提交
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAddApi(data) {
  return request({
    url: '/product/inventory/in/order/add',
    method: 'post',
    data
  });
}

/**
 入库管理-添加表单-售后订单查询
 * @param {Object} param data {Object} 传值参数
 */
export function refundInfoApi(data) {
  return request({
    url: `/product/inventory/in/order/refundInfo`,
    method: 'get',
    params: data
  });
}

/**
 入库管理-入库单详情
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryInfoApi(id) {
  return request({
    url: `/product/inventory/in/order/info/${id}`,
    method: 'get'
  });
}

/**
 入库管理-入库单列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryListApi(data) {
  return request({
    url: `/product/inventory/in/order`,
    method: 'get',
    params: data
  });
}

/**
 入库管理-入库单列表-备注
 * @param {Object} param data {Object} 传值参数
 */
export function orderRemarkApi(id) {
  return request({
    url: `/product/inventory/in/order/remark/form/${id}`,
    method: 'get'
  });
}

/**
 入库管理-入库单列表-详情-库存明细列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryDetailApi(data) {
  return request({
    url: `/product/inventory/detail/list`,
    method: 'get',
    params: data
  });
}

/**
 入库管理-入库单列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockInOrderApi(data) {
  return request({
    url: `/export/productStockInOrder`,
    method: 'get',
    params: data
  });
}

/**
 出库管理-添加表单-提交
 * @param {Object} param data {Object} 传值参数
 */
export function outventoryAddApi(data) {
  return request({
    url: '/product/inventory/out/order/add',
    method: 'post',
    data
  });
}

/**
 出库管理-出库单列表
 * @param {Object} param data {Object} 传值参数
 */
export function outventoryListApi(data) {
  return request({
    url: `/product/inventory/out/order`,
    method: 'get',
    params: data
  });
}

/**
 出库管理-出库单列表-备注
 * @param {Object} param data {Object} 传值参数
 */
export function outOrderRemarkApi(id) {
  return request({
    url: `/product/inventory/out/order/remark/form/${id}`,
    method: 'get'
  });
}

/**
 出库管理-出库单列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockOutOrderApi(data) {
  return request({
    url: `/export/productStockOutOrder`,
    method: 'get',
    params: data
  });
}

/**
 库存盘点-添加表单-提交
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryCountApi(id, data) {
  return request({
    url: `/product/inventory/count/save/${id}`,
    method: 'post',
    data
  });
}

/**
 库存盘点-添加盘点单-编辑详情
 * @param {Object} param data {Object} 传值参数
 */
export function productCountInfoApi(id) {
  return request({
    url: `/product/inventory/count/info/${id}`,
    method: 'get'
  });
}

/**
 库存盘点-盘点列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryCountListApi(data) {
  return request({
    url: `/product/inventory/count/list`,
    method: 'get',
    params: data
  });
}

/**
 库存盘点-盘点列表-备注
 * @param {Object} param data {Object} 传值参数
 */
export function countRemarkApi(id) {
  return request({
    url: `/product/inventory/count/remark/form/${id}`,
    method: 'get'
  });
}

/**
 库存盘点-盘点列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockCountApi(data) {
  return request({
    url: `/export/productStockCount`,
    method: 'get',
    params: data
  });
}

/**
 出入库明细-明细列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAttrListApi(data) {
  return request({
    url: `/product/inventory/productAttr/list`,
    method: 'get',
    params: data
  });
}

/**
 出入库明细-明细详情列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAttrInfoApi(data) {
  return request({
    url: `/product/inventory/productAttr/info`,
    method: 'get',
    params: data
  });
}

/**
 出入库明细-明细详情列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventoryAttrDetailsListApi(data) {
  return request({
    url: `/product/inventory/productAttr/order/list`,
    method: 'get',
    params: data
  });
}

/**
 出入库明细-明细详情列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productStockDetailApi(data) {
  return request({
    url: `/export/productStockDetail`,
    method: 'get',
    params: data
  });
}

/**
 出入库统计-入库统计列表
 * @param {Object} param data {Object} 传值参数
 */
export function inventorystatisticsApi(data) {
  return request({
    url: `/product/inventory/order/statistics`,
    method: 'get',
    params: data
  });
}

/**
 出入库统计-入库统计列表-导出
 * @param {Object} param data {Object} 传值参数
 */
export function productstatisticsApi(data) {
  return request({
    url: `/export/productStockOrderStatistics`,
    method: 'get',
    params: data
  });
}

/**
 出入库统计-库存统计
 * @param {Object} param data {Object} 传值参数
 */
export function overallStatisticsApi(data) {
  return request({
    url: `/product/inventory/order/overall_statistics`,
    method: 'get',
    params: data
  });
}

/**
 出入库明细-库存统计
 * @param {Object} param data {Object} 传值参数
 */
export function productAttrStatisticsApi(data) {
  return request({
    url: `/product/inventory/productAttr/statistics`,
    method: 'get',
    params: data
  });
}

/** 入库导入模板（POST 承载 product_ids） */
export function stockInTemplateApi(data) {
  return request({
    url: `/product/inventory/in/order/template`,
    method: 'post',
    data
  });
}

/**
 * 入库模板文件流下载（鉴权 Blob，禁止直链 /phpExcel）
 * @returns {Promise<{ blob: Blob, fileName: string, contentType: string }>}
 */
export function stockInTemplateFileApi(key, fileName) {
  return stockTemplateFileBlob(`/product/inventory/in/order/template/file`, key, fileName);
}

/** 入库 Excel 导入 */
export function stockInImportApi(data) {
  return request({
    url: `/product/inventory/in/order/import`,
    method: 'post',
    data
  });
}

/** 出库导入模板（POST 承载 product_ids） */
export function stockOutTemplateApi(data) {
  return request({
    url: `/product/inventory/out/order/template`,
    method: 'post',
    data
  });
}

/**
 * 出库模板文件流下载（鉴权 Blob，禁止直链 /phpExcel）
 * @returns {Promise<{ blob: Blob, fileName: string, contentType: string }>}
 */
export function stockOutTemplateFileApi(key, fileName) {
  return stockTemplateFileBlob(`/product/inventory/out/order/template/file`, key, fileName);
}

function decodeArrayBufferText(buf) {
  try {
    if (typeof TextDecoder !== 'undefined') {
      return new TextDecoder('utf-8').decode(buf);
    }
  } catch (e) {
    /* fallthrough */
  }
  const view = new Uint8Array(buf);
  let s = '';
  const len = Math.min(view.length, 4000);
  for (let i = 0; i < len; i++) s += String.fromCharCode(view[i]);
  return s;
}

/**
 * 库存模板二进制下载。
 * 禁止走 axios@0.18 的 blob/arraybuffer（会把高位字节替换成 0xFD，Excel 损坏）。
 * 使用原生 fetch().arrayBuffer() 保真。
 */
function stockTemplateFileBlob(urlPath, key, fileName) {
  const token = util.cookies.get('token') || '';
  const excelType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  const name = fileName || 'stock-template.xlsx';
  const qs = 'key=' + encodeURIComponent(key || '');
  const url = `${Setting.apiBaseURL}${urlPath}${urlPath.indexOf('?') >= 0 ? '&' : '?'}${qs}`;
  return fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers: {
      'Authori-zation': token ? `Bearer ${token}` : '',
      'X-Source': 'f76d38d0ee4f854f',
    },
  }).then((res) => {
    return res.arrayBuffer().then((buf) => {
      const bytes = new Uint8Array(buf);
      const isPk = bytes.length >= 2 && bytes[0] === 0x50 && bytes[1] === 0x4b;
      if (isPk) {
        return { blob: new Blob([buf], { type: excelType }), fileName: name, contentType: excelType };
      }
      const text = decodeArrayBufferText(buf);
      const head = (text || '').slice(0, 200).toLowerCase();
      if (head.indexOf('<!doctype') !== -1 || head.indexOf('<html') !== -1) {
        return Promise.reject({ msg: '下载失败：收到 HTML 而非 Excel（请勿直链 /phpExcel）' });
      }
      try {
        const json = JSON.parse(text);
        return Promise.reject({ msg: json.msg || json.message || '下载失败' });
      } catch (e) {
        return Promise.reject({ msg: '下载失败：文件不是有效的 Excel（xlsx）' });
      }
    });
  }).catch((err) => {
    if (err && err.msg) return Promise.reject(err);
    return Promise.reject({ msg: (err && err.message) || '下载失败' });
  });
}

/** 出库 Excel 导入 */
export function stockOutImportApi(data) {
  return request({
    url: `/product/inventory/out/order/import`,
    method: 'post',
    data
  });
}

