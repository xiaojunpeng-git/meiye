<template>
  <div>
    <div
      class="i-layout-sider-logo"
      :class="{ 'i-layout-sider-logo-dark': siderTheme === 'dark' }"
    >
      <transition name="fade-quick">
        <i-link to="/" v-show="!hideLogo">
          <img :src="logoSmall" v-if="menuCollapse" />
          <img :src="logo" v-else-if="siderTheme === 'light'" />
          <img :src="logo" v-else />
        </i-link>
      </transition>
    </div>
  <div class="h-52 lh-52px fs-16 mr-10 text-wlll-303133 fw-600 border-b-F0F1F5 acea-row row-between-wrapper overflow" :class='!menuCollapse && !isMobile?"ml-16":"ml-10"'>
    <span v-if="!menuCollapse && !isMobile">{{headerTitle}}</span>
    <i-header-collapse />
  </div>
    <Menu
      ref="menu"
      class="i-layout-menu-side i-scrollbar-hide"
    :class="menuOn?'on':''"
      :theme="siderTheme"
      :active-name="activePath"
      :open-names="openPath"
      width="auto"
      v-if="displaySider.length"
    @on-open-change='menuTap'
    >
      <template v-if="!menuCollapse" v-for="(item, index) in displaySider">
        <i-menu-side-item
          v-if="item.children === undefined || !item.children.length"
          :menu="item"
          :key="index"
        />
        <i-menu-side-submenu v-else :menu="item" :key="index" />
      </template>
      <template v-else>
        <!-- <Tooltip
          :content="tTitle(item.title)"
          placement="right"
          v-if="item.children === undefined || !item.children.length"
          :key="index"
        >
          <i-menu-side-item :menu="item" hide-title />
        </Tooltip> -->
        <i-menu-side-collapse :menu="item" :key="index" top-level />
      </template>
    </Menu>
  </div>
</template>
<script>
/* eslint-disable indent */
import iMenuSideItem from './menu-item';
import iMenuSideSubmenu from './submenu';
import iMenuSideCollapse from './menu-collapse';
import iHeaderCollapse from '../header-collapse';
import tTitle from '../mixins/translate-title';
import Setting from '@/setting';

import { mapState, mapGetters } from 'vuex';

