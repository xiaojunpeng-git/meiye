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
        <FormItem label="组织：">
          <Select
            v-model="formValidate.org_id"
            filterable
            class="input-add"
            placeholder="组织节点"
            @on-change="onScopeChange"
          >
            <Option
              v-for="item in orgOptions"
              :key="item.id"
              :value="item.id"
              :label="item.label"
            >{{ item.label }}</Option>
          </Select>
        </FormItem>
        <FormItem label="门店：">
          <Select
            v-model="formValidate.store_id"
            clearable
            filterable
            class="input-add"
            placeholder="全部下属门店"
            @on-change="onScopeChange"
          >
            <Option
              v-for="item in storeList"
              :key="item.id"
              :value="item.id"
            >{{ item.name }}</Option>
          </Select>
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
        <div class="fonts">门店业绩排行</div>
        <div class="rank-filters">
          <span class="rank-label">排序指标</span>
          <Select v-model="rankSortBy" class="rank-select" @on-change="loadRanking">
            <Option
              v-for="card in cards"
              :key="card.metric_code"
              :value="card.metric_code"
            >{{ card.name }}</Option>
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
        no-filtered-data-text="暂无筛选结果"
      />
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import Setting from '@/setting';
import {
  businessDashboardOverview,
  businessDashboardTrend,
  businessDashboardStoreRanking,
  getOrganizationTree,
  staffListInfo,
} from '@/api/store';
import echartsFrom from '@/components/echarts/index';
import timeOptions from '@/utils/timeOptions';

const DETAIL_ROUTE = {
  business_money: `${Setting.roterPre}/store/statistics/money-detail`,
  customer: `${Setting.roterPre}/store/statistics/new-profile-detail`,
  report_sale: `${Setting.roterPre}/store/statistics/source-customer-detail`,
  appointment: `${Setting.roterPre}/store/statistics/reservation-detail`,
};

