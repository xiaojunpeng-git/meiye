<script>
export default {
  props: {
    userInfo: {
      type: Object,
      default: () => {},
    },
    MyMenus: {
      type: Array,
      default() {
        return [];
      },
    },
  },
  data() {
    return {
      orderMenu: [
        {
          icon: "icon-ic_daifukuan12",
          title: "待付款",
          url: "/pages/goods/order_list/index?status=0",
        },
        {
          icon: "icon-ic_daifahuo11",
          title: "待发货",
          url: "/pages/goods/order_list/index?status=1",
        },
        {
          icon: "icon-ic_daishouhuo1",
          title: "待收货",
          url: "/pages/goods/order_list/index?status=2",
        },
        {
          icon: "icon-ic_daipingjia1",
          title: "待评价",
          url: "/pages/goods/order_list/index?status=3",
        },
        {
          icon: "icon-ic_daituikuan1",
          title: "售后/退款",
          url: "/pages/users/user_return_list/index",
        },
      ],
    };
  },
  methods: {
    intoPage(url) {
      this.$emit("intoPage", url);
    },
    goMenuPage(url, name) {
      this.$emit("goMenuPage", url, name);
    },
    tapQrCode() {
      this.$emit("tapQrCode");
    },
  },
};
</script>
<template>
  <div>
    <!-- 订单中心 -->
    <div class="order-section">
      <div class="acea-row row-middle row-between section-header">
        <div>订单中心</div>
        <div class="arrow">
          查看全部<span class="mobiconfont icon-ic_rightarrow"></span>
        </div>
      </div>
      <div class="acea-row section-content">
        <div v-for="item in orderMenu" class="item">
          <div class="icon">
            <span class="mobiconfont" :class="item.icon"></span>
          </div>
          <div class="">{{ item.title }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="stylus" scoped>
.order-section {
  border-radius: 10px;
  margin: 10px;
  background-color: #FFFFFF;

  .top {
    position: relative;
    height: 42px;

    &::after {
      content: '';
      position: absolute;
      right: 16px;
      bottom: 0;
      left: 16px;
      height: 1px;
      background-color: #EEEEEE;
    }

    .item {
      flex: 1;
      padding-left: 16px;
      font-size: 13px;
      color: #999999;
    }

    .value {
      margin-left: 4px;
      font-size: 14px;
      color: #333333;
    }
  }

  .section-header {
    padding: 16px 12px 0;
    font-weight: 500;
    font-size: 15px;
    line-height: 21px;
    color: #333333;
  }

  .arrow {
    font-weight: 400;
    font-size: 13px;
    line-height: 18px;
    color: #999999;
  }

  .mobiconfont {
    font-size: 12px;
  }

  .section-content {
    padding: 14px 0 18px;

    .item {
      flex: 1;
      text-align: center;
      font-size: 13px;
      line-height: 18px;
      color: #333333;
    }

    .icon {
      margin-bottom: 9px;
    }

    .mobiconfont {
      font-size: 24px;
    }
  }
}
</style>