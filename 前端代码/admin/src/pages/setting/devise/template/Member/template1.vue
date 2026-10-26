<template>
  <div class="">
    <div class="header">
      <!-- 用户信息、设置 -->
      <div class="pege-title">个人中心</div>
      <div class="acea-row row-middle user">
        <img class="avatar" :src="memberData.avatar_url" alt="" />
        <div class="name-wrap">
          <div class="name">用户昵称</div>
          <div class="phone" v-if="memberData.per_show_type">ID:123</div>
          <div class="phone" v-else>13012341234</div>

        </div>
        <div class="">
          <span class="mobiconfont icon-a-ic_QRcode fs-40"
            ><span class="tips">会员码</span></span
          >
          <span class="mobiconfont icon-a-ic_setup1 fs-40 mx-17"></span>
          <span class="mobiconfont icon-ic_message3 fs-40"
            ><span class="number">56</span></span
          >
        </div>
      </div>
      <!-- 余额、优惠券 -->
      <div class="acea-row balance-coupon">
        <div class="item" v-for="(item,index) in property.slice(0,5)" :key="index">
          <div class="value">{{item.value}}</div>
          <div>{{item.label}}</div>
        </div>
      </div>
    </div>
    <!-- 会员中心、积分商城 -->
    <div class="acea-row member-points">
      <div class="acea-row row-middle item">
        <div>
          <div>会员中心</div>
          <div class="arrow">
            查看新权益<span class="mobiconfont icon-ic_rightarrow"></span>
          </div>
        </div>
        <img src="@/assets/img/user-member.png" class="image"></img>
      </div>
      <div class="acea-row row-middle item">
        <div>
          <div>积分商城</div>
          <div class="arrow">
            限量兑神券<span class="mobiconfont icon-ic_rightarrow"></span>
          </div>
        </div>
        <img src="@/assets/img/user-points.png" class="image"></img>
      </div>
    </div>
  </div>
</template>

<script>
import propertyList from "@/plugins/propertyList";

export default {
  props: {},
  data() {
    return {
      property: [],
    };
  },
  computed: {
    // 获取用户信息配置
    memberData() {
      return this.$store.state.admin.userTemplateConfig.member;
    },
    propertyData() {
      return this.$store.state.admin.userTemplateConfig.member.property;
    },
  },
  watch: {
    propertyData: {
      handler(nVal, oVal) {
        this.getPropertyArr(nVal);
      },
      immediate: true,
      deep: true,
    },
  },
  methods: {
    getPropertyArr(arr) {
      this.property = [];
      propertyList.map((e) => {
        arr.map((i) => {
          if (i == e.value) {
            this.property.push({
              label: e.label,
              value: this.getRandomNum(),
            });
          }
        });
      });
      console.log(this.property);
    },
    // 生成0-100随机数
    getRandomNum() {
      return Math.floor(Math.random() * 100);
    },
  },
};
</script>

<style lang="stylus" scoped>
.header {
  padding: 9px 0 58px;
  border-bottom-right-radius: 50% 10px;
  border-bottom-left-radius: 50% 10px;
  margin-bottom: -44px;
  background-color: #E93323;
  font-family: PingFang SC, PingFang SC;

  .pege-title {
    padding: 15px 0;
    font-weight: 500;
    color: #FFFFFF;
    font-size: 17px;
    line-height: 17px;
    text-align: center;
  }

  .user {
    padding: 0 20px 0 15px;

    .mobiconfont {
      font-size: 20px;
    }
  }

  .avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
  }

  .name-wrap {
    flex: 1;
    padding: 0 8px;
    color: #ffffff;
  }

  .name {
    font-weight: 500;
    font-size: 16px;
    line-height: 22px;
  }

  .phone {
    margin-top: 10px;
    font-size: 12px;
    line-height: 17px;
  }

  .mobiconfont {
    position: relative;
    color: #ffffff;
  }

  .tips {
    position: absolute;
    bottom: 100%;
    left: 50%;
    height: 14px;
    padding: 0 7px;
    border-radius: 7px;
    margin-bottom: 4px;
    background-color: #ffd89c;
    transform: translateX(-50%);
    white-space: nowrap;
    font-size: 8px;
    line-height: 14px;
    color: #9e5e1a;
  }

  .number {
    position: absolute;
    top: -8px;
    right: 0;
    min-width: 12px;
    height: 12px;
    padding: 0 6px;
    border: 1px solid #e93323;
    border-radius: 6px;
    background-color: #ffffff;
    transform: translateX(50%);
    font-weight: 500;
    font-size: 9px;
    line-height: 9px;
    color: #e93323;
    white-space: nowrap;
  }
}

