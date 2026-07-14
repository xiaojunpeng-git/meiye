<template>
    <div class="px-10">
      <div class="rd-12px bg--w111-fff mb-8">
        <div class="flex-between-center p-10">
          <div class="text--w111-333 fs-13 flex-center">
            <img src="@/assets/images/product_diy_spread_Integral.png" alt="" class="w-14 h-14" style="margin-right: 3px;">
            分享好友最高可赚<span class="text-primary-con">¥12.00</span>元
          </div>
          <div class="w-72 h-25 rd-12px bg-w111-423F3C text--w111-FFF0D1" style="font-size: 0;line-height: 25px;text-align: center;">
            <span class="fs-12">立即分享</span>
            <span class="mobiconfont icon-ic_rightarrow fs-12"></span>
          </div>
        </div>
        <div class="px-10">
            <div style="border-top: 1px solid #F5F5F5;"></div>
        </div>
        <!-- 滑动内容 -->
        <div class="p-10">
            <div class="flex justify-around fs-13">
                <div class="flex-center flex-col">
                    <img src="@/assets/images/product_diy_spread01.png" alt="" class="w-40 h-40 mb-10">
                    <div>立即分享</div>
                </div>
                <div class="flex-center h-40">
                    <img src="@/assets/images/product_diy_spread_arrow.png" alt="" class="w-23 h-5">
                </div>
                <div class="flex-center flex-col">
                    <img src="@/assets/images/product_diy_spread02.png" alt="" class="w-40 h-40 mb-10">
                    <div>分享海报</div>
                </div>
                <div class="flex-center h-40">
                    <img src="@/assets/images/product_diy_spread_arrow.png" alt="" class="w-23 h-5">
                </div>
                <div class="flex-center flex-col">
                    <img src="@/assets/images/product_diy_spread03.png" alt="" class="w-40 h-40 mb-10">
                    <div>好友下单</div>
                </div>
                <div class="flex-center h-40">
                    <img src="@/assets/images/product_diy_spread_arrow.png" alt="" class="w-23 h-5">
                </div>
                <div class="flex-center flex-col">
                    <img src="@/assets/images/product_diy_spread04.png" alt="" class="w-40 h-40 mb-10">
                    <div>佣金到账</div>
                </div>
            </div>
        </div>
      </div>
      <div class="mask" v-if="!showSpread">已隐藏</div>
    </div>
  </template>
  <script>
  export default {
    props:{
      showSpread:{
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
  