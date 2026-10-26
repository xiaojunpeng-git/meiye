<template>
  <view :style="colorStyle">
    <view class="w-full relative pb-100 z-99 bg-gradient " :style="{'padding-top': sysHeight + 'px'}">
      <view class="w-full px-20 pl-20 h-80 flex-between-center" @tap="goIndex()">
        <text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff"></text>
        <text class="fs-34 fw-500 text--w111-fff">订单支付</text>
        <text></text>
      </view>
      <view class="flex-col flex-center mt-50">
        <view class="flex-y-center">
          <text class="iconfont icon-ic-complete1 fs-52 text--w111-fff"
                v-if="order_pay_info.paid || order_pay_info.pay_type == 'offline'"></text>
          <view v-else class="iconfont icon-ic_close1 fs-52 text--w111-fff"></view>
          <text class="fs-40 fw-500 text--w111-fff pl-16" v-if="order_pay_info.pay_type == 'offline' && !order_pay_info.paid">订单创建成功</text>
          <text class="fs-40 fw-500 text--w111-fff pl-16" v-else>{{order_pay_info.paid ? '订单支付成功':'等待支付...'}}</text>
        </view>
        <view class="flex-center mt-30">
          <view class="w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border" @tap="goIndex">返回首页</view>
          <view v-if="order_pay_info.type != 3" class="w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border ml-48" @tap="goOrderDetails">查看订单</view>
		  <view @click="goPink(order_pay_info.pink_id)" class='w-192 h-64 rd-40rpx flex-center fs-24 text--w111-fff white-border ml-48'
			v-if="order_pay_info.pink_id && order_pay_info.paid!=0 && status!=2 && status!=1">
			{{order_pay_info.pinkStatus==2?'查看拼团':'邀请好友参团'}}
		  </view>
        </view>
      </view>
    </view>
    <view class="relative content bg--w111-fff w-full pt-32 pl-20 pr-20">
      <view class="card bg--w111-fff rd-24rpx h-468 pl-20 pr-20"
            v-if="order_pay_info.paid && order_pay_info.pay_type != 'offline' && loading && lotteryLoading">
        <gridsLottery :prizeData="prize" @get_winingIndex='getWiningIndex'
                      @luck_draw_finish='luck_draw_finish' :lotteryNum="lottery_num" :lotteryType='1' :datatime="datatime">
        </gridsLottery>
      </view>
      <view class="card bg--w111-fff rd-24rpx px-32" v-if="showGift">
        <view class="cell flex-between-center py-32" v-for="(item,index) in couponList" :key="index + 'n'" @click="goPage(1,'/pages/users/user_coupon/index')">
          <view class="flex-y-center">
            <image :src="imgHost + '/statics/images/order/prize_coupon_icon.png'" class="w-76 h-76 rd-40rpx"></image>
            <view class="ml-24">
              <view class="fs-28 text--w111-333 lh-38rpx">下单得{{item.coupon_price}}元优惠券</view>
              <view class="fs-22 text--w111-999 lh-30rpx mt-8" v-if="parseInt(item.use_min_price > 0)">满{{item.use_min_price}}元可用</view>
              <view class="fs-22 text--w111-999 lh-30rpx mt-8" v-else>无门槛券</view>
            </view>
          </view>
          <view class="card_btn flex-center font-color fs-22">去使用</view>
        </view>
        <view class="cell flex-between-center py-32" v-for="(item,index) in giftList" :key="index + 'o'">
          <view class="flex-y-center">
            <image :src="imgHost + '/statics/images/order/prize_gift_icon.png'" class="w-76 h-76 rd-40rpx"></image>
            <view class="ml-24">
              <view class="fs-28 text--w111-333 line2 w-400">{{item.store_name}}</view>
            </view>
          </view>
          <view class="card_btn flex-center font-color fs-22" @tap="goOrderDetails">去查看</view>
        </view>
        <view class="cell flex-between-center py-32" v-if="prize_integral > 0" @click="goPage(1,'/pages/users/user_integral/index')">
          <view class="flex-y-center">
            <image :src="imgHost + '/statics/images/order/prize_integral_icon.png'" class="w-76 h-76 rd-40rpx"></image>
            <view class="ml-24">
              <view class="fs-28 text--w111-333 line2 w-400">获得{{prize_integral}}积分</view>
              <view class="fs-22 text--w111-999 lh-30rpx mt-8">下单即可抵扣</view>
            </view>
          </view>
          <view class="card_btn flex-center font-color fs-22" @tap="goOrderDetails">去使用</view>
        </view>
        <view class="cell flex-between-center py-32" v-if="prize_exp > 0">
          <view class="flex-y-center">
            <image :src="imgHost + '/statics/images/order/prize_exp_icon.png'" class="w-76 h-76 rd-40rpx"></image>
            <view class="ml-24">
              <view class="fs-28 text--w111-333 line2 w-400">获得{{prize_exp}}经验</view>
              <view class="fs-22 text--w111-999 lh-30rpx mt-8">下单即可抵扣</view>
            </view>
          </view>
          <view class="card_btn flex-center font-color fs-22" @tap="goOrderDetails">去使用</view>
        </view>
      </view>
      <recommend v-if='hostProduct.length' :hostProduct='hostProduct' :class="{recommend1:!showGift && !(order_pay_info.paid && order_pay_info.pay_type != 'offline' && loading && lotteryLoading)}"></recommend>
    </view>
    <lotteryAleart
        :aleartStatus="aleartStatus"
        :alData="alData"
        theme
        :aleartType="aleartType"
        @close="closeLottery" >
    </lotteryAleart>
    <view class="mask" v-if="aleartStatus || addressModel" @touchmove.stop.prevent="moveHandle"></view>
  </view>
