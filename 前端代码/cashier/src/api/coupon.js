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
 * 优惠券领取
 */
export function receiveCoupon(uid,data) {
  return request({
    url: `coupon/receive/${uid}`,
    method: 'post',
    data
  });
}

/**
 * @description 已发布管理--列表
 * @param {Object} param params {Object} 传值参数
 */
export function releasedListApi (params) {
  return request({
    url: 'coupon/released',
    method: 'get',
    params
  });
}
