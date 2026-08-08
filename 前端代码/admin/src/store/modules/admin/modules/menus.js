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

// 数据大屏作为总部下的普通菜单项，紧跟“概况”；同时补齐旧登录缓存。
function withOperatingScreenMenu(menuData) {
  if (!Array.isArray(menuData)) return [];
  const routePrefix = isAgentPath() ? Setting.routePreAgent : Setting.roterPre;
  const screenPath = `${routePrefix}/operating-screen`;
  const homeMenu = menuData.find(item => item && item.header === 'home');
  if (!homeMenu || !Array.isArray(homeMenu.children)) return menuData;

  const overviewIndex = homeMenu.children.findIndex(item => item && (
    item.unique_auth === 'admin-index-index' || item.title === '概况'
  ));
  const existedIndex = homeMenu.children.findIndex(item => item && (
    item.unique_auth === 'admin-operating-screen' ||
    /\/operating-screen\/?$/.test(item.path || '')
  ));
  const insertScreenAt = (screenMenu) => {
    screenMenu.target = '_blank';
    screenMenu.icon = screenMenu.icon || 'md-podium';
    // 有「概况」则紧跟其后；没有则追加到末尾，禁止插到首位以免登录误进大屏
    const idx = overviewIndex >= 0 ? overviewIndex + 1 : homeMenu.children.length;
    homeMenu.children.splice(idx, 0, screenMenu);
  };

  if (existedIndex >= 0) {
    const screenMenu = homeMenu.children.splice(existedIndex, 1)[0];
    const currentOverviewIndex = homeMenu.children.findIndex(item => item && (
      item.unique_auth === 'admin-index-index' || item.title === '概况'
    ));
    // 重新计算概况下标后再插入
    const insertIdx = currentOverviewIndex >= 0 ? currentOverviewIndex + 1 : homeMenu.children.length;
    screenMenu.target = '_blank';
    screenMenu.icon = screenMenu.icon || 'md-podium';
    homeMenu.children.splice(insertIdx, 0, screenMenu);
    return menuData;
  }

  insertScreenAt({
    id: 'operating-screen',
    pid: homeMenu.id,
    title: '数据大屏',
    menu_name: '数据大屏',
    icon: 'md-podium',
    path: screenPath,
    target: '_blank',
    header: '',
    is_header: 0,
    is_show_path: 0,
    unique_auth: 'admin-operating-screen'
  });
  return menuData;
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
  return withOperatingScreenMenu(normalizeProductBusinessConfigMenu(
    normalizeOrganizationWorkspaceMenu(withoutLegacyInventoryMovement(menuData), prefix),
    prefix
  ));
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
      menuList = withOperatingScreenMenu(normalizeProductBusinessConfigMenu(
        normalizeOrganizationWorkspaceMenu(withoutLegacyInventoryMovement(menuList), prefix),
        prefix
      ));
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
      menuList = withOperatingScreenMenu(normalizeProductBusinessConfigMenu(
        normalizeOrganizationWorkspaceMenu(withoutLegacyInventoryMovement(menuList), prefix),
        prefix
      ));
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
