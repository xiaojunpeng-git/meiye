<template>
  <view class="member-checkout" :style="colorStyle">
    <view class="checkout-header gradient-box" :style="{'padding-top': sysHeight + 'px'}">
      <view class="header-bar">
        <text class="iconfont icon-ic_leftarrow header-back" @click="goBack"></text>
        <text class="header-title">{{ order ? '订单结果' : '提交订单' }}</text>
        <text class="header-space"></text>
      </view>
    </view>

    <view v-if="error" class="checkout-error">{{ error }}</view>

    <template v-if="order">
      <view class="checkout-card result-card">
        <text class="result-title">{{ order.status === 'paid' ? '支付成功' : order.status === 'cancelled' ? '已取消' : order.status === 'failed' ? '支付失败' : '待完成支付' }}</text>
        <text v-if="order.orderNo" class="order-number">{{ order.orderNo }}</text>
        <text class="secondary-text">{{ order.storeName }}</text>
      </view>
      <view class="checkout-card">
        <view v-for="(item, index) in order.items" :key="index" class="goods-row">
          <image v-if="item.image" :src="item.image" mode="aspectFill" class="goods-image" />
          <view class="goods-copy">
            <text class="goods-name">{{ item.name }}</text>
            <text class="secondary-text">数量 {{ item.quantity }}</text>
          </view>
          <text class="goods-price">¥{{ yuan(item.amountCents) }}</text>
        </view>
        <view class="summary-row"><text>余额支付</text><text>¥{{ yuan(order.balanceCents) }}</text></view>
        <view class="summary-row"><text>富友支付</text><text>¥{{ yuan(order.externalCents) }}</text></view>
      </view>
      <view class="checkout-card result-actions">
        <button v-if="pendingOrder" class="primary-button" :loading="paying" @click="payOnline">继续支付</button>
        <button v-if="pendingOrder" class="plain-button" @click="refreshOrder">刷新支付结果</button>
        <button v-if="order.status === 'pending'" class="plain-button" @click="cancelOrder">取消订单</button>
        <button class="plain-button" @click="openOrders">查看订单</button>
        <button v-if="order.status === 'paid' && order.kind === 'purchase'" class="plain-button" @click="openRights">我的项目</button>
      </view>
    </template>

    <template v-else>
      <view class="page-body">
        <view class="checkout-card store-card">
          <picker :range="quote.stores || []" range-key="name" :value="storeIndex" @change="changeStore">
            <view class="choice-row">
              <text>{{ kind === 'recharge' ? '充值门店' : '购买门店' }}</text>
              <view class="choice-value">
                <text class="choice-text">{{ storeName || '请选择门店' }}</text>
                <text class="iconfont icon-ic_rightarrow choice-arrow"></text>
              </view>
            </view>
          </picker>
        </view>

        <view class="checkout-card">
          <view v-for="(item, index) in quote.items || []" :key="index" class="goods-row">
            <image v-if="item.image" :src="item.image" mode="aspectFill" class="goods-image" />
            <view class="goods-copy">
              <text class="goods-name">{{ item.name }}</text>
              <view v-if="canChangeQuantity && index === 0" class="quantity-row">
                <text class="secondary-text">购买数量</text>
                <view class="quantity-stepper">
                  <button class="quantity-button" :disabled="purchaseQuantity <= 1 || loading" @click="changeQuantity(-1)">
                    <text class="iconfont icon-ic_Reduce"></text>
                  </button>
                  <text class="quantity-value">{{ purchaseQuantity }}</text>
                  <button class="quantity-button" :disabled="purchaseQuantity >= 1000 || loading" @click="changeQuantity(1)">
                    <text class="iconfont icon-ic_increase"></text>
                  </button>
                </view>
              </view>
              <text v-else class="secondary-text">数量 {{ item.quantity }}</text>
            </view>
            <text class="goods-price">¥{{ yuan(item.amountCents) }}</text>
          </view>
          <view class="summary-row total-row"><text>商品合计</text><text class="amount">¥{{ yuan(quote.grossAmountCents) }}</text></view>
        </view>

        <view v-if="kind === 'purchase'" class="checkout-card benefit-card">
          <view class="choice-row benefit-row" @click="openCoupons">
            <text>优惠券</text>
            <view class="choice-value">
              <text class="choice-text" :class="{'secondary-text': !couponId}">{{ couponSummary }}</text>
              <text class="iconfont icon-ic_rightarrow choice-arrow"></text>
            </view>
          </view>
          <view class="benefit-row integral-row">
            <view>
              <text>积分抵扣</text>
              <text class="payment-note">可用 {{ quote.integralAvailable || 0 }} 积分</text>
            </view>
            <switch :checked="useIntegral" :disabled="!quote.integralEnabled || !quote.integralMaxUsable" :color="themeColor" @change="changeIntegral" />
          </view>
          <view v-if="quote.couponDiscountCents" class="summary-row benefit-summary"><text>优惠券</text><text>-¥{{ yuan(quote.couponDiscountCents) }}</text></view>
          <view v-if="quote.integralDiscountCents" class="summary-row benefit-summary"><text>积分抵扣</text><text>-¥{{ yuan(quote.integralDiscountCents) }}</text></view>
          <view class="summary-row benefit-payable"><text>优惠后金额</text><text class="amount">¥{{ yuan(quote.amountCents) }}</text></view>
        </view>

        <view v-if="kind === 'purchase'" class="checkout-card">
          <view class="choice-row" @click="openSalespeople">
            <text>销售人</text>
            <view class="choice-value">
              <text class="choice-text" :class="{'secondary-text': !salespeople.length}">{{ salespeopleSummary }}</text>
              <text class="iconfont icon-ic_rightarrow choice-arrow"></text>
            </view>
          </view>
        </view>

        <view class="checkout-card">
          <view class="section-title">支付方式</view>
          <!-- Reuse packaged glyphs: decoration must not change payment values,
               availability or the provider handoff for purchase/recharge. -->
          <radio-group @change="changePayment">
            <label v-if="kind === 'purchase'" class="payment-row">
              <view class="payment-label"><text class="iconfont icon-ic_money payment-icon"></text><view><text>余额支付</text><text class="payment-note">可用 ¥{{ yuan(quote.balanceCents) }}</text></view></view>
              <radio value="balance" :checked="paymentMode === 'balance'" :color="themeColor" />
            </label>
            <label class="payment-row">
              <view class="payment-label"><text class="iconfont icon-ic_card payment-icon"></text><text>在线支付（富友）</text></view>
              <radio value="fuyou" :checked="paymentMode === 'fuyou'" :color="themeColor" />
            </label>
            <label v-if="kind === 'purchase'" class="payment-row">
              <view class="payment-label"><text class="iconfont icon-ic_switch payment-icon"></text><text>余额＋在线支付</text></view>
              <radio value="combined" :checked="paymentMode === 'combined'" :color="themeColor" />
            </label>
          </radio-group>
          <!-- #ifndef MP -->
          <radio-group v-if="paymentMode !== 'balance'" class="external-methods" @change="changeExternalMethod">
            <label class="payment-row"><view class="payment-label"><text class="iconfont icon-a-ic_wechatpay payment-icon payment-icon--wechat"></text><text>微信支付</text></view><radio value="wechat" :checked="externalMethod === 'wechat'" :color="themeColor" /></label>
            <label class="payment-row"><view class="payment-label"><text class="iconfont icon-a-ic_alipay payment-icon payment-icon--alipay"></text><text>支付宝支付</text></view><radio value="alipay" :checked="externalMethod === 'alipay'" :color="themeColor" /></label>
          </radio-group>
          <!-- #endif -->
          <view v-if="paymentMode === 'combined'" class="split-row">
            <text>使用余额</text>
            <view class="split-input"><input v-model="balanceYuan" type="number" @input="newIntent" /><text>元</text></view>
          </view>
          <view v-if="paymentMode === 'combined'" class="summary-row"><text>在线支付差额</text><text>¥{{ residualYuan }}</text></view>
        </view>
      </view>

      <view class="checkout-footer">
        <view class="footer-total"><text>合计</text><text class="amount">¥{{ yuan(quote.amountCents) }}</text></view>
        <button class="submit-button" :disabled="loading || submitting || !quote.canSubmit" :loading="submitting" @click="submit">提交订单</button>
      </view>
    </template>

    <baseDrawer :visible="salespeopleVisible" mode="bottom" backgroundColor="transparent" @close="closeSalespeople">
      <view class="sales-drawer">
        <view class="drawer-header">
          <text class="drawer-action" @click="clearSalespeople">清空</text>
          <text class="drawer-title">选择销售人</text>
          <text class="drawer-action confirm-action" @click="closeSalespeople">确定</text>
        </view>
        <scroll-view scroll-y class="sales-list">
          <checkbox-group @change="changeSalespeople">
            <label v-for="person in quote.salespeople || []" :key="person.id" class="sales-row">
              <text>{{ person.name }}</text>
              <checkbox :value="String(person.id)" :checked="salespeople.indexOf(person.id) !== -1" :color="themeColor" />
            </label>
          </checkbox-group>
          <view v-if="!(quote.salespeople || []).length" class="empty-sales">暂无可选销售人</view>
        </scroll-view>
        <view class="safe-bottom"></view>
      </view>
    </baseDrawer>

    <baseDrawer :visible="couponVisible" mode="bottom" backgroundColor="transparent" @close="closeCoupons">
      <view class="sales-drawer">
        <view class="drawer-header">
          <text class="drawer-action" @click="selectCoupon(0)">不使用</text>
          <text class="drawer-title">选择优惠券</text>
          <text class="drawer-action confirm-action" @click="closeCoupons">完成</text>
        </view>
        <scroll-view scroll-y class="sales-list">
          <view v-for="coupon in quote.coupons || []" :key="coupon.id" class="coupon-row" @click="coupon.canUse !== false && selectCoupon(coupon.id)" :style="coupon.canUse === false ? 'opacity:0.55' : ''">
            <view class="coupon-copy">
              <text class="coupon-name">{{ coupon.name }}</text>
              <text class="payment-note">优惠 ¥{{ yuan(coupon.discountCents) }}</text>
              <text v-if="coupon.unavailableReasons && coupon.unavailableReasons.length" class="payment-note">{{ coupon.unavailableReasons.join('；') }}</text>
            </view>
            <radio :disabled="coupon.canUse === false" :checked="couponId === coupon.id" :color="themeColor" />
          </view>
          <view v-if="!(quote.coupons || []).length" class="empty-sales">暂无可用优惠券</view>
        </scroll-view>
        <view class="safe-bottom"></view>
      </view>
    </baseDrawer>
  </view>
