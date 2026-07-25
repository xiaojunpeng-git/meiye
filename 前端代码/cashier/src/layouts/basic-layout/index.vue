<template>
  <Layout class="i-layout">
    <!-- 顶部黑色栏已移除：Logo / 账号迁至左侧导航；交接班仅取消入口展示，方法与弹窗保留 -->
    <Layout
        class="i-layout-inside"
        :class="isChildren ? 'bodyBig' : 'bodySmall'"
    >
      <Sider class="i-layout-sider" :class="siderClasses" :width="menuWidth">
        <i-menu-side :hide-logo="false"/>
      </Sider>
      <main>
        <Content class="i-layout-content" :class="contentClasses">
          <div class="i-layout-content-main">
            <keep-alive :include="keepAlive">
              <router-view v-if="loadRouter"/>
            </keep-alive>
          </div>
        </Content>
      </main>
    </Layout>
    <Modal
        v-model="modal1"
        title="交接班"
        width="900"
        class-name="handover-modal"
        footer-hide>
        <div class="acea-row">
          <div>收银员：{{userInfo.staff_name}}（{{userInfo.account}}）</div>
          <div class="ml-24">班次：{{userInfo.shift_start_time * 1000 | timeFormat}}  ~ {{userInfo.shift_end_time | timeFormat}}</div>
        </div>
        <div class="grid-box mt-20 text-wlll-606266">
          <div class="acea-row row-column row-center-wrapper h-76 border-1-DDDDDD rd-10">
            <div>应收金额</div>
            <div class="mt-6 fs-16 text-wlll-303133">¥{{Number(handoverData.sumPrice)}}</div>
          </div>
          <div class="acea-row row-column row-center-wrapper h-76 border-1-DDDDDD rd-10">
            <div>订单销售额</div>
            <div class="mt-6 fs-16 text-wlll-303133">¥{{handoverData.order_price}}</div>
          </div>
          <div class="acea-row row-column row-center-wrapper h-76 border-1-DDDDDD rd-10">
            <div>充值金额</div>
            <div class="mt-6 fs-16 text-wlll-303133">¥{{handoverData.recharge_price}}</div>
          </div>
          <div class="acea-row row-column row-center-wrapper h-76 border-1-DDDDDD rd-10">
            <div>付费会员金额</div>
            <div class="mt-6 fs-16 text-wlll-303133">¥{{handoverData.other_price}}</div>
          </div>
          <div class="acea-row row-column row-center-wrapper h-76 border-1-DDDDDD rd-10">
            <div>退款金额</div>
            <div class="mt-6 fs-16 text-wlll-303133">¥{{handoverData.refund_price}}</div>
          </div>
          <div class="acea-row row-column row-center-wrapper h-76 border-1-DDDDDD rd-10">
            <div>现金金额</div>
            <div class="mt-6 fs-16 text-wlll-303133">¥{{Number(handoverData.cash_price)}}</div>
          </div>
        </div>
        <div class="border-1-DDDDDD rd-10 mt-20 overflow">
          <Table :columns="columns1" :data="data1"></Table>
        </div>
        <div class="pt-17 pb-17 mt-10">
          <Button type="primary" class="w-full h-46 rd-23 fw-500 fs-16" @click="logoutHandle">交班并登出</Button>
        </div>
    </Modal>
  </Layout>
</template>
<script>
import iMenuHead from "./menu-head";
import iMenuSide from "./menu-side/index";
import iHeaderLogo from "./header-logo";
import iHeaderCollapse from "./header-collapse";
import iHeaderReload from "./header-reload";
import iHeaderBreadcrumb from "./header-breadcrumb";
import iHeaderSearch from "./header-search";
import iHeaderLog from "./header-log";
import iHeaderFullscreen from "./header-fullscreen";
import iHeaderNotice from "./header-notice";
import iHeaderUser from "./header-user";
import iHeaderI18n from "./header-i18n";
import iHeaderSetting from "./header-setting";
import iTabs from "./tabs";
import iCopyright from "@/components/copyright";

import {mapState, mapGetters, mapMutations, mapActions} from "vuex";
import Setting from "@/setting";

import {requestAnimation} from "@/libs/util";
import dayjs from "dayjs";
import {shiftHandoverApi} from "@/api/account.js";

