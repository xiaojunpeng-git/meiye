<template>
  <view :style="colorStyle">
    <view class="w-full relative pb-100 z-99 bg-gradient " :style="{'padding-top': sysHeight + 'px'}">
      <view class="w-full px-20 pl-20 h-80 flex-between-center" @tap="goIndex()">
        <text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff"></text>
        <text class="fs-34 fw-500 text--w111-fff">预约完成</text>
        <text></text>
      </view>
      <view class="flex-col flex-center mt-50">
        <view class="flex-y-center">
          <view class="iconfont icon-ic-complete1 fs-52 text--w111-fff"></view>
          <text class="fs-40 fw-500 text--w111-fff pl-16">预约成功</text>
        </view>
        <view class="flex-center mt-30">
          <view class="w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border" @tap="goIndex">返回首页</view>
          <view class="w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border ml-48" @tap="goReservation">预约详情</view>
        </view>
      </view>
    </view>
    <view class="relative content bg--w111-fff w-full pl-20 pr-20">
      <recommend v-if='hostProduct.length' :hostProduct='hostProduct'></recommend>
    </view>
  </view>
</template>

<script>
let sysHeight = uni.getSystemInfoSync().statusBarHeight;
import colors from "@/mixins/color";
import recommend from '@/components/recommend';
import { getProductHot } from '@/api/store.js';
import { goWithYuyueSubscribe } from '@/utils/SubscribeMessage.js';
export default{
	components: {
	  recommend
	},
	mixins: [colors],
	data() {
		return {
			sysHeight: sysHeight,
			hotScroll: false,
			hotPage: 1,
			hotLimit: 10,
			hostProduct:[],
			orderId:0,
			bookUid: 0
		}
	},
	onLoad(options){
		this.orderId = options.orderId || 0;
		this.bookUid = Number(options.book_uid || 0) || 0;
		this.getHostProduct();
	},
	methods:{
		getHostProduct: function() {
		  let that = this;
		  if (that.hotScroll) return
		  getProductHot(
		      that.hotPage,
		      that.hotLimit,
		  ).then(res => {
		    that.hotPage++
		    that.hotScroll = res.data.length < that.hotLimit
		    that.hostProduct = that.hostProduct.concat(res.data)
		  });
		},
		/**
		 * 去首页关闭当前所有页面
		 */
		goIndex: function(e) {
		  uni.switchTab({
		    url: '/pages/index/index'
		  });
		},
		goReservation(){
			let url = `/pages/goods/reservation_list/index?orderId=${this.orderId}`;
			if (this.bookUid > 0) {
				url += `&book_uid=${this.bookUid}`;
			}
			goWithYuyueSubscribe(url);
		}
	}
}
</script>

<style lang="scss" scoped>
.recommend{
	padding-top: 24rpx;
}
.fs-52{
  font-size:52rpx;
}
.white-border{
  border: 1rpx solid #fff;
}
.ml-48{
  margin-left: 48rpx;
}
.h-216{
  height:216rpx;
}
.content{
  background: #f5f5f5;
  border-radius: 40rpx 40rpx 0 0;
  left:0;
  min-height:500rpx;
}
.card ~ .card{
  margin-top: 20rpx;
}
.h-76{
  height:76rpx;
}
.cell ~ .cell {
  border-top: 1px solid #eee;
}
.card_btn{
  width: 114rpx;
  height: 52rpx;
  border-radius: 26rpx;
  border: 1px solid #E93323;
}
</style>