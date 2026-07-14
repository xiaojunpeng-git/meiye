<template>
  <div class="px-10">
    <div class="rd-12px bg--w111-fff py-16 mb-8">
      <div class="px-10 flex-between-center">
        <div>
          <span class="text--w111-333 fs-15 fw-500">评价</span>
          <span class="fs-12 text--w111-666 pl-8">(20)</span>
        </div>
        <div class="flex-y-center">
          <span class="fs-14 text-primary-con">99%</span>
          <span class="fs-12 text--w111-999 pr-8">好评率</span>
          <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
        </div>
      </div>
      <!-- 滑动内容 -->
      <div class="px-10 mt-12">
        <div class="h-96px w-280 rd-8px bg--w111-f5f5f5 flex justify-between">
          <div class="flex-1 p-12">
            <div class="flex-y-center">
              <img class="w-32 h-32 rd-50-p111-" :src="pic" mode="aspectFill" />
              <div class="flex-col pl-8">
                <span class="text--w111-333 fs-12">X***8</span>
              </div>
            </div>
            <div class="w-162 mt-6 text--w111-333 fs-12 break_word line2">质量挺不错的，用了一段时间也不起球</div>
          </div>
          <img :src="pic" class="w-96 rd-8px" />
        </div>
      </div>
    </div>
    <div class="mask" v-if="!showReply">已隐藏</div>
  </div>
</template>
<script>
export default {
  props:{
    showReply:{
      type: Number,
      default: 1
    }
  },
  data(){
    return {
      pic: require('@/assets/images/product_diy.png')
    }
  }
}
</script>
