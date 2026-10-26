// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import cookies from './util.cookies';
import log from './util.log';
import db from './util.db';
import Cookies from 'js-cookie';

import Setting from '@/setting';

const util = {
  cookies,
  log,
  db
};

function tTitle(title = '') {
  if (window && window.$t) {
    if (title.indexOf('$t:') === 0) {
      return window.$t(title.split('$t:')[1]);
    } else {
      return title;
    }
  } else {
    return title;
  }
}

/**
 * @description 更改标题
 * @param {Object} title 标题
 * @param {Object} count 未读消息数提示（可视情况选择使用或不使用）
 */
util.title = function({ title, count }) {
  title = tTitle(title);
  let fullTitle = '';
  if (util.cookies.get('pageTitle')) {
    fullTitle = title ? `${title} - ${util.cookies.get('pageTitle')}` : util.cookies.get('pageTitle');
  } else {
    fullTitle = title ? `${title} - ${Setting.titleSuffix}` : Setting.titleSuffix;
  }
  if (count) fullTitle = `(${count}条消息)${fullTitle}`;
  window.document.title = fullTitle;
};

util.wss = function(wsSocketUrl) {
  const ishttps = document.location.protocol == 'https:';
  if (ishttps) {
    return wsSocketUrl.replace('ws:', 'wss:');
  } else {
    return wsSocketUrl.replace('wss:', 'ws:');
  }
};

util.makeMenu = function makeMenu(prefix, menus) {
  for (let i = 0; i < menus.length; i++) {
    menus[i].path = `${prefix}${menus[i].path}`;
    if (!menus[i].children) {
      continue;
    }
    makeMenu(prefix, menus[i].children);
  }
};

/** 数据大屏等新开页，禁止作为登录/顶栏默认落地 */
function isExternalOrScreenMenu(item) {
  if (!item) return false;
  if (item.target === '_blank') return true;
  if (item.unique_auth === 'admin-operating-screen') return true;
  return /\/operating-screen\/?$/.test(String(item.path || ''));
}

/**
 * 解析登录/顶栏默认落地路径：严格沿服务端权限菜单顺序，进入第一个大类的
 * 第一个站内叶子功能。菜单顺序就是管理员配置的业务顺序，不能再跨大类优先
 * 寻找“概况”，也不能把外链或数据大屏当成登录后的当前页面。
 * @param {Array|Object} menus 顶层菜单数组，或单个带 children 的菜单节点
 * @returns {string}
 */
util.resolveDefaultMenuPath = function resolveDefaultMenuPath(menus) {
  // 空菜单不是首页授权；登录及刷新后的默认落地均保持业务内容为空。
  const emptyWorkspace = `${Setting.roterPre}/workspace`;
  if (!menus) return emptyWorkspace;

  const pickLeaf = (node) => {
    if (!node) return '';
    if (node.children && node.children.length) {
      // 某个菜单分组可能只含外链；继续检查同级后续菜单，避免误落空白页。
      for (let i = 0; i < node.children.length; i++) {
        const child = node.children[i];
        if (!child || isExternalOrScreenMenu(child)) continue;
        const path = pickLeaf(child);
        if (path) return path;
      }
      return '';
    }
    if (isExternalOrScreenMenu(node)) return '';
    return node.path || '';
  };

  if (Array.isArray(menus)) {
    if (!menus.length) return emptyWorkspace;
    for (let i = 0; i < menus.length; i++) {
      const path = pickLeaf(menus[i]);
      if (path) return path;
    }
    return emptyWorkspace;
  }

  return pickLeaf(menus) || emptyWorkspace;
};

/**
 * 平台端免密进入门店端。
 *
 * 平台只负责签发受控的门店会话；新门店端从同源 cashier_token / localStorage
 * 读取该会话后，仍由服务端按令牌解析门店与权限，前端不携带或猜测门店范围。
 */