export default {
  name: 'store_statistics',
  components: { echartsFrom },
  data() {
    const now = new Date();
    const pad = (n) => (n < 10 ? `0${n}` : `${n}`);
    const fmt = (d) => `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())}`;
    const monthRange = [fmt(new Date(now.getFullYear(), now.getMonth(), 1)), fmt(now)];
    return {
      options: timeOptions,
      formValidate: {
        data: monthRange.join('-'),
        org_id: 0,
        store_id: 0,
      },
      timeVal: monthRange,
      orgOptions: [],
      storeList: [],
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
      scopeReady: false,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
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
    rankColumns() {
      const moneyCodes = [
        'cash_performance',
        'actual_performance',
        'consume_amount',
        'refund_amount',
        'recharge_amount',
        'balance_deduction_amount',
      ];
      const nameMap = {};
      this.cards.forEach((c) => {
        nameMap[c.metric_code] = c.name;
      });
      const cols = [
        { title: '排名', key: 'rank', width: 70, fixed: 'left' },
        { title: '门店名称', key: 'store_name', minWidth: 140, fixed: 'left' },
      ];
      const codes = [
        'cash_performance',
        'actual_performance',
        'consume_amount',
        'refund_amount',
        'recharge_amount',
        'balance_deduction_amount',
        'new_profile_count',
        'casual_customer_count',
        'new_customer_count',
        'reservation_customer',
      ];
      codes.forEach((code) => {
        cols.push({
          title: nameMap[code] || code,
          key: code,
          minWidth: 110,
          render: (h, { row }) => {
            const v = row[code];
            const text = moneyCodes.includes(code)
              ? this.formatMoney(v)
              : String(v == null ? 0 : v);
            return h('span', text);
          },
        });
      });
      return cols;
    },
  },
  created() {
    this.applyRouteQuery();
    this.initTrendDefault();
    this.bootstrap();
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
      if (q.org_id) {
        this.formValidate.org_id = Number(q.org_id) || 0;
      }
      if (q.store_id) {
        this.formValidate.store_id = Number(q.store_id) || 0;
      }
      if (q.metric) {
        this.selectedMetric = q.metric;
        this.rankSortBy = q.metric;
      }
    },
    buildMonthRange() {
      const now = new Date();
      const start = new Date(now.getFullYear(), now.getMonth(), 1);
      return [this.formatDate(start), this.formatDate(now)];
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
      const n = Number(v || 0);
      return n.toFixed(2);
    },
    formatCardValue(card) {
      if (!card) return '0';
      const money = ['元'].includes(card.unit);
      return money ? this.formatMoney(card.value) : String(card.value == null ? 0 : card.value);
    },
    scopeParams() {
      const params = {
        data: this.formValidate.data,
        org_id: this.formValidate.org_id || 0,
      };
      if (this.formValidate.store_id) {
        params.store_id = this.formValidate.store_id;
      }
      return params;
    },
    flattenOrg(nodes, level = 0, out = []) {
      (nodes || []).forEach((node) => {
        const id = Number(node.id);
        if (id > 0) {
          const name = node.name || node.title || `组织${id}`;
          out.push({
            id,
            label: `${'　'.repeat(level)}${name}`,
          });
        }
        if (node.children && node.children.length) {
          this.flattenOrg(node.children, level + 1, out);
        }
      });
      return out;
    },
    bootstrap() {
      Promise.all([
        getOrganizationTree().catch((err) => {
          this.$Message.error((err && err.msg) || '组织树加载失败');
          return { data: [] };
        }),
        staffListInfo().catch(() => ({ data: [] })),
      ]).then(([orgRes, storeRes]) => {
        const tree = orgRes.data || [];
        this.orgOptions = this.flattenOrg(tree);
        const rootId = this.orgOptions.length ? this.orgOptions[0].id : 0;
        if (!this.formValidate.org_id) {
          this.formValidate.org_id = rootId;
        }
        this.storeList = storeRes.data || [];
        this.scopeReady = true;
        this.reloadAll();
      });
    },
    onScopeChange() {
      if (!this.scopeReady) return;
      this.reloadAll();
    },
    onchangeTime(e) {
      this.timeVal = e || [];
      if (e && e[0] && e[1]) {
        this.formValidate.data = `${e[0]}-${e[1]}`;
      } else {
        const monthRange = this.buildMonthRange();
        this.timeVal = monthRange;
        this.formValidate.data = monthRange.join('-');
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
          if (!cards.find((c) => c.metric_code === this.rankSortBy) && cards[0]) {
            this.rankSortBy = cards[0].metric_code;
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
      const params = {
        ...this.scopeParams(),
        metric: this.selectedMetric,
        trend_data: this.trendData,
      };
      businessDashboardTrend(params)
        .then((res) => {
          const data = res.data || {};
          const xAxis = data.xAxis || [];
          const seriesData = data.series || [];
          const name = data.metric_code
            ? (this.cards.find((c) => c.metric_code === data.metric_code) || {}).name || '指标'
            : this.selectedMetricName;
          // 标题已含指标名，不传 legend，避免图例压住 Y 轴刻度
          this.infoList = {
            xAxis,
            legend: [],
          };
          this.series = [
            {
              name,
              type: 'line',
              smooth: true,
              data: seriesData,
              itemStyle: { normal: { color: '#1495EB' } },
            },
          ];
          this.yAxisData = [
            {
              type: 'value',
              name: data.unit || '',
              axisLine: { show: false },
              axisTick: { show: false },
              axisLabel: { textStyle: { color: '#7F8B9C' } },
              splitLine: {
                show: true,
                lineStyle: { color: '#F5F7F9' },
              },
            },
          ];
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
      businessDashboardStoreRanking({
        ...this.scopeParams(),
        sort_by: this.rankSortBy || 'cash_performance',
        sort_order: this.rankSortOrder || 'desc',
      })
        .then((res) => {
          this.rankList = (res.data && res.data.list) || [];
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
        org_id: this.formValidate.org_id || 0,
        metric: card.metric_code,
        detail_type: detailType,
        from: 'business_dashboard',
      };
      if (this.formValidate.store_id) {
        query.store_id = this.formValidate.store_id;
      }
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
.input-add {
  width: 250px;
}
.enter-key {
  margin-left: 2px;
  font-weight: 600;
}
.filter-card {
  /deep/ .ivu-form-item {
    margin-bottom: 0 !important;
  }
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
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  min-height: 96px;
}
.metric-card:hover {
  border-color: #b7d4f5;
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
.metric-name {
  font-size: 13px;
  color: #515a6e;
  font-weight: 500;
}
.tip-icon {
  color: #808695;
  font-size: 16px;
}
.tip-body p {
  margin: 0 0 6px;
  line-height: 1.5;
}
.metric-value-row {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 6px;
}
.metric-value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.2;
  cursor: pointer;
}
.metric-value:hover {
  text-decoration: underline;
}
.tone-0 { color: #2d8cf0; }
.tone-1 { color: #19be6b; }
.tone-2 { color: #ff9900; }
.tone-3 { color: #ed4014; }
.tone-4 { color: #9b59b6; }
.metric-unit {
  color: #808695;
  font-size: 12px;
}
.detail-link {
  margin-left: auto;
  font-size: 12px;
}
.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}
.fonts {
  font-weight: bold;
}
.trend-filters,
.rank-filters {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.trend-date {
  width: 230px;
}
.rank-label {
  color: #808695;
  font-size: 12px;
}
.rank-select {
  width: 150px;
}
.empty-tip {
  padding: 40px 0;
  text-align: center;
  color: #c5c8ce;
}
.box {
  padding-bottom: 24px;
}
@media (max-width: 1400px) {
  .metric-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (max-width: 900px) {
  .metric-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
