<template>
    <Layout class="i-layout">
        <Sider v-if="!isMobile && !hideSider" class="i-layout-sider" :class="siderClasses" :width="menuSideWidth">
			<i-menu-side :hide-logo="isHeaderStick && headerFix && showHeader" />
        </Sider>
        <Layout class="i-layout-inside" :class="insideClasses">
            <transition name="fade-quick">
                <Header class="i-layout-header" :class="headerClasses" :style="headerStyle" v-show="showHeader" v-resize="handleHeaderWidthChange">
                    <i-header-logo />
                    <!-- <i-header-logo v-if="!isMobile && isHeaderStick && headerFix" /> -->
                    <!-- <i-header-collapse v-if="(isMobile || showSiderCollapse) && !hideSider" @on-toggle-drawer="handleToggleDrawer" /> -->
                    <i-menu-head v-if="headerMenu && !isMobile" ref="menuHead" />
                    <i-header-breadcrumb v-if="showBreadcrumb && !headerMenu && !isMobile" ref="breadcrumb" />
                    <i-header-search v-if="showSearch && !headerMenu && !isMobile && !showBreadcrumb" />
                    <div class="i-layout-header-right">
                        <i-header-search v-if="(showSearch && isMobile) || (showSearch && (headerMenu || showBreadcrumb))" />
                        <i-menu-head v-if="headerMenu && isMobile" />
                        <i-header-log v-if="isDesktop && showLog" />
                        <i-header-fullscreen v-if="isDesktop && showFullscreen" />
                        <i-header-reload v-if="!isMobile && showReload" @on-reload="handleReload" />
                        <i-header-notice v-if="showNotice" />
                        <i-header-user />
                        <i-header-i18n v-if="showI18n" />
                        <i-header-setting v-if="enableSetting && !isMobile" />
                    </div>
                </Header>
            </transition>
            <Content class="i-layout-content" :class="contentClasses">
                <transition name="fade-quick">
                    <i-tabs v-if="tabs" v-show="showHeader" />
                </transition>
				<div v-if="menuData.length > 1" class="menu-tabs" :class="{ 'menu-tabs--collapsed': !menuTabsExpanded }">
					<Tabs class="menuTabs" :animated="false" :value="menuPath" @on-click="tabClick">
						<TabPane v-for="item in menuData" :key="item.path" :label="item.title" :name="item.path"></TabPane>
					</Tabs>
					<button
						type="button"
						class="menu-tabs__toggle"
						:aria-expanded="String(menuTabsExpanded)"
						@click="toggleMenuTabs"
					>
						{{ menuTabsExpanded ? '收起' : '展开' }}
						<Icon :type="menuTabsExpanded ? 'ios-arrow-up' : 'ios-arrow-down'" />
					</button>
				</div>
                <div class="i-layout-content-main">
                    <keep-alive v-if="tabs" :include="keepAlive" :max="20">
                        <router-view v-if="loadRouter" />
                    </keep-alive>
                    <template v-else>
                        <keep-alive :include="keepAlive">
                            <router-view v-if="loadRouter && $route.meta.keepAlive" />
                        </keep-alive>
                        <router-view v-if="loadRouter && !$route.meta.keepAlive" />
                    </template>
                </div>
            </Content>
            <i-copyright v-if="copyrightShow" />
        </Layout>
        <div v-if="isMobile && !hideSider">
            <Drawer v-model="showDrawer" placement="left" :closable="false" :class-name="drawerClasses">
                <i-menu-side />
            </Drawer>
        </div>
    </Layout>
</template>
<script>
import iMenuHead from './menu-head';
import iMenuSide from './menu-side';
import iHeaderLogo from './header-logo';
import iHeaderCollapse from './header-collapse';
import iHeaderReload from './header-reload';
import iHeaderBreadcrumb from './header-breadcrumb';
import iHeaderSearch from './header-search';
import iHeaderLog from './header-log';
import iHeaderFullscreen from './header-fullscreen';
import iHeaderNotice from './header-notice';
import iHeaderUser from './header-user';
import iHeaderI18n from './header-i18n';
import iHeaderSetting from './header-setting';
import iTabs from './tabs';
import iCopyright from '@/components/copyright';

import { mapState, mapGetters, mapMutations } from 'vuex';
import Setting from '@/setting';

import { requestAnimation } from '@/libs/util';
import { isMenuPathMatch } from '@/libs/system';

