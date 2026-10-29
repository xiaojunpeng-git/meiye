// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import store from "../store";
import Cache from '../utils/cache';
import {
	Debounce
} from '@/utils/validate.js'
// #ifdef H5 || APP-PLUS
import {
	isWeixin
} from "../utils";
import auth from './wechat';
// #endif

import {
	LOGIN_STATUS,
	USER_INFO,
	EXPIRES_TIME,
	STATE_R_KEY
} from './../config/cache';
import Routine from '@/libs/routine';

function prePage() {
	let pages = getCurrentPages();
	let prePage = pages[pages.length - 1];
	// #ifndef APP-PLUS
	return prePage.route;
	// #endif
	// #ifdef APP-PLUS
	return prePage.$page.fullPath;
	// #endif

}




export const toLogin = Debounce(_toLogin, 800)

function _toLogin(push, pathLogin, returnUrl) {
	// #ifdef H5
	if (isWeixin()) {
		if (!uni.getStorageSync('authIng')) {
			store.commit("LOGOUT");
		}
	} else {
		store.commit("LOGOUT");
	}
	// #endif
	// #ifndef H5
	store.commit("LOGOUT");
	// #endif
	let path = prePage();

	// #ifdef H5
	path = location.pathname + location.search;
	// #endif

	if (!pathLogin)
		pathLogin = '/page/users/login/index'
	// 公开任务登录后返回原深链；只接收应用内路径，不允许外站跳转。
	if (typeof returnUrl === 'string' && /^\/pages\/[a-zA-Z0-9_/?=&%.-]+$/.test(returnUrl)) path = returnUrl;
	Cache.set('login_back_url', path);
	// #ifdef H5
	// 显式深链返回的业务先到标准微信登录页；不要在问卷URL上附加OAuth code或覆盖任务参数。
	if (returnUrl && /^\/pages\/[a-zA-Z0-9_/?=&%.-]+$/.test(returnUrl) && isWeixin()) {
		uni.navigateTo({ url: '/pages/users/wechat_login/index' });
		return;
	}
	// #endif
	let wechatStatus = uni.getStorageSync('wechatStatus');
	const BASIC_CONFIG = Cache.get('BASIC_CONFIG')
	// #ifdef H5
	if (isWeixin() && wechatStatus) {
		let urlData = location.pathname + location.search
		if (urlData.indexOf('?') !== -1) {
			urlData += '&go_longin=1';
		} else {
			urlData += '?go_longin=1';
		}
		if (!Cache.has('snsapiKey')) {
			auth.oAuth('snsapi_base', urlData);
		} else {
			uni.navigateTo({
				url: '/pages/users/wechat_login/index',
			});
		}

	} else {
		uni.navigateTo({
			url: '/pages/users/login/index'
		})
	}
	// #endif

	// #ifdef MP
	let url
	// 问卷提交要求已授权手机号，显式返回问卷时统一走现有强制手机号授权页。
	if (returnUrl && /^\/pages\/[a-zA-Z0-9_/?=&%.-]+$/.test(returnUrl)) {
		url = '/pages/users/wechat_login/index'
	} else if (!BASIC_CONFIG.wechat_auth_switch) {
		url = '/pages/users/binding_phone/index?pageType=0'
	} else {
		url = '/pages/users/wechat_login/index'
	}
	uni.navigateTo({
		url
	})
	// Routine.getCode()
	// 	.then(code => {
	// 		console.log(code)
	// 		Routine.silenceAuth(code).then(res => {
	// 			console.log(res)
	// 		})
	// 	})
	// 	.catch(err => {
	// 		uni.hideLoading();
	// 	});
	// #endif

	// #ifdef APP-PLUS
	uni.navigateTo({
		url: '/pages/users/login/index'
	})
	// #endif

}


export function checkLogin() {
	let token = Cache.get(LOGIN_STATUS);
	let expiresTime = Cache.get(EXPIRES_TIME);
	let newTime = Math.round(new Date() / 1000);
	if (expiresTime < newTime || !token) {
		uni.setStorageSync('authIng', false)
		Cache.clear(LOGIN_STATUS);
		Cache.clear(EXPIRES_TIME);
		Cache.clear(USER_INFO);
		Cache.clear(STATE_R_KEY);
		return false;
	} else {
		store.commit('UPDATE_LOGIN', token);
		let userInfo = Cache.get(USER_INFO, true);
		if (userInfo) {
			store.commit('UPDATE_USERINFO', userInfo);
		}
		return true;
	}

}
