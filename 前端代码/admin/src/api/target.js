import request from '@/plugins/request';

export function targetAnalysis(data) {
  return request({
    url: 'target/analysis',
    method: 'get',
    params: data
  });
}

export function targetList(data) {
  return request({
    url: 'target/list',
    method: 'get',
    params: data
  });
}

export function targetRanking(data) {
  return request({
    url: 'target/ranking',
    method: 'get',
    params: data
  });
}

export function targetStoreOptions(data) {
  return request({
    url: 'target/store_options',
    method: 'get',
    params: data
  });
}

export function targetMetricOptions() {
  return request({
    url: 'target/metric_options',
    method: 'get'
  });
}

export function targetMonthlyDetail(data) {
  return request({
    url: 'target/monthly_detail',
    method: 'get',
    params: data
  });
}

export function targetStoreDetail(data) {
  return request({
    url: 'target/store_detail',
    method: 'get',
    params: data
  });
}

// 目标方案的草稿、有效目标和报表引用均由同一个后端服务维护。
export const schemeCatalog = () => request({ url: 'target/scheme/catalog', method: 'get' });
export const schemeProducts = (params) => request({ url: 'target/scheme/products', method: 'get', params });
export const schemeList = () => request({ url: 'target/scheme/list', method: 'get' });
export const schemeDetail = (id) => request({ url: 'target/scheme/detail', method: 'get', params: { id }});
export const schemeCreate = (data) => request({ url: 'target/scheme/create', method: 'post', data });
export const schemeUpdate = (data) => request({ url: 'target/scheme/update', method: 'post', data });
export const schemeSaveLines = (data) => request({ url: 'target/scheme/lines', method: 'post', data });
export const schemeActivate = (data) => request({ url: 'target/scheme/activate', method: 'post', data });
export const schemeReport = (params) => request({ url: 'target/scheme/report', method: 'get', params });
