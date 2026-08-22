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

// 客户分析页面由平台源码提供路由，菜单仍由后端菜单树驱动。开发联调期间，
// 对已有“客户”顶栏补齐九个独立入口；如果后端以后返回同一 unique_auth，
// 这里不会重复注入。每个入口仍保留独立权限标识，非超级管理员会由菜单权限过滤。
const CUSTOMER_ANALYTICS_MENU = Object.freeze([
  ['overview', 'customer_overview', '客户概况'],
  ['source-analysis', 'customer_source_analysis', '客户开源分析'],
  ['visit-analysis', 'customer_visit_analysis', '到店数据分析'],
  ['store-health', 'customer_store_health', '门店健康数据分析'],
  ['consumption-tier', 'customer_consumption_tier', '消费分级分析'],
  ['cash-performance', 'customer_cash_performance', '现金业绩分析'],
  ['refund-performance', 'customer_refund_performance', '退货业绩分析'],
  ['item-analysis', 'customer_item_analysis', '客户品相分析'],
  ['unconsumed-analysis', 'customer_unconsumed_analysis', '客户未耗分析']
]);

function relocateCustomerAnalyticsToMember(menuData, prefix) {
  if (!Array.isArray(menuData)) return [];
  const authPrefix = 'admin-customer-analytics-';
  const titleOf = item => String(item && (item.title || item.menu_name || '')).trim();
  // 历史接口把“会员看板”作为“总部”下的叶子节点返回；先在整棵树上
  // 标准化为“会员”父级，后续再统一挂入“看板”与九张表。
  const normalizeLegacyMemberDashboard = items => (items || []).map(item => {
    if (!item) return item;
    const next = { ...item };
    if (Array.isArray(item.children)) next.children = normalizeLegacyMemberDashboard(item.children);
    if (titleOf(item) === '会员看板') {
      return { ...next, title: '会员', menu_name: '会员', header: item.header || 'user' };
    }
    return next;
  });
  const normalizedInput = normalizeLegacyMemberDashboard(menuData);
  let memberIndex = normalizedInput.findIndex(item => titleOf(item) === '会员' || String(item && item.header || '').toLowerCase() === 'user');
  if (memberIndex < 0) memberIndex = normalizedInput.findIndex(item => titleOf(item) === '客户');

  const cleaned = (items, isRoot = false) => (items || []).reduce((acc, item) => {
    if (!item) return acc;
    const auth = String(item.unique_auth || '');
    if (auth.startsWith(authPrefix)) return acc;
    const children = Array.isArray(item.children) ? cleaned(item.children) : item.children;
    const next = { ...item };
    if (Array.isArray(children)) next.children = children;
    // 旧“客户”节点只承载这九项时移除，防止出现第二套侧栏。
    if (isRoot && titleOf(next) === '客户' && (!next.children || !next.children.length)) return acc;
    acc.push(next);
    return acc;
  }, []);

  const result = cleaned(normalizedInput, true);
  memberIndex = result.findIndex(item => titleOf(item) === '会员' || String(item && item.header || '').toLowerCase() === 'user');
  if (memberIndex < 0) memberIndex = result.findIndex(item => titleOf(item) === '客户' || titleOf(item) === '会员看板');
  if (memberIndex < 0) {
    // 顶栏菜单的子树可能仍以“总部”作为根，递归处理其下的“会员”节点。
    // 不能因为根节点不是会员就直接放弃，否则旧菜单会继续显示单独的“会员看板”。
    return result.map(item => (Array.isArray(item && item.children)
      ? { ...item, children: relocateCustomerAnalyticsToMember(item.children, prefix) }
      : item));
  }

  const sourceMember = result[memberIndex];
  const legacyMemberDashboard = titleOf(sourceMember) === '会员看板';
  const member = { ...sourceMember, title: '会员', menu_name: '会员', header: sourceMember.header || 'user' };
  const memberChildren = Array.isArray(member.children) ? member.children.slice() : [];
  if (legacyMemberDashboard && !memberChildren.some(item => titleOf(item) === '看板')) {
    memberChildren.push({
      ...sourceMember,
      id: `${sourceMember.id || 'member'}-dashboard`,
      pid: sourceMember.id,
      title: '看板',
      menu_name: '看板',
      children: [],
      is_header: 0
    });
  }
  let board = memberChildren.find(item => titleOf(item) === '看板');
  if (!board) {
    board = {
      id: 'customer-analytics-dashboard',
      pid: member.id,
      type: 0,
      title: '看板',
      menu_name: '看板',
      path: `${prefix}/user/customer-analytics`,
      menu_path: `${prefix}/user/customer-analytics`,
      header: member.header,
      is_header: 0,
      is_show: 1,
      icon: 'ios-analytics-outline',
      sort: 8,
      children: []
    };
    memberChildren.push(board);
  }
  const existing = new Set((board.children || []).map(item => String(item.unique_auth || '')));
  board.children = (board.children || []).concat(CUSTOMER_ANALYTICS_MENU
    .filter(([, code]) => !existing.has(`${authPrefix}${code}`))
    .map(([path, code, label], index) => ({
      id: `customer-analytics-${code}`,
      pid: board.id,
      type: 1,
      title: label,
      menu_name: label,
      path: `${prefix}/user/customer-analytics/${path}`,
      menu_path: `${prefix}/user/customer-analytics/${path}`,
      header: member.header,
      is_header: 0,
      is_show: 1,
      icon: 'ios-analytics-outline',
      sort: 10 + index,
      unique_auth: `${authPrefix}${code}`
    })));
  member.children = memberChildren.map(item => titleOf(item) === '看板' ? board : item);
  result[memberIndex] = member;
  return result.filter((item, index) => index !== memberIndex && titleOf(item) !== '客户');
}

