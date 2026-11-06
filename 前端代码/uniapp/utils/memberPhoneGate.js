import { LOGIN_STATUS, USER_INFO } from '@/config/cache';
import Cache from '@/utils/cache';
import { getMemberSessionRecovery } from '@/utils/memberSessionRecovery';

// 访客可浏览公开首页、分类、门店与商品；会员权益和个人业务仍在进入时校验手机号。
const PHONE_GATE_ALLOWLIST = [
	'pages/users/wechat_login/index',
	'pages/users/privacy/index',
	'pages/users/survey/index',
	// 小程序默认先打开公开启动页，再切换到首页。
	'pages/guide/index',
	'pages/index/index',
	'pages/goods_cate/goods_cate',
	'pages/store_list/index',
	'pages/store/home/index',
	'pages/goods_details/index',
	// 活动展示和适用门店是公开信息；购买及参团仍在操作入口校验手机号。
	'pages/activity/goods_details/index',
	'pages/activity/goods_combination/index',
	'pages/activity/goods_combination_status/index',
	'pages/store/list/index'
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
 * 手机号是会员端有效会话的一部分：只有 token 与非空手机号同时存在才允许进入个人业务页。
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

function redirectToMemberLogin(pendingRoute = '') {
	// Never let cold-start onShow race the existing-session/code recovery. Block
	// the attempted navigation now, then recheck its target after identity is known.
	const recovery = getMemberSessionRecovery();
	if (recovery) {
		// 当前页可能是公开首页；不能用当前页代替刚被拦下的会员业务目标。
		recovery.then(() => {
			if (!hasMemberPhoneAuthorization(memberStore) && !isAllowedRoute(pendingRoute)) redirectToMemberLogin(pendingRoute);
		});
		return;
	}
	if (redirecting || currentRoute() === normalizeRoute(LOGIN_ROUTE)) return;
	redirecting = true;
	uni.reLaunch({
		url: LOGIN_ROUTE,
		complete: () => {
			redirecting = false;
		}
	});
}

/** 返回时按真实页面栈判定目标，避免访客从商品详情返回公开首页时被误送登录。 */
function routeAfterBack(delta = 1) {
	const pages = getCurrentPages();
	const steps = Math.max(1, Number(delta) || 1);
	const target = pages[pages.length - 1 - steps];
	return target ? normalizeRoute(target.route) : '';
}

let memberStore = null;

/**
 * 校验当前页面；返回 false 表示当前业务页已被拦截并开始跳转登录页。
 */
export function enforceMemberPhoneGate(store, pendingRoute = '') {
    // 冷启动时页面栈尚未建立，使用框架传入的落地页；已有页面永远优先，不能借旧扫码路径放宽门禁。
    if (hasMemberPhoneAuthorization(store) || isAllowedRoute(currentRoute() || pendingRoute)) return true;
	redirectToMemberLogin(currentRoute() || pendingRoute);
	return false;
}

/**
 * 拦截全部常见页面跳转（包括返回），防止用户从分享链接、底部导航或历史页面绕过手机号授权。
 */
export function installMemberPhoneGate(store) {
	memberStore = store;
	if (interceptorsInstalled) return;
	interceptorsInstalled = true;
	['navigateTo', 'redirectTo', 'reLaunch', 'switchTab', 'navigateBack'].forEach(method => {
		uni.addInterceptor(method, {
			invoke(options = {}) {
				const targetRoute = method === 'navigateBack' ? routeAfterBack(options.delta) : normalizeRoute(options.url || '');
				if (hasMemberPhoneAuthorization(store) || isAllowedRoute(targetRoute)) return true;
				redirectToMemberLogin(targetRoute);
				return false;
			}
		});
	});
}
