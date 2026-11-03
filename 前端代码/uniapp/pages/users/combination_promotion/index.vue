<template>
  <view class="promotion-page">
    <view class="promotion-hero">
      <text class="promotion-hero__eyebrow">我的推广</text>
      <view class="promotion-hero__title">拼团推广战报</view>
      <text class="promotion-hero__intro">每一份分享，都值得被看见</text>
      <view class="promotion-total"><text>总业绩</text><view>¥{{ money(metrics.total_amount) }}</view></view>
      <view class="promotion-subtotals"><view><text>成团业绩</text><text class="money-value">¥{{ money(metrics.formed_amount) }}</text></view><view><text>未成团业绩</text><text class="money-value">¥{{ money(metrics.pending_amount) }}</text></view></view>
    </view>
    <view class="promotion-filters">
      <picker :range="activityOptions" range-key="title" :value="activityIndex" @change="selectActivity"><view class="promotion-picker"><text>拼团活动</text><text class="promotion-picker__value">{{ selectedActivityTitle }}</text><text>⌄</text></view></picker>
      <view class="promotion-dates"><picker mode="date" :value="dateDraft.start_date" @change="dateDraft.start_date = $event.detail.value"><view>{{ dateDraft.start_date }}</view></picker><text>至</text><picker mode="date" :value="dateDraft.end_date" @change="dateDraft.end_date = $event.detail.value"><view>{{ dateDraft.end_date }}</view></picker><button :disabled="loading" size="mini" @click="queryDates">查询</button></view>
    </view>
    <view v-if="error" class="promotion-error" role="alert">{{ error }}<text @click="load(1)"> 重试</text></view>
    <view class="promotion-counts"><view><text class="money-value">{{ displayCount('order_count') }}</text><text>支付订单</text></view><view><text class="money-value">{{ displayCount('group_count') }}</text><text>参与拼团</text></view><view><text class="money-value">{{ displayCount('formed_count') }}</text><text>已成团</text></view><view><text class="money-value">{{ displayCount('pending_count') }}</text><text>拼团中</text></view><view><text class="money-value">{{ displayCount('failed_count') }}</text><text>未成功</text></view></view>
    <view class="promotion-orders">
      <view class="promotion-orders__heading"><text>推广订单</text><text class="promotion-source-link" @click="notesOpen = !notesOpen">统计说明</text></view>
      <view v-if="notesOpen" class="promotion-notes"><view v-for="(note, index) in sourceNotes" :key="index">{{ note }}</view><view v-if="!sourceNotes.length">仅显示当前登录会员分享归属的已支付拼团订单，成功退款冲减对应业绩。团数按拼团去重。</view></view>
      <view class="promotion-tabs"><button v-for="tab in tabs" :key="tab.value" :class="{ active: applied.status === tab.value }" @click="selectStatus(tab.value)">{{ tab.label }}</button></view>
      <view v-if="loading" class="promotion-empty">正在加载推广记录…</view>
      <view v-else-if="!orders.length" class="promotion-empty">{{ error ? '暂时无法加载数据' : '当前条件下暂无推广订单' }}</view>
      <view v-for="order in orders" :key="order.id" class="promotion-order">
        <view class="promotion-order__top"><text>{{ order.activity_name }}</text><text class="promotion-status">{{ order.status_label }}</text></view>
        <view class="promotion-order__number">订单号 {{ order.order_no }}</view>
        <view class="promotion-order__line"><text>购买会员</text><text>{{ order.member_name || '—' }}</text></view>
        <view class="promotion-order__line"><text>业绩归属门店</text><text>{{ order.store_name || '未归属门店' }}</text></view>
        <view class="promotion-order__line"><text>支付时间</text><text>{{ order.paid_at || '—' }}</text></view>
        <view class="promotion-order__amounts"><view><text>实付金额</text><text class="money-value">¥{{ money(order.paid_amount) }}</text></view><view><text>成功退款</text><text class="money-value">¥{{ money(order.refund_amount) }}</text></view><view><text>净业绩</text><text class="money-value promotion-net">¥{{ money(order.net_amount) }}</text></view></view>
      </view>
    </view>
    <view class="promotion-pagination"><text>共 {{ count }} 条 · 每页 20 条 · 第 {{ page }} / {{ pages }} 页</text><view><button :disabled="loading || page <= 1" @click="load(page - 1)">上一页</button><button :disabled="loading || page >= pages" @click="load(page + 1)">下一页</button></view></view>
  </view>
</template>