export default {
  name: 'BasicLayout',
  components: { iMenuHead, iMenuSide, iCopyright, iHeaderLogo, iHeaderCollapse, iHeaderReload, iHeaderBreadcrumb, iHeaderSearch, iHeaderUser, iHeaderI18n, iHeaderLog, iHeaderFullscreen, iHeaderSetting, iHeaderNotice, iTabs },
  // provide (){
  //     return {
  //         reload:this.handleReload
  //     }
  // },
  data() {
    return {
      showDrawer: false,
      ticking: false,
      headerVisible: true,
      oldScrollTop: 0,
      isDelayHideSider: false, // hack，当从隐藏侧边栏的 header 切换到正常 header 时，防止 Logo 抖动
      loadRouter: true,
      menuData: [],
      menuPath: '',
      menuTabsExpanded: false
    };
  },
  computed: {
    ...mapState('admin/layout', [
      'siderTheme',
      'headerTheme',
      'headerStick',
      'tabs',
      'tabsFix',
      'siderFix',
      'headerFix',
      'headerHide',
      'headerMenu',
      'isMobile',
      'isTablet',
      'isDesktop',
      'menuCollapse',
      'showMobileLogo',
      'showSearch',
      'showNotice',
      'showFullscreen',
      'showSiderCollapse',
      'showBreadcrumb',
      'showLog',
      'showI18n',
      'showReload',
      'enableSetting',
      'copyrightShow'
    ]),
    ...mapState('admin/page', [
      'keepAlive'
    ]),
    ...mapState('admin/menu', ['activePath']),
    ...mapGetters('admin/menu', [
      'hideSider',
      'filterSider'
    ]),
    // 如果开启 headerMenu，且当前 header 的 hideSider 为 true，则将顶部按 headerStick 处理
    // 这时，即使没有开启 headerStick，仍然按开启处理
    isHeaderStick() {
      let state = this.headerStick;
      if (this.hideSider) state = true;
      return state;
    },
    showHeader() {
      let visible = true;
      if (this.headerFix && this.headerHide && !this.headerVisible) visible = false;
      return visible;
    },
    headerClasses() {
      return [
        `i-layout-header-color-${this.headerTheme}`,
        {
          'i-layout-header-fix': this.headerFix,
          'i-layout-header-fix-collapse': this.headerFix && this.menuCollapse,
          'i-layout-header-mobile': this.isMobile,
          'i-layout-header-stick': this.isHeaderStick && !this.isMobile,
          'i-layout-header-with-menu': this.headerMenu,
          'i-layout-header-with-hide-sider': this.hideSider || this.isDelayHideSider
        }
      ];
    },
    headerStyle() {
      const menuWidth = this.isHeaderStick ? 0 : this.menuCollapse ? 60 : Setting.menuSideWidth;
      return this.isMobile || !this.headerFix ? {} : {
        width: `calc(100% - ${menuWidth}px)`
      };
    },
    siderClasses() {
      return {
        'i-layout-sider-fix': this.siderFix,
        'i-layout-sider-dark': this.siderTheme === 'dark'
      };
    },
    contentClasses() {
      return {
        'i-layout-content-fix-with-header': this.headerFix,
        'i-layout-content-with-tabs': this.tabs,
        'i-layout-content-with-tabs-fix': this.tabs && this.tabsFix
      };
    },
    insideClasses() {
      return {
        'i-layout-inside-fix-with-sider': this.siderFix,
        'i-layout-inside-fix-with-sider-collapse': this.siderFix && this.menuCollapse,
        'i-layout-inside-with-hide-sider': this.hideSider,
        'i-layout-inside-mobile': this.isMobile
      };
    },
    drawerClasses() {
      let className = 'i-layout-drawer';
      if (this.siderTheme === 'dark') className += ' i-layout-drawer-dark';
      return className;
    },
    menuSideWidth() {
      return this.menuCollapse ? 60 : Setting.menuSideWidth;
    }
  },
  watch: {
    hideSider() {
      this.isDelayHideSider = true;
      setTimeout(() => {
        this.isDelayHideSider = false;
      }, 0);
    },
    filterSider() {
      this.updateMenuTabs(this.$route);
    },
    $route: {
      handler(to, from) {
        this.updateMenuTabs(to);
        if (from && to.path === from.path) {
					    // 相同路由，不同参数，跳转时，重载页面
					    if (Setting.sameRouteForceUpdate) {
					        this.handleReload();
					    }
        }
      },
      immediate: true
    }
  },
  methods: {
    ...mapMutations('admin/layout', [
      'updateMenuCollapse'
    ]),
    ...mapMutations('admin/order', [
      'getOrderStatus',
      'getOrderTime',
      'getOrderNum'
    ]),
    isRouteMatch(to, menuPath) {
      return isMenuPathMatch(to, menuPath);
    },
    isRechargeRoute(to) {
      return /\/marketing\/(setup_recharge|balance_recharge)$/.test((to.path || '').replace(/\/$/, ''));
    },
    isStoreSettingRoute(to) {
      const path = (to.path || '').replace(/\/$/, '');
      const prefix = Setting.roterPre;
      return [
        `${prefix}/store/system/base`,
        `${prefix}/store/store_menus/index`,
        `${prefix}/store/cashier_menus/index`
      ].includes(path);
    },
    getVisibleMenuChildren(children = []) {
      return (children || []).filter(item => item && item.path);
    },
    findMenuTabs(menus, to) {
      if (!menus || !menus.length) return null;
      for (let i = 0; i < menus.length; i++) {
        const menu = menus[i];
        if (!menu.children || !menu.children.length) continue;
        const visibleChildren = this.getVisibleMenuChildren(menu.children);
        const matched = visibleChildren.find(item => this.isRouteMatch(to, item.path));
        if (matched) {
          return {
            menuData: visibleChildren,
            menuPath: matched.path
          };
        }
        const deepMatch = this.findMenuTabs(menu.children, to);
        if (deepMatch) return deepMatch;
      }
      return null;
    },
    updateMenuTabs(to) {
      if (this.isRechargeRoute(to) || this.isStoreSettingRoute(to)) {
        this.menuData = [];
        this.menuPath = '';
        return;
      }
      this.menuData = [];
      this.menuPath = '';
      const matched = this.findMenuTabs(this.filterSider, to);
      if (matched) {
        this.menuData = matched.menuData;
        this.menuPath = matched.menuPath;
        if (!this.menuCollapse && matched.menuData.length) {
          this.$store.commit('admin/menu/setActivePath', matched.menuData[0].path);
        }
      }
    },
    tabClick(path) {
			  this.menuPath = path;
			  this.$router.push(path);
    },
    toggleMenuTabs() {
      this.menuTabsExpanded = !this.menuTabsExpanded;
    },
    handleToggleDrawer(state) {
      if (typeof state === 'boolean') {
        this.showDrawer = state;
      } else {
        this.showDrawer = !this.showDrawer;
      }
    },
    handleScroll() {
      if (!this.headerHide) return;

      const scrollTop = document.body.scrollTop + document.documentElement.scrollTop;

      if (!this.ticking) {
        this.ticking = true;
        requestAnimation(() => {
          if (this.oldScrollTop > scrollTop) {
            this.headerVisible = true;
          } else if (scrollTop > 300 && this.headerVisible) {
            this.headerVisible = false;
          } else if (scrollTop < 300 && !this.headerVisible) {
            this.headerVisible = true;
          }
          this.oldScrollTop = scrollTop;
          this.ticking = false;
        });
      }
    },
    handleHeaderWidthChange() {
      const $breadcrumb = this.$refs.breadcrumb;
      if ($breadcrumb) {
        $breadcrumb.handleGetWidth();
        $breadcrumb.handleCheckWidth();
      }
      const $menuHead = this.$refs.menuHead;
      if ($menuHead) {
        $menuHead.handleGetMenuHeight();
      }
    },
    handleReload() {
      this.loadRouter = false;
      this.getOrderStatus('');
      this.getOrderTime('');
      this.getOrderNum('');
      this.$nextTick(() => {
        this.loadRouter = true;
      });
    }

  },
  mounted() {
    document.addEventListener('scroll', this.handleScroll, { passive: true });
  },
  beforeDestroy() {
    document.removeEventListener('scroll', this.handleScroll);
  },
  created() {
    if (this.isTablet && this.showSiderCollapse) this.updateMenuCollapse(true);
  }
};
</script>
<style scoped lang="stylus">
	/deep/.i-layout-header-trigger{
		vertical-align: middle;
	}
	.menu-tabs{
		position: relative;
		margin: 0 14px;
		padding-right: 64px;
	}
	.menu-tabs__toggle{
		position: absolute;
		top: 0;
		right: 0;
		height: 42px;
		padding: 0 8px;
		border: 0;
		background: #fff;
		color: #2d8cf0;
		font-size: 14px;
		line-height: 42px;
		cursor: pointer;
	}
	.menu-tabs__toggle:focus{
		outline: 2px solid rgba(45, 140, 240, .35);
		outline-offset: -2px;
	}
	.menu-tabs__toggle .ivu-icon{
		margin-left: 2px;
	}
	.menuTabs /deep/.ivu-tabs-bar{
		margin-bottom: 1px;
		border-bottom: 0;
	}
	.menuTabs /deep/.ivu-tabs-nav-wrap,
	.menuTabs /deep/.ivu-tabs-nav-scroll{
		overflow: visible;
		white-space: normal;
	}
	.menuTabs /deep/.ivu-tabs-nav-wrap.ivu-tabs-nav-scrollable{
		padding: 0;
	}
	.menuTabs /deep/.ivu-tabs-nav-prev,
	.menuTabs /deep/.ivu-tabs-nav-next{
		display: none;
	}
	.menuTabs /deep/.ivu-tabs-nav{
		display: flex;
		float: none;
		flex-wrap: wrap;
		width: 100%;
		transform: none !important;
		transition: none;
	}
	.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab{
		flex: 0 0 auto;
		padding: 12px 0;
		margin: 0 24px 0 0;
	}
	.menuTabs /deep/.ivu-tabs-ink-bar{
		display: none;
	}
	.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab-active:before{
		content:'';
		position: absolute;
		width: 100%;
		height: 2px;
		background-color: #2d8cf0;
		bottom: 1px;
	}
	.menu-tabs--collapsed .menuTabs /deep/.ivu-tabs-nav-wrap,
	.menu-tabs--collapsed .menuTabs /deep/.ivu-tabs-nav-scroll{
		height: 42px;
		overflow: hidden;
	}
	/deep/.i-layout-menu-side .ivu-menu-submenu-title-icon{
	   color: #C0C4CC;
	}
	.i-layout /deep/.ivu-menu{
		color: #303133;
		.ivu-menu-item-active{
			color: #2d8cf0;
		}
		.ivu-menu-opened{
			color: #303133;
		}
	}
	/deep/.i-layout-header-trigger{
		height: 45px;
		line-height: 42px;
	}
	/deep/.i-layout-menu-side .ivu-menu{
		padding-left:20px;
	}
	/deep/.i-layout-sider-fix .i-layout-menu-side{
		padding-left: 0
	}
	/deep/.i-layout-menu-side.ivu-menu-vertical .ivu-menu-submenu .ivu-menu-item{
		padding-left:13px !important;
	}
	/deep/.i-layout-menu-side-title-icon{
		margin-right: 4px;
		width: 15px;
	}
	/deep/.i-layout-menu-side .ivu-menu-submenu-title,/deep/.i-layout-menu-side .ivu-menu-item{
		height: 40px;
		margin-top: 8px;
	}
	/deep/.i-layout-menu-side .ivu-menu-submenu-title-icon{
		transition: none;
	}
	/deep/.i-layout-menu-side.on .ivu-menu-submenu-title-icon{
		transition: transform 0.2s ease-in-out;
	}
	/deep/.i-layout-menu-side .ivu-menu-submenu{
		.ivu-menu{
			display: flex;
			flex-wrap: wrap;
			transition: all 0s;
			//height: max-content;
		}
		.menu-item{
			// display: inline-block;
			width: calc(100% / 2);
			color: #606266;
			vertical-align: middle;

			.ivu-menu-item{
				height: 38px;
				margin-top: 0;
				&:hover{
					background-color: #fff !important;
				}
			}
		}
	}
	/deep/.i-layout-menu-side.on .ivu-menu-submenu{
		.ivu-menu{
			transition: all 0.3s;
		}
	}
	.i-layout-inside-fix-with-sider{
		padding-left: 236px;
	}
	.i-layout-inside-fix-with-sider-collapse{
		padding-left: 60px;
	}
	.i-layout-inside-mobile{
		padding-left: 0;
	}
	/deep/.i-layout-sider .ivu-menu-vertical .ivu-menu-item, /deep/.i-layout-sider .ivu-menu-vertical .ivu-menu-submenu-title{
		padding: 9px 16px;
	}
	/deep/.ivu-menu-light.ivu-menu-vertical .ivu-menu-item:hover,
	/deep/.ivu-menu-vertical .ivu-menu-submenu-title:hover{
		background-color: #F1F3F4;
	}
	/deep/.ivu-menu-light.ivu-menu-vertical .ivu-dropdown .ivu-menu-item:hover{
		background-color: #fff;
	}
    /deep/.ivu-notice-desc{
       word-break: break-all;
    }
    .i-layout-sider /deep/.ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu):after{
        background: unset!important;
        left:0!important;
        right:unset;
    }
    .i-layout-sider /deep/.ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu){
        background: #fff;
		&:hover{
			background-color: #F1F3F4 !important;
		}
    }
	.i-layout-sider /deep/.ivu-menu-light.ivu-menu-vertical .ivu-menu .ivu-menu-item-active:not(.ivu-menu-submenu){
		&:hover{
			background-color: #fff !important;
		}
	}
	.i-layout-sider /deep/.ivu-menu-light.ivu-menu-vertical .ivu-dropdown .ivu-menu-item-active:not(.ivu-menu-submenu){
		&:hover{
			background-color: #fff !important;
		}
	}
    .i-layout-content-main
       /*height 100%;*/
    .i-layout-header{
		z-index: 999;
	}
</style>
