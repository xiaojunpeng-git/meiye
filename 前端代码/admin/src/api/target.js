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
