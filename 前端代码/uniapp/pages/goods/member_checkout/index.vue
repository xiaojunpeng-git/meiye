<template>
  <view class="member-checkout" :style="colorStyle">
    <view v-if="error" class="checkout-error">{{ error }}</view>
    <template v-if="order">
      <view class="checkout-band result">
        <text class="result-title">{{ order.status === 'paid' ? '支付成功' : order.status === 'cancelled' ? '已取消' : order.status === 'failed' ? '支付失败' : '待完成支付' }}</text>
        <text v-if="order.orderNo" class="order-number">{{ order.orderNo }}</text>
        <text>{{ order.storeName }}</text>
      </view>
      <view class="checkout-band">
        <view v-for="(item, index) in order.items" :key="index" class="item-row">
          <image v-if="item.image" :src="item.image" mode="aspectFill" class="item-image" />
          <view class="item-copy"><text>{{ item.name }}</text><text class="muted">数量 {{ item.quantity }}</text></view>
          <text>¥{{ yuan(item.amountCents) }}</text>
        </view>
        <view class="total-row"><text>余额支付</text><text>¥{{ yuan(order.balanceCents) }}</text></view>
        <view class="total-row"><text>富友支付</text><text>¥{{ yuan(order.externalCents) }}</text></view>
      </view>
      <view class="checkout-band result-actions">
        <button v-if="['pending','payment_requested','payment_unknown'].indexOf(order.status) !== -1" class="primary" :loading="paying" @click="payOnline">继续支付</button>
        <button v-if="['pending','payment_requested','payment_unknown'].indexOf(order.status) !== -1" @click="refreshOrder">刷新支付结果</button>
        <button v-if="order.status === 'pending'" @click="cancelOrder">取消订单</button>
        <button @click="openOrders">查看订单</button>
        <button v-if="order.status === 'paid' && order.kind === 'purchase'" @click="openRights">我的项目</button>
      </view>
    </template>
    <template v-else>
      <view class="checkout-band">
        <picker :range="quote.stores || []" range-key="name" :value="storeIndex" @change="changeStore">
          <view class="store-row"><text>{{ kind === 'recharge' ? '充值门店' : '购买门店' }}</text>
            <text class="store-name">{{ storeName || '请选择门店' }} <text class="iconfont icon-ic_rightarrow" /></text></view>
        </picker>
      </view>
      <view class="checkout-band">
        <view v-for="(item, index) in quote.items || []" :key="index" class="item-row">
          <image v-if="item.image" :src="item.image" mode="aspectFill" class="item-image" />
          <view class="item-copy"><text>{{ item.name }}</text><text class="muted">数量 {{ item.quantity }}</text></view>
          <text>¥{{ yuan(item.amountCents) }}</text>
        </view>
        <view class="total-row"><text>应付合计</text><text class="amount">¥{{ yuan(quote.amountCents) }}</text></view>
      </view>
      <view v-if="kind === 'purchase'" class="checkout-band">
        <text class="section-label">销售人（选填）</text>
        <checkbox-group @change="changeSalespeople">
          <label v-for="person in quote.salespeople || []" :key="person.id" class="person-row">
            <text>{{ person.name }}</text><checkbox :value="String(person.id)" :checked="salespeople.indexOf(person.id) !== -1" :color="themeColor" />
          </label>
        </checkbox-group>
        <text v-if="!(quote.salespeople || []).length" class="muted">暂无可选销售人</text>
      </view>
      <view class="checkout-band">
        <text class="section-label">支付方式</text>
        <radio-group @change="changePayment">
          <label v-if="kind === 'purchase'" class="payment-row"><view><text>余额支付</text><text class="muted">可用 ¥{{ yuan(quote.balanceCents) }}</text></view><radio value="balance" :checked="paymentMode === 'balance'" :color="themeColor" /></label>
          <label class="payment-row"><text>在线支付（富友）</text><radio value="fuyou" :checked="paymentMode === 'fuyou'" :color="themeColor" /></label>
          <label v-if="kind === 'purchase'" class="payment-row"><text>余额＋在线支付</text><radio value="combined" :checked="paymentMode === 'combined'" :color="themeColor" /></label>
        </radio-group>
        <!-- #ifndef MP -->
        <radio-group v-if="paymentMode !== 'balance'" @change="changeExternalMethod">
          <label class="payment-row"><text>微信支付</text><radio value="wechat" :checked="externalMethod === 'wechat'" :color="themeColor" /></label>
          <label class="payment-row"><text>支付宝支付</text><radio value="alipay" :checked="externalMethod === 'alipay'" :color="themeColor" /></label>
        </radio-group>
        <!-- #endif -->
        <view v-if="paymentMode === 'combined'" class="split-row"><text>使用余额</text><input v-model="balanceYuan" type="number" @input="newIntent" /><text>元</text></view>
        <view v-if="paymentMode === 'combined'" class="total-row"><text>在线支付差额</text><text>¥{{ residualYuan }}</text></view>
      </view>
      <view class="checkout-footer"><text>合计 <text class="amount">¥{{ yuan(quote.amountCents) }}</text></text>
        <button class="primary" :disabled="loading || submitting || !quote.canSubmit" :loading="submitting" @click="submit">确认支付</button></view>
    </template>
  </view>
