<script>
export default {
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
    <div class="acea-row row-middle user">
      <img class="avatar" :src="memberData.avatar_url"></img>
      <div class="name-wrap">
        <div class="name">测试昵称</div>
          <div class="phone" v-if="memberData.per_show_type">ID:123</div>
          <div class="phone" v-else>13012341234</div>
      </div>
      <span class="mobiconfont icon-a-ic_QRcode fs-20" @click="tapQrCode"
        ><span class="tips">会员码</span></span
      >
      <span
        class="mobiconfont icon-a-ic_setup1 fs-20 mx-34"
      ></span>
      <span
        class="mobiconfont icon-ic_message3 fs-20"
        ><span class="number">5</span></span
      >
    </div>
    <div class="distribution">
      <div class="acea-row row-middle row-between withdraw">
        <div>
          <div>可提现(元)</div>
          <div class="value">200.00</div>
        </div>
        <div
          class="button"
        >
          立即提现
        </div>
      </div>
      <div class="acea-row">
        <div class="item">
          <div class="inner">
            <div>累计佣金</div>
            <div class="value">60.8</div>
          </div>
        </div>
        <div class="item">
          <div class="inner">
            <div>推荐人数</div>
            <div class="value">80</div>
          </div>
        </div>
        <div class="item">
          <div class="inner">
            <div>推荐单数</div>
            <div class="value">100</div>
          </div>
        </div>
      </div>
    </div>
    
  </div>
</template>

<style lang="stylus" scoped>
.user {
  padding: 12px 20px 20px 16px;

  .avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
  }

  .name-wrap {
    flex: 1;
    padding: 0 16px;
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
    position: relative;
  }

  .tips {
    position: absolute;
    bottom: 100%;
    left: 50%;
    height: 14px;
    padding: 0 7px;
    border-radius: 7px;
    margin-bottom: 2px;
    background-color: #ffd89c;
    transform: translateX(-50%);
    white-space: nowrap;
    font-size: 8px;
    line-height: 14px;
    color: #9e5e1a;
  }

  .number {
    position: absolute;
    top: -4px;
    right: 0;
    min-width: 12px;
    height: 12px;
    padding: 0 3px;
    border: 1px solid #e93323;
    border-radius: 6px;
    background-color: #ffffff;
    transform: translateX(50%);
    font-weight: 500;
    font-size: 9px;
    line-height: 9px;
    color: #e93323;
  }
}

.distribution {
  border-radius: 12px;
  margin: 0 10px 10px;
  background-color: #ffffff;

  .withdraw {
    height: 88px;
    padding: 0 28px 0 24px;
    background-image: url('~@/assets/img/black_user_bg.png');
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
    font-size: 13px;
    line-height: 18px;
    color: rgba(255, 231, 198, 0.8);

    .value {
      margin-top: 8px;
      font-family: SemiBold;
      font-weight: 600;
      font-size: 28px;
      line-height: 28px;
      color: #ffe7c6;
    }

    .button {
      height: 28px;
      padding: 0 12px;
      border-radius: 14px;
      background-color: #fce6c8;
      font-weight: 500;
      font-size: 12px;
      line-height: 28px;
      color: #9c5a00;
    }
  }

  .item {
    flex: 1;
    padding: 10px 0;
    text-align: center;
    font-size: 13px;
    line-height: 15px;
    color: #999999;

    .inner {
      display: inline-block;
    }

    .value {
      margin-top: 10px;
      text-align: left;
      font-family: SemiBold;
      font-weight: 600;
      font-size: 18px;
      line-height: 18px;
      color: #333333;
    }
  }
}

.service {
  padding: 10px 0;
  border-radius: 16px;
  margin: 10px;
  background-color: #ffffff;

  .item {
    padding: 14px 10px 14px 16px;
  }

  .image {
    width: 20px;
    height: 20px;
    margin-right: 12px;
  }

  .name {
    flex: 1;
    font-size: 14px;
    color: #333333;
  }

  .mobiconfont {
    font-size: 14px;
    color: #999999;
  }
}
</style>