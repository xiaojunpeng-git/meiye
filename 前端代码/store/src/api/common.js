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
 * 获取消息提醒
 */
export function jnoticeRequest () {
    return request({
        url: 'jnotice',
        method: 'GET'
    });
}

/**
 * @description 表格--删除
 * @param {Number} param id {Number} 配置id
 */
export function tableDelApi (data) {
    return request({
        url: data.url,
        method: data.method,
        data: data.ids,
        kefu:data.kefu || ''
    });
}

export function ajCaptcha(params){
  return request({
    url:'ajcaptcha',
    method:'get',
    params:params
  });
}

export function ajCaptchaCheck(data){
  return request({
    url:'ajcheck',
    method:'post',
    data:data
  });
}

export function getStoreQRCode(){
  return request({
    url:'/system/store/qrcode',
    method:'get'
  });
}