export default {
  name: "BasicLayout",
  components: {
    iMenuHead,
    iMenuSide,
    iCopyright,
    iHeaderLogo,
    iHeaderCollapse,
    iHeaderReload,
    iHeaderBreadcrumb,
    iHeaderSearch,
    iHeaderUser,
    iHeaderI18n,
    iHeaderLog,
    iHeaderFullscreen,
    iHeaderSetting,
    iHeaderNotice,
    iTabs,
  },
  // provide (){
  //     return {
  //         reload:this.handleReload
  //     }
  // },
  filters: {
      timeFormat (value) {
          if (!value) {
              return '-';
          }
          return dayjs(value).format('YYYY-MM-DD HH:mm:ss');
      }
  },
  data() {
    return {
      showDrawer: false,
      ticking: false,
      headerVisible: true,
      oldScrollTop: 0,
      isDelayHideSider: false, // hack，当从隐藏侧边栏的 header 切换到正常 header 时，防止 Logo 抖动
      loadRouter: true,
      openImage: true,
      menuWidth: 120,
      modal1: false,
      handoverData: {},
      userInfo: {},
      columns1: [
        {
          title: '支付方式',
          key: 'pay_type'
        },
        {
          title: '订单销售额',
          key: 'order_price',
          render: (h, params) => {
            return h('div', `¥${params.row.order_price}`);
          },
        },
        {
          title: '充值金额',
          key: 'recharge_price',
          render: (h, params) => {
            return h('div', `¥${params.row.recharge_price}`);
          },
        },
        {
          title: '付费会员金额',
          key: 'other_price',
          render: (h, params) => {
            return h('div', `¥${params.row.other_price}`);
          },
        },
        {
          title: '退款金额',
          key: 'refund_price',
          render: (h, params) => {
            return h('div', `¥${params.row.refund_price}`);
          },
        },
        {
          title: '应收金额',
          key: 'sum_price',
          render: (h, params) => {
            return h('div', `¥${Number(params.row.sum_price)}`);
          },
        },
      ],
      data1: [],
    };
  },
  computed: {
    ...mapState("store/layout", [
      "siderTheme",
      "headerTheme",
      "headerStick",
      "tabs",
      "tabsFix",
      "siderFix",
      "headerFix",
      "headerHide",
      "headerMenu",
      "isMobile",
      "isTablet",
      "isDesktop",
      "menuCollapse",
      "showMobileLogo",
      "showSearch",
      "showNotice",
      "showFullscreen",
      "showSiderCollapse",
      "showBreadcrumb",
      "showLog",
      "showI18n",
      "showReload",
      "enableSetting",
      "isChildren",
    ]),
    ...mapState("store/page", ["keepAlive"]),
    ...mapState("store/user", ["staffList"]),
    ...mapGetters("store/menu", ["hideSider"]),
    // 如果开启 headerMenu，且当前 header 的 hideSider 为 true，则将顶部按 headerStick 处理
    // 这时，即使没有开启 headerStick，仍然按开启处理
    isHeaderStick() {
      let state = this.headerStick;
      if (this.hideSider) state = true;
      return state;
    },
    showHeader() {
      let visible = true;
      if (this.headerFix && this.headerHide && !this.headerVisible)
        visible = false;
      return visible;
    },
    headerClasses() {
      return [
        `i-layout-header-color-${this.headerTheme}`,
        {
          "i-layout-header-fix": this.headerFix,
          "i-layout-header-fix-collapse": this.headerFix && this.menuCollapse,
          "i-layout-header-mobile": this.isMobile,
          "i-layout-header-stick": this.isHeaderStick && !this.isMobile,
          "i-layout-header-with-menu": this.headerMenu,
          "i-layout-header-with-hide-sider":
              this.hideSider || this.isDelayHideSider,
        },
      ];
    },
    headerStyle() {
      // const menuWidth = this.isHeaderStick ? 0 : this.menuCollapse ? 80 : Setting.menuSideWidth;
      // return {width: `calc(100% - ${this.menuWidth}px)`};
      // return this.isMobile || !this.headerFix ? {} : {
      //     width: `calc(100% - ${menuWidth}px)`
      // };
    },
    siderClasses() {
      return {
        "i-layout-sider-fix": this.siderFix,
        "i-layout-sider-dark": this.siderTheme === "dark",
      };
    },
    contentClasses() {
      return {
        "i-layout-content-fix-with-header": this.headerFix,
        "i-layout-content-with-tabs": this.tabs,
        "i-layout-content-with-tabs-fix": this.tabs && this.tabsFix,
      };
    },
    insideClasses() {
      return {
        "i-layout-inside-fix-with-sider": this.siderFix,
        "i-layout-inside-fix-with-sider-collapse":
            this.siderFix && this.menuCollapse,
        "i-layout-inside-with-hide-sider": this.hideSider,
        "i-layout-inside-mobile": this.isMobile,
      };
    },
    drawerClasses() {
      let className = "i-layout-drawer";
      if (this.siderTheme === "dark") className += " i-layout-drawer-dark";
      return className;
    },
    menuSideWidth() {
      return this.menuCollapse ? 60 : Setting.menuSideWidth;
    },
  },
  watch: {
    hideSider() {
      this.isDelayHideSider = true;
      setTimeout(() => {
        this.isDelayHideSider = false;
      }, 0);
    },
    $route(to, from) {
      if (to.path === from.path) {
        // 相同路由，不同参数，跳转时，重载页面
        if (Setting.sameRouteForceUpdate) {
          this.handleReload();
        }
      }
    },
  },
  methods: {
    ...mapMutations("store/layout", ["updateMenuCollapse"]),
    ...mapMutations("store/order", [
      "getOrderStatus",
      "getOrderTime",
      "getOrderNum",
    ]),
    ...mapActions('store/account', [
      'logout'
    ]),
    handleToggleDrawer(state) {
      if (typeof state === "boolean") {
        this.showDrawer = state;
      } else {
        this.showDrawer = !this.showDrawer;
      }
    },
    handleScroll() {
      if (!this.headerHide) return;

      const scrollTop =
          document.body.scrollTop + document.documentElement.scrollTop;

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
      this.getOrderStatus("");
      this.getOrderTime("");
      this.getOrderNum("");
      this.$nextTick(() => {
        this.loadRouter = true;
      });
    },
    clear() {
      this.openImage = false;
    },
    // 交接班数据
    getHandover(staffId) {
      shiftHandoverApi(staffId).then(res => {
        this.handoverData = res.data;
        this.data1 = res.data.details;
      });
    },
    // 交接班
    async handoverHandle() {
      const db = await this.$store.dispatch("store/db/database", {
        user: true,
      });
      const userInfo = db.get('cashier_user_info').value();
      const { staff_name } = this.staffList.find((item) => item.id == userInfo.id);
      const date1 = dayjs(userInfo.shift_start_time * 1000);
      const date2 = dayjs();
      const diffTime = date2.diff(date1);
      this.userInfo = {
         ...userInfo,
         staff_name,
         shift_end_time: diffTime > 0 ? date2 : date1.add(-(diffTime || -1000), 'millisecond'),
      };
      this.getHandover(this.userInfo.id);
      this.modal1 = true;
    },
    // 交班并登出
    logoutHandle() {
      try {
        window.Jsbridge.invoke('collectLogout',JSON.stringify({'p1-key':'p1-value'}));
      }catch (e){}
      this.logout({
        confirm: this.logoutConfirm,
        vm: this,
        type: 1
      });
    }
  },
  mounted() {
    document.addEventListener("scroll", this.handleScroll, {passive: true});
  },
  beforeDestroy() {
    document.removeEventListener("scroll", this.handleScroll);
  },
  created() {
    if (this.isTablet && this.showSiderCollapse) this.updateMenuCollapse(true);
    if (!this.staffList.length) {
      this.$store.dispatch('store/user/cashierList');
    }
  },
};
</script>
<style scoped lang="stylus">
/deep/ .i-layout {
  display: flex;
  flex-direction: column;
  height: 100vh !important;
  min-height: 100vh !important;
  overflow: hidden;
}

