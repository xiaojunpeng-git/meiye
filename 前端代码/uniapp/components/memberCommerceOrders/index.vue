<template>
  <view v-if="rows.length || error" class="commerce-orders">
    <view v-if="error" class="commerce-error">{{error}}</view>
    <view v-for="order in rows" :key="order.id" class="commerce-order" @click="open(order)">
      <view class="order-heading"><text>{{order.storeName}}</text><text>{{order.status === 'paid' ? '已支付' : '待支付'}}</text></view>
      <text class="order-number">{{order.orderNo || order.id}}</text>
      <view v-for="(item,index) in order.items" :key="index" class="commerce-item">
        <image v-if="item.image" :src="item.image" mode="aspectFill" />
        <text class="commerce-item-name">{{item.name}}</text><text>×{{item.quantity}}</text>
      </view>
      <view class="order-total">合计 ¥{{Math.round(order.amountCents / 100)}}</view>
    </view>
    <view v-if="total > 20" class="commerce-pagination"><button size="mini" :disabled="page <= 1" @click="load(page-1)">上一页</button><text>{{page}} / {{Math.ceil(total/20)}}</text><button size="mini" :disabled="page*20 >= total" @click="load(page+1)">下一页</button></view>
  </view>
</template>
<script>
import { listMemberCommerce } from '@/api/memberCommerce';
export default {
  props: {status: {default: ''}, keyword: {default: ''}, revision: {default: 0}},
  data() {return {rows: [], total: 0, page: 1, error: '', sequence: 0};},
  mounted() {this.load(1);},
  watch: {status() {this.load(1);}, keyword() {this.load(1);}, revision() {this.load(1);}},
  methods: {
    // Late filter responses cannot replace a newer list or its page count.
    async load(page) {const seq = ++this.sequence; this.error = '';
      try {const res = await listMemberCommerce({page, status: this.status, keyword: this.keyword});
        if (seq !== this.sequence) return;
        this.rows = res.data.list; this.total = res.data.total; this.page = res.data.page; this.$emit('count', this.total);
      } catch(e) {if(seq === this.sequence) this.error = e.msg || '读取会员订单失败';}},
    open(order) {uni.navigateTo({url:'/pages/goods/member_checkout/index?order_id=' + encodeURIComponent(order.id)});}
  }
};
</script>
<style scoped>
.commerce-order {padding: 24rpx; margin-bottom: 20rpx; background: #fff; border-radius: 12rpx; color: #302b2e; font-size: 26rpx;}
.order-heading {display: flex; justify-content: space-between; gap: 20rpx;}.order-number {display: block; color: #777; font-size: 22rpx; margin-top: 12rpx; overflow-wrap: anywhere;}
.commerce-item {display: flex; align-items: center; gap: 20rpx; margin-top: 24rpx;}.commerce-item image {width: 100rpx; height: 100rpx; flex-shrink: 0;}.commerce-item-name {flex: 1; min-width: 0; overflow-wrap: anywhere;}.order-total {text-align: right; margin-top: 20rpx;}
.commerce-pagination {display:flex; align-items:center; justify-content:space-between; padding:20rpx 0; font-size:24rpx;}.commerce-error {padding:20rpx; color:#a93149;}
</style>
