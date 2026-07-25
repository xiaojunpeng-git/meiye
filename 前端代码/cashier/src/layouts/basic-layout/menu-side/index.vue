<template>
  <div class="cashier-sidebar">
    <!-- 顶部 Logo：布局节点，不参与菜单权限 -->
    <div class="sidebar-logo">
      <img :src="logoDisplay" alt="logo" />
    </div>
    <!-- 中间菜单：仍使用 Vuex filterSider 权限过滤结果，禁止写死菜单 -->
    <div class="sidebar-menu">
      <div class="list">
        <div
          :class="{ on: parentCur == index }"
          class="parent-nav-item"
          v-for="(nav, index) in filterSider"
          :key="index"
          @click="handelParentClick(nav, index)"
        >
          <div class="parent-nav-item-inner">
            <div class="icon-box">
              <span
                class="iconfont icon"
                :class="resolveNavIcon(nav)"
                v-if="resolveNavIcon(nav)"
              ></span>
            </div>
            <div>{{ nav.title }}</div>
          </div>
        </div>
      </div>
    </div>
    <!-- 底部账号：独立入口，不混入菜单权限树 -->
    <div class="sidebar-account">
      <i-header-user class="sidebar-account-user" />
    </div>
  </div>
</template>
<script>
import iMenuSideItem from './menu-item';
import iMenuSideSubmenu from './submenu';
import iMenuSideCollapse from './menu-collapse';
import iHeaderUser from '../header-user';
import tTitle from '../mixins/translate-title';

import { mapState, mapGetters } from 'vuex';

export default {
  name: 'iMenuSide',
  mixins: [tTitle],
  components: { iMenuSideItem, iMenuSideSubmenu, iMenuSideCollapse, iHeaderUser },
  props: {
    hideLogo: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      logo: require('@/assets/images/m_logo.png'),
      logoSmall: require('@/assets/images/logo-small.png'),
      parentCur: 0,
    };
  },
  computed: {
    ...mapState('store/layout', [
      'siderTheme',
      'menuAccordion',
      'menuCollapse',
      'isChildren',
    ]),
    ...mapState('store/menu', ['activePath', 'openNames']),
    ...mapGetters('store/menu', ['filterSider']),
    logoDisplay() {
      return this.logo || this.logoSmall;
    },
  },
  watch: {
    $route: {
      handler(n) {
        let that = this;
        let activeLink = n.path;
        let storage = window.localStorage;
        storage.setItem('chiidLinkCashier', activeLink);
        let funLink = function (children, index) {
          children.forEach((item) => {
            if (activeLink == item.path) {
              that.parentCur = index;
              storage.setItem(
                'parentLinkCashier',
                that.filterSider[index].path
              );
              if (that.filterSider[index].hasOwnProperty('children')) {
                storage.setItem('isChildren', true);
              } else {
                storage.setItem('isChildren', false);
              }
              return false;
            }
            if (item.children) {
              funLink(item.children, index);
            }
          });
        };

        this.filterSider.forEach((item, index) => {
          if (item.children) {
            funLink(item.children, index);
          } else {
            if (activeLink == item.path) {
              that.parentCur = index;
              return false;
            }
          }
        });
        this.handleUpdateMenuState();
      },
      immediate: true,
    },
    menuCollapse() {
      this.handleUpdateMenuState();
    },
  },
  mounted() {
    this.getLogo();
    this.activeMenu();
  },
  methods: {
    resolveNavIcon(nav) {
      if (!nav) return '';
      const icon = String(nav.icon || '').trim();
      const path = String(nav.path || '');
      const title = String(nav.title || '');
      const pathIconMap = {
        'cashier/index': 'iconshouyintai-shouyin1',
        'verify/index': 'iconshouyintai-hexiao1',
        'schedule/index': 'iconshouyintai-jiaojieban',
        'reservation/list': 'iconic-clock2',
        'hang/index': 'iconshouyintai-guadan1',
        'recharge/index': 'iconshouyintai-yonghu',
      };
      if (icon && icon.indexOf('icon') === 0) {
        return icon;
      }
      const matchedKey = Object.keys(pathIconMap).find((key) => path.includes(key));
      if (matchedKey) {
        return pathIconMap[matchedKey];
      }
      if (title === '排班') {
        return 'iconshouyintai-jiaojieban';
      }
      return icon;
    },
    activeMenu() {
      let storage = window.localStorage;
      let activeLink = storage.getItem('parentLinkCashier');
      let chiidLink = storage.getItem('chiidLinkCashier');
      if (
        storage.getItem('isChildrenCashier') == null ||
        (this.filterSider[0] && activeLink == this.filterSider[0].path)
      ) {
        if (this.filterSider[0] && this.filterSider[0].hasOwnProperty('children')) {
          this.$store.commit('store/layout/setChildren', true);
        } else {
          this.$store.commit('store/layout/setChildren', false);
        }
      } else {
        this.$store.commit(
          'store/layout/setChildren',
          JSON.parse(storage.getItem('isChildrenCashier'))
        );
        this.filterSider.forEach((item, index) => {
          if (activeLink == item.path) {
            this.parentCur = index;
          }
        });
        this.$router.push({
          path: chiidLink,
        });
      }
    },
    handelParentClick(nav, index) {
      this.parentCur = index;
      this.$router.push({
        path: nav.path,
      });
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
      try {
        let storage = window.localStorage;
        let value = storage.getItem('cashier_user_info');
        let infoUser = value ? JSON.parse(value) : null;
        if (infoUser && infoUser.logoSmall) {
          this.logo = infoUser.logoSmall;
          this.logoSmall = infoUser.logoSmall;
        }
      } catch (e) {
        /* ignore */
      }
    },
  },
};
</script>
<style scoped lang="stylus">
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #fff;
}

