<script>
export default {
  props: {
    userInfo: {
      type: Object,
      default: () => {},
    },
  },
};
</script>
<template>
  <div class="px-10">
    <div class="pt-16 pr-12 pb-16 pl-12 bg--w111-fff rd-8px">
      <div class="fs-15 fw-500 lh-21px text--w111-333">运营统计</div>
      <div class="flex-between-center mt-14">
        <div class="w-104 rd-8px pt-12 pl-12 pb-10 flex-col bg_1">
          <span class="fs-12 text--w111-999 lh-17px"
            >支付金额<span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </span>
          <span class="fs-16 text--w111-333 fw-bold lh-26px">200</span>
        </div>
        <div class="w-104 rd-8px pt-12 pl-12 pb-10 flex-col bg_2">
          <span class="fs-12 text--w111-999 lh-17px"
            >支付金额<span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </span>
          <span class="fs-16 text--w111-333 fw-bold lh-26px">200</span>
        </div>
        <div class="w-104 rd-8px pt-12 pl-12 pb-10 flex-col bg_3">
          <span class="fs-12 text--w111-999 lh-17px"
            >支付金额<span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </span>
          <span class="fs-16 text--w111-333 fw-bold lh-26px">200</span>
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
  bottom: -2px;
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