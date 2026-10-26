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
 * @description erp设置
 * @param {Object} param data {Object} 传值参数
 */
export function erpConfig() {
  return request({
    url: 'erp/config',
    method: 'get'
  });
}

/**
 * @description erp门店列表
 * @param {Object} param data {Object} 传值参数
 */
export function erpShop(data) {
  return request({
    url: 'store/erp/shop',
    method: 'get',
    params: data
  });
}

/**
 * @description 导入erp
 * @param {Object} param data {Object} 传值参数
 */
export function erpProduct(data) {
  return request({
    url: 'product/import_erp_product',
    method: 'post',
    data
  });
}
