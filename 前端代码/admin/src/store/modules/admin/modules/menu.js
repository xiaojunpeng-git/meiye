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
 * 菜单
 * */
import { cloneDeep } from 'lodash';
import { includeArray } from '@/libs/system';
import util from '@/libs/util';
import Setting from '@/setting';
import { normalizeOrganizationWorkspaceMenu } from '@/libs/organizationWorkspaceMenu';
import { normalizeProductBusinessConfigMenu } from '@/libs/productBusinessConfigMenu';

// 客户分析九项统一挂在“会员 → 看板”下，避免旧登录态把它们平铺到
// “客户”顶栏或生成第二套重复入口。
const CUSTOMER_SIDER_ENTRIES = Object.freeze([
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

function ensureCustomerSiderEntries(menu, headerName = '') {
  if (!Array.isArray(menu)) return [];
  const titleOf = item => String(item && (item.title || item.menu_name || '')).trim();
  const hasLegacyCustomer = menu.some(item => ['客户概况', '消费分级', '客户来源'].includes(titleOf(item)));
  const hasLegacyMemberDashboard = menu.some(item => titleOf(item) === '会员看板');
  const hasMemberTitle = menu.some(item => titleOf(item) === '会员');
  const isMemberMenu = headerName === 'user' || headerName === 'member' || hasLegacyCustomer || hasLegacyMemberDashboard || hasMemberTitle;
  if (!isMemberMenu) {
    // 当前侧栏有时仍以“总部”作为根，会员节点位于其 children 中；递归
    // 归一化，避免只处理顶层数组导致“会员看板”继续成为孤立叶子。
    return menu.map(item => (Array.isArray(item && item.children)
      ? { ...item, children: ensureCustomerSiderEntries(item.children, headerName) }
      : item));
  }
  const legacyLabels = new Set(['客户概况', '消费分级', '客户来源']);
  const analyticsPrefix = 'admin-customer-analytics-';
  const retained = menu.filter(item => {
    const title = titleOf(item);
    const auth = String(item && (item.unique_auth || item.auth && item.auth[0] || ''));
    return !legacyLabels.has(title) && !auth.startsWith(analyticsPrefix);
  });
  const legacyMemberDashboard = retained.find(item => titleOf(item) === '会员看板');
  const existingBoard = retained.find(item => titleOf(item) === '看板') || (legacyMemberDashboard && {
    ...legacyMemberDashboard,
    title: '看板',
    menu_name: '看板',
    children: Array.isArray(legacyMemberDashboard.children) ? legacyMemberDashboard.children : []
  });
  const existingChildren = existingBoard && Array.isArray(existingBoard.children) ? existingBoard.children : [];
  const existing = new Set(existingChildren.map(item => String(item && (item.unique_auth || item.auth && item.auth[0] || ''))));
  const additions = CUSTOMER_SIDER_ENTRIES
    .filter(([, code]) => !existing.has(`${analyticsPrefix}${code}`))
    .map(([path, code, label], index) => ({
      id: `customer-analytics-${code}`,
      pid: existingBoard ? existingBoard.id : undefined,
      type: 1,
      title: label,
      menu_name: label,
      path: `${Setting.roterPre}/user/customer-analytics/${path}`,
      menu_path: `${Setting.roterPre}/user/customer-analytics/${path}`,
      header: headerName || 'user',
      is_header: 0,
      is_show: 1,
      icon: 'ios-analytics-outline',
      sort: 10 + index,
      unique_auth: `${analyticsPrefix}${code}`
    }));
  if (existingBoard) {
    return retained
      .filter(item => titleOf(item) !== '会员看板' || titleOf(item) === '看板')
      .map(item => titleOf(item) === '看板'
        ? { ...item, children: existingChildren.concat(additions) }
        : item)
      .concat(legacyMemberDashboard && !retained.some(item => titleOf(item) === '看板')
        ? [{ ...existingBoard, children: existingChildren.concat(additions) }]
        : []);
  }
  return retained.concat([{
    id: 'customer-analytics-dashboard',
    type: 0,
    title: '看板',
    menu_name: '看板',
    path: `${Setting.roterPre}/user/customer-analytics`,
    menu_path: `${Setting.roterPre}/user/customer-analytics`,
    header: headerName || 'user',
    is_header: 0,
    is_show: 1,
    icon: 'ios-analytics-outline',
    sort: 8,
    children: additions
  }]);
}

// 根据 menu 配置的权限，过滤菜单
function filterMenu(menuList, access, lastList) {
  menuList.forEach(menu => {
    const menuAccess = menu.auth;

    if (!menuAccess || includeArray(menuAccess, access)) {
      const newMenu = {};
      for (const i in menu) {
        if (i !== 'children') newMenu[i] = cloneDeep(menu[i]);
      }
      if (menu.children && menu.children.length) newMenu.children = [];

      lastList.push(newMenu);
      menu.children && filterMenu(menu.children, access, newMenu.children);
    }
  });
  return lastList;
}
// 递归处理顶部菜单问题（优先概况，避免误进组织架构等）
function getChilden(data) {
  return util.resolveDefaultMenuPath(data);
}

export default {
  namespaced: true,
  state: {
    // 顶部菜单
    header: [],
    // 侧栏菜单
    sider: [],
    // 当前顶栏菜单的 name
    headerName: '',
    // 当前所在菜单的 path
    activePath: '',
    // 展开的子菜单 name 集合
    openNames: []
  },
  getters: {

    /**
         * @description 根据 user 里登录用户权限，对侧边菜单进行鉴权过滤
         * */
    filterSider(state, getters, rootState) {
      const userInfo = rootState.admin.user.info;
      // @权限
      const access = userInfo.access;
      // 后端对 level=0 的平台超级管理员不做菜单权限裁剪；前端也必须保持同一口径，
      // 否则新增的独立报表入口会在菜单中消失并被路由守卫拦截。
      const account = String(userInfo.account || userInfo.username || '').trim().toLowerCase();
      const isRootAdmin = (
        Number(userInfo.level) === 0 && Number(userInfo.admin_type || userInfo.adminType || 0) !== 3
      ) || account === 'admin';
      let filtered;
      if (isRootAdmin) {
        filtered = cloneDeep(state.sider);
      } else if (access && access.length) {
        filtered = filterMenu(state.sider, access, []);
      } else {
        filtered = filterMenu(state.sider, [], []);
      }
      return normalizeProductBusinessConfigMenu(
        normalizeOrganizationWorkspaceMenu(filtered, Setting.roterPre),
        Setting.roterPre
      );
    },
    // 处理顶部路由递归

    /**
         * @description 根据 user 里登录用户权限，对顶栏菜单进行鉴权过滤
         * */
    filterHeader(state, getters, rootState) {
      //  调用递归函数
      state.header.forEach(item => {
        item.path = getChilden(item);
      });

      // @权限
      const userInfo = rootState.admin.user.info;
      const access = userInfo.access;
      if (access && access.length) {
        return state.header.filter(item => {
          let state = true;
          if (item.auth && !includeArray(item.auth, access)) state = false;
          return state;
        });
      } else {
        return state.header.filter(item => {
          let state = true;
          if (item.auth && item.auth.length) state = false;
          return state;
        });
      }
    },
    /**
         * @description 当前 header 的全部信息
         * */
    currentHeader(state) {
      return state.header.find(item => item.name === state.headerName);
    },
    /**
         * @description 在当前 header 下，是否隐藏 sider（及折叠按钮）
         * */
    hideSider(state, getters) {
      let visible = false;
      if (getters.currentHeader && 'hideSider' in getters.currentHeader) visible = getters.currentHeader.hideSider;
      return visible;
    }
  },
  mutations: {
    /**
         * @description 设置侧边栏菜单
         * @param {Object} state vuex state
         * @param {Array} menu menu
         */
    setSider(state, menu) {
      state.sider = menu;
    },
    /**
         * @description 设置顶栏菜单
         * @param {Object} state vuex state
         * @param {Array} menu menu
         */
    setHeader(state, menu) {
      state.header = menu;
    },
    /**
         * @description 设置当前顶栏菜单 name
         * @param {Object} state vuex state
         * @param {Array} name headerName
         */
    setHeaderName(state, name) {
      state.headerName = name;
    },
    /**
         * @description 设置当前所在菜单的 path，用于侧栏菜单高亮当前项
         * @param {Object} state vuex state
         * @param {Array} path fullPath
         */
    setActivePath(state, path) {
      state.activePath = path;
    },
    /**
         * @description 设置当前所在菜单的全部展开父菜单的 names 集合
         * @param {Object} state vuex state
         * @param {Array} names openNames
         */
    setOpenNames(state, names) {
      state.openNames = names;
    }
  }
};