/deep/ .i-layout-header-fix {
  left: 0;
}

/deep/ .i-layout-sider {
  margin-top: 0 !important;
  height: 100vh !important;
}

/deep/ .i-layout-inside {
  height: 100vh !important;
  min-height: 100vh !important;
  flex: 1;
  min-height: 0;
}

/deep/ .i-layout-menu-head-title-text {
  font-size: 20px;
  font-weight: 500;
  margin-left: 30px;
  color: #fff;
}

/deep/ .ivu-layout-header {
  height: 0 !important;
  line-height: 0 !important;
  display: none !important;
  padding: 0 !important;
  margin: 0 !important;
}

/deep/ .bodyBig {
  padding-left: 150px;
}

/deep/ .bodySmall {
  padding-left: 120px;
}

/deep/ .onBig {
}

/deep/ .onSmall {
  left: 150px !important;
}

.i-layout-content-main {
  height: 100% !important;
  min-height: 0;
}

.i-layout-header {
  display: none !important;
  height: 0 !important;
  margin: 0 !important;
  padding: 0 !important;
  background-color: #001529;
  z-index: 15;
}

/deep/ .i-layout-content-fix-with-header {
  padding-top: 0 !important;
}

/deep/ .i-layout-content {
  height: 100% !important;
  min-height: 0;
}

main {
  display: flex;
  flex-direction: column;
  border-top-left-radius: 30px;
  margin-left: -20px;
  width: calc(100% - 90px);
  height: 100vh !important;
  max-height: 100vh !important;
  background-color: #fff;
  overflow: hidden;
  z-index: 9;
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
}

.i-layout-sider {
  z-index: 8;
}

.i-layout-header-trigger:hover {
  background: rgba(#001529, 0.3);
}
.i-layout-sider-fix {
  top: 0 !important;
  bottom: 0 !important;
  height: 100vh !important;
  min-height: 0;
  margin-top: 0 !important;
}
.grid-box {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1fr;
  grid-column-gap: 19px;
}
/deep/ .handover-modal .ivu-modal-body {
  padding: 20px 25px 0;
}
/deep/ .handover-modal tr:last-of-type td {
  border-bottom: 0;
}
/deep/ .handover-modal th {
  color: #606266;
}
</style>
