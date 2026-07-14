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
 *
 */
export function auth() {
    return request({
        url: 'auth',
        method: 'get'
    });
}

/**
 * 门店列表
 * @returns 
 */
export function storeListApi() {
    return request({
        url: 'system/store/list',
        method: 'get'
    });
}

export function getImportErrorDown(params) {
  return request({
      url: '/export/import/error/down',
      method: 'get',
      params
  });
}
