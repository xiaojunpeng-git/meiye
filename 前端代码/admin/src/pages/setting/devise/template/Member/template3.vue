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
    getTimePeriod() {
      const currentTime = new Date();
      const currentHour = currentTime.getHours(); 
      if (currentHour >= 6 && currentHour < 12) {
        return "，早上好";
      } else if (currentHour >= 12 && currentHour < 14) {
        return "，中午好";
      } else if (currentHour >= 14 && currentHour < 18) {
        return "，下午好";
      } else {
        return "，晚上好";
      }
    },
  },
};
</script>
<template>
  <div>
    <div class="user_info_card relative">
      <div class="px-24 mt-22 flex-between-center">
        <div class="flex-y-center">
          <img class="w-136 h-136 rd-50-p111-" :src="memberData.avatar_url"></img>
          <div class="ml-12">
            <div class="fs-36 text--w111-333 lh-50px fw-500">测试昵称</div>
            <div v-if="memberData.per_show_type" class="fs-12 text--w111-333 lh-17px mt-8">ID:123</div>
            <div v-else class="fs-12 text--w111-333 lh-17px mt-8">13012341234</div>
          </div>
        </div>
        <div>
        <span class="mobiconfont icon-a-ic_QRcode fs-40"></span>
        <span class="mobiconfont icon-a-ic_setup1 fs-40 mx-34"></span>
        <span class="mobiconfont icon-ic_message3 fs-40"></span>
        </div>
      </div>
      <div class="abs-lb w-full h-86 bg_zs">
        <div class="svip_card">
          <div
            class="h-full flex-between-center pl-32 pr-28 text--w111-FFD89C fs-12"
          >
            <div class="flex-y-center">
              <span class="mobiconfont icon-ic_crown fs-32"></span>
              <span class="pl-12">尊贵的SVIP会员{{getTimePeriod()}}</span>
            </div>
            <div class="flex-y-center">
              <span>查看会员权益</span>
              <span class="mobiconfont icon-ic_rightarrow fs-24"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="stylus" scoped>
.SemiBold {
  font-family: SemiBold;
}

.user_info_card {
  background-image: url('~@/assets/img/user_header_bg.png');
  height: 152px;
  background-size: 100%;
}

.bg_zs {
  background-image: url('~@/assets/img/bg_zs.png');
  background-size: 100%;
  background-repeat: no-repeat;
}

.svip_card {
  width: 331px;
  height: 46px;
  background-image: url('~@/assets/img/user_svip_bg.png');
  background-size: 100%;
  background-repeat: no-repeat;
  position: absolute;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
}

.v-Line {
  width: 1px;
  height: 20px;
  background: #eee;
}

.bg_1 {
  background-image: url('~@/assets/img/group1_pic.png');
  background-size: 100%;
}

.bg_2 {
  background-image: url('~@/assets/img/group2_pic.png');
  background-size: 100%;
}

.bg_3 {
  background-image: url('~@/assets/img/group3_pic.png');
  background-size: 100%;
}

.span-primary-con {
  color: #E93323;
}

.con_border {
  border: 1px solid #E93323;
}
</style>