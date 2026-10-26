<template>
  <div class="px-10">
    <div class="rd-12px bg--w111-fff py-16 mb-8">
      <div class="px-10 flex-between-center">
        <div>
          <span class="text--w111-333 fs-15 fw-500">种草秀</span>
          <span class="fs-12 text--w111-666 pl-8">(3)</span>
        </div>
        <span
          class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"
        ></span>
      </div>
      <!-- 滑动内容 -->
      <div class="px-10 mt-12">
        <div class="h-96px w-full rd-8px flex-y-center">
          <img
            :src="pic"
            class="w-96 rd-8px block mr-12"
            v-for="i in 3"
            :key="i"
          />
        </div>
      </div>
    </div>
    <div class="mask" v-if="!showCommunity">已隐藏</div>
  </div>
</template>
<script>
export default {
  props: {
    showCommunity: {
      type: Number,
      default: 1,
    },
  },
  data() {
    return {
      pic: require('@/assets/images/product_diy.png'),
    };
  },
};
</script>
