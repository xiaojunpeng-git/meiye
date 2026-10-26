<template>
  <div>
    <div class="main-pic relative">
      <div class="w-full h-40 flex-between-center abs-lt z-20">
        <div class="menu_box flex-center bg--w111-fff ml10">
          <img src="@/assets/img/back_icon.png">
          <div class="menu_line"></div>
          <img src="@/assets/img/menu_icon.png">
        </div>
        <div class="share-icon">
          <img src="@/assets/img/share_icon.png" v-if="showShare">
        </div>
      </div>
      <img :src="pic" />
      <div class="dot-line acea-row row-center-wrapper" v-if="showDot">
        <div class="item" v-for="item in 5" :key="item"></div>
      </div>
    </div>
    <div class="relative info-box">
      <div class="px-16">
        <div class="pic-list acea-row">
          <img class="pic" :src="pic" />
          <div class="pic acea-row row-column row-center-wrapper fs-11">
            <span>5款</span>
            <span>可选</span>
          </div>
          <img class="pic mr-8" :src="pic" v-for="item in 5" :key="item" />
        </div>
        <div class="acea-row items-baseline mt-6">
          <span class="fs-11 lh-15 red">到手价</span>
          <div class="acea-row items-baseline fw-bold red">
            <span class="fs-16">¥</span>
            <span class="fs-24">199</span>
            <span class="fs-16">.00</span>
          </div>
          <div class="fs-13 pl-6">售价¥299.00</div>
          <div class="svip flex-y-center">
            <div class="left">SVIP</div>
            <div class="flex-1 pl-4">¥26.00</div>
          </div>
        </div>
        <div class="mt-6 acea-row row-between-wrapper">
          <div class="acea-row">
            <div class="tag">买一送一</div>
            <div class="tag">满1000送赠品</div>
            <div class="tag">最高返200元</div>
          </div>
          <div class="acea-row red fs-11">
            <span>查看</span>
            <span class="mobiconfont icon-ic_rightarrow fs-11"></span>
          </div>
        </div>
        <div class="pro-title">年货节立即抢购兰蔻唇香礼盒 是我香水口红289节日套装礼品</div>
        <div class="acea-row row-between-wrapper mt10 fs-11 text--w111-999 pb-12">
          <div v-show="isOpen.includes(0)"><span class="line-through">¥234.00</span></div>
          <div v-show="isOpen.includes(2)">库存：1425件</div>
          <div v-show="isOpen.includes(1)">销量：2399件</div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
export default {
  name: 'productBox',
  props:{
    showDot:{
      type: Number,
      default: 1
    },
    showShare:{
      type: Number,
      default: 1
    },
    isOpen:{
      type: Array,
      default: ()=> []
    }
  },
  data(){
    return {
      pic: require('@/assets/images/product_diy.png')
    }
  },

}
</script>
<style scoped lang="less">
  .main-pic img{
    width: 100%;
    height: 375px;
  }
  .menu_box{
    width: 77px;
    height: 29px;
    border-radius: 14px;
    border: 1px solid rgba(0, 0, 0, 0.1);
  }
  .menu_box img{
    width: 16px;
    height: 16px;
  }
  .menu_box .menu_line {
    width: 1px;
    height: 15px;
    background: #B3B3B3;
    margin: 0 10px;
  }
  .share-icon{
    margin-right: 10px;
    img{
      width: 16px;
      height: 16px;
    }
  }
  .dot-line{
    width: 100%;
    position: absolute;
    left: 0;
    bottom: 27px;
    .item{
      width: 66px;
      height: 2px;
      border-radius: 2px;
      background: #fff;
      & ~ .item{
        margin-left: 4px;
      }
    }
  }
  .info-box{
    background: linear-gradient(180deg, #ffffff 0%, #ffffff 54%, rgba(255, 255, 255, 0) 100%);
    margin-top: -17px;
    border-radius: 20px 20px 0 0;
  }
  .svip{
    width: 63px;
    height: 14px;
    border-radius: 8px;
    background: #FFF0D1;
    font-size: 9px;
    color: #333;
    margin-left: 10px;
    .left{
      width: 28px;
      height: 14px;
      line-height: 14px;
      padding-left: 4px;
      border-radius: 8px 0 8px 8px;
      color: #FDDAA4;
      background: #333;
    }
  }

  .pic-list{
    width: 100%;
    padding-top: 18px;
    .pic{
      width: 40px;
      height: 40px;
      border-radius: 8px;
    }
  }
  .tag{
    display: inline-flex;
    justify-content: center;
    align-items: center;
    height: 22px;
    padding: 0 8px;
    font-size: 11px;
    color: #e93323;
    background: rgba(233, 51, 35, 0.1);
    margin-right: 8px;
    border-radius: 4px;
  }
  .pro-title{
    font-size: 15px;
    font-weight: 500;
    line-height: 21px;
    margin-top: 10px;
  }
  .fs-11{
    font-size: 11px;
  }
  .mt-13{
    margin-top: 13px;
  }
  .red{
    color: #e93323;
  }
  .lh-15{
    line-height: 15px;
  }
</style>
