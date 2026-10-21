<template>
  <view class="recharge-page" :style="colorStyle">
    <!-- Restore the original visual shell only; the current quote/pay flow
         and radio-group selection contract remain authoritative. -->
    <view class="balance-background">
      <view class="balance-band">
        <text class="balance-label">我的余额</text>
        <text class="balance-value">¥{{ whole(balance) }}</text>
        <image class="balance-image" src="../static/chongzhi.png" mode="aspectFit" aria-hidden="true" />
      </view>
    </view>
    <view class="recharge-band">
      <radio-group class="package-grid" @change="choosePackage" aria-label="充值金额">
        <label v-for="item in packages" :key="item.id" class="package-row" :class="{ 'package-row--selected': packageId === Number(item.id) }">
          <text class="package-price">¥{{ whole(item.price) }}</text>
          <text v-if="Number(item.give_money) > 0" class="bonus">赠送 {{ whole(item.give_money) }} 元</text>
          <radio class="package-choice" :value="String(item.id)" :checked="packageId === Number(item.id)" />
        </label>
        <label class="package-row package-row--custom" :class="{ 'package-row--selected': packageId === 0 }">
          <text v-if="packageId !== 0" class="custom-label">自定义</text>
          <!-- Input changes are not package changes; keep them inside their
               own control now that the field occupies the original tile. -->
          <input v-else v-model="amount" class="custom-amount" type="number" placeholder="自定义" aria-label="请输入整数金额" @change.stop />
          <radio class="package-choice" value="0" :checked="packageId === 0" />
        </label>
      </radio-group>
      <view class="attention-section">
        <text class="attention-title">充值说明</text>
        <view v-for="(line,index) in attention" :key="index" class="attention">
          <view class="attention-dot"></view><text class="attention-text">{{ line }}</text>
        </view>
      </view>
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
.recharge-page { min-height: 100vh; padding-bottom: 32rpx; background: #f5f5f5; color: #333; font-size: 28rpx; }
/* All accents consume the existing theme tokens, including future palettes;
   historical payment/commission code is deliberately not restored. */
.balance-background { padding-top: 32rpx; background: linear-gradient(180deg, var(--view-minorColorT) 0%, #f5f5f5 100%); }
.balance-band { position: relative; box-sizing: border-box; width: 710rpx; max-width: calc(100% - 40rpx); height: 396rpx; margin: 0 auto; padding: 56rpx 60rpx; border-radius: 32rpx 32rpx 0 0; background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%); color: #fff; }
.balance-label { position: relative; z-index: 1; display: block; color: rgba(255, 255, 255, .8); }
.balance-value { position: relative; z-index: 1; display: block; width: calc(100% - 150rpx); margin-top: 16rpx; font-family: SemiBold, sans-serif; font-size: 68rpx; overflow-wrap: anywhere; }
.balance-image { position: absolute; top: 66rpx; right: 60rpx; width: 186rpx; height: 186rpx; }
.recharge-band { position: relative; box-sizing: border-box; width: 710rpx; max-width: calc(100% - 40rpx); margin: -176rpx auto 0; padding: 46rpx 32rpx 64rpx; border-radius: 32rpx; background: #fff; }
.package-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16rpx; }
.package-row { position: relative; box-sizing: border-box; display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 0; min-height: 144rpx; padding: 20rpx 12rpx; border: 1px solid #f5f5f5; border-radius: 20rpx; background: #f5f5f5; text-align: center; }
.package-price { max-width: 100%; font-size: 36rpx; font-weight: 500; overflow-wrap: anywhere; }
.bonus { max-width: 100%; margin-top: 6rpx; color: #999; font-size: 24rpx; overflow-wrap: anywhere; }
.package-row--selected { border-color: var(--view-theme); background: var(--view-minorColorT); color: var(--view-theme); }
.package-row--selected .bonus { color: var(--view-theme); }
/* Native radios still own selection and accessibility; only their visual
   marks are replaced by the original tile border/background. */
.package-choice { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
.custom-label, .custom-amount { box-sizing: border-box; width: 100%; min-width: 0; height: 70rpx; font-size: 32rpx; line-height: 70rpx; text-align: center; }
.custom-amount { color: inherit; }
.attention-section { margin-top: 48rpx; }
.attention-title { display: block; margin-bottom: 24rpx; font-size: 32rpx; font-weight: 600; }
.attention { display: flex; align-items: flex-start; margin-bottom: 20rpx; color: #999; font-size: 24rpx; }
.attention-dot { flex: none; width: 10rpx; height: 10rpx; margin-top: 12rpx; margin-right: 16rpx; border-radius: 50%; background: var(--view-theme); }
.attention-text { min-width: 0; flex: 1; overflow-wrap: anywhere; }
.error { padding-top: 20rpx; color: #a93149; overflow-wrap: anywhere; }
.continue-button { box-sizing: border-box; width: 100%; height: 88rpx; margin: 150rpx auto 0; padding: 0; border-radius: 50rpx; background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%); color: #fff; font-size: 28rpx; font-weight: 500; line-height: 88rpx; }
</style>
