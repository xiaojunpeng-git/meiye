import request from '@/plugins/request';

export function businessReportCatalog() {
  return request({
    url: 'report/business-catalog',
    method: 'get'
  });
}

export function unifiedBusinessReportQuery(data) {
  return request({ url: 'report/unified/query', method: 'get', params: data });
}

export function unifiedBusinessReportCatalog() {
  return request({ url: 'report/unified/catalog', method: 'get' });
}

export function unifiedBusinessReportExport(data) {
  return request({ url: 'report/unified/export', method: 'get', params: data });
}

export function staffingQuotaList(params) {
  return request({ url: 'report/staffing-quota', method: 'get', params });
}

export function saveStaffingQuota(data) {
  return request({ url: 'report/staffing-quota', method: 'post', data });
}

/** 平台端门店运营报表补充字段，与门店 V3 使用同一注释事实。 */
export function saveUnifiedBusinessReportAnnotation(data) {
  return request({ url: 'report/operations/annotation', method: 'post', data });
}

/**
 * 门店运营报表范围选择器：组织树与门店列表均由平台后端按当前账号权限返回。
 * 报表查询仍由后端对 org_id / store_id 再次裁剪，前端只用于缩小范围。
 */
export function reportOrganizationTree() {
  return request({ url: 'region/organization/tree', method: 'get' });
}

export function reportOrganizationStores(data) {
  return request({ url: 'region/organization/stores', method: 'get', params: data });
}

/** 门店运营商品分类合作方配置（仅启用分类可保存）。 */
export function reportOperationCategories() {
  return request({ url: 'report/operations/categories', method: 'get' });
}

export function saveReportOperationCategory(data) {
  return request({ url: 'report/operations/category', method: 'post', data });
}

export function reportSale(data) {
  return request({
    url: 'report/reportSale',
    method: 'get',
    params: data
  });
}

export function reportList(data) {
  return request({
    url: 'report/reportList',
    method: 'get',
    params: data
  });
}

export function pkInfo(data) {
  return request({
    url: 'report/pkInfo',
    method: 'get',
    params: data
  });
}

export function savePk(data) {
  return request({
    url: 'report/save_pk',
    method: 'post',
    params: data
  });
}

export function fenxiList(data) {
  return request({
    url: 'report/fenxiList',
    method: 'get',
    params: data
  });
}
export function xnList(data) {
  return request({
    url: 'report/xnList',
    method: 'get',
    params: data
  });
}
export function orderData(data) {
  return request({
    url: 'report/order_data',
    method: 'get',
    params: data
  });
}
export function receiveColumn(data) {
  return request({
    url: 'report/receiveColumn',
    method: 'get',
    params: data
  });
}
