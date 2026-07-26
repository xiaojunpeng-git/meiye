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

function isOverviewMenu(item) {
  if (!item) return false;
  return item.unique_auth === 'admin-index-index' ||
    item.title === '概况' ||
    item.menu_name === '概况';
}

/** 数据大屏等新开页，禁止作为登录/顶栏默认落地 */
function isExternalOrScreenMenu(item) {
  if (!item) return false;
  if (item.target === '_blank') return true;
  if (item.unique_auth === 'admin-operating-screen') return true;
  return /\/operating-screen\/?$/.test(String(item.path || ''));
}

/**
 * 解析登录/顶栏默认落地路径：优先「概况」，避免菜单数组顺序把「组织架构」等排在前面时误进业务页。
 * @param {Array|Object} menus 顶层菜单数组，或单个带 children 的菜单节点
 * @returns {string}
 */
util.resolveDefaultMenuPath = function resolveDefaultMenuPath(menus) {
  const fallback = `${Setting.roterPre}/home/`;
  if (!menus) return fallback;

  const pickLeaf = (node) => {
    if (!node) return '';
    if (node.children && node.children.length) {
      const overviewChild = node.children.find(isOverviewMenu);
      if (overviewChild) return pickLeaf(overviewChild);
      const firstInApp = node.children.find((c) => c && !isExternalOrScreenMenu(c));
      if (firstInApp) return pickLeaf(firstInApp);
      return '';
    }
    if (isExternalOrScreenMenu(node)) return '';
    return node.path || '';
  };

  if (Array.isArray(menus)) {
    if (!menus.length) return fallback;
    for (let i = 0; i < menus.length; i++) {
      const hit = (function findOverview(list) {
        for (let j = 0; j < list.length; j++) {
          const item = list[j];
          if (isOverviewMenu(item)) return item;
          if (item.children && item.children.length) {
            const nested = findOverview(item.children);
            if (nested) return nested;
          }
        }
        return null;
      })(menus[i].children && menus[i].children.length ? menus[i].children : [menus[i]]);
      if (hit) return pickLeaf(hit) || fallback;
    }
    return pickLeaf(menus[0]) || fallback;
  }

  return pickLeaf(menus) || fallback;
};

/**
 * 平台端免密进入门店后台（写入门店端 cookie / localStorage 后打开门店首页）
 * 开发预览 18081 与门店集成页 8080 不同端口时，通过 store_auto_login.html 桥接会话。
 */
util.openStoreBackend = function openStoreBackend(data, options = {}) {
  if (!data || !data.token) {
    throw new Error('门店登录信息无效');
  }
  const menus = JSON.parse(JSON.stringify(data.menus || []));
  util.makeMenu(`/${data.prefix}`, menus);
  const expires = data.expires_time;
  const pageTitle = options.pageTitle || '';
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
    window.localStorage.setItem('menuListStore', storagePayload.menuListStore);
    window.localStorage.setItem('uniqueAuthStore', storagePayload.uniqueAuthStore);
    window.localStorage.setItem('userInfoStore', storagePayload.userInfoStore);
  } catch (e) {
    // ignore
  }

  const storeOrigin = Setting.apiBaseURL.replace(/\/adminapi\/?$/, '');
  const sameOrigin = !storeOrigin || storeOrigin === window.location.origin;
  if (sameOrigin) {
    const baseURL = `${storeOrigin}/${data.prefix}/home/`;
    window.open(baseURL);
    return;
  }

  const bridgeUrl = `${storeOrigin}/store_auto_login.html`;
  const win = window.open(bridgeUrl);
  if (!win) {
    throw new Error('请允许浏览器弹出窗口');
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
