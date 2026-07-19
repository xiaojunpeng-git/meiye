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
  if (existedIndex >= 0) {
    const screenMenu = homeMenu.children.splice(existedIndex, 1)[0];
    screenMenu.target = '_blank';
    // 与“概况”保持同一套标准菜单结构；旧缓存里没有图标时在这里补齐。
    screenMenu.icon = screenMenu.icon || 'md-podium';
    const currentOverviewIndex = homeMenu.children.findIndex(item => item && (
      item.unique_auth === 'admin-index-index' || item.title === '概况'
    ));
    homeMenu.children.splice(currentOverviewIndex >= 0 ? currentOverviewIndex + 1 : 0, 0, screenMenu);
    return menuData;
  }

  homeMenu.children.splice(overviewIndex >= 0 ? overviewIndex + 1 : 0, 0, {
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
  return withOperatingScreenMenu(menuData);
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
      menuList = withOperatingScreenMenu(menuList);
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
      menuList = withOperatingScreenMenu(menuList);
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
