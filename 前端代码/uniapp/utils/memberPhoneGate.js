import { LOGIN_STATUS, USER_INFO } from '@/config/cache';
import Cache from '@/utils/cache';

// 公开调查仅允许查看与填写；提交和奖励仍由认证接口保护，其他业务深链继续执行手机号门禁。
const PHONE_GATE_ALLOWLIST = [
	'pages/users/wechat_login/index',
	'pages/users/privacy/index',
	'pages/users/survey/index'
];
const LOGIN_ROUTE = '/pages/users/wechat_login/index';
let interceptorsInstalled = false;
let redirecting = false;

function normalizeRoute(url = '') {
	return String(url).replace(/^\//, '').split('?')[0].split('#')[0];
}

function currentRoute() {
	const pages = getCurrentPages();
	return pages.length ? normalizeRoute(pages[pages.length - 1].route) : '';
}

function isAllowedRoute(route) {
	return PHONE_GATE_ALLOWLIST.indexOf(normalizeRoute(route)) !== -1;
}

/**
 * 手机号是会员端有效会话的一部分：只有 token 与非空手机号同时存在才允许浏览业务页。
 * 这里读取 Vuex 和持久缓存，兼容冷启动时 Vuex 尚未完成恢复的短暂窗口。
 */
export function hasMemberPhoneAuthorization(store) {
	const token = (store && store.getters && store.getters.isLogin) || Cache.get(LOGIN_STATUS);
	const storeUser = store && store.state && store.state.app ? store.state.app.userInfo : null;
	// 旧 Vuex 初始化会把 USER_INFO 作为 JSON 字符串读入，不能因此误拦截已绑定手机的会员。
	const hasObjectUser = storeUser && typeof storeUser === 'object' && !Array.isArray(storeUser) && Object.keys(storeUser).length;
	const userInfo = hasObjectUser ? storeUser : (Cache.get(USER_INFO, true) || {});
	return Boolean(token && String(userInfo.phone || '').trim());
}

function redirectToMemberLogin() {
	if (redirecting || currentRoute() === normalizeRoute(LOGIN_ROUTE)) return;
	redirecting = true;
	uni.reLaunch({
		url: LOGIN_ROUTE,
		complete: () => {
			redirecting = false;
		}
	});
}

/**
 * 校验当前页面；返回 false 表示当前业务页已被拦截并开始跳转登录页。
 */
export function enforceMemberPhoneGate(store) {
	if (hasMemberPhoneAuthorization(store) || isAllowedRoute(currentRoute())) return true;
	redirectToMemberLogin();
	return false;
}

/**
 * 拦截全部常见页面跳转（包括返回），防止用户从分享链接、底部导航或历史页面绕过手机号授权。
 */
export function installMemberPhoneGate(store) {
	if (interceptorsInstalled) return;
	interceptorsInstalled = true;
	['navigateTo', 'redirectTo', 'reLaunch', 'switchTab', 'navigateBack'].forEach(method => {
		uni.addInterceptor(method, {
			invoke(options = {}) {
				const targetRoute = method === 'navigateBack' ? '' : normalizeRoute(options.url || '');
				if (hasMemberPhoneAuthorization(store) || isAllowedRoute(targetRoute)) return true;
				redirectToMemberLogin();
				return false;
			}
		});
	});
}
