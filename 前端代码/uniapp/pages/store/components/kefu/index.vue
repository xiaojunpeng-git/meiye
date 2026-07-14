<template>
  <!-- 客服列表 -->
  <view v-if="show" :style="colorStyle">
    <view class="discountInfo on">
      <view class="title">
		  客服列表
		  <view class="btn acea-row row-center-wrapper" style="flex-wrap: nowrap;" @click="closeDiscount"><text class="iconfont icon-ic_close"></text></view>
	</view>
      <view class="list">
        <view class="item acea-row row-middle" v-for="(item,index) in customerList" :key="index">
          <image :src="item.avatar" mode="" class="img"></image>
          <view class="text">{{item.staff_name}}</view>
          <view class="contact acea-row row-center-wrapper" @click="callPhone(item)">
            <view class="inner">联系客服</view>
          </view>
        </view>
      </view>
      <slot name="bottom"></slot>
    </view>
    <view class="mask" @touchmove.prevent :hidden="false" @click="closeDiscount"></view>
  </view>
</template>

<script>
   import colors from "@/mixins/color";
  export default {
    props: {
      customerList: {
        type: Array,
        default: []
      },
			customerType:{
				type:Number,
				default:1
			}
    },
    mixins:[colors],
    data() {
      return {
        show: false,
      };
    },
    mounted() {},
    methods: {
      closeDiscount() {
        this.$emit('closeKefu')
      },
      callPhone(item) {
				if(this.customerType == 1){
					if (item.customer_phone) {
						uni.makePhoneCall({
							phoneNumber: item.customer_phone //仅为示例
						});
					}
				}else{
					uni.navigateTo({
					  url: '/pages/store/service/index?id='+item.id
					})
				}
      }
    }
  }
</script>

<style scoped lang="scss">
  .discountInfo {
    position: fixed;
    bottom: 0;
    width: 100%;
    left: 0;
    background-color: #fff;
    z-index: 300;
    border-radius: 40rpx 40rpx 0 0;
    transform: translate3d(0, 100%, 0);
    transition: all .3s cubic-bezier(.25, .5, .5, .9);
    padding-bottom: 22rpx;
    padding-bottom: calc(22rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
    padding-bottom: calc(22rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/

    .title {
		height: 108rpx;
		font-weight: 500;
      font-size: 32rpx;
	  line-height: 108rpx;
      color: #333333;
      text-align: center;
      position: relative;

      .btn {
        position: absolute;
		top: 50%;
        right: 32rpx;
		width: 36rpx;
		height: 36rpx;
		border-radius: 50%;
		background: #EEEEEE;
		transform: translateY(-50%);
		text-align: center;
		line-height: 36rpx;
      }
	  
	  .iconfont {
		  font-size: 24rpx;
		  color: #999999;
		  vertical-align: text-bottom;
	  }
    }

    .list {
      height: 750rpx;
      margin: 34rpx 32rpx;
      overflow-x: hidden;
      overflow-y: auto;

      .item {
        height: 80rpx;
		margin-bottom: 40rpx;

        .img {
          width: 80rpx;
          height: 80rpx;
          border-radius: 50%;
          border: 1rpx solid #EEEEEE;
		  transform: rotateZ(360deg);
        }

        .text {
			flex: 1;
			padding: 0 20rpx;
          font-size: 28rpx;
          color: #333333;
        }

        .contact {
          width: 166rpx;
          height: 56rpx;
		  border-radius: 40rpx;
          background: var(--view-theme);
		  text-align: center;
          font-size: 24rpx;
		  line-height: 56rpx;
          color: var(--view-theme);
		  // overflow: hidden;
		  
		  .inner {
			  width: 168rpx;
			  height: 56rpx;
			  // border-radius: 28rpx;
			  background: rgba(255, 255, 255, 0.9);
		  }
        }
      }
    }
  }

  .on {
    transform: translate3d(0, 0, 0);
  }
</style>