util.openStoreBackend = function openStoreBackend(data, options = {}) {
  if (!data || !data.token) {
    throw new Error('门店登录信息无效');
  }
  const menus = JSON.parse(JSON.stringify(data.menus || []));
  util.makeMenu(`/${data.prefix}`, menus);
  const expires = data.expires_time;
  const pageTitle = options.pageTitle || '';
  const targetWindow = options.targetWindow || null;
  util.cookies.setStore('token', data.token, { expires });
  util.cookies.setStore('uuid', data.user_info.id, { expires });
  util.cookies.setStore('expires_time', expires, { expires });
  if (pageTitle) {
    util.cookies.setStore('pageTitle', pageTitle, { expires });
  }
  const userInfoStore = {
    account: data.user_info.account,
    head_pic: data.user_info.avatar,
    logo: data.logo,
    logoSmall: data.logo_square,
    version: data.version,
  };
  const storagePayload = {
    menuListStore: JSON.stringify(menus),
    uniqueAuthStore: JSON.stringify(data.unique_auth || []),
    userInfoStore: JSON.stringify(userInfoStore),
  };
  try {
    // 收银 V3 优先读取这个独立会话名。平台页面可能保留旧收银标签页，
    // 因而不能只写 store-token/localStorage，否则旧 cashier_token 会覆盖
    // 本次“进入门店”签发的目标门店超级管理员会话。
    Cookies.set('cashier_token', data.token, { expires, path: '/' });
    window.localStorage.setItem('menuListStore', storagePayload.menuListStore);
    window.localStorage.setItem('uniqueAuthStore', storagePayload.uniqueAuthStore);
    window.localStorage.setItem('userInfoStore', storagePayload.userInfoStore);
    // Cashier V3 only accepts a same-origin token. Do not pass it through the
    // URL, which would expose it to history, referrers, and copied links.
    // 平台端与门店端同源时，通用 token 属于平台登录态。收银 V3 已优先
    // 从 cashier_token 读取门店会话，不能覆盖平台 token，否则切换门店会
    // 破坏当前管理员会话并让已有收银页回退到错误门店。
    window.localStorage.setItem('cashier_store_title', pageTitle);
  } catch (e) {
    // ignore
  }

  const storeOrigin = Setting.apiBaseURL.replace(/\/adminapi\/?$/, '');
  const isLocalDevelopment = process.env.NODE_ENV === 'development'
    && typeof window !== 'undefined'
    && ['127.0.0.1', 'localhost'].includes(window.location.hostname);
  if (isLocalDevelopment) {
    // 平台开发页必须连接收银 V3 源码热更新服务，不能回退到 8080 的上一次
    // 受控静态构建。库存由该收银页继续嵌入独立的 18088 源码服务。
    const cashierDevOrigin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || '')
      .trim()
      .replace(/\/+$/, '');
    if (!cashierDevOrigin) {
      throw new Error('门店端开发地址未配置');
    }
    const cashierURL = `${cashierDevOrigin}/view_cashier_v3/#/cashier`;
    if (targetWindow) {
      targetWindow.location.replace(cashierURL);
    } else {
      window.open(cashierURL);
    }
    return;
  }
  const sameOrigin = !storeOrigin || storeOrigin === window.location.origin;
  if (sameOrigin) {
    // 8080 serves the published V3 bundle. Vite's development entry only
    // exists on its dedicated dev server, so it must not be selected here.
    const cashierPath = '/cashier-v3/#/cashier';
    const cashierURL = `${storeOrigin}${cashierPath}`;
    if (targetWindow) {
      targetWindow.location.replace(cashierURL);
    } else {
      window.open(cashierURL);
    }
    return;
  }

  const bridgeUrl = `${storeOrigin}/store_auto_login.html`;
  const win = targetWindow || window.open(bridgeUrl);
  if (!win) {
    throw new Error('请允许浏览器弹出窗口');
  }
  if (targetWindow) {
    targetWindow.location.replace(bridgeUrl);
  }
  const payload = {
    token: data.token,
    uuid: String(data.user_info.id),
    expires_time: expires,
    pageTitle,
    storage: storagePayload,
  };
  let tries = 0;
  const timer = setInterval(() => {
    tries += 1;
    if (win.closed || tries > 60) {
      clearInterval(timer);
      return;
    }
    try {
      win.postMessage({ type: 'MOHE_STORE_AUTO_LOGIN', payload }, storeOrigin);
    } catch (e) {
      // ignore
    }
  }, 100);
};

function requestAnimation(task) {
  if ('requestAnimationFrame' in window) {
    return window.requestAnimationFrame(task);
  }

  setTimeout(task, 16);
}

export { requestAnimation };

export default util;