</template>

<script>
import colors from '@/mixins/color';
import { toLogin } from '@/libs/login.js';
import { quoteMemberCommerce, createMemberCommerce, getMemberCommerce, payMemberCommerce, cancelMemberCommerce } from '@/api/memberCommerce';

export default {
  mixins: [colors],
  data() { return { kind: 'purchase', items: [], cartIds: '', price: '0', packageId: 0,
    storeId: 0, salespeople: [], paymentMode: 'balance', externalMethod: 'wechat', balanceYuan: '', quote: {},
    order: null, error: '', loading: false, submitting: false, paying: false, commandKey: '', quoteRevision: 0 }; },
  computed: {
    themeColor() { return this.$store.state.app.themeColor || '#d54868'; },
    storeIndex() { return Math.max(0, (this.quote.stores || []).findIndex(s => s.id === this.storeId)); },
    storeName() { const s = (this.quote.stores || []).find(s => s.id === this.storeId); return s ? s.name : ''; },
    residualYuan() { return Math.max(0, Math.round((this.quote.amountCents || 0) / 100) - (Number(this.balanceYuan) || 0)); }
  },
  onLoad(options) {
    if (!this.$store.getters.isLogin) { toLogin(); return; }
    if (options.order_id) { this.order = { id: options.order_id, items: [] }; this.refreshOrder(); return; }
    this.kind = options.kind === 'recharge' ? 'recharge' : 'purchase';
    this.paymentMode = this.kind === 'recharge' ? 'fuyou' : 'balance';
    this.price = options.price || '0'; this.packageId = Number(options.package_id || 0);
    this.cartIds = options.cart_ids || '';
    if (options.product_id) this.items = [{ product_id: Number(options.product_id), quantity: 1 }];
    this.newIntent(); this.loadQuote();
  },
  onShow() { if (this.order && this.order.id) this.refreshOrder(); },
  methods: {
    yuan(cents) { return Math.round(Number(cents || 0) / 100); },
    // Keep a stable command across transport retries; editing intent creates a new one.
    newIntent() { this.commandKey = 'MEMBER-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 18); },
    payload() { return { kind: this.kind, store_id: this.storeId, items: this.items, cart_ids: this.cartIds,
      price: this.price, package_id: this.packageId, salespeople: this.salespeople, payment_mode: this.paymentMode,
      balance_yuan: this.balanceYuan || '0', external_method: this.externalMethod,
      idempotency_key: this.commandKey, fingerprint: this.quote.fingerprint }; },
    async loadQuote() {
      const revision = ++this.quoteRevision;
      this.loading = true; this.error = '';
      try {
        const res = await quoteMemberCommerce(this.payload());
        if (revision !== this.quoteRevision) return;
        this.quote = res.data; this.storeId = res.data.storeId;
      } catch (e) { if (revision === this.quoteRevision) { this.error = e.msg || '读取交易信息失败'; this.quote.canSubmit = false; } }
      finally { if (revision === this.quoteRevision) this.loading = false; }
    },
    changeStore(e) { const store = this.quote.stores[Number(e.detail.value)]; this.storeId = store.id; this.salespeople = []; this.newIntent(); this.loadQuote(); },
    changeSalespeople(e) { this.salespeople = e.detail.value.map(Number); this.newIntent(); this.loadQuote(); },
    changePayment(e) { this.paymentMode = e.detail.value; this.newIntent(); },
    changeExternalMethod(e) { this.externalMethod = e.detail.value; this.newIntent(); },
    async submit() {
      if (this.submitting || !this.quote.canSubmit) return;
      if (!this.storeId) { this.error = '请选择交易门店'; return; }
      this.submitting = true; this.error = '';
      try { const res = await createMemberCommerce(this.payload()); this.order = res.data;
        if (this.order.status !== 'paid') await this.payOnline();
      } catch (e) { this.error = e.msg || '支付未完成，请重试或查询订单'; }
      finally { this.submitting = false; }
    },
    async payOnline() {
      if (this.paying) return;
      this.paying = true; this.error = '';
      try {
        const res = await payMemberCommerce(this.order.id, {});
        const pay = res.data;
        // Provider parameters are generated server-side for this exact attempt.
        // #ifdef MP
        if (pay.qr_code) {
          await new Promise((resolve, reject) => wx.openEmbeddedMiniProgram({ appId: pay.app_id,
            path: '/pages/qrPay/qrPay?t=' + encodeURIComponent(pay.qr_code), envVersion: 'release', success: resolve, fail: reject }));
        } else {
          await new Promise((resolve, reject) => uni.requestPayment({timeStamp: String(pay.timestamp), nonceStr: pay.nonceStr,
            package: pay.package, signType: pay.signType, paySign: pay.paySign, success: resolve, fail: reject}));
        }
        // #endif
        // #ifdef H5
        if (pay.url_link || pay.url) window.location.href = pay.url_link || pay.url;
        else if (pay.paySign && this.$wechat) await this.$wechat.pay(pay);
        // #endif
        // #ifdef APP-PLUS
        if (pay.url_link || pay.url) plus.runtime.openURL(pay.url_link || pay.url);
        // #endif
        await this.refreshOrder();
      } catch (e) { this.error = e.msg || '支付处理中，请查询支付结果'; }
      finally { this.paying = false; }
    },
    async refreshOrder() { try { const res = await getMemberCommerce(this.order.id); this.order = res.data; } catch (e) { this.error = e.msg || '查询失败'; } },
    async cancelOrder() { try { const res = await cancelMemberCommerce(this.order.id); this.order = res.data; } catch (e) { this.error = e.msg || '取消失败'; } },
    openOrders() { uni.navigateTo({ url: '/pages/goods/order_list/index' }); },
    openRights() { uni.navigateTo({ url: '/pages/users/user_card_list/index' }); }
  }
};
</script>

<style scoped>
.member-checkout { min-height: 100vh; background: #f5f6f7; color: #302b2e; padding: 16rpx 0 150rpx; font-size: 28rpx; }
.checkout-band { background: #fff; padding: 28rpx; margin-bottom: 16rpx; }
.store-row,.item-row,.total-row,.person-row,.payment-row,.split-row { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.store-row { min-height: 64rpx; }.store-name { max-width: 65%; overflow-wrap: anywhere; text-align: right; }
.item-row { padding: 18rpx 0; }.item-image { width: 112rpx; height: 112rpx; flex-shrink: 0; border-radius: 8rpx; }
.item-copy { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 14rpx; overflow-wrap: anywhere; }
.muted { display: block; color: #7e7880; font-size: 24rpx; margin-top: 8rpx; }
.total-row { padding-top: 24rpx; }.section-label { display: block; font-weight: 600; margin-bottom: 20rpx; }
.person-row,.payment-row { min-height: 90rpx; border-bottom: 1px solid #f2eff1; }.amount { color: var(--view-theme, #d54868); font-weight: 600; }
.split-row { padding-top: 24rpx; }.split-row input { min-width: 0; flex: 1; text-align: right; border-bottom: 1px solid #ddd; height: 64rpx; }
.checkout-footer { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 20rpx 28rpx; padding-bottom: calc(20rpx + env(safe-area-inset-bottom)); display: flex; align-items: center; justify-content: space-between; gap: 20rpx; }
.checkout-footer button { margin: 0; min-width: 240rpx; font-size: 28rpx; border-radius: 8rpx; }.primary { background: var(--view-theme, #d54868); color: #fff; }
.result { display: flex; flex-direction: column; align-items: center; gap: 20rpx; }.result-title { font-size: 36rpx; font-weight: 600; }.order-number { overflow-wrap: anywhere; }
.result-actions button { margin-top: 20rpx; font-size: 28rpx; border-radius: 8rpx; }.checkout-error { color: #a93149; background: #fff1f3; padding: 24rpx; overflow-wrap: anywhere; }
</style>
