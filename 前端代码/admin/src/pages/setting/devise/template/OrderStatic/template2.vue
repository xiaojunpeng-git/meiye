<script>
export default {
  props: {
    orderMenu: {
      type: Array,
      default() {
        return [];
      },
    },
  },
  methods: {},
};
</script>
<template>
  <div>
    <div class="px-10">
      <div
        class="rd-16px bg--w111-fff pt-18 pr-17 pb-19 pl-20 flex-between-center"
      >
        <div class="flex-col flex-center text--w111-333">
          <span class="fs-18 fw-600 lh-36px SemiBold">2200</span>
          <span class="fs-12 lh-17px pt-8"
            >支付订单金额
            <span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </span>
        </div>
        <div class="v-Line"></div>
        <div class="flex-col flex-center text--w111-333">
          <span class="fs-18 fw-600 lh-36px SemiBold">20</span>
          <span class="fs-12 lh-17px pt-8"
            >支付订单数 <span class="mobiconfont icon-ic_rightarrow fs-12"></span
          ></span>
        </div>
        <div class="v-Line"></div>
        <div class="flex-col flex-center text--w111-333">
          <span class="fs-18 fw-600 lh-36px SemiBold">100</span>
          <span class="fs-12 lh-17px pt-8"
            >待发货订单数 <span class="mobiconfont icon-ic_rightarrow fs-12"></span
          ></span>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="stylus" scoped>
.border_top {
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.SemiBold {
  font-family: SemiBold;
}

.v-Line {
  width: 1px;
  height: 40px;
  background: #eee;
}

.span-primary-con {
  color: var(--div-theme);
}

.promotion-wrapper {
  padding: 0 48px;
  font-size: 26px;
  line-height: 36px;
  color: #999999;

  .value {
    margin-left: 8px;
    font-family: SemiBold;
    font-weight: 600;
    font-size: 28px;
    color: #333333;
  }
}

.member-wrapper {
  margin: 28px 20px 20px;
  color: #7e4b06;

  .card {
    position: relative;
    border-radius: 32px;
    margin-bottom: -28px;
    background: linear-gradient(-270deg, #f4dfaf 0%, #d0a15b 100%);

    .top {
      padding: 25px 35px;
    }

    .name-wrap {
      flex: 1;
      font-size: 19px;
      line-height: 26px;
    }

    .name {
      margin-bottom: 4px;
      font-weight: 700;
      font-size: 34px;
      line-height: 48px;

      .mobiconfont {
        margin-right: 12px;
        font-size: 36px;
      }
    }

    .icon-wrap {
      font-size: 18px;
      line-height: 26px;

      + .icon-wrap {
        margin-left: 32px;
      }
    }

    .icon {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin: 0 auto 6px;
      background-color: #eccd8b;
      text-align: center;
      line-height: 40px;

      .mobiconfont {
        font-size: 24px;
      }
    }

    .bottom {
      position: relative;
      padding: 17px 35px;
      font-size: 24px;

      &::before {
        content: '';
        position: absolute;
        top: 0;
        right: 35px;
        left: 35px;
        height: 1px;
        background-color: rgba(0, 0, 0, 0.08);
      }
    }

    .span {
      padding-left: 114px;
    }

    .button {
      height: 48px;
      padding: 0 24px;
      border-radius: 24px;
      background-color: #eccd8b;
      font-weight: 500;
      line-height: 48px;
    }
  }

  .grow {
    padding: 50px 40px 20px;
    border-radius: 32px;
    background: linear-gradient(180deg, #faeed9 0%, #ffffff 100%);

    .span {
      flex: 1;
      padding-left: 16px;
    }
  }
}

.service {
  .image {
    width: 48px;
    height: 48px;
  }
}

.order-wrapper {
  .image {
    width: 48px;
    height: 48px;
  }
}
</style>