</template>
<script>
let sysHeight = uni.getSystemInfoSync().statusBarHeight;
import gridsLottery from '../components/lottery/payLottery.vue';
import lotteryAleart from '../components/lotteryAleart/index.vue'
import recommend from '@/components/recommend';
import { getOrderDetail,orderCoupon, getOrderPrizeApi } from '@/api/order.js';
import { openOrderSubscribe } from '@/utils/SubscribeMessage.js';
import {
  lotteryUseApi,
  getLotteryData,
  startLottery,
  receiveLottery
} from '@/api/lottery.js';
import { getProductHot, postCartAdd} from '@/api/store.js';
import { toLogin} from '@/libs/login.js';
import { mapGetters } from "vuex";
import colors from "@/mixins/color";
import { HTTP_REQUEST_URL } from '@/config/app';
export default {
  components: {
    gridsLottery,
    lotteryAleart,
    recommend
  },
  mixins: [colors],
  data() {
    return {
      imgHost: HTTP_REQUEST_URL,
      sysHeight: sysHeight,
      loading: false,
      lotteryLoading: false,
      orderLottery: false,
      orderId: '',
      order_pay_info: {
        paid: 1,
        _status: {}
      },
      isAuto: false, //没有授权的不会自动授权
      isShowAuth: false, //是否隐藏授权
      status: 0,
      msg: '',
      couponsHidden: true,
      couponList: [],
      giftList:[],
      options: {},
      prize: [],
      datatime:0,
      totalPrice: 0,
      type:'',
      lottery_draw_param: {
        startIndex: 3, //开始抽奖位置，从0开始
        totalCount: 3, //一共要转的圈数
        winingIndex: 1, //中奖的位置，从0开始
        speed: 100 //抽奖动画的速度 [数字越大越慢,默认100]
      },
      aleartType: 0,
      aleartStatus: false,
      addressModel:false,
      lottery_num:0,
      alData: {},
      hostProduct:[],
      hotScroll: false,
      hotPage: 1,
      hotLimit: 10,
      prize_integral:0,
      prize_exp:0,
    };
  },
  computed: {
    ...mapGetters(['isLogin', 'cartNum']),
    showGift(){
      if(!this.couponList.length && !this.giftList.length && this.prize_integral == 0 && this.prize_exp == 0){
        return false
      }else{
        return true
      }
    }
  },
  watch: {
    isLogin: {
      handler: function(newV, oldV) {
        if (newV) {
          //#ifndef MP
          this.getOrderPayInfo();
          //#endif
        }
      },
      deep: true
    }
  },
  onLoad: function(options) {
    this.options = options
    if (!options.order_id) return this.$util.Tips({
      title: '缺少参数无法查看订单支付状态'
    }, {
      tab: 3,
      url: 1
    });
    this.orderId = options.order_id;
    this.status = options.status || 0;
    this.msg = options.msg || '';
    this.type = this.options.type;
    this.totalPrice = this.options.totalPrice;
	this.getLotteryUse();
    this.getHostProduct();
  },
  onShow() {
    uni.setStorageSync('form_type_cart', 1);
    if (this.isLogin) {
      this.getOrderPayInfo();
    } else {
      toLogin()
    }
  },
  methods: {
    moveHandle(){
      return false
    },
    // 授权关闭
    authColse: function(e) {
      this.isShowAuth = e
    },
    openTap() {
      this.$set(this, 'couponsHidden', !this.couponsHidden);
    },
    onLoadFun: function() {
      this.getOrderPayInfo();
      this.isShowAuth = false;
    },
    /**
     *
     * 支付完成查询支付状态
     *
     */
    getOrderPayInfo: function() {
      let that = this;
      uni.showLoading({
        title: '正在加载中'
      });
      setTimeout(() => {
      getOrderDetail(that.orderId).then(res => {
        uni.hideLoading();
        that.$set(that, 'order_pay_info', res.data);
        uni.setNavigationBarTitle({
          title: res.data.paid ? '支付成功' : '未支付'
        });
        this.loading = true
        setTimeout(function(){
          that.getOrderPrize()
        }, 1000);
      }).catch(err => {
        this.loading = true
        uni.hideLoading();
      });
      }, 1000);
    },
    getOrderPrize() {
      getOrderPrizeApi(this.orderId).then(res=>{
        this.couponList = res.data.coupons;
        this.giftList = res.data.gift;
        this.prize_integral  =res.data.integral;
        this.prize_exp  =res.data.exp;
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
    // 去参团页面；
    goPink: function(id) {
      uni.navigateTo({
        url: '/pages/activity/goods_combination_status/index?id=' + id
      });
    },
    /**
     *
     * 去订单详情页面
     */
    goOrderDetails: function(e) {
      let that = this;
      // #ifdef MP
      uni.showLoading({
        title: '正在加载',
      })
      openOrderSubscribe().then(res => {
        uni.hideLoading();
        uni.navigateTo({
          url: '/pages/goods/order_details/index?order_id=' + that.orderId
        });
      }).catch(() => {
        nui.hideLoading();
      });
      // #endif
      // #ifndef MP
      uni.navigateTo({
        url: '/pages/goods/order_details/index?order_id=' + that.orderId
      })
      // #endif
    },
    getLotteryData(type, id) {
      getLotteryData(type, id).then(res => {
        this.factor_num = res.data.lottery.factor_num
        this.id = res.data.lottery.id
        this.prize = res.data.lottery.prize
        this.lottery_num = res.data.lottery_num
        this.prize.push({
          a: 1
        })
        this.lotteryLoading = true;
        this.datatime = parseInt(res.data.cache_time);
        if(res.data.lottery.type == 6){
          uni.navigateTo({
            url:"/pages/goods/lottery/grids/record"
          })
        }
      }).catch(err => {
        this.lotteryLoading = false;
      })
    },
    getWiningIndex(callback){
      this.aleartType = 0
      startLottery({
        id: this.id
      }).then(res => {
        this.prize.forEach((item, index) => {
          if (res.data.id === item.id) {
            this.alData = res.data
            this.lottery_draw_param.winingIndex = index;
            callback(this.lottery_draw_param);
          }
        })
      }).catch(err => {
        this.$util.Tips({
          title: err
        });
      })
    },
    // 抽奖完成
    luck_draw_finish(param) {
      this.aleartType = 2
      this.aleartStatus = true
    },
    closeLottery(status) {
      this.aleartStatus = false;
      if (this.alData.type === 6) {
        postCartAdd({
          cartNum: 1,
          new: 1,
          is_new: 1,
          productId: this.alData.product_id,
          uniqueId: this.alData.unique,
          luckRecordId: this.alData.lottery_record_id,
        }).then(res => {
          uni.navigateTo({
            url: `/pages/goods/order_confirm/index?new=1&luckRecordId=${this.alData.lottery_record_id}&cartId=${res.data.cartId}&product_id=${this.alData.product_id}`
          });
        }).catch(err => {
          this.$util.Tips({
            title: `${err},请联系客服`
          });
        });
      }
      this.getLotteryData(this.type)

    },
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
    goPage(type, url){
      if(type == 1){
        uni.navigateTo({
          url
        })
      }else if(type == 2){
        uni.switchTab({
          url
        })
      }else if(type == 3){
        uni.navigateBack();
      }

    },
	getLotteryUse() {
		lotteryUseApi().then(res => {
			const { order_pay_lottery } = res.data;
			if (order_pay_lottery != 0) {
				this.getLotteryData(this.type, order_pay_lottery);
			}
		});
	}

  },
  onPageScroll(e) {
    uni.$emit('scroll');
  },
}
</script>
<style>
.recommend1{
	padding-top: 0;
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