<script>
import { mapGetters } from 'vuex';
import { toLogin } from '@/libs/login.js';
import { getCombinationPromotionSummary, getCombinationPromotionOrders } from '@/api/combinationPromotion.js';
import formatBusinessMoney from '@/utils/formatBusinessMoney.js';

// 小程序和 H5 共用当前会员查询；金额、成团状态与团数直接展示统一服务的结果。
export default {
  data() {
    const today = new Date();
    const prefix = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
    const dates = { start_date: prefix + '-01', end_date: prefix + '-' + String(today.getDate()).padStart(2, '0') };
    return { dateDraft: { ...dates }, applied: { ...dates, activity_id: '', status: '' }, activities: [], metrics: {}, orders: [], count: 0, page: 1, loading: false, error: '', sourceNotes: [], notesOpen: false,
      tabs: [{ value: '', label: '全部' }, { value: 'formed', label: '已成团' }, { value: 'pending', label: '拼团中' }, { value: 'failed', label: '未成功' }]
    };
  },
  computed: {
    ...mapGetters(['isLogin']),
    activityOptions() { return [{ id: '', title: '全部拼团活动' }].concat(this.activities.map(item => ({ id: item.id, title: item.title || item.name }))); },
    activityIndex() { const index = this.activityOptions.findIndex(item => String(item.id) === String(this.applied.activity_id)); return Math.max(0, index); },
    selectedActivityTitle() { return this.activityOptions[this.activityIndex].title; },
    pages() { return Math.max(1, Math.ceil(this.count / 20)); }
  },
  onLoad() { if (!this.isLogin) { toLogin(); return; } this.load(1); },
  onUnload() { this.requestId = (this.requestId || 0) + 1; },
  methods: {
    money: formatBusinessMoney,
    displayCount(key) { return this.metrics[key] == null ? '—' : this.metrics[key]; },
    message(error) { return typeof error === 'string' ? error : (error && (error.msg || error.message)) || '数据加载失败，请稍后重试'; },
    queryDates() { if (this.dateDraft.start_date > this.dateDraft.end_date) { this.error = '开始日期不能晚于结束日期'; return; } this.applied = { ...this.applied, ...this.dateDraft }; this.load(1); },
    selectActivity(event) { const activity = this.activityOptions[Number(event.detail.value)]; this.applied.activity_id = activity ? activity.id : ''; this.load(1); },
    selectStatus(status) { this.applied.status = status; this.load(1); },
    // 汇总与明细携带完全相同条件，旧请求晚到不得覆盖用户新选活动；服务端自行固定会员身份。
    async load(page) {
      const requestId = this.requestId = (this.requestId || 0) + 1;
      this.loading = true; this.error = ''; this.page = page; this.orders = []; this.metrics = {};
      try {
        const [summary, details] = await Promise.all([getCombinationPromotionSummary({ ...this.applied }), getCombinationPromotionOrders({ ...this.applied, page, limit: 20 })]);
        if (requestId !== this.requestId) return;
        this.metrics = summary.data.metrics || {}; this.activities = summary.data.activities || [];
        this.sourceNotes = (summary.data.source_notes || []).map(note => typeof note === 'string' ? note : (note.title + '：' + note.description));
        this.orders = details.data.list || []; this.count = Number(details.data.count || 0);
        if (page > this.pages) return this.load(this.pages);
      } catch (error) { if (requestId === this.requestId) { this.error = this.message(error); this.count = 0; } }
      finally { if (requestId === this.requestId) this.loading = false; }
    }
  }
};
</script>

