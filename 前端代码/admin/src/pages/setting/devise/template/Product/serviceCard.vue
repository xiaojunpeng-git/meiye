<template>
  <div class="px-10">
    <div class="rd-12px bg--w111-fff mb-8">
      <div class="flex-y-center h-50 px-10" v-if="showService.includes(0)">
        <span class="fs-13 text--w111-888">活动</span>
        <div class="flex-y-center ml-16">
          <div
              class="inline-block px-4 h-24 lh-24px rd-12px bg-primary-light text-primary-con mr-8"
              >
            <span class="mobiconfont icon-ic_user fs-14"></span>
            <span class="fs-12 pl-4">二人拼团</span>
            <span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </div>
          <div
              class="inline-block px-4 h-24 lh-24px rd-12px bg-primary-light text-primary-con mr-8"
              >
            <span class="mobiconfont icon-ic_clock fs-14"></span>
            <span class="fs-12 pl-4">限时秒杀</span>
            <span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </div>
          <div
              class="inline-block px-4 h-24 lh-24px rd-12px bg-primary-light text-primary-con mr-8"
              >
            <span class="mobiconfont icon-ic_sale fs-14"></span>
            <span class="fs-12 pl-4">参与砍价</span>
            <span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </div>
        </div>
      </div>
      <div class="flex-between-center h-50 px-10" v-if="showService.includes(1)">
        <div class="flex-y-center">
          <span class="fs-13 text--w111-888">选择</span>
          <div class="ml-16 text--w111-333 fs-13 flex-1 line1">黑色，80ml</div>
        </div>
        <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
      </div>
      <div class="flex-between-center h-50 px-10" v-if="showService.includes(2)">
        <div class="flex-y-center">
          <span class="fs-13 text--w111-888">服务</span>
          <div class="ml-16 text--w111-333 fs-13 flex-1 line1">正品保障 · 七天无理由退换货</div>
        </div>
        <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
      </div>
      <div class="flex-between-center h-50 px-10" v-if="showService.includes(3)">
        <div class="flex-y-center">
          <span class="fs-13 text--w111-888">参数</span>
          <div class="ml-16 text--w111-333 fs-13 flex-1 line1">假一赔十 · 全国包邮</div>
        </div>
        <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
      </div>
	  <div class="acea-row row-between h-73 pl-10 pr-10 pt-15" v-if="showService.includes(4)">
	    <div class="acea-row">
	      <span class="fs-13 text--w111-888 pt-3">门店</span>
	      <div class="ml-16 text--w111-333 fs-13 flex-1 line1">
			  <div><span class="iconfont iconic_mall mr-6"></span>陕西西咸广场小米之家</div>
			  <div class="fs-11 text--w111-666 mt-3 line1">陕西省咸阳市秦都区</div>
		  </div>
	    </div>
	    <span class="mobiconfont icon-ic_rightarrow fs-12 text--w111-666"></span>
	  </div>
      <div class="flex-center h-50 px-10" v-if="!showService.length"></div>
    </div>
    <div class="mask" v-if="!showService.length">已隐藏</div>
  </div>
</template>
<script>
export default {
  props:{
    showService:{
      type: Array,
      default: ()=>[]
    }
  },
}
</script>
<style>
.bg-primary-light{
  background: rgba(233, 51, 35, 0.1);
}
.text-primary-con{
  color: #e93323;
}
</style>
