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
 * @description 商品详情里面分类-- cascader
 */
export function cascaderList(type) {
    return request({
        url: `product/cascader_list/${type}`,
        method: 'get'
    });
}
/**
 *商品列表
 */
export function productList(data) {
    return request({
        url: `product/list`,
        method: 'get',
        params: data
    });
}
/**
 * 收银台-活动商品列表
 */
export function activityList(data) {
    return request({
        url: `promotions/activity_list/${data.uid}/${data.type}`,
        method: 'get',
        params:data
    });
}

export function activityTypeList(type) {
  return request({
      url: `/promotions/list/${type}`,
      method: 'get',
  });
}

export function cardRelated(id) {
  return request({
      url: `product/card/related/${id}`,
      method: 'get',
  });
}