</template>

<script>
import colors from '@/mixins/color';
import { toLogin } from '@/libs/login.js';
import { quoteMemberCommerce, createMemberCommerce, getMemberCommerce, payMemberCommerce, cancelMemberCommerce } from '@/api/memberCommerce';
// #ifdef MP-WEIXIN
import { openMemberCommerceSubscribe } from '@/utils/SubscribeMessage.js';
// #endif

export default {
  mixins: [colors],
  data() {
    return {
      sysHeight: uni.getSystemInfoSync().statusBarHeight || 0,
      kind: 'purchase', items: [], cartIds: '', price: '0', packageId: 0,
      storeId: 0, salespeople: [], salespeopleVisible: false,
      couponId: 0, couponVisible: false, useIntegral: false,
      paymentMode: 'balance', externalMethod: 'wechat', balanceYuan: '', quote: {},
      order: null, error: '', loading: false, submitting: false, paying: false,
      commandKey: '', quoteRevision: 0
    };
  },
  computed: {
    // Native radio/checkbox controls cannot consume a CSS variable directly,
    // so read the same persisted theme token used by the rest of the page.
    themeColor() {
      const match = String(this.colorStyle || '').match(/--view-theme:\s*([^;]+)/);
      return match ? match[1].trim() : '#e93323';
    },
    storeIndex() { return Math.max(0, (this.quote.stores || []).findIndex(store => store.id === this.storeId)); },
    storeName() { const store = (this.quote.stores || []).find(item => item.id === this.storeId); return store ? store.name : ''; },
    residualYuan() { return Math.max(0, Math.round((this.quote.amountCents || 0) / 100) - (Number(this.balanceYuan) || 0)); },
    pendingOrder() { return this.order && ['pending', 'payment_requested', 'payment_unknown'].indexOf(this.order.status) !== -1; },
    canChangeQuantity() { return this.kind === 'purchase' && !this.cartIds && this.items.length === 1; },
    purchaseQuantity() { return this.canChangeQuantity ? Number(this.items[0].quantity || 1) : 1; },
    couponSummary() {
      const coupon = (this.quote.coupons || []).find(item => item.id === this.couponId);
      return coupon ? coupon.name + ' -¥' + this.yuan(coupon.discountCents) : '请选择';
    },
    salespeopleSummary() {
      if (!this.salespeople.length) return '选填';
      const names = (this.quote.salespeople || []).filter(person => this.salespeople.indexOf(person.id) !== -1).map(person => person.name);
      return names.length <= 2 ? names.join('、') : names.slice(0, 2).join('、') + '等' + names.length + '人';
    }
  },
  onLoad(options) {
    if (!this.$store.getters.isLogin) { toLogin(); return; }
    if (options.order_id) { this.order = { id: options.order_id, items: [] }; this.refreshOrder(); return; }
    this.kind = options.kind === 'recharge' ? 'recharge' : 'purchase';
    this.paymentMode = this.kind === 'recharge' ? 'fuyou' : 'balance';
    this.price = options.price || '0';
    this.packageId = Number(options.package_id || 0);
    this.cartIds = options.cart_ids || '';
    if (options.product_id) this.items = [{ product_id: Number(options.product_id), quantity: 1 }];
    this.newIntent();
    this.loadQuote();
  },
  onShow() { if (this.order && this.order.id) this.refreshOrder(); },
  methods: {
    yuan(cents) { return Math.round(Number(cents || 0) / 100); },
    goBack() { uni.navigateBack(); },
    // A command key is stable across transport retries and changes whenever
    // the customer edits an order-defining choice.
    newIntent() { this.commandKey = 'MEMBER-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 18); },
    payload() {
      return {
        kind: this.kind, store_id: this.storeId, items: this.items, cart_ids: this.cartIds,
        price: this.price, package_id: this.packageId, salespeople: this.salespeople,
        coupon_id: this.kind === 'purchase' ? this.couponId : 0,
        use_integral: this.kind === 'purchase' && this.useIntegral ? 1 : 0,
        payment_mode: this.paymentMode, balance_yuan: this.balanceYuan || '0',
        external_method: this.externalMethod, idempotency_key: this.commandKey,
        fingerprint: this.quote.fingerprint
      };
    },
    async loadQuote() {
      const revision = ++this.quoteRevision;
      this.loading = true;
      this.error = '';
      try {
        const res = await quoteMemberCommerce(this.payload());
        if (revision !== this.quoteRevision) return;
        this.quote = res.data;
        this.storeId = res.data.storeId;
      } catch (error) {
        if (revision === this.quoteRevision) {
          this.error = error.msg || '读取交易信息失败';
          this.$set(this.quote, 'canSubmit', false);
        }
      } finally {
        if (revision === this.quoteRevision) this.loading = false;
      }
    },
    changeStore(event) {
      const store = this.quote.stores[Number(event.detail.value)];
      if (!store) return;
      this.storeId = store.id;
      this.salespeople = [];
      this.couponId = 0;
      this.useIntegral = false;
      this.newIntent();
      this.loadQuote();
    },
    // Direct purchase owns one authoritative quantity. Cart checkout keeps its
    // cart quantities and therefore must not expose a second editor here.
    changeQuantity(delta) {
      if (!this.canChangeQuantity || this.loading) return;
      const quantity = Math.max(1, Math.min(1000, this.purchaseQuantity + delta));
      if (quantity === this.purchaseQuantity) return;
      this.$set(this.items[0], 'quantity', quantity);
      this.couponId = 0;
      this.useIntegral = false;
      this.newIntent();
      this.loadQuote();
    },
    openSalespeople() { this.salespeopleVisible = true; },
    closeSalespeople() { this.salespeopleVisible = false; },
    clearSalespeople() {
      if (!this.salespeople.length) { this.closeSalespeople(); return; }
      this.salespeople = [];
      this.newIntent();
      this.loadQuote();
    },
    // Salespeople remain an optional multi-select. Every selection change is
    // included in a fresh server quote so submission cannot reuse a stale fact.
    changeSalespeople(event) {
      this.salespeople = event.detail.value.map(Number);
      this.newIntent();
      this.loadQuote();
    },
    openCoupons() { this.couponVisible = true; },
    closeCoupons() { this.couponVisible = false; },
    selectCoupon(id) {
      if (this.couponId === Number(id)) { this.closeCoupons(); return; }
      this.couponId = Number(id);
      this.closeCoupons();
      this.newIntent();
      this.loadQuote();
    },
    changeIntegral(event) {
      this.useIntegral = !!event.detail.value;
      this.newIntent();
      this.loadQuote();
    },
    changePayment(event) { this.paymentMode = event.detail.value; this.newIntent(); },
    changeExternalMethod(event) { this.externalMethod = event.detail.value; this.newIntent(); },
    async submit() {
      if (this.submitting || !this.quote.canSubmit) return;
      if (!this.storeId) { this.error = '请选择交易门店'; return; }
      this.submitting = true;
      this.error = '';
      try {
        // Ask while still handling the explicit submit gesture. The duplicate
        // submit guard stays held until permission and order creation finish.
        // #ifdef MP-WEIXIN
        await openMemberCommerceSubscribe(this.kind);
        // #endif
        const res = await createMemberCommerce(this.payload());
        this.order = res.data;
        if (this.order.status !== 'paid') await this.payOnline(false);
      } catch (error) {
        this.error = error.msg || '支付未完成，请重试或查询订单';
      } finally {
        this.submitting = false;
      }
    },
    async payOnline(requestSubscription = true) {
      if (this.paying) return;
      this.paying = true;
      this.error = '';
      try {
        // A manual retry has its own gesture; the automatic submit-to-payment
        // handoff has already requested permission and must not ask twice.
        // #ifdef MP-WEIXIN
        if (requestSubscription !== false) await openMemberCommerceSubscribe(this.order.kind);
        // #endif
        const res = await payMemberCommerce(this.order.id, {});
        const pay = res.data;
        // Payment parameters belong to this server-created attempt. The client
        // only hands them to the platform SDK and then re-reads order state.
        // #ifdef MP
        if (pay.qr_code) {
          await new Promise((resolve, reject) => wx.openEmbeddedMiniProgram({ appId: pay.app_id,
            path: '/pages/qrPay/qrPay?t=' + encodeURIComponent(pay.qr_code), envVersion: 'release', success: resolve, fail: reject }));
        } else {
          await new Promise((resolve, reject) => uni.requestPayment({ timeStamp: String(pay.timestamp), nonceStr: pay.nonceStr,
            package: pay.package, signType: pay.signType, paySign: pay.paySign, success: resolve, fail: reject }));
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
      } catch (error) {
        this.error = error.msg || '支付处理中，请查询支付结果';
      } finally {
        this.paying = false;
      }
    },
    async refreshOrder() { try { const res = await getMemberCommerce(this.order.id); this.order = res.data; } catch (error) { this.error = error.msg || '查询失败'; } },
    async cancelOrder() { try { const res = await cancelMemberCommerce(this.order.id); this.order = res.data; } catch (error) { this.error = error.msg || '取消失败'; } },
    openOrders() { uni.navigateTo({ url: '/pages/goods/order_list/index' }); },
    openRights() { uni.navigateTo({ url: '/pages/users/user_card_list/index' }); }
  }
};
</script>

<style scoped>
.member-checkout { min-height: 100vh; background: #f5f5f5; color: #333; padding-bottom: calc(136rpx + env(safe-area-inset-bottom)); font-size: 28rpx; }
.checkout-header { position: relative; z-index: 3; color: #fff; }
.gradient-box { background: linear-gradient(180deg, var(--view-theme) 0%, var(--view-theme) 52%, transparent 100%); }
.header-bar { height: 80rpx; padding: 0 20rpx; display: flex; align-items: center; justify-content: space-between; }
.header-back { width: 80rpx; font-size: 40rpx; }.header-title { font-size: 34rpx; font-weight: 500; }.header-space { width: 80rpx; }
.page-body { padding: 20rpx; }.checkout-card { margin: 20rpx; padding: 28rpx 24rpx; background: #fff; border-radius: 16rpx; }
.page-body .checkout-card { margin: 0 0 20rpx; }.store-card { padding-top: 20rpx; padding-bottom: 20rpx; }
.choice-row,.summary-row,.payment-row,.split-row { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.choice-row { min-height: 64rpx; }.choice-value { max-width: 70%; min-width: 0; display: flex; align-items: center; justify-content: flex-end; }
.choice-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.choice-arrow { color: #999; font-size: 24rpx; margin-left: 10rpx; }
.goods-row { display: flex; align-items: center; gap: 20rpx; min-height: 136rpx; }.goods-row + .goods-row { margin-top: 24rpx; }
.goods-image { width: 136rpx; height: 136rpx; flex-shrink: 0; border-radius: 16rpx; }.goods-copy { flex: 1; min-width: 0; }
.goods-name { display: block; line-height: 40rpx; overflow-wrap: anywhere; }.goods-price { flex-shrink: 0; font-size: 30rpx; }
.secondary-text { color: #999; font-size: 24rpx; }.goods-copy > .secondary-text { display: block; margin-top: 14rpx; }
.quantity-row { display: flex; align-items: center; justify-content: space-between; margin-top: 18rpx; }
.quantity-stepper { display: flex; align-items: center; height: 48rpx; background: #f5f5f5; border-radius: 8rpx; overflow: hidden; }
.quantity-button { width: 58rpx; height: 48rpx; margin: 0; padding: 0; border: 0; border-radius: 0; background: transparent; color: #333; line-height: 48rpx; font-size: 24rpx; }
.quantity-button::after { border: 0; }.quantity-button[disabled] { color: #bbb; opacity: 1; }.quantity-value { min-width: 58rpx; text-align: center; font-size: 26rpx; }
.summary-row { margin-top: 28rpx; }.total-row { padding-top: 24rpx; border-top: 1rpx solid #eee; }
.benefit-card { padding-top: 8rpx; padding-bottom: 20rpx; }.benefit-row { min-height: 92rpx; border-bottom: 1rpx solid #eee; }
.integral-row { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.benefit-summary { margin-top: 18rpx; color: #666; }.benefit-payable { padding-top: 20rpx; border-top: 1rpx solid #eee; }
.section-title { margin-bottom: 8rpx; font-weight: 500; }.payment-row { min-height: 96rpx; border-bottom: 1rpx solid #eee; }
.payment-label { display: flex; align-items: center; gap: 20rpx; min-width: 0; }
.payment-icon { flex-shrink: 0; width: 44rpx; height: 44rpx; line-height: 44rpx; font-size: 40rpx; color: var(--view-theme); }
.payment-icon--wechat { color: #09bb07; }.payment-icon--alipay { color: #1296db; }
.payment-note { display: block; color: #999; font-size: 22rpx; margin-top: 8rpx; }.external-methods { display: block; margin-top: 12rpx; }
.split-row { padding-top: 28rpx; }.split-input { display: flex; align-items: center; gap: 8rpx; }.split-input input { width: 180rpx; text-align: right; border-bottom: 1rpx solid #ddd; height: 56rpx; }
.amount { color: var(--view-theme); font-weight: 600; }.checkout-error { margin: 20rpx; padding: 24rpx; background: #fff1f3; color: #a93149; border-radius: 16rpx; overflow-wrap: anywhere; }
/* The safe area extends the footer instead of shrinking its content row inside border-box. */
.checkout-footer { position: fixed; z-index: 10; left: 0; right: 0; bottom: 0; box-sizing: border-box; height: calc(112rpx + env(safe-area-inset-bottom)); padding: 0 24rpx; padding-bottom: env(safe-area-inset-bottom); border-top: 1rpx solid #eee; background: #fff; display: flex; align-items: center; justify-content: space-between; gap: 24rpx; }
.footer-total { display: flex; align-items: baseline; gap: 12rpx; min-width: 0; }.footer-total .amount { font-size: 38rpx; }
.submit-button { display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-sizing: border-box; min-width: 210rpx; height: 72rpx; margin: 0; padding: 0 32rpx; border: 0; border-radius: 36rpx; background: var(--view-theme); color: #fff; line-height: normal; font-size: 27rpx; }
.submit-button::after { border: 0; }.submit-button[disabled] { background: var(--view-minorColor); color: #fff; opacity: 1; }
.sales-drawer { background: #fff; border-radius: 40rpx 40rpx 0 0; overflow: hidden; }
.drawer-header { height: 108rpx; padding: 0 32rpx; display: flex; align-items: center; justify-content: space-between; border-bottom: 1rpx solid #eee; }
.drawer-title { font-size: 32rpx; font-weight: 500; }.drawer-action { min-width: 80rpx; color: #666; }.confirm-action { color: var(--view-theme); text-align: right; }
.sales-list { max-height: 650rpx; }.sales-row { min-height: 96rpx; margin: 0 32rpx; display: flex; align-items: center; justify-content: space-between; border-bottom: 1rpx solid #eee; }
.coupon-row { min-height: 112rpx; margin: 0 32rpx; display: flex; align-items: center; justify-content: space-between; gap: 20rpx; border-bottom: 1rpx solid #eee; }
.coupon-copy { min-width: 0; display: flex; flex-direction: column; }.coupon-name { overflow-wrap: anywhere; }
.empty-sales { padding: 80rpx 32rpx; color: #999; text-align: center; }.safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
.result-card { display: flex; flex-direction: column; align-items: center; gap: 18rpx; }.result-title { font-size: 36rpx; font-weight: 600; }.order-number { overflow-wrap: anywhere; color: #666; }
/* Native button line-height differs from H5; flex centers every result label on both targets. */
.result-actions button { display: flex; align-items: center; justify-content: center; box-sizing: border-box; width: 100%; height: 76rpx; padding: 0 24rpx; margin: 18rpx 0 0; border-radius: 38rpx; line-height: normal; font-size: 28rpx; }.primary-button { background: var(--view-theme); color: #fff; }
/* Result actions share the runtime palette with checkout, including future theme colors. */
.plain-button { background: var(--view-minorColorT); color: var(--view-theme); }
</style>