function withCustomerAnalyticsMenu(menuData, prefix) {
  if (!Array.isArray(menuData)) return [];

  const visit = (items) => items.map(item => {
    if (!item) return item;
    const normalized = {
      ...item,
      children: Array.isArray(item.children) ? visit(item.children) : item.children
    };
    const title = String(item.title || item.menu_name || '');
    const header = String(item.header || '').trim().toLowerCase();
    const path = String(item.path || item.menu_path || '').trim();
    const isCustomerHeader = (
      title === '客户' || header === 'crm' || /\/crm\/?$/.test(path)
    ) && (
      Number(item.is_header) === 1 || header === 'user' || header === 'crm' || Number(item.pid) === 0
    );
    if (!isCustomerHeader) return normalized;

    const children = Array.isArray(normalized.children) ? normalized.children.slice() : [];
    const hiddenLegacyAuth = new Set(['admin-statistic', 'user-user-group', 'admin-user']);
    const retained = children.filter(child => !hiddenLegacyAuth.has(String(child && child.unique_auth || '')));
    const existing = new Set(retained.map(child => String(child && child.unique_auth || '')));
    const additions = CUSTOMER_ANALYTICS_MENU
      .filter(([, code]) => !existing.has(`admin-customer-analytics-${code}`))
      .map(([path, code, label], index) => ({
        id: `customer-analytics-${code}`,
        pid: item.id,
        type: 1,
        title: label,
        menu_name: label,
        path: `${prefix}/user/customer-analytics/${path}`,
        menu_path: `${prefix}/user/customer-analytics/${path}`,
        // 继承“客户”顶栏的 header（正式数据通常是 crm），否则
        // 路由守卫虽能进入页面，但主布局找不到对应侧栏分组。
        header: item.header || 'user',
        is_header: 1,
        is_show: 1,
        icon: 'ios-analytics-outline',
        sort: 10 + index,
        unique_auth: `admin-customer-analytics-${code}`
      }));
    normalized.children = additions.concat(retained);
    return normalized;
  });

  const result = visit(menuData);
  // 某些历史菜单接口只返回展示名称或把客户根节点挂在旧路径下，
  // 此时无法依赖根节点的 title/header 判断。只要根节点下仍有旧客户入口，
  // 就把它作为客户根节点补齐九个入口，保证 18081 平台菜单与路由一致。
  const hasCustomerAnalytics = result.some(item => {
    const children = Array.isArray(item && item.children) ? item.children : [];
    return children.some(child => String(child && (child.unique_auth || '')).startsWith('admin-customer-analytics-'));
  });
  if (!hasCustomerAnalytics) {
    const legacyCustomerRoot = result.find(item => {
      const children = Array.isArray(item && item.children) ? item.children : [];
      return children.some(child => ['客户概况', '消费分级', '客户来源'].includes(String(child && (child.title || child.menu_name || ''))));
    });
    if (legacyCustomerRoot) {
      const injected = withCustomerAnalyticsMenu([{
        ...legacyCustomerRoot,
        title: '客户',
        header: legacyCustomerRoot.header || 'crm',
        is_header: 1,
        pid: 0
      }], prefix)[0];
      if (injected && Array.isArray(injected.children)) legacyCustomerRoot.children = injected.children;
    }
  }
  return result;
}

// 主布局在切换顶栏时直接读取 menusName；导出同一归一化函数，确保
// 首屏缓存菜单、热更新菜单和登录后新菜单都使用一致的客户分析入口。
export function ensureCustomerAnalyticsMenu(menuData, prefix = Setting.roterPre) {
  return withCustomerAnalyticsMenu(menuData, prefix);
}

// "出入库记录" has been consolidated into inventory query/statistics.  Filter
// the legacy entry here as well as in the menu migration so a browser with an
// older cached menu cannot keep exposing a closed workflow after refresh.
function withoutLegacyInventoryMovement(menuData) {
  if (!Array.isArray(menuData)) return [];
  return menuData
    .filter((item) => {
      const path = String((item && (item.path || item.menu_path)) || '');
      return item && item.unique_auth !== 'admin-inventory-statistics' && !/\/?inventory\/statistics\/?$/.test(path) && item.title !== '出入库记录';
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
      return item && item.unique_auth !== 'admin-operating-screen' && !/\/operating-screen\/?$/.test(path);
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
  const cleaned = withoutStoreOperationsReportIcons(withoutLegacyStoreOperationsMenu(withoutOperatingScreenMenu(withoutLegacyInventoryMovement(menuData))));
  return disambiguateDuplicateMenuPaths(normalizeProductBusinessConfigMenu(
    normalizeOrganizationWorkspaceMenu(cleaned, prefix),
    prefix
  ));
}

function getMenusName() {
  let menuList;
  const storage = window.localStorage;
  let menuData;
  if (isAgentPath()) {
    menuList = storage.getItem('agent_menuList');
  } else {
    menuList = storage.getItem('menuList');
  }
  try {
    menuData = menuList !== undefined ? JSON.parse(menuList) : [];
  } catch (e) {
    menuData = [];
  }
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
