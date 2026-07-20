<template>
  <div class="biz-dashboard" v-resize="handleResize">
    <Card :bordered="false" dis-hover class="ivu-mt filter-card">
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        inline
        @submit.native.prevent
      >
        <FormItem label="统计时间：">
          <DatePicker
            :editable="false"
            :clearable="false"
            :value="timeVal"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-start"
            placeholder="选择时间"
            class="input-add"
            :options="options"
            transfer
            @on-change="onchangeTime"
          />
        </FormItem>
        <FormItem>
          <Button type="primary" @click="reloadAll">查询 <span class="enter-key">↵</span></Button>
        </FormItem>
      </Form>
    </Card>

    <div class="metric-grid ivu-mt">
      <div
        v-for="(card, index) in cards"
        :key="card.metric_code"
        class="metric-card"
        :class="{ active: selectedMetric === card.metric_code }"
        @click="onSelectMetric(card)"
      >
        <div class="metric-head" @click.stop="onSelectMetric(card)">
          <span class="metric-name">{{ card.name }}</span>
          <Tooltip transfer max-width="360" placement="bottom">
            <Icon type="ios-information-circle-outline" class="tip-icon" @click.stop.native />
            <div slot="content" class="tip-body">
              <p v-if="card.tooltip && card.tooltip.summary">{{ card.tooltip.summary }}</p>
              <p v-if="card.tooltip && card.tooltip.include">包含：{{ card.tooltip.include }}</p>
              <p v-if="card.tooltip && card.tooltip.exclude">不含：{{ card.tooltip.exclude }}</p>
              <p v-if="card.tooltip && card.tooltip.timing">时间：{{ card.tooltip.timing }}</p>
              <p v-if="card.tooltip && card.tooltip.note">{{ card.tooltip.note }}</p>
            </div>
          </Tooltip>
        </div>
        <div class="metric-value-row">
          <span
            class="metric-value"
            :class="'tone-' + (index % 5)"
            @click.stop="goDetail(card)"
          >{{ formatCardValue(card) }}</span>
          <span v-if="card.unit" class="metric-unit">{{ card.unit }}</span>
          <a class="detail-link" @click.stop="goDetail(card)">查看明细</a>
        </div>
      </div>
    </div>

    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="section-head">
        <div class="fonts">营业趋势图 · {{ selectedMetricName }}</div>
        <div class="trend-filters">
          <RadioGroup v-model="trendPreset" type="button" size="small" @on-change="onTrendPresetChange">
            <Radio label="7">近7天</Radio>
            <Radio label="30">近30天</Radio>
            <Radio label="month">本月</Radio>
            <Radio label="custom">自定义</Radio>
          </RadioGroup>
          <DatePicker
            v-if="trendPreset === 'custom'"
            :editable="false"
            :clearable="false"
            :value="trendTimeVal"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-end"
            placeholder="趋势时间"
            class="trend-date"
            transfer
            @on-change="onTrendCustomChange"
          />
        </div>
      </div>
      <Spin v-if="trendLoading" fix />
      <echarts-from
        v-if="infoList"
        ref="visitChart"
        :series="series"
        echartsTitle="inlie"
        :infoList="infoList"
        :yAxisData="yAxisData"
      />
      <div v-else-if="!trendLoading" class="empty-tip">暂无趋势数据</div>
    </Card>

    <Card :bordered="false" dis-hover class="ivu-mt box">
      <div class="section-head">
        <div class="fonts">员工业绩排行</div>
        <div class="rank-filters">
          <span class="rank-label">排序指标</span>
          <Select v-model="rankSortBy" class="rank-select" @on-change="loadRanking">
            <Option value="cash_performance">现金业绩</Option>
            <Option value="labor_performance">劳动业绩</Option>
            <Option value="dianke_count">点客数</Option>
            <Option value="service_customer_count">服务客户数</Option>
            <Option value="service_project_count">服务项目数</Option>
          </Select>
          <RadioGroup v-model="rankSortOrder" type="button" size="small" @on-change="loadRanking">
            <Radio label="desc">降序</Radio>
            <Radio label="asc">升序</Radio>
          </RadioGroup>
        </div>
      </div>
      <Table
        :columns="rankColumns"
        :data="rankList"
        :loading="rankLoading"
        no-data-text="暂无数据"
        highlight-row
      />
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  businessDashboardOverview,
  businessDashboardTrend,
  businessDashboardStaffRanking,
} from '@/api/index';
import echartsFrom from '@/components/echarts/index';
import timeOptions from '@/utils/timeOptions';

