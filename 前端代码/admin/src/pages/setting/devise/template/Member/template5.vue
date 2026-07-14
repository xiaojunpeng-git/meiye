<script>
import propertyList from "@/plugins/propertyList";

export default {
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
<template>
  <div>
    <div class="acea-row row-middle user">
      <img class="avatar" :src="memberData.avatar_url"></img>
      <div class="name-wrap">
        <div class="name">测试昵称</div>
        <div class="phone" v-if="memberData.per_show_type">ID:123</div>
        <div class="phone" v-else>13012341234</div>
      </div>
      <div>
        <span class="mobiconfont icon-a-ic_QRcode fs-40"
          ><span class="tips">会员码</span></span
        >
        <span
          class="mobiconfont icon-a-ic_setup1 fs-40 mx-34"
        ></span>
        <span
          class="mobiconfont icon-ic_message3 fs-40"
          ><span class="number">8</span></span
        >
      </div>
    </div>
    <div class="acea-row row-middle order">
      <div class="item" v-for="(item,index) in property.slice(0,5)" :key="index">
        {{item.label}}<span class="value">{{item.value}}</span>
        </div>
    </div>
  </div>
</template>

<style lang="stylus" scoped>
.user {
  padding: 30px 21px 28px 15px;
  background-image: url('~@/assets/img/template4_bg.png');
  background-position: bottom;
  background-size: 100%;

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
    top: -6px;
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

.order {
  padding: 0 26px 16px 16px;
  font-size: 13px;
  line-height: 18px;
  color: #999999;
  justify-content: space-between;

  .item + .item {
    margin-left: 20px;
  }

  .value {
    margin-left: 4px;
    font-weight: 600;
    font-size: 14px;
    line-height: 14px;
    color: #333333;
  }
}

.service {
  padding-left: 10px;
  margin: 0 -13px -13px 0;

  .item {
    flex: 0 0 110px;
    height: 110px;
    border-radius: 12px;
    background-color: #ffffff;
    margin: 0 13px 13px 0;
    font-size: 13px;
    line-height: 18px;
    color: #333333;
  }

  .image {
    width: 32px;
    height: 32px;
    margin-bottom: 16px;
  }
}

.SemiBold {
  font-family: SemiBold;
}
</style>