<style scoped>
.promotion-page { min-height: 100vh; background: #f6f4f3; padding-bottom: 180rpx; color: #283b4f; }
.promotion-hero { padding: 38rpx 32rpx 32rpx; color: #ffeaca; background: radial-gradient(ellipse at 85% 0,#c3423d,transparent 65%),linear-gradient(120deg,#8e2630,#4c1423); border-radius: 0 0 32rpx 32rpx; }
.promotion-hero__eyebrow { font-size: 22rpx; letter-spacing: 4rpx; color: #e5b9a0; }.promotion-hero__title { font-size: 42rpx; font-weight: 700; margin-top: 10rpx; }.promotion-hero__intro { display: block; font-size: 22rpx; color: #e5b9a0; margin-top: 12rpx; }.promotion-total { margin-top: 32rpx; }.promotion-total > text { font-size: 24rpx; }.promotion-total > view { font-size: 64rpx; font-weight: 700; margin-top: 8rpx; overflow-wrap: anywhere; }.promotion-subtotals { display: flex; border-top: 1rpx solid #ffffff25; padding-top: 24rpx; margin-top: 20rpx; }.promotion-subtotals > view { flex: 1; min-width: 0; }.promotion-subtotals text { display: block; font-size: 22rpx; color: #e5b9a0; }.promotion-subtotals .money-value { display: block; font-size: 30rpx; margin-top: 10rpx; overflow-wrap: anywhere; }
.promotion-filters { margin: 24rpx; padding: 24rpx; background: #fff; border-radius: 16rpx; }.promotion-picker { display: flex; align-items: center; gap: 14rpx; font-size: 26rpx; }.promotion-picker__value { margin-left: auto; max-width: 65%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }.promotion-dates { display: flex; gap: 12rpx; align-items: center; margin-top: 24rpx; font-size: 22rpx; }.promotion-dates picker { flex: 1; }.promotion-dates picker view { padding: 14rpx 6rpx; background: #f5f7fa; border-radius: 8rpx; text-align: center; }.promotion-dates button { background: #a6323a; color: white; margin: 0; font-size: 22rpx; padding: 0 20rpx; }.promotion-error { color: #ad3439; margin: 24rpx; background: #fff1ee; padding: 20rpx; border-radius: 12rpx; font-size: 24rpx; }.promotion-error text { text-decoration: underline; }
.promotion-counts { display: flex; margin: 24rpx; padding: 28rpx 8rpx; background: #fff; border-radius: 16rpx; }.promotion-counts > view { flex: 1; text-align: center; min-width: 0; }.promotion-counts .money-value { display: block; font-size: 32rpx; color: #2b4358; }.promotion-counts text { display: block; margin-top: 10rpx; font-size: 20rpx; color: #7c8996; }
.promotion-orders { margin: 24rpx; }.promotion-orders__heading { display: flex; justify-content: space-between; align-items: center; font-size: 30rpx; font-weight: 600; padding: 8rpx 0 24rpx; }.promotion-source-link { font-size: 22rpx; color: #64768b; font-weight: 400; }.promotion-notes { background: #fff9ee; border: 1rpx solid #ecdcc2; color: #816343; border-radius: 12rpx; font-size: 23rpx; padding: 18rpx; margin-bottom: 20rpx; line-height: 1.7; }.promotion-notes > view { margin-bottom: 8rpx; }.promotion-tabs { display: flex; gap: 12rpx; margin-bottom: 20rpx; }.promotion-tabs button { flex: 1; margin: 0; border-radius: 10rpx; background: #fff; color: #6f7c8b; font-size: 24rpx; line-height: 2.8; padding: 0; }.promotion-tabs button::after { border: 0; }.promotion-tabs button.active { background: #a6323a; color: #fff; }
.promotion-order { background: #fff; border: 1rpx solid #e8edf1; border-radius: 16rpx; margin-bottom: 20rpx; padding: 24rpx; }.promotion-order__top { display: flex; justify-content: space-between; gap: 20rpx; font-size: 28rpx; font-weight: 600; }.promotion-order__top > text:first-child { flex: 1; min-width: 0; }.promotion-status { color: #ad353b; font-size: 22rpx; white-space: nowrap; font-weight: 400; }.promotion-order__number { color: #91a0ac; font-size: 21rpx; margin: 10rpx 0 20rpx; }.promotion-order__line { display: flex; justify-content: space-between; gap: 14rpx; margin: 10rpx 0; font-size: 23rpx; }.promotion-order__line > text:first-child { color: #8a96a3; white-space: nowrap; }.promotion-order__line > text:last-child { text-align: right; }.promotion-order__amounts { display: flex; border-top: 1rpx solid #edf0f3; margin-top: 20rpx; padding-top: 20rpx; }.promotion-order__amounts > view { flex: 1; }.promotion-order__amounts text { display: block; color: #8a96a3; font-size: 20rpx; }.promotion-order__amounts .money-value { display: block; font-size: 26rpx; margin-top: 8rpx; color: #1f5f8b; }.promotion-order__amounts .promotion-net { color: #ad353b; }.promotion-empty { padding: 100rpx 20rpx; text-align: center; font-size: 26rpx; color: #8a96a3; background: #fff; border-radius: 16rpx; }
.promotion-pagination { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1rpx solid #e6ebf0; padding: 20rpx 24rpx; padding-bottom: calc(20rpx + env(safe-area-inset-bottom)); z-index: 10; font-size: 22rpx; display: flex; align-items: center; justify-content: space-between; gap: 8rpx; }.promotion-pagination > view { display: flex; gap: 10rpx; }.promotion-pagination button { margin: 0; font-size: 22rpx; padding: 0 18rpx; line-height: 2.7; color: #40566f; background: #fff; }.promotion-pagination button[disabled] { color: #bac2ca; }
</style>
