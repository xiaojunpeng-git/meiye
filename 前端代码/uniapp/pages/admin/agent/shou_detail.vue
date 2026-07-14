<template>
  <view class="pagebox">
	  <!-- #ifdef MP || APP-PLUS -->
	  <NavBar titleText="实际收款金额(元)" :iconColor="iconColor" :textColor="iconColor" :bagColor="bagColor" :isScrolling="isScrolling" showBack></NavBar>
	  <!-- #endif -->
    <view class="headerBg">
      <view :style="{ height: `${getHeight.barTop}px` }"></view>
      <view :style="{ height: `${getHeight.barHeight}px` }"></view>
      <view class="inner"></view>
    </view>
    <view class="search fixed z-10 acea-row row-between-wrapper w-full  pl-20 pr-20">
      <view class="top_kuai">
        <view>实际收款金额(元)</view>
        <view class="money">{{ total_price }}</view>
      </view>
    </view>
    <view class="kuai_center">
      <view class="link_kuai" v-for="(item,index) in reportData">
             <view>{{ item.name }}</view>
             <view class="price">{{ item.total_money }}</view>
      </view>
    </view>
  </view>
</template>
<script>
// #ifdef MP || APP-PLUS
import NavBar from '@/components/NavBar.vue';
// #endif
import {
  reportDetail
} from '@/api/yeji.js'
export default {
  name: 'agent',
  components: {
  	// #ifdef MP ||APP-PLUS
  	NavBar,
  	// #endif
  },
  data() {
    return {
      bagColor: 'linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%)',
      iconColor: '#FFFFFF',
      isScrolling: false,
      getHeight: this.$util.getWXStatusHeight(),
      total_price:0,
      reportData:[],
      form:{
        data:'',
        store_id:0
      }
    };
  },
  onLoad(option) {
    this.total_price=option.number || 0;
    this.form.data=option.data_range || 0;
    this.form.store_id=option.store_id || 0;
    this.getReport();
  },
  methods: {
     getReport(){
       let that=this;
       reportDetail(this.form).then(function (res){
              that.reportData=res.data
       })
     }
  },
};
</script>

<style lang="scss" scoped>
.kuai_center{
  margin-top: 256rpx;
}
.link_kuai{
  display: inline-block;
  padding: 20rpx 20rpx;
  width: 49%;
  border-left: 1px solid #d5d2d2;
  border-bottom: 1px solid #d5d2d2;
  text-align: left;
}
.price{
   color: #8d8686;
   margin-top: 20rpx;
}
.money{
   font-size: 60rpx;
}
.top_kuai{
  padding:50rpx 60rpx;
  color: #fff;
}
.money{
  margin-top: 20rpx;
}
.kuai_out{
  display: flex;
  gap:20px;
  margin-bottom: 20px;
  margin-top: 20px;
  font-size: 24rpx;
}
.kuai{
  padding-bottom: 10px;
}
.kuai_choose{
  color: #2A7EFB;
  border-bottom: 2px solid #2A7EFB;
}
.w-198{
  width: 60% !important;
}
.w-170{
  width: 40% !important;
  text-align: right;
}
.footer{
  height: calc(30rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
  height: calc(30rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
}
.charts{
  width: 710rpx;
  height: 500rpx;
}
.pagebox {
  position: relative;
  overflow: hidden;
  .headerBg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);

    .inner {
      height: 200rpx;
    }
  }
  .search{
    background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);
    padding-bottom: 10px;
  }
  .active{
    border-radius: 120rpx;
    color: #2A7EFB;
    background: #FFFFFF;
  }
}
</style>
