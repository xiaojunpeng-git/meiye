import request from '@/plugins/request';

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

