<template>
  <main ref="screen" class="combination-screen" :class="{ 'is-fullscreen': fullscreen }">
    <header class="screen-heading">
      <div><span class="screen-eyebrow">一起拼 · 一起赢</span><h1>拼团战报</h1></div>
      <div class="screen-actions">
        <button type="button" @click="notesOpen = true">列名取值来源</button>
        <button type="button" @click="openSettings">大屏设置</button>
        <button type="button" :disabled="loading" @click="refresh">{{ loading ? '更新中…' : '刷新' }}</button>
        <button type="button" @click="toggleFullscreen">{{ fullscreen ? '退出全屏' : '全屏展示' }}</button>
      </div>
    </header>
    <section class="screen-query" aria-label="统计条件">
      <span class="activity-selection">{{ activityScopeLabel }}</span>
      <label>支付日期 <input v-model="dateDraft.start_date" type="date" aria-label="支付开始日期"></label>
      <span>至</span><input v-model="dateDraft.end_date" type="date" aria-label="支付结束日期">
      <button type="button" :disabled="loading" @click="query">查询</button>
      <span class="screen-updated">{{ dashboard.updated_at ? '更新于 ' + dashboard.updated_at : '等待数据' }} · 每 30 秒刷新</span>
    </section>
    <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
    <section class="screen-metrics" aria-label="拼团业绩">
      <button v-for="metric in metricCards" :key="metric.key" type="button" class="screen-metric" :class="{ 'screen-metric--total': metric.key === 'total_amount' }" :disabled="!loaded" @click="openDetails('', null, metric.label, metric.status)">
        <span>{{ metric.label }}</span><strong><small>¥</small>{{ metricValue(metric.key) }}</strong><em>查看明细 →</em>
      </button>
    </section>
    <section class="screen-summary" aria-label="统计摘要">
      <span>有效支付订单 <b>{{ countValue('order_count') }}</b> 笔</span>
      <span>参与拼团 <b>{{ countValue('group_count') }}</b> 团</span>
      <span>失败待退款 <b>¥{{ metricValue('refund_pending_amount') }}</b></span>
      <span>总业绩 = 成团业绩 + 未成团业绩</span>
    </section>
    <section class="screen-rankings">
      <article v-for="rank in rankingPanels" :key="rank.key" class="screen-ranking">
        <header><h2>{{ rank.title }}</h2><button type="button" :aria-pressed="paused[rank.key]" @click="$set(paused, rank.key, !paused[rank.key])">{{ paused[rank.key] ? '继续滚动' : '暂停滚动' }}</button></header>
        <div class="rank-columns"><span>{{ rank.subject }}</span><span>总业绩 / 成团业绩</span></div>
        <div :ref="rank.key" class="rank-scroll" tabindex="0" :aria-label="rank.title" @mouseenter="hovered = rank.key" @mouseleave="hovered = ''" @focusin="hovered = rank.key" @focusout="hovered = ''">
          <p v-if="!loaded" class="screen-empty">{{ loading ? '正在加载战报…' : '战报尚未加载' }}</p>
          <p v-else-if="!rankRows(rank.key).length" class="screen-empty">当前范围暂无业绩</p>
          <button v-for="(row, index) in rankRows(rank.key)" :key="row.id" type="button" class="rank-row" :class="{ 'rank-row--first': index === 0 }" @click="openDetails(rank.dimension, row.id, row.name)">
            <span class="rank-number" :class="'rank-number--' + (index + 1)">{{ index + 1 }}</span>
            <span class="rank-identity"><strong>{{ row.name || '未归属' }}</strong><small v-if="rank.key === 'members'">{{ row.store_name || '未归属门店' }}</small><small v-if="rank.key === 'stores' && row.buyer_name">购买会员：{{ row.buyer_name }}</small><small>{{ row.order_count }} 笔 · {{ row.group_count }} 团</small></span>
            <span class="rank-money"><strong>¥{{ money(row.total_amount) }}</strong><small>成团 ¥{{ money(row.formed_amount) }}</small></span>
          </button>
        </div>
        <footer>按总业绩排行 · 前 50 名 · 点击查看明细</footer>
      </article>
    </section>
    <footer class="screen-footer"><span>每一份分享，都值得被看见</span><span>已支付订单按当前成团状态统计，成功退款冲减对应业绩。</span></footer>

    <div v-if="settingsOpen" class="screen-overlay" @click.self="settingsOpen = false">
      <section class="screen-dialog" role="dialog" aria-modal="true" aria-labelledby="screen-settings-title">
        <header><h2 id="screen-settings-title">大屏设置</h2><button type="button" aria-label="关闭设置" @click="settingsOpen = false">×</button></header>
        <p>选择参与战报的拼团活动。未勾选表示统计全部有权限的活动。</p>
        <input v-model="activitySearch" class="activity-search" placeholder="搜索拼团名称或编号" aria-label="搜索拼团活动">
        <div class="activity-options">
          <label v-for="activity in filteredActivities" :key="activity.id"><input v-model="selectedActivities" type="checkbox" :value="activity.id"><span>{{ activity.title }}</span><small>#{{ activity.id }}</small></label>
          <p v-if="!filteredActivities.length">暂无匹配的拼团活动</p>
        </div>
        <p v-if="settingsError" class="dialog-error" role="alert">{{ settingsError }}</p>
        <footer><button type="button" @click="selectedActivities = []">全部活动</button><span>已选 {{ selectedActivities.length }} 个</span><button type="button" :disabled="saving" class="primary" @click="saveSettings">{{ saving ? '保存中…' : '保存设置' }}</button></footer>
      </section>
    </div>

    <div v-if="detailsOpen" class="screen-overlay" @click.self="detailsOpen = false">
      <section class="screen-dialog screen-dialog--details" role="dialog" aria-modal="true" aria-labelledby="screen-details-title">
        <header><h2 id="screen-details-title">{{ detailTitle }}明细</h2><button type="button" aria-label="关闭明细" @click="detailsOpen = false">×</button></header>
        <div class="detail-toolbar"><span>{{ detailFilter.start_date || '不限开始日期' }} 至 {{ detailFilter.end_date || '不限结束日期' }}</span><button type="button" :disabled="exporting || detailLoading" @click="exportDetails">{{ exporting ? '导出中…' : '导出全部查询结果' }}</button></div>
        <p v-if="detailError" class="dialog-error" role="alert">{{ detailError }}</p>
        <div class="detail-table-scroll">
          <table><thead><tr><th v-for="column in detailColumns" :key="column.key" :style="{ minWidth: (column.width || 110) + 'px' }">{{ column.title }}</th></tr></thead>
            <tbody><tr v-if="details.summary" class="detail-total"><td v-for="(column, index) in detailColumns" :key="column.key">{{ index === 0 ? '合计' : (column.money && details.summary[column.key] != null ? '¥' + money(details.summary[column.key]) : '—') }}</td></tr>
              <tr v-for="row in details.list || []" :key="row.id"><td v-for="column in detailColumns" :key="column.key">{{ column.money ? '¥' + money(row[column.key]) : (row[column.key] == null || row[column.key] === '' ? '—' : row[column.key]) }}</td></tr>
              <tr v-if="!(details.list || []).length"><td :colspan="detailColumns.length" class="screen-empty">{{ detailLoading ? '正在加载…' : (detailError ? '数据加载失败，请重试' : '暂无符合条件的记录') }}</td></tr>
            </tbody>
          </table>
        </div>
        <footer class="detail-pagination"><span>共 {{ details.count || 0 }} 条 · 每页 20 条 · 第 {{ detailPage }} / {{ detailPages }} 页</span><button type="button" :disabled="detailLoading || detailPage <= 1" @click="loadDetails(detailPage - 1)">上一页</button><button type="button" :disabled="detailLoading || detailPage >= detailPages" @click="loadDetails(detailPage + 1)">下一页</button></footer>
      </section>
    </div>

    <div v-if="notesOpen" class="screen-overlay" @click.self="notesOpen = false">
      <section class="screen-dialog" role="dialog" aria-modal="true" aria-labelledby="screen-notes-title"><header><h2 id="screen-notes-title">列名取值来源</h2><button type="button" aria-label="关闭来源说明" @click="notesOpen = false">×</button></header><ul class="source-notes"><li v-for="(note, index) in dashboard.source_notes || []" :key="index">{{ typeof note === 'string' ? note : (note.title + '：' + note.description) }}</li></ul><p v-if="!(dashboard.source_notes || []).length">数据加载后可查看统计口径。</p></section>
    </div>
  </main>
