// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
/**
 * 布局菜单配置
 * */
import { menusApi } from '@/api/account';
import Setting from '@/setting';
import util from '@/libs/util';
import { isAgentPath } from '@/utils/pathUtils';
import { normalizeOrganizationWorkspaceMenu } from '@/libs/organizationWorkspaceMenu';
import { normalizeProductBusinessConfigMenu } from '@/libs/productBusinessConfigMenu';
import { disambiguateDuplicateMenuPaths } from '@/libs/menuRouteDisambiguation';

// "出入库记录" has been consolidated into inventory query/statistics.  Filter
// the legacy entry here as well as in the menu migration so a browser with an
// older cached menu cannot keep exposing a closed workflow after refresh.
function withoutLegacyInventoryMovement(menuData) {
  if (!Array.isArray(menuData)) return [];
  return menuData
    .filter((item) => {
      const path = String((item && (item.path || item.menu_path)) || '');
      return item && item.unique_auth !== 'admin-inventory-statistics'
        && !/\/?inventory\/statistics\/?$/.test(path)
        && item.title !== '出入库记录';
    })
    .map(item => ({
      ...item,
      children: Array.isArray(item.children) ? withoutLegacyInventoryMovement(item.children) : item.children
    }));
}

// 数据大屏不再作为平台端侧栏入口。同步过滤旧版本写入浏览器缓存的节点，
// 避免用户刷新后继续看到已下线的固定菜单。
function withoutOperatingScreenMenu(menuData) {
  if (!Array.isArray(menuData)) return [];
  return menuData
    .filter((item) => {
      const path = String((item && (item.path || item.menu_path)) || '');
      return item && item.unique_auth !== 'admin-operating-screen'
        && !/\/operating-screen\/?$/.test(path);
    })
    .map(item => ({
      ...item,
      children: Array.isArray(item.children) ? withoutOperatingScreenMenu(item.children) : item.children
    }));
}

// 门店运营报表必须由权限菜单返回，不能由浏览器补造。仅移除旧版缓存中前端
// 补造的节点；数据库返回的菜单 ID 为数字，必须保留其真实父子层级。
function withoutLegacyStoreOperationsMenu(menuData) {
  if (!Array.isArray(menuData)) return [];
  return menuData
    .filter((item) => {
      if (!item) return false;
      return !String(item.id || '').startsWith('admin-report-store-operations-');
    })
    .map(item => ({
      ...item,
      children: Array.isArray(item.children) ? withoutLegacyStoreOperationsMenu(item.children) : item.children
    }));
}

// 门店运营的报表名称已经表达清楚功能。18 个二级报表共用统计图标会造成
// 视觉噪音，因此只在平台菜单渲染模型中清空这一组图标；父级菜单及其他模块不受影响。
function withoutStoreOperationsReportIcons(menuData) {
  if (!Array.isArray(menuData)) return [];
  return menuData.map(item => {
    if (!item) return item;
    const children = Array.isArray(item.children) ? withoutStoreOperationsReportIcons(item.children) : item.children;
    if (String(item.unique_auth || '').startsWith('admin-report-store-operations-')) {
      return { ...item, icon: '', custom: '', children };
    }
    return { ...item, children };
  });
}

function normalizeMenus(menuData, prefix) {
  return disambiguateDuplicateMenuPaths(normalizeProductBusinessConfigMenu(
    normalizeOrganizationWorkspaceMenu(withoutStoreOperationsReportIcons(withoutLegacyStoreOperationsMenu(withoutOperatingScreenMenu(withoutLegacyInventoryMovement(menuData)))), prefix),
    prefix
  ));
}

function getMenusName() {
  let menuList, roterPre;
  let storage = window.localStorage,
    menuData;
  if (isAgentPath()) {
    menuList = storage.getItem('agent_menuList');
    roterPre = storage.getItem('agent_roterPre');
  } else {
    menuList = storage.getItem('menuList');
    roterPre = storage.getItem('roterPre');
  }
  try {
    menuData = menuList !== undefined ? JSON.parse(menuList) : [];
  } catch (e) {}
  const prefix = isAgentPath() ? Setting.routePreAgent : Setting.roterPre;
  return normalizeMenus(menuData, prefix);
}

export default {
  namespaced: true,
  state: {
    menusName: getMenusName(),
    // 返回首页path
    indexPath: ''
  },
  mutations: {
    getmenusNav(state, menuList) {
      const storage = window.localStorage;
      const prefix = isAgentPath() ? Setting.routePreAgent : Setting.roterPre;
      menuList = normalizeMenus(menuList, prefix);
      state.menusName = menuList;
      if (isAgentPath()) {
        storage.setItem('agent_menuList', JSON.stringify(menuList));
        storage.setItem('agent_roterPre', 'agent');
      } else {
        storage.setItem('menuList', JSON.stringify(menuList));
        storage.setItem('roterPre', Setting.roterPre);
      }
    },
    getAgentMenusNav(state, menuList) {
      const storage = window.localStorage;
      const prefix = isAgentPath() ? Setting.routePreAgent : Setting.roterPre;
      menuList = normalizeMenus(menuList, prefix);
      //   state.menusName = menuList;
      storage.setItem('agent_menuList', JSON.stringify(menuList));
      storage.setItem('agent_roterPre', 'agent');
    },
    /**
     * @description 设置返回首页path
     * @param {Object} state vuex state
     * @param {Array} menu menu
     */
    setIndexPath(state, data) {
      state.indexPath = data;
    }
  },
  getters: {
    indexPath(state, getters) {
      const menus = state.menusName;
      if (menus.length && !state.indexPath) {
        state.indexPath = util.resolveDefaultMenuPath(menus);
      } else if (!menus.length && !state.indexPath) {
        return `${Setting.roterPre}/home/`;
      }
      return state.indexPath;
    }
  },
  actions: {
    getMenusNavList({ commit }) {
      return new Promise((resolve, reject) => {
        menusApi()
          .then(async(res) => {
            resolve(res);
            util.makeMenu(Setting.roterPre, res.data.menus);
            commit('getmenusNav', res.data.menus);
          })
          .catch((res) => {
            reject(res);
          });
      });
    }
  }
};
