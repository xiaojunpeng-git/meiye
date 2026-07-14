// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import {
	HTTP_REQUEST_URL,
	HEADER,
	TOKENNAME
} from '@/config/app';
import {
	toLogin,
	checkLogin
} from '../libs/login';
import store from '../store';
import pako from '../plugin/pako/pako.es5.min.js'

function toLoginMp(){
	uni.showToast({
		title: '请登录',
		icon: 'none',
		duration: 1000
	});
}

function decompress(str) {
      return pako.inflateRaw(base64ToUint8Array(str), {
        to: 'string'
      });
}

function base64ToUint8Array(base64String) {
  let padding = '='.repeat((4 - base64String.length % 4) % 4);
  let base64 = (base64String + padding)
    .replace(/\-/g, '+')
    .replace(/_/g, '/');
  let rawData = atob(base64);
  let outputArray = new Uint8Array(rawData.length);
  for (var i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

/**
 * 发送请求
 */
function baseRequest(url, method, data, {
	noAuth = false,
	noVerify = false
}) {
	let Url = HTTP_REQUEST_URL,
		header = HEADER;

	if (!noAuth) {
		//登录过期自动登录
		if (!store.state.app.token && !checkLogin()) {
			toLogin();
			return Promise.reject({
				msg: '未登录'
			});
		}
	}
	if (store.state.app.token) header[TOKENNAME] = 'Bearer ' + store.state.app.token;
    // 410000 请登录
	// 410001 登录已过期,请重新登录
	// 410002 登录状态有误,请重新登录
	// 410010 站点升级中，请稍候访问
	// 410020 您已被禁止登录，请联系管理员
	return new Promise((reslove, reject) => {
		uni.request({
			url: Url + '/api/' + url,
			method: method || 'GET',
			header: header,
			data: data || {},
			success: (res) => {
				
				if (res.data.data && res.data.gzde === 1) {
					try{
						res.data.data = JSON.parse(decompress(res.data.data));
					}catch(e){
						res.data.data = decompress(res.data.data);
					}
				}
				
				if (noVerify)
					reslove(res.data, res);
				else if (res.data.status == 200)
					reslove(res.data, res);
				else if ([410000, 410001, 410002].indexOf(res.data.status) !== -1) {
					toLogin();
					reject(res.data);
				} else if (res.data.status == 410010) {
					uni.showModal({
						title: '提示',
						content: res.data.msg,
						showCancel: false,
						confirmText: '我知道了'
					});
				} else if(res.data.status == 410020){
					// reject(res.data.msg);
					uni.showModal({
						title: '提示',
						content: res.data.msg,
						showCancel: false,
						confirmText: '我知道了'
					});
					uni.setStorageSync('authIng', true)
				} else if(res.data.status == 403){
					reject(res.data);
				} else {
					// 返回完整 data，便于页面展示 res.data.msg（勿只 reject 字符串）
					reject(res.data && typeof res.data === 'object' ? res.data : { msg: res.data.msg || String(res.data || '系统错误') });
				}
			},
			fail: (msg) => {
				let data = {
					mag: '请求失败',
					status: 1 //1没网
				}
				// #ifdef APP-PLUS
				reject(data);
				// #endif
				// #ifndef APP-PLUS
				reject('请求失败');
				// #endif
			}
		})
	});
}

const request = {};

['options', 'get', 'post', 'put', 'head', 'delete', 'trace', 'connect'].forEach((method) => {
	request[method] = (api, data, opt) => baseRequest(api, method, data, opt || {})
});



export default request;