const DETAIL_ROUTE = {
  business_money: '/store/home/money-detail',
  customer: '/store/home/new-profile-detail',
  report_sale: '/store/home/source-customer-detail',
  appointment: '/store/home/reservation-detail',
};

export default {
  name: 'home',
  components: { echartsFrom },
  data() {
    const now = new Date();
    const pad = (n) => (n < 10 ? `0${n}` : `${n}`);
    const fmt = (d) => `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())}`;
    const today = fmt(now);
    return {
      options: timeOptions,
      formValidate: {
        data: `${today}-${today}`,
      },
      timeVal: [today, today],
      cards: [],
      selectedMetric: 'cash_performance',
      trendPreset: '30',
      trendTimeVal: [],
      trendData: '',
      series: [],
      yAxisData: [],
      infoList: null,
      trendLoading: false,
      rankSortBy: 'cash_performance',
      rankSortOrder: 'desc',
      rankList: [],
      rankLoading: false,
      rankColumns: [
        { title: '排名', key: 'rank', width: 70 },
        { title: '员工名称', key: 'staff_name', minWidth: 120 },
        { title: '现金业绩', key: 'cash_performance', minWidth: 110 },
        { title: '劳动业绩', key: 'labor_performance', minWidth: 110 },
        { title: '点客数', key: 'dianke_count', minWidth: 90 },
        { title: '服务客户数', key: 'service_customer_count', minWidth: 110 },
        { title: '服务项目数', key: 'service_project_count', minWidth: 110 },
      ],
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
    selectedMetricName() {
      const hit = this.cards.find((c) => c.metric_code === this.selectedMetric);
      return hit ? hit.name : '现金业绩';
    },
  },
  created() {
    this.applyRouteQuery();
    this.initTrendDefault();
    // 门店默认当天（产品确认）
    const now = new Date();
    const pad = (n) => (n < 10 ? `0${n}` : `${n}`);
    const fmt = (d) => `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())}`;
    if (!this.$route.query.data) {
      const today = fmt(now);
      this.timeVal = [today, today];
      this.formValidate.data = `${today}-${today}`;
    }
    this.reloadAll();
  },
  methods: {
    pad2(n) {
      return n < 10 ? `0${n}` : `${n}`;
    },
    formatDate(d) {
      return `${d.getFullYear()}/${this.pad2(d.getMonth() + 1)}/${this.pad2(d.getDate())}`;
    },
    applyRouteQuery() {
      const q = this.$route.query || {};
      if (q.data) {
        this.formValidate.data = q.data;
        this.timeVal = String(q.data).split('-');
      }
      if (q.metric) {
        this.selectedMetric = q.metric;
      }
    },
    initTrendDefault() {
      const end = new Date();
      const start = new Date();
      start.setDate(end.getDate() - 29);
      this.trendTimeVal = [this.formatDate(start), this.formatDate(end)];
      this.trendData = this.trendTimeVal.join('-');
      this.trendPreset = '30';
    },
    formatMoney(v) {
      return Number(v || 0).toFixed(2);
    },
    formatCardValue(card) {
      if (!card) return '0';
      return card.unit === '元' ? this.formatMoney(card.value) : String(card.value == null ? 0 : card.value);
    },
    scopeParams() {
      return { data: this.formValidate.data };
    },
    onchangeTime(e) {
      this.timeVal = e || [];
      if (e && e[0] && e[1]) {
        this.formValidate.data = `${e[0]}-${e[1]}`;
      } else {
        const today = this.formatDate(new Date());
        this.timeVal = [today, today];
        this.formValidate.data = `${today}-${today}`;
      }
      this.reloadAll();
    },
    reloadAll() {
      this.loadOverview();
      this.loadTrend();
      this.loadRanking();
    },
    loadOverview() {
      businessDashboardOverview(this.scopeParams())
        .then((res) => {
          const cards = (res.data && res.data.cards) || [];
          this.cards = cards;
          if (!cards.find((c) => c.metric_code === this.selectedMetric) && cards[0]) {
            this.selectedMetric = cards[0].metric_code;
          }
        })
        .catch((err) => {
          this.cards = [];
          this.$Message.error((err && err.msg) || '概览加载失败');
        });
    },
    onSelectMetric(card) {
      if (!card || !card.metric_code) return;
      this.selectedMetric = card.metric_code;
      this.loadTrend();
    },
    onTrendPresetChange(val) {
      const end = new Date();
      let start = new Date();
      if (val === '7') {
        start.setDate(end.getDate() - 6);
      } else if (val === '30') {
        start.setDate(end.getDate() - 29);
      } else if (val === 'month') {
        start = new Date(end.getFullYear(), end.getMonth(), 1);
      } else {
        return;
      }
      this.trendTimeVal = [this.formatDate(start), this.formatDate(end)];
      this.trendData = this.trendTimeVal.join('-');
      this.loadTrend();
    },
    onTrendCustomChange(e) {
      this.trendTimeVal = e || [];
      if (e && e[0] && e[1]) {
        this.trendData = `${e[0]}-${e[1]}`;
        this.loadTrend();
      }
    },
    loadTrend() {
      if (!this.selectedMetric) return;
      this.trendLoading = true;
      businessDashboardTrend({
        ...this.scopeParams(),
        metric: this.selectedMetric,
        trend_data: this.trendData,
      })
        .then((res) => {
          const data = res.data || {};
          const name = this.selectedMetricName;
          // 标题已含指标名，不传 legend，避免图例压住 Y 轴刻度
          this.infoList = { xAxis: data.xAxis || [], legend: [] };
          this.series = [{
            name,
            type: 'line',
            smooth: true,
            data: data.series || [],
            itemStyle: { normal: { color: '#1495EB' } },
          }];
          this.yAxisData = [{
            type: 'value',
            name: data.unit || '',
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { textStyle: { color: '#7F8B9C' } },
            splitLine: { show: true, lineStyle: { color: '#F5F7F9' } },
          }];
        })
        .catch((err) => {
          this.infoList = null;
          this.series = [];
          this.$Message.error((err && err.msg) || '趋势加载失败');
        })
        .finally(() => {
          this.trendLoading = false;
        });
    },
    loadRanking() {
      this.rankLoading = true;
      businessDashboardStaffRanking({
        ...this.scopeParams(),
        sort_by: this.rankSortBy || 'cash_performance',
        sort_order: this.rankSortOrder || 'desc',
      })
        .then((res) => {
          const list = (res.data && res.data.list) || [];
          this.rankList = list.map((row) => ({
            ...row,
            cash_performance: this.formatMoney(row.cash_performance),
            labor_performance: this.formatMoney(row.labor_performance),
          }));
        })
        .catch((err) => {
          this.rankList = [];
          this.$Message.error((err && err.msg) || '排行加载失败');
        })
        .finally(() => {
          this.rankLoading = false;
        });
    },
    goDetail(card) {
      if (!card) return;
      const detailType = card.detail_type || '';
      const query = {
        data: this.formValidate.data,
        metric: card.metric_code,
        detail_type: detailType,
        from: 'business_dashboard',
      };
      const path = DETAIL_ROUTE[detailType];
      if (!path) {
        this.$Message.warning('暂无对应明细入口');
        return;
      }
      this.$router.push({ path, query }).catch(() => {});
    },
    handleResize() {
      if (this.$refs.visitChart && this.$refs.visitChart.handleResize) {
        this.$refs.visitChart.handleResize();
      }
    },
  },
};
</script>

<style scoped lang="less">
.input-add { width: 250px; }
.enter-key { margin-left: 2px; font-weight: 600; }
.filter-card {
  /deep/ .ivu-form-item { margin-bottom: 0 !important; }
}
.metric-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
}
.metric-card {
  background: #fff;
  border: 1px solid #eef0f4;
  border-radius: 6px;
  padding: 14px 16px;
  cursor: pointer;
  min-height: 96px;
}
.metric-card.active {
  border-color: #2d8cf0;
  box-shadow: 0 0 0 2px rgba(45, 140, 240, 0.12);
}
.metric-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.metric-name { font-size: 13px; color: #515a6e; font-weight: 500; }
.tip-icon { color: #808695; font-size: 16px; }
.tip-body p { margin: 0 0 6px; line-height: 1.5; }
.metric-value-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; }
.metric-value { font-size: 22px; font-weight: 600; cursor: pointer; }
.metric-value:hover { text-decoration: underline; }
.tone-0 { color: #2d8cf0; }
.tone-1 { color: #19be6b; }
.tone-2 { color: #ff9900; }
.tone-3 { color: #ed4014; }
.tone-4 { color: #9b59b6; }
.metric-unit { color: #808695; font-size: 12px; }
.detail-link { margin-left: auto; font-size: 12px; }
.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}
.fonts { font-weight: bold; }
.trend-filters, .rank-filters {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.trend-date { width: 230px; }
.rank-label { color: #808695; font-size: 12px; }
.rank-select { width: 150px; }
.empty-tip { padding: 40px 0; text-align: center; color: #c5c8ce; }
.box { padding-bottom: 24px; }
@media (max-width: 1400px) {
  .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
