<template>
  <div class="px-10">
    <div class="rd-12px bg--w111-fff py-16 mb-8">
      <div class="px-10 flex-between-center">
        <div>
          <span class="text--w111-333 fs-15 fw-500">搭配购</span>
          <span class="fs-12 text--w111-666 pl-8">(3)</span>
        </div>
        <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
      </div>
      <div class="px-10 mt-12">
        <div class="flex">
          <img class="w-80 h-80 rd-8px" :src="pic" />
          <div class="flex-1 pl-12">
            <div class="w-140 text--w111-333 fs-12 break_word line1">居家首选-热卖三件套</div>
            <div class="w-140 lh-15px break_word fs-12 line2">
              <span>加湿器+清洁工具+床头落地 灯+沙发抱枕</span>
            </div>
            <div class="flex-y-center">
              <span class="mobiconfont icon-a-ic_Money111 red fs-14"></span>
              <span class="text--w111-999 px-4">最多可省</span>
              <span class="fs-12 red fw-600">¥20</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="mask" v-if="!showMatch">已隐藏</div>
  </div>
</template>
<script>
export default {
  props:{
    showMatch:{
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