export default {
  name: 'iMenuSide',
  mixins: [tTitle],
  components: { iMenuSideItem, iMenuSideSubmenu, iMenuSideCollapse, iHeaderCollapse },
  props: {
    hideLogo: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      logo: require('@/assets/images/logo.png'),
      logoSmall: require('@/assets/images/logo-small.png'),
    showDrawer: false,
    headerTitle: '',
    openPath: [],
    closePath: [],
    menuOn: false,
    customerAnalyticsEntries: [
      { path: `${Setting.roterPre}/user/customer-analytics/overview`, title: '客户概况' },
      { path: `${Setting.roterPre}/user/customer-analytics/source-analysis`, title: '客户开源分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/visit-analysis`, title: '到店数据分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/store-health`, title: '门店健康数据分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/consumption-tier`, title: '消费分级分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/cash-performance`, title: '现金业绩分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/refund-performance`, title: '退货业绩分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/item-analysis`, title: '客户品相分析' },
      { path: `${Setting.roterPre}/user/customer-analytics/unconsumed-analysis`, title: '客户未耗分析' }
    ]
    };
  },
  computed: {
    ...mapState('admin/layout', [
      'siderTheme',
      'menuAccordion',
      'menuCollapse',
    'isMobile'
    ]),
    ...mapState('admin/menu', ['activePath', 'openNames', 'headerName']),
    ...mapGetters('admin/menu', ['filterSider', 'filterHeader']),
    displaySider() {
      // 客户九张表统一归入“会员 → 看板”，由菜单树渲染真实父子层级。
      // 不再为客户分析单独创建顶栏或平铺快捷入口。
      const entries = this.customerAnalyticsEntries.map((entry, index) => ({
        ...entry,
        id: `customer-analytics-${index}`,
        title: entry.title,
        menu_name: entry.title,
        is_show: 1,
        children: undefined
      }));
      const inject = (items) => (items || []).map(item => {
        if (!item) return item;
        const title = String(item.title || item.menu_name || '').trim();
        const children = Array.isArray(item.children) ? inject(item.children) : item.children;
        if (title === '会员') {
          const existingBoard = Array.isArray(children)
            ? children.find(child => String(child.title || child.menu_name || '').trim() === '看板')
            : null;
          const board = existingBoard || {
            id: 'customer-analytics-dashboard',
            title: '看板',
            menu_name: '看板',
            path: `${Setting.roterPre}/user/customer-analytics`,
            menu_path: `${Setting.roterPre}/user/customer-analytics`,
            is_show: 1,
            children: []
          };
          const boardChildren = Array.isArray(board.children) ? board.children : [];
          const existing = new Set(boardChildren.map(child => String(child.path || child.menu_path || '')));
          board.children = boardChildren.concat(entries.filter(entry => !existing.has(entry.path)));
          return { ...item, children: (Array.isArray(children) ? children.filter(child => String(child.title || child.menu_name || '').trim() !== '看板') : []).concat(board) };
        }
        return Array.isArray(children) ? { ...item, children } : item;
      });
      return inject(this.filterSider);
    },
    showCustomerAnalyticsShortcuts() {
      return false;
    }
  },
  watch: {
    $route: {
      handler() {
        const closePath = JSON.parse(localStorage.getItem('closeMenuPath')) || [];
        this.menuOn = false;
        const itemPath = [];
        // 所有组件默认是打开的，所以用关闭的进行筛选；
        // 过滤出被打开的组件
        const array = this.filterSider.filter(item =>
      !closePath.some(j => j.id === item.id)
        );
        array.forEach(item => {
          itemPath.push(item.path);
        });
        this.openPath = itemPath;
        // 获取一级导航标题；
        this.filterHeader.forEach(item => {
          if (item.header === this.headerName) {
            this.headerTitle = item.title;
          }
        });
        this.handleUpdateMenuState();
      },
      immediate: true
    },
    // 在展开/收起侧边菜单栏时，更新一次 menu 的状态
    menuCollapse() {
      this.handleUpdateMenuState();
    }
  },
  mounted() {
    this.getLogo();
  },
  methods: {
    menuTap(e) {
      this.menuOn = true;
      const closePath = JSON.parse(localStorage.getItem('closeMenuPath')) || [];
      // 过滤出当前二级导航被关闭的组件
      const array = this.filterSider.filter(item => e.indexOf(item.path) === -1);
      // closePath：所有二级导航被关闭的组件（合并数组）
      const mergedArray = [...closePath, ...array];
      // 关闭组件去重
      const uniqueArray = mergedArray.filter((item, index, self) =>
      index === self.findIndex(t => t.id === item.id)
      );
      // 从关闭的组件里去除被打开的组件
      const newArray = uniqueArray.filter(item => e.indexOf(item.path) === -1);
      localStorage.setItem('closeMenuPath', JSON.stringify(newArray));
      this.closePath = closePath;
    },
    handleUpdateMenuState() {
      this.$nextTick(() => {
        if (this.$refs.menu) {
          this.$refs.menu.updateActiveName();
          if (this.menuAccordion) this.$refs.menu.updateOpened();
        }
      });
    },
    getLogo() {
      this.$store
        .dispatch('admin/db/get', {
          dbName: 'sys',
          path: 'user.info',
          user: true
        })
        .then((res) => {
          this.logo = res.logo ? res.logo : this.logo;
          this.logoSmall = res.logoSmall ? res.logoSmall : this.logoSmall;
        });
    }
  }
};
</script>

<style lang="stylus" scoped>
.customer-analytics-shortcuts
  padding 8px 10px
  border-bottom 1px solid #f0f1f5

.customer-analytics-shortcut
  display block
  padding 9px 8px
  color #515a6e
  font-size 14px
  line-height 20px
  border-radius 4px
  text-decoration none

.customer-analytics-shortcut:hover,
.customer-analytics-shortcut.router-link-active
  color #2d8cf0
  background #f0faff
</style>
