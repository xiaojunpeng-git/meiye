<template>
  <div class="acea-row">
    <div
      class="i-layout-sider-logo"
      :class="{ 'i-layout-sider-logo-dark': siderTheme === 'dark' }"
    >
      <!-- <transition name="fade-quick">
        <i-link style="width: 100%;height: 60px;display: block;line-height: 60px;">
          <img :src="logoSmall" v-if="menuCollapse" />
          <img :src="logo" v-else-if="siderTheme === 'light'" />
          <img :src="logo" v-else />
        </i-link>
      </transition> -->
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
  </div>
</template>
<script>
import iMenuSideItem from './menu-item';
import iMenuSideSubmenu from './submenu';
import iMenuSideCollapse from './menu-collapse';
import tTitle from '../mixins/translate-title';

import { mapState, mapGetters } from 'vuex';

export default {
  name: 'iMenuSide',
  mixins: [tTitle],
  components: { iMenuSideItem, iMenuSideSubmenu, iMenuSideCollapse },
  props: {
    hideLogo: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      logo: require('@/assets/images/logo.png'),
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
              // that.$store.commit("admin/layout/setParentCur", index);
              // that.$store.commit("admin/layout/setChildren", false);
              return false;
            }
          }
        });
        this.handleUpdateMenuState();
      },
      immediate: true,
    },
    // 在展开/收起侧边菜单栏时，更新一次 menu 的状态
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
        activeLink == this.filterSider[0].path
      ) {
        if (this.filterSider[0].hasOwnProperty('children')) {
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
      let chiidLink = '';
      let storage = window.localStorage;
      chiidLink = nav.path;
      // this.$store.commit("store/layout/setChildren", false);
      // storage.setItem("isChildrenCashier", false);
      this.$router.push({
        path: nav.path,
      });
      // storage.setItem("parentLinkCashier", nav.path);
      // storage.setItem("chiidLinkStore", chiidLink);
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
      let storage = window.localStorage;
      let value = storage.getItem('cashier_user_info');
      let infoUser = JSON.parse(value);
      // this.logo = infoUser.logoSmall ? infoUser.logoSmall : this.logoSmall;
      // this.logoSmall = infoUser.logoSmall ? infoUser.logoSmall : this.logoSmall;
      // this.$store
      //   .dispatch("store/db/get", {
      //     dbName: "sys",
      //     path: "user.info",
      //     user: true,
      //   })
      //   .then((res) => {
      //     this.logo = res.logo ? res.logo : this.logo;
      //     this.logoSmall = res.logoSmall ? res.logoSmall : this.logoSmall;
      //   });
    },
  },
};
</script>
<style scoped lang="stylus">
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #fff;
}

::-webkit-scrollbar {
  width: 0 !important; /* 对垂直流动条有效 */
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

.menu {
  width: 160px;

  .title {
    height: 61px;
    width: 100%;
    text-align: center;
    line-height: 61px;
    font-size: 15px;
    font-weight: 600;
    border-bottom: 1px solid #e9e9e9;
    border-right: 1px solid #f7f7f7;
  }
}

.i-layout-sider-logo {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  height: 100%;
  line-height: unset;
  width: 140px;
  padding-right: 0px;
  background-color: #001529;
  color: #fff;
  font-size: 14px;
  padding-top: 9px;
  border-bottom: unset;
  overflow-y: auto;
  overflow-x: hidden;

  .list {

    .parent-nav-item {
      padding: 11px;
      cursor: pointer;
      font-size: 16px;
      line-height: 16px;

      .parent-nav-item-inner {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        width: 68px;
        height: 68px;
        border-radius: 10px;
      }

      &.on {
        .parent-nav-item-inner {
          background-color: rgba(24, 144, 255, 1);
        }
      }

      .icon-box {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 34px;
        height: 34px;
        margin: 0 auto 8px;
      }

      .icon {
        font-size: 24px;
      }
    }
  }

  img {
    width: 41px;
    height: 41px;
    border-radius: 50%;
  }
}
</style>