</template>

<script>
import dayjs from 'dayjs';
import formatBusinessMoney from '@/utils/formatBusinessMoney';
import exportExcel from '@/utils/combinationDashboardExcel';
import { getCombinationDashboard, getCombinationDashboardSettings, saveCombinationDashboardSettings, getCombinationDashboardActivities, getCombinationDashboardDetails, exportCombinationDashboardDetails } from '@/api/combinationDashboard';

// 列仅描述显示契约；金额、排序、完整合计和数据范围均读取后端，禁止用当前页求和。
const fallbackColumns = [
  { key: 'order_no', title: '订单号', width: 180 }, { key: 'activity_name', title: '拼团活动', width: 180 },
  { key: 'member_name', title: '购买会员' }, { key: 'promoter_name', title: '分享人' }, { key: 'store_name', title: '业绩归属门店', width: 150 },
  { key: 'status_label', title: '拼团状态' }, { key: 'paid_amount', title: '实付金额', money: true },
  { key: 'refund_amount', title: '成功退款', money: true }, { key: 'net_amount', title: '净业绩', money: true }, { key: 'paid_at', title: '支付时间', width: 180 }
];
export default {
  name: 'CombinationDashboard',
  data() {
    const date = { start_date: dayjs().startOf('month').format('YYYY-MM-DD'), end_date: dayjs().format('YYYY-MM-DD') };
    return {
      dashboard: {}, initialized: false, loading: false, loaded: false, error: '', dateDraft: { ...date }, applied: { ...date, activity_ids: '' },
      activities: [], settingsOpen: false, selectedActivities: [], activitySearch: '', settingsError: '', saving: false, notesOpen: false,
      detailsOpen: false, details: {}, detailTitle: '', detailFilter: {}, detailPage: 1, detailLoading: false, detailError: '', exporting: false,
      fullscreen: false, paused: { members: false, stores: false, activities: false }, hovered: '',
      metricCards: [{ key: 'formed_amount', label: '成团业绩', status: 'formed' }, { key: 'pending_amount', label: '未成团业绩', status: 'unformed' }, { key: 'total_amount', label: '总业绩', status: '' }],
      rankingPanels: [{ key: 'members', dimension: 'member', title: '个人业绩排行', subject: '分享人 / 所属门店' }, { key: 'stores', dimension: 'store', title: '门店业绩排行', subject: '业绩归属门店' }, { key: 'activities', dimension: 'activity', title: '拼团活动排行', subject: '拼团活动' }]
    };
  },
  computed: {
    activityScopeLabel() { return this.applied.activity_ids ? `已选 ${this.applied.activity_ids.split(',').length} 个拼团活动` : '全部有权限的拼团活动'; },
    filteredActivities() { const text = this.activitySearch.trim().toLowerCase(); return this.activities.filter(item => !text || String(item.title).toLowerCase().includes(text) || String(item.id).includes(text)); },
    detailColumns() { return Array.isArray(this.details.columns) && this.details.columns.length ? this.details.columns : fallbackColumns; },
    detailPages() { return Math.max(1, Math.ceil(Number(this.details.count || 0) / 20)); }
  },
  mounted() {
    this.initialize();
    this.refreshTimer = setInterval(() => { if (this.initialized && !this.loading && !document.hidden && !this.settingsOpen && !this.detailsOpen && !this.notesOpen) this.loadDashboard(); }, 30000);
    // 无鼠标操作时持续缓慢滚动；悬停、聚焦或用户暂停均立即停下，不改变后端排行顺序。
    this.scrollTimer = setInterval(this.scrollRanks, 60);
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) this.paused = { members: true, stores: true, activities: true };
    document.addEventListener('keydown', this.onEscape);
    document.addEventListener('fullscreenchange', this.syncFullscreen);
  },
  beforeDestroy() { clearInterval(this.refreshTimer); clearInterval(this.scrollTimer); document.removeEventListener('keydown', this.onEscape); document.removeEventListener('fullscreenchange', this.syncFullscreen); this.exitFullscreen(); this.dashboardRequest = (this.dashboardRequest || 0) + 1; this.detailRequest = (this.detailRequest || 0) + 1; },
  methods: {
    money: formatBusinessMoney,
    metricValue(key) { return this.loaded ? this.money((this.dashboard.metrics || {})[key]) : '—'; },
    countValue(key) { return this.loaded ? ((this.dashboard.metrics || {})[key] || 0) : '—'; },
    rankRows(key) { return (this.dashboard.rankings || {})[key] || []; },
    message(error) { return typeof error === 'string' ? error : (error && (error.msg || error.message)) || '暂时无法加载，请重试'; },
    refresh() { return this.initialized ? this.loadDashboard() : this.initialize(); },
    async initialize() {
      try { const [settings, activities] = await Promise.all([getCombinationDashboardSettings(), getCombinationDashboardActivities()]); this.activities = (activities.data.list || []).map(item => ({ ...item, id: Number(item.id) })); this.selectedActivities = (settings.data.activity_ids || []).map(Number); this.applied.activity_ids = this.selectedActivities.join(','); this.initialized = true; await this.loadDashboard(); }
      catch (error) { this.error = this.message(error); }
    },
    async loadDashboard() {
      // 新筛选必须使旧请求失效，不能因自动刷新正在请求就忽略本次查询。
      if (!this.initialized) return this.initialize();
      const request = this.dashboardRequest = (this.dashboardRequest || 0) + 1;
      this.loading = true; this.error = '';
      try { const response = await getCombinationDashboard({ ...this.applied }); if (request !== this.dashboardRequest) return; this.dashboard = response.data; this.loaded = true; }
      catch (error) { if (request === this.dashboardRequest) this.error = `战报更新失败，${this.loaded ? '当前保留上次结果。' : ''}${this.message(error)}`; }
      finally { if (request === this.dashboardRequest) this.loading = false; }
    },
    query() {
      if (this.dateDraft.start_date && this.dateDraft.end_date && this.dateDraft.start_date > this.dateDraft.end_date) { this.error = '开始日期不能晚于结束日期'; return; }
      this.applied = { ...this.applied, ...this.dateDraft }; this.clearQueryResult(); this.loadDashboard();
    },
    // 主动更改筛选时旧排行已不属于当前条件，必须清空，避免旧金额下钻到新范围。
    // 自动刷新不调用此方法：失败时保留上次同条件结果，并由错误提示标明。
    clearQueryResult() { this.dashboard = {}; this.loaded = false; },
    openSettings() { this.selectedActivities = this.applied.activity_ids ? this.applied.activity_ids.split(',').map(Number) : []; this.settingsError = ''; this.settingsOpen = true; },
    async saveSettings() {
      if (this.saving) return; this.saving = true; this.settingsError = '';
      try { const response = await saveCombinationDashboardSettings({ activity_ids: this.selectedActivities }); this.applied.activity_ids = (response.data.activity_ids || this.selectedActivities).join(','); this.settingsOpen = false; this.clearQueryResult(); await this.loadDashboard(); }
      catch (error) { this.settingsError = this.message(error); }
      finally { this.saving = false; }
    },
    // 下钻固定点击当时的日期/活动/维度 ID；导出复用该快照，不随自动刷新或设置草稿变化。
    openDetails(dimension, id, title, status = '') { this.detailFilter = { ...this.applied, dimension, dimension_id: id == null ? '' : id, status }; this.detailTitle = title; this.details = {}; this.detailsOpen = true; this.loadDetails(1); },
    async loadDetails(page) {
      const request = this.detailRequest = (this.detailRequest || 0) + 1;
      this.detailPage = page; this.detailLoading = true; this.detailError = '';
      try { const response = await getCombinationDashboardDetails({ ...this.detailFilter, page, limit: 20 }); if (request !== this.detailRequest) return; this.details = response.data; if (page > this.detailPages) return this.loadDetails(this.detailPages); }
      catch (error) { if (request === this.detailRequest) { this.details = {}; this.detailError = this.message(error); } }
      finally { if (request === this.detailRequest) this.detailLoading = false; }
    },
    async exportDetails() {
      this.exporting = true;
      try { const response = await exportCombinationDashboardDetails({ ...this.detailFilter }); const data = response.data; exportExcel(data); }
      catch (error) { this.detailError = this.message(error); }
      finally { this.exporting = false; }
    },
    scrollRanks() {
      if (document.hidden || this.settingsOpen || this.detailsOpen || this.notesOpen) return;
      this.rankingPanels.forEach(panel => { const refs = this.$refs[panel.key]; const node = Array.isArray(refs) ? refs[0] : refs; if (!node || this.paused[panel.key] || this.hovered === panel.key || node.scrollHeight <= node.clientHeight) return; node.scrollTop = node.scrollTop + node.clientHeight >= node.scrollHeight - 1 ? 0 : node.scrollTop + 1; });
    },
    /** 系统全屏以大屏节点为目标，浏览器导航也退出屏幕；实际状态由浏览器事件确认。 */
    async toggleFullscreen() {
      const screen = this.$refs.screen;
      if (document.fullscreenElement === screen) return this.exitFullscreen();
      if (!screen || !screen.requestFullscreen) { this.$Message.error('当前浏览器不支持全屏展示'); return; }
      try { await screen.requestFullscreen(); }
      catch (error) { this.$Message.error('浏览器未允许全屏，请检查浏览器设置'); }
    },
    syncFullscreen() { this.fullscreen = document.fullscreenElement === this.$refs.screen; },
    exitFullscreen() {
      if (document.fullscreenElement === this.$refs.screen && document.exitFullscreen) document.exitFullscreen();
      this.fullscreen = false;
    },
    onEscape(event) { if (event.key === 'Escape') { this.settingsOpen = false; this.detailsOpen = false; this.notesOpen = false; } }
  }
};
</script>