::-webkit-scrollbar {
  width: 0 !important;
}

.cashier-sidebar {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  height: 100%;
  /*
   * 可见黑条宽度须与 basic-layout 的 main 对齐：
   *   main { right:0; width: calc(100% - 90px) } → 白底从 90px 起盖住侧栏
   *   Sider menuWidth=120 → 右侧约 30px 被白底盖住，不能参与居中
   * 因此菜单列按 90px 可见黑条水平居中，而不是按完整 120px Sider 居中。
   */
  width: 90px;
  display: flex;
  flex-direction: column;
  align-items: center;
  background-color: #001529;
  color: #fff;
  font-size: 14px;
  overflow: hidden;
  box-sizing: border-box;
}

.sidebar-logo {
  flex: 0 0 auto;
  width: 100%;
  height: 64px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px 8px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  position: relative;
  z-index: 2;

  img {
    max-width: 100%;
    max-height: 44px;
    object-fit: contain;
  }
}

/* 中间菜单独立占满 Logo 与账号之间的剩余空间；仅此区域滚动 */
.sidebar-menu {
  flex: 1 1 auto;
  align-self: stretch;
  width: 100%;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  /* 底部留白：滚到底时最后一项整块高于账号分隔线，不被压住 */
  padding: 6px 0 32px;
  position: relative;
  z-index: 1;
  -webkit-overflow-scrolling: touch;
}

/* 底部账号：文档流 flex 子项，禁止绝对定位覆盖菜单 */
.sidebar-account {
  flex: 0 0 auto;
  align-self: stretch;
  width: 100%;
  position: relative;
  z-index: 2;
  padding: 10px 6px 14px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 76px;
  background-color: #001529;
  overflow: visible;
}

/deep/ .sidebar-account-user {
  display: block;
  width: 100%;

  .i-layout-header-trigger {
    width: 100%;
  }

  .ivu-dropdown {
    width: 100%;
    display: block;
  }

  .ivu-select-dropdown {
    margin-left: 8px;
  }
}

.list {
  /* 额外底部空白，保证滚到底时最后一项整块露出 */
  width: 100%;
  padding-bottom: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;

  .parent-nav-item {
    width: 100%;
    padding: 8px 0;
    cursor: pointer;
    font-size: 14px;
    line-height: 18px;
    display: flex;
    justify-content: center;
    align-items: center;
    box-sizing: border-box;

    .parent-nav-item-inner {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      width: 68px;
      min-height: 68px;
      height: auto;
      padding: 8px 4px;
      margin: 0 auto;
      border-radius: 10px;
      box-sizing: border-box;
    }

    &.on {
      .parent-nav-item-inner {
        background-color: rgba(24, 144, 255, 1);
      }
    }

    .icon-box {
      height: 22px;
      margin-bottom: 6px;
      flex-shrink: 0;

      .icon {
        font-size: 20px;
      }
    }

    .parent-nav-item-inner > div:last-child {
      max-width: 100%;
      text-align: center;
      word-break: break-all;
      line-height: 1.25;
    }
  }
}

/deep/.ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu) {
  background: #F0F2F5;
  color: #515a6e;
}

/deep/.ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu):after {
  background: #F0F2F5;
}

/deep/.i-layout-menu-side .ivu-menu-submenu-title, /deep/.i-layout-menu-side .ivu-menu-item {
  height: 40px !important;
  line-height: 40px;
  padding: 0 10px 0 20px !important;
}
</style>
