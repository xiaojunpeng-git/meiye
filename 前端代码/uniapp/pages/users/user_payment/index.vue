<template>
  <view class="recharge-page" :style="colorStyle">
    <view class="balance-band"><text>我的余额</text><text class="balance-value">¥{{ whole(balance) }}</text></view>
    <view class="recharge-band">
      <text class="recharge-title">充值金额</text>
      <radio-group @change="choosePackage">
        <label v-for="item in packages" :key="item.id" class="package-row">
          <view><text>¥{{ whole(item.price) }}</text><text v-if="Number(item.give_money) > 0" class="bonus">赠送 {{ whole(item.give_money) }} 元</text></view>
          <radio :value="String(item.id)" :checked="packageId === Number(item.id)" />
        </label>
        <label class="package-row"><text>自定义金额</text><radio value="0" :checked="packageId === 0" /></label>
      </radio-group>
      <view v-if="packageId === 0" class="amount-row"><text>¥</text><input v-model="amount" type="number" placeholder="请输入整数金额" /></view>
      <text v-for="(line,index) in attention" :key="index" class="attention">{{ line }}</text>
      <view v-if="error" class="error">{{ error }}</view>
      <button class="continue-button" :disabled="loading" @click="continuePayment">下一步</button>
    </view>
  </view>
</template>
<script>
import colors from '@/mixins/color';
import { getRechargeApi } from '@/api/user.js';
import { toLogin } from '@/libs/login.js';

// Recharge selection creates no order. Store and Fuyou confirmation are shared
// with the purchase checkout, and balance/commission cannot recharge this wallet.
export default {
  mixins: [colors],
  data() { return { balance: 0, packages: [], packageId: 0, amount: '', attention: [], error: '', loading: true }; },
  onLoad() {
    if (!this.$store.getters.isLogin) { toLogin(); return; }
    getRechargeApi().then(res => {
      const data = res.data;
      this.balance = data.user_now_money || 0;
      this.packages = (data.recharge_quota || []).filter(p => Number(p.price) > 0);
      this.attention = (data.recharge_attention || []).filter(Boolean);
    }).catch(e => { this.error = e.msg || '读取充值信息失败'; }).finally(() => { this.loading = false; });
  },
  methods: {
    whole(value) { return Math.round(Number(value || 0)); },
    choosePackage(e) { this.packageId = Number(e.detail.value); },
    continuePayment() {
      if (!this.packageId && (!/^\d+$/.test(this.amount) || Number(this.amount) <= 0)) { this.error = '请输入正整数充值金额'; return; }
      uni.navigateTo({ url: '/pages/goods/member_checkout/index?kind=recharge&price=' + encodeURIComponent(this.amount || '0') + '&package_id=' + this.packageId });
    }
  }
};
</script>
<style scoped>
.recharge-page { min-height: 100vh; background: #f6f7f8; color: #302b2e; font-size: 28rpx; }
.balance-band { background: var(--view-theme, #d54868); color: white; padding: 52rpx 32rpx; display: flex; flex-direction: column; gap: 20rpx; }
.balance-value { font-size: 52rpx; font-weight: 600; }.recharge-band { padding: 32rpx; background: #fff; }
.recharge-title { font-size: 32rpx; font-weight: 600; }.package-row { display: flex; align-items: center; justify-content: space-between; padding: 24rpx 0; border-bottom: 1px solid #eee; }
.bonus { margin-left: 24rpx; color: #9f4259; }.amount-row { display: flex; align-items: center; gap: 20rpx; padding: 28rpx 0; }.amount-row input { min-width: 0; flex: 1; }
.attention { display: block; color: #777; margin-top: 16rpx; }.error { color: #a93149; padding-top: 20rpx; }
.continue-button { margin-top: 36rpx; font-size: 28rpx; border-radius: 8rpx; color: white; background: var(--view-theme, #d54868); }
</style>
