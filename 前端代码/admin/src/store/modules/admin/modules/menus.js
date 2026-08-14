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

const STORE_OPERATION_REPORTS = [
  { code: 'partner_item_summary', title: '合作方品项汇总' },
  { code: 'partner_item_detail', title: '合作方品项明细' },
  { code: 'member_consumption_detail', title: '会员消费明细' },
  { code: 'store_item_analysis', title: '门店品项分析' },
  { code: 'store_craftsman_consumption', title: '门店手艺人消耗' },
  { code: 'store_salesperson_performance', title: '门店销售人业绩' },
  { code: 'market_performance', title: '市场业绩' }
];

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

// 门店运营七表是平台“数据”菜单下的统一入口。部分本地账号的菜单权限缓存
// 仍来自旧菜单树，后端不会把新节点返回到该账号的 role rules 中；这里补齐
// 受控的前端入口，路由和接口权限仍由后端/路由守卫校验，不改变数据范围。
function withStoreOperationsMenu(menuData) {
  if (!Array.isArray(menuData)) return [];
  const routePrefix = isAgentPath() ? Setting.routePreAgent : Setting.roterPre;
  const dataMenu = menuData.find(item => {
    if (!item) return false;
    const path = String(item.path || item.menu_path || '').replace(/\/$/, '');
    return item.header === 'data'
      || path === `${routePrefix}/data`
      || item.title === '数据'
      || item.menu_name === '数据';
  });
  if (!dataMenu) return menuData;
  if (!Array.isArray(dataMenu.children)) dataMenu.children = [];
  const reportPathPrefix = `${routePrefix}/report/store-operations/`;
  const isGeneratedReport = (item) => {
    if (!item) return false;
    const path = String(item.path || item.menu_path || '').replace(/\/$/, '');
    return item.unique_auth === 'admin-report-store-operations'
      || String(item.unique_auth || '').startsWith('admin-report-store-operations-')
      || item.title === '门店运营'
      || item.menu_name === '门店运营'
      || path === `${routePrefix}/report/business-center`
      || path === `${routePrefix}/report/store-operations`
      || path.startsWith(reportPathPrefix);
  };
  // Replace the old single hub entry (including stale cached menu nodes) with
  // seven ordinary second-level links. No menu API call or menu-state refresh
  // is needed when switching between these links; they are normal router paths.
  dataMenu.children = dataMenu.children.filter(child => !isGeneratedReport(child));
  menuData.forEach(item => {
    if (!item || item === dataMenu || !Array.isArray(item.children)) return;
    item.children = item.children.filter(child => !isGeneratedReport(child));
  });
  STORE_OPERATION_REPORTS.forEach((report, index) => {
    dataMenu.children.push({
      id: `admin-report-store-operations-${report.code}`,
      pid: dataMenu.id,
      title: report.title,
      menu_name: report.title,
      icon: index === 0 ? 'ios-pie-outline' : 'ios-stats-outline',
      path: `${reportPathPrefix}${report.code}`,
      target: '_self',
      header: '',
      is_header: 0,
      is_show_path: 0,
      unique_auth: `admin-report-store-operations-${report.code}`,
      children: []
    });
  });
  return menuData;
}

function normalizeMenus(menuData, prefix) {
  return withStoreOperationsMenu(withOperatingScreenMenu(normalizeProductBusinessConfigMenu(
    normalizeOrganizationWorkspaceMenu(withoutLegacyInventoryMovement(menuData), prefix),
    prefix
  )));
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
