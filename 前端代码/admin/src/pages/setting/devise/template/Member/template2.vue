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
    <div class="pege-title">个人中心</div>
    <div class="acea-row row-middle user-wrapper">
      <!-- 头像 -->
      <img class="avatar" :src="memberData.avatar_url"></img>
      <div class="name-wrap">
        <div class="name">测试昵称</div>
        <div class="phone" v-if="memberData.per_show_type">ID:123</div>
        <div class="phone" v-else>13012341234</div>
      </div>
      <span class="mobiconfont icon-a-ic_QRcode fs-40"></span>
      <span class="mobiconfont icon-a-ic_setup1 fs-40 mx-34"></span>
      <span class="mobiconfont icon-ic_message3 fs-40"></span>
    </div>
    <div class="acea-row row-between promotion-wrapper">
      <div v-for="(item,index) in property.slice(0,5)" :key="index">
        {{item.label}}<span class="value">{{item.value}}</span>
      </div>
    </div>
    <!-- 会员 -->
    <div class="member-wrapper">
      <div class="card">
        <div class="acea-row row-middle top">
          <div class="name-wrap">
            <div class="name">
              <span class="mobiconfont icon-ic_crown fs-31"></span>黄金会员
            </div>
            <div>商城购物可享98折</div>
          </div>
          <div class="icon-wrap">
            <div class="icon">
              <span class="mobiconfont icon-a-ic_discount1 fs-20"></span>
            </div>
            <div>购物折扣</div>
          </div>
          <div class="icon-wrap">
            <div class="icon">
              <span class="mobiconfont icon-ic_badge fs-20"></span>
            </div>
            <div>专属徽章</div>
          </div>
          <span class="mobiconfont icon-ic_rightarrow fs-24 ml-20"></span>
        </div>
        <div class="acea-row row-middle row-between bottom">
          <div class="span">掌握更多快速升级技巧</div>
          <div class="button">去获取</div>
        </div>
      </div>
      <div class="acea-row row-middle grow">
        <div class="mobiconfont icon-ic_horn1 fs-28"></div>
        <div class="span">查看会员成长值，获得更优惠购物折扣～</div>
        <div class="mobiconfont icon-ic_rightarrow fs-24"></div>
      </div>
    </div>
  </div>
</template>

<style lang="stylus" scoped>
.pege-title {
  padding: 15px 0;
  font-weight: 500;
  color: #333;
  font-size: 17px;
  line-height: 17px;
  text-align: center;
}

.jianbian1 {
  background: linear-gradient(90deg, #f4dfaf 0%, #d0a15b 100%);
}

.jianbian2 {
  background: linear-gradient(180deg, #faeed9 0%, #ffffff 100%);
  width: 710px;
  left: 50%;
  transform: translateX(-50%);
}

.border_top {
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.SemiBold {
  font-family: SemiBold;
}

.v-Line {
  width: 1px;
  height: 20px;
  background: #eee;
}

.span-primary-con {
  color: var(--div-theme);
}

.user-wrapper {
  padding: 20px 20px 20px 16px;

  .avatar {
    width: 68px;
    height: 68px;
    border-radius: 50%;
  }

  .name-wrap {
    flex: 1;
    padding: 0 12px;
    color: #333333;
  }

  .name {
    font-weight: 500;
    font-size: 16px;
    line-height: 22px;
  }

  .phone {
    margin-top: 5px;
    font-size: 12px;
    line-height: 17px;
  }

  .mobiconfont {
    align-self: flex-start;
    margin-top: 11px;
  }
}

.promotion-wrapper {
  padding: 0 24px;
  font-size: 13px;
  line-height: 18px;
  color: #999999;

  .value {
    margin-left: 4px;
    font-family: SemiBold;
    font-weight: 600;
    font-size: 14px;
    color: #333333;
  }
}

.member-wrapper {
  margin: 14px 10px 10px;
  color: #7e4b06;

  .card {
    position: relative;
    border-radius: 16px;
    margin-bottom: -14px;
    background: linear-gradient(-270deg, #f4dfaf 0%, #d0a15b 100%);

    .top {
      padding: 12px 17px;
    }

    .name-wrap {
      flex: 1;
      font-size: 12px;
      line-height: 12px;
    }

    .name {
      margin-bottom: 2px;
      font-weight: 700;
      font-size: 17px;
      line-height: 24px;

      .mobiconfont {
        margin-right: 6px;
        font-size: 18px;
      }
    }

    .icon-wrap {
      font-size: 12px;
      line-height: 12px;

      + .icon-wrap {
        margin-left: 16px;
      }
    }

    .icon {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      margin: 0 auto 3px;
      background-color: #eccd8b;
      text-align: center;
      line-height: 20px;

      .mobiconfont {
        font-size: 12px;
      }
    }

    .bottom {
      position: relative;
      padding: 8px 17px;
      font-size: 12px;

      &::before {
        content: '';
        position: absolute;
        top: 0;
        right: 17px;
        left: 17px;
        height: 1px;
        background-color: rgba(0, 0, 0, 0.08);
      }
    }

    .span {
      padding-left: 57x;
    }

    .button {
      height: 24px;
      padding: 0 12px;
      border-radius: 12px;
      background-color: #eccd8b;
      font-weight: 500;
      line-height: 24px;
    }
  }

  .grow {
    padding: 25px 20px 10px;
    border-radius: 16px;
    background: linear-gradient(180deg, #faeed9 0%, #ffffff 100%);

    .span {
      flex: 1;
      padding-left: 8px;
    }
  }
}

.service {
  .image {
    width: 24px;
    height: 24px;
  }
}

.order-wrapper {
  .image {
    width: 24px;
    height: 24px;
  }
}
</style>