.balance-coupon {
  margin-top: 22px;

  .item {
    flex: 1;
    text-align: center;
    font-weight: 500;
    font-size: 11px;
    line-height: 11px;
    color: rgba(255, 255, 255, 0.6);
  }

  .value {
    margin-bottom: 12px;
    font-weight: 600;
    font-size: 16px;
    line-height: 16px;
    color: rgba(255, 255, 255, 0.85);
  }
}

.member-points {
  border-radius: 10px;
  margin: 10px;
  background-color: #ffffff;
  height: 67px;

  .item {
    flex: 1;
    padding-left: 40px;
    font-weight: 500;
    font-size: 14px;
    line-height: 17px;
    color: #333333;
  }

  .arrow {
    margin-top: 12px;
    font-weight: 400;
    font-size: 11px;
    line-height: 12px;
    color: #ff7d00;
  }

  .image {
    width: 44px;
    height: 44px;
    margin-left: 10px;
  }

  .mobiconfont {
    margin-left: 2px;
    font-size: 12px;
  }
}

.order-section {
  border-radius: 10px;
  margin: 10px;
  background-color: #ffffff;

  .top {
    position: relative;
    height: 84px;

    &::after {
      content: '';
      position: absolute;
      right: 8px;
      bottom: 0;
      left: 8px;
      height: 1px;
      background-color: #eeeeee;
    }

    .item {
      flex: 1;
      padding-left: 8px;
      font-size: 13px;
      color: #999999;
    }

    .value {
      margin-left: 8px;
      font-size: 28px;
      color: #333333;
    }
  }

  .section-header {
    padding: 8px 12px 0;
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
    padding: 24px 0 18px;

    .item {
      flex: 1;
      text-align: center;
      font-size: 13px;
      line-height: 18px;
      color: #333333;
    }

    .icon {
      margin-bottom: 18px;
    }

    .mobiconfont {
      font-size: 24px;
    }
  }
}

.pay-order {
  border-radius: 10px;
  margin: 10px;
  background-color: #ffffff;

  .item {
    position: relative;
    flex: 1;
    height: 160px;
    font-weight: 600;
    font-size: 18px;
    line-height: 18px;
    color: #333333;

    &::before {
      content: '';
      position: absolute;
      top: 27px;
      left: 0;
      width: 1px;
      height: 21px;
      background-color: #dddddd;
    }

    &:first-child::before {
      display: none;
    }
  }

  .arrow {
    margin-top: 8px;
    font-weight: 400;
    font-size: 12px;
    line-height: 17px;
    color: #666666;
  }

  .mobiconfont {
    font-size: 12px;
  }
}

.shortcut {
  border-radius: 10px;
  margin: 10px;
  background-color: #ffffff;

  .image {
    width: 100%;
    height: 188px;
    object-fit: contain;
  }
}

.service-section {
  border-radius: 10px;
  margin: 10px;
  background-color: #ffffff;

  .section-header {
    padding: 8px 12px 0;
    font-weight: 500;
    font-size: 15px;
    line-height: 21px;
    color: #333333;
  }

  .section-content {
    padding: 12px 0 12px;
  }

  .item {
    flex: 0 0 25%;
    padding: 10px 0;
    text-align: center;
    font-size: 13px;
    line-height: 18px;
    color: #282828;
  }

  .image {
    display: block;
    width: 66px;
    height: 68px;
    margin: 0 auto 8px;
  }
}
</style>