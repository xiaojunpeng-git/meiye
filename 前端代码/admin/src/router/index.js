// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import Vue from 'vue';
import VueRouter from 'vue-router';
import iView from 'view-design';

import util from '@/libs/util';

import Setting from '@/setting';

import store from '@/store/index';

// 路由数据
import routes from './routes';

import { includeArray } from '@/libs/system';
import { isAgentPath } from '@/utils/pathUtils';
import moheAiRequest from '@/api/moheAi';
import { managementRouteAccess } from '../../../shared/mohe-ai/management-route-access.mjs';

Vue.use(VueRouter);

/**
 * 重写路由的push方法
 */
const routerPush = VueRouter.prototype.push;
VueRouter.prototype.push = function push(location) {
  return routerPush.call(this, location).catch((error) => error);
};

// 生成带agent前缀的路由函数
function generateAgentRoutes(
  routes,
  prefix = 'agent',
  split = '',
  isChild = false
) {
  return routes.map((route) => {
    let newRoute;
    if (isChild) {
      newRoute = {
        ...route,
        name: route.name ? `agent_${route.name}` : undefined,
        meta: {
          ...route.meta,
          isAgentRoute: true
        }
      };
    } else {
      newRoute = {
        ...route,
        path: route.path.replace(Setting.roterPre, Setting.routePreAgent),
        name: route.name ? `agent_${route.name}` : undefined,
        meta: {
          ...route.meta,
          isAgentRoute: true
        }
      };
    }

    // 递归处理子路由
    if (route.children) {
      newRoute.children = generateAgentRoutes(route.children, '', '', true); // 子路由不需要再加前缀
    }

    return newRoute;
  });
}

// 动态添加agent路由
const agentRoutes = generateAgentRoutes(routes, Setting.routePreAgent, '');

// 导出路由 在 main.js 里使用
const router = new VueRouter({
  routes: [...routes],
  mode: Setting.routerMode
});
router.addRoutes(agentRoutes);

/**
 * 路由拦截
 * 权限验证
 */

router.beforeEach(async(to, from, next) => {
  const targetAnalysisPath = `${Setting.roterPre}/target/data_analysis`;
  if (
    to.matched.length === 1 &&
    to.matched[0].name === 'target' &&
    to.path !== targetAnalysisPath
  ) {
    return next({ path: targetAnalysisPath, replace: true });
  }

  // 提取权限数据
  const agent_access = localStorage.getItem('agent_unique_auth')
    ? localStorage.getItem('agent_unique_auth').split(',')
    : [];
  const access = localStorage.getItem('unique_auth')
    ? localStorage.getItem('unique_auth').split(',')
    : [];

  // 客服路由直接通过
  if (to.fullPath.indexOf(`${Setting.routePreKF}`) !== -1) {
    return next();
  }

  // 需要身份验证的路由
  if (to.matched.some((route) => route.meta.auth)) {
    return await handleAuthenticatedRoute(to, next, agent_access, access);
  }

  // 不需要身份验证的路由
  return handlePublicRoute(to, next);
});

// 处理需要身份验证的路由
async function handleAuthenticatedRoute(to, next, agent_access, access) {
  const db = await store.dispatch('admin/db/database', { user: true });
  const token = Vue.prototype.__getToken();

  if (!token || token === 'undefined') {
    store.dispatch('admin/db/databaseClear', { user: true });
    return next({
      name: 'login',
      query: { redirect: to.fullPath }
    });
  }

  // 动态子路由可继承父级权限；不能假定末级路由自身声明 auth。
  const permissionRoute = [...to.matched]
    .reverse()
    .find(route => route.meta && route.meta.auth);
  const meta = permissionRoute ? permissionRoute.meta : {};
  const userInfo = store.state.admin.user.info || {};
  // Old login snapshots may not include account. Resolve this dedicated entry
  // from the current server principal, never from root-level or cached access.
  if (meta.moheAiMaintainer) {
    store.commit('admin/menu/setMoheAiVerifiedUser', null);
    if (meta.isAgentRoute) return next({ name: '403' });
    // The configuration endpoint resolves the current bearer-token principal.
    // Menu cache fields are never enough to decide this special entry.
    const decision = await managementRouteAccess(moheAiRequest);
    if (!decision.allowed) return next({ name: '403' });
    if (decision.serverVerified) store.commit('admin/menu/setMoheAiVerifiedUser', userInfo);
    // A menu refresh is a presentation update. An operational refresh failure
    // must still allow the destination to show its precise server message.
    try {
      await store.dispatch('admin/menus/getMenusNavList');
    } catch (ignored) {
      // Endpoint authorization remains authoritative.
    }
    return next();
  }
  const account = String(userInfo.account || userInfo.username || '').trim().toLowerCase();
  const isRootAdmin = (
    Number(userInfo.level) === 0 && Number(userInfo.admin_type || userInfo.adminType || 0) !== 3
  ) || account === 'admin';
  const isPermission = isRootAdmin || includeArray(
    meta.auth,
    meta.isAgentRoute ? agent_access : access
  );

  return next(isPermission ? undefined : { name: '403' });
}

// 处理公共路由
function handlePublicRoute(to, next) {
  const hasMenus = store.state.admin.menus.menusName && store.state.admin.menus.menusName.length;
  const isLoginPage = [
    `${Setting.roterPre}/login`,
    `${Setting.routePreAgent}/login`,
    '/app/upload'
  ].includes(to.path);

  if (hasMenus || isLoginPage) {
    return next();
  }

  store.dispatch('admin/db/databaseClear', { user: true });
  const pathName = isAgentPath() ? 'agentLogin' : 'login';
  const roterPre = isAgentPath() ? 'agent_roterPre' : 'roterPre';

  return next({
    name: pathName,
    query: {
      redirect: to.fullPath.replace(
        localStorage.getItem(roterPre),
        Setting.roterPre
      )
    }
  });
}

router.afterEach((to) => {
  // if (Setting.showProgressBar) iView.LoadingBar.finish();
  // 多页控制 打开新的页面
  store.dispatch('admin/page/open', to);

  // 更改标题
  util.title({
    title: to.meta.title
  });
  // 返回页面顶端
  window.scrollTo(0, 0);
});

export default router;
