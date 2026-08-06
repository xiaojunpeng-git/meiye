import request from '@/plugins/request';

export function createBusinessConfigIdempotencyKey(scope) {
  const randomBytes = new Uint32Array(2);
  if (typeof window !== 'undefined' && window.crypto && window.crypto.getRandomValues) {
    window.crypto.getRandomValues(randomBytes);
  } else {
    randomBytes[0] = Math.floor(Math.random() * 0xffffffff);
    randomBytes[1] = Math.floor(Math.random() * 0xffffffff);
  }
  return `ADMIN-${scope}-${Date.now()}-${randomBytes[0].toString(16)}${randomBytes[1].toString(16)}`;
}

export function businessSourceListApi() {
  return request({
    url: 'product/business-config/sources',
    method: 'get'
  });
}

export function businessSourceCreateApi(data) {
  return request({
    url: 'product/business-config/sources',
    method: 'post',
    data
  });
}

export function businessSourceUpdateApi(id, data) {
  return request({
    url: `product/business-config/sources/${id}`,
    method: 'put',
    data
  });
}

export function accountingMethodListApi() {
  return request({
    url: 'product/business-config/accounting-methods',
    method: 'get'
  });
}

export function accountingMethodUpdateApi(code, data) {
  return request({
    url: `product/business-config/accounting-methods/${code}`,
    method: 'put',
    data
  });
}

export function accountingMethodRestoreApi(data) {
  return request({
    url: 'product/business-config/accounting-methods/restore-defaults',
    method: 'post',
    data
  });
}