<style scoped>
.combination-screen { --gold: #f8d897; position: relative; min-height: calc(100vh - 165px); padding: 24px; color: #fff1db; background: radial-gradient(ellipse at 50% -15%, #a52c31 0, #6d1925 38%, #30101e 85%); border-radius: 12px; overflow: hidden; }
.combination-screen::before { content: ''; position: absolute; width: 520px; height: 520px; border: 1px solid #e9ba7130; border-radius: 50%; top: -380px; left: calc(50% - 260px); pointer-events: none; box-shadow: 0 0 0 60px #e9ba7109, 0 0 0 120px #e9ba7106; }
.combination-screen.is-fullscreen { position: fixed; inset: 0; z-index: 2147483000; width: 100vw; height: 100vh; min-height: 0; margin: 0; border-radius: 0; overflow: auto; }
.combination-screen button { cursor: pointer; font: inherit; }
.combination-screen button:disabled { opacity: .55; cursor: default; }
.combination-screen button:focus-visible, .combination-screen input:focus-visible { outline: 2px solid #e8b95d; outline-offset: 3px; }
.screen-heading { display: flex; align-items: center; justify-content: space-between; gap: 20px; position: relative; margin-bottom: 20px; }
.screen-eyebrow { font-size: 11px; color: #e7bc92; letter-spacing: 5px; }
.screen-heading h1 { font-size: 32px; line-height: 1.3; color: #fff0cb; margin: 4px 0 0; font-weight: 800; letter-spacing: 3px; }
.screen-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.screen-actions button, .screen-query button, .screen-ranking header button { color: #ffe5b9; border: 1px solid #e8b95d4d; background: #ffffff09; border-radius: 5px; padding: 7px 12px; }
.screen-actions button:hover, .screen-query button:hover { background: #ffffff18; }
.screen-query { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-bottom: 18px; font-size: 12px; }
.screen-query input { color: #fff3df; border: 1px solid #e8b95d40; border-radius: 5px; background: #ffffff09; padding: 6px 8px; color-scheme: dark; }
.activity-selection { padding-right: 14px; color: #f5d7ad; }.screen-updated { margin-left: auto; font-size: 11px; color: #ddaea1; }
.screen-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.screen-metric { position: relative; text-align: left; min-width: 0; border: 1px solid #d89d5e60; border-radius: 10px; background: linear-gradient(125deg,#ffffff0e,#ffffff02); color: #fce7c5; padding: 23px 22px 17px; }
.screen-metric > span { font-size: 15px; font-weight: 600; letter-spacing: 2px; }.screen-metric > strong { display: block; font-size: clamp(30px,3.5vw,58px); line-height: 1.45; color: #ffe7ad; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.screen-metric small { font-size: 21px; margin-right: 8px; }.screen-metric em { font-size: 11px; font-style: normal; color: #e7bc92; }.screen-metric--total { background: linear-gradient(125deg,#b73535,#7a2028); box-shadow: inset 0 1px #ffd98e88; border-color: #efc67d; }
.screen-summary { display: flex; justify-content: center; gap: 30px; flex-wrap: wrap; padding: 17px 0 22px; color: #dcb5ab; font-size: 12px; }.screen-summary b { color: #ffdfaa; margin: 0 4px; }
.screen-rankings { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 16px; }.screen-ranking { min-width: 0; border: 1px solid #d89d5e44; background: #200d172e; border-radius: 10px; overflow: hidden; }
.screen-ranking header { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 16px; border-bottom: 1px solid #d89d5e30; }.screen-ranking h2 { font-size: 16px; color: #ffdfaa; font-weight: 700; margin: 0; }.screen-ranking header button { font-size: 11px; padding: 4px 7px; white-space: nowrap; }
.rank-columns { display: flex; justify-content: space-between; padding: 12px 16px; font-size: 10px; color: #c3a0a0; }.rank-scroll { height: 360px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #ba7a61 #ffffff05; }.rank-row { display: flex; width: 100%; align-items: center; gap: 10px; border: 0; border-bottom: 1px solid #ffffff0b; padding: 14px 14px; color: inherit; background: transparent; text-align: left; }.rank-row:hover { outline: 1px solid #e8b95d77; outline-offset: -1px; }.rank-row--first { background: linear-gradient(90deg,#d49c4322,transparent); }
.rank-number { width: 24px; height: 24px; flex: 0 0 24px; display: grid; place-items: center; color: #c8a9a2; font-size: 12px; border-radius: 50%; }.rank-number--1 { background: #dca733; color: #fff; }.rank-number--2 { background: #b17c59; color: #fff; }.rank-number--3 { background: #d9d17b; color: #5a4b27; }.rank-number--4,.rank-number--5 { background: #afbfcb; color: #25394a; }
.rank-identity { flex: 1; min-width: 0; }.rank-identity strong { display: block; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.rank-identity small { color: #c9a7a1; display: block; font-size: 10px; margin-top: 4px; }.rank-money { text-align: right; min-width: 92px; }.rank-money strong { color: #ffe1a2; font-size: 17px; font-variant-numeric: tabular-nums; }.rank-money small { display: block; color: #c9a7a1; font-size: 10px; margin-top: 5px; }
.screen-ranking footer { text-align: center; padding: 12px; border-top: 1px solid #d89d5e30; font-size: 10px; color: #c9a7a1; }.screen-footer { display: flex; justify-content: space-between; gap: 10px; margin-top: 20px; font-size: 10px; color: #bd9291; }.screen-error { background: #fff1e9; border-radius: 6px; color: #9b3535; padding: 10px 14px; margin-bottom: 14px; }.screen-empty { text-align: center; padding: 60px 15px; color: #aa9596; }
.screen-overlay { position: fixed; inset: 0; z-index: 1100; background: #160b15b5; padding: 28px; display: flex; align-items: center; justify-content: center; }.screen-dialog { width: 590px; max-width: 100%; max-height: 88vh; background: #fff; color: #304356; border-radius: 12px; padding: 22px; display: flex; flex-direction: column; box-shadow: 0 20px 70px #160b1577; }.screen-dialog header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }.screen-dialog h2 { color: #223549; margin: 0; font-size: 20px; }.screen-dialog button { border: 1px solid #dde4ec; background: #fff; color: #455a71; padding: 7px 12px; border-radius: 5px; }.screen-dialog header button { border: 0; font-size: 24px; padding: 0 8px; }.screen-dialog p { font-size: 12px; margin-bottom: 12px; }.screen-dialog input { border: 1px solid #dce3eb; border-radius: 5px; padding: 9px 12px; }.screen-dialog .activity-search { width: 100%; }.activity-options { min-height: 140px; max-height: 44vh; overflow-y: auto; padding: 10px 0; }.activity-options label { display: flex; align-items: center; padding: 12px 4px; gap: 10px; border-bottom: 1px solid #edf1f5; cursor: pointer; }.activity-options span { flex: 1; }.activity-options small { color: #8a98a7; }.screen-dialog footer { border-top: 1px solid #e7ecf2; padding-top: 14px; display: flex; gap: 10px; align-items: center; }.screen-dialog footer span { margin-left: auto; font-size: 12px; }.screen-dialog .primary { background: #ad353b; color: #fff; border-color: #ad353b; }.dialog-error { color: #ad353b; padding-top: 10px; }.screen-dialog--details { width: min(1280px,96vw); height: 84vh; }.detail-toolbar { display: flex; align-items: center; justify-content: space-between; padding-bottom: 12px; font-size: 12px; }.detail-table-scroll { flex: 1; min-height: 0; overflow: auto; }.detail-table-scroll table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12px; }.detail-table-scroll th { position: sticky; top: 0; z-index: 2; background: #eef3f8; text-align: left; color: #5a6f86; }.detail-table-scroll th,.detail-table-scroll td { padding: 12px; border-bottom: 1px solid #e8edf3; white-space: nowrap; }.detail-total td { position: sticky; top: 42px; background: #f4f8fd; z-index: 1; color: #1f5f8b; font-weight: bold; }.detail-pagination { justify-content: flex-end; }.source-notes { padding: 0 0 0 18px; overflow: auto; font-size: 13px; line-height: 1.8; }.source-notes li { margin-bottom: 12px; }
@media(min-height:950px) { .is-fullscreen .rank-scroll { height: calc(100vh - 460px); } }
@media(max-width:1000px) { .screen-rankings { grid-template-columns: 1fr; }.rank-scroll { height: 300px; }.screen-heading { align-items: flex-start; }.screen-metric { padding: 18px 14px; }.screen-summary { gap: 12px; } }
@media(max-width:600px) { .combination-screen { padding: 16px; }.screen-heading { flex-direction: column; }.screen-metrics { grid-template-columns: 1fr; }.screen-metric > strong { font-size: 38px; }.screen-footer { flex-direction: column; }.screen-overlay { padding: 12px; }.screen-dialog { padding: 16px; }.detail-toolbar { gap: 8px; align-items: flex-start; } }
</style>
