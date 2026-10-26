<template>
  <div>
    <Card :bordered="false" dis-hover :padding="16">
      <!-- 页面头部操作按钮 -->
      <div class="page-header-actions">
        <Button type="primary" class="header-action-btn" @click="goToDetail">
          <Icon type="md-calendar" /> 月度目标明细
        </Button>
        <Button type="warning" class="header-action-btn" @click="goToStoreDetail">
          <Icon type="md-store" /> 门店目标明细
        </Button>
        <Button class="header-action-btn" @click="refreshData">
          <Icon type="md-refresh" /> 刷新数据
        </Button>
      </div>

      <!-- 主布局 -->
      <div class="main-layout">
        <!-- 左侧筛选面板 -->
        <div class="filter-sidebar">
          <div class="filter-section">
            <div class="filter-title"><Icon type="md-calendar" /> 时间范围</div>
            <div class="time-filter">
              <DatePicker type="month" v-model="startTime" placeholder="开始月份" style="width: 100%;" @on-change="onTimeChange"></DatePicker>
              <DatePicker type="month" v-model="endTime" placeholder="结束月份" style="width: 100%;" @on-change="onTimeChange"></DatePicker>
            </div>
          </div>

          <div class="filter-section">
            <div class="filter-title"><Icon type="md-bullseye" /> 目标对象</div>
            <div class="target-object-select" @click="openTargetObjectModal">
              <span>{{ selectedTargetObject }}</span>
              <Icon type="ios-arrow-down" />
            </div>
          </div>

          <div class="filter-section">
            <div class="filter-title"><Icon type="md-bar-chart" /> 配置指标</div>
            <div class="target-object-select" @click="openMetricConfigModal">
              <span>已选{{ selectedMetrics.length }}项指标</span>
              <Icon type="ios-arrow-down" />
            </div>
          </div>
        </div>

        <!-- 主内容区 -->
        <div class="main-content">
          <!-- 数据概览 - 各指标卡片 -->
          <div class="overview-section">
            <div class="section-header">
              <div class="section-title">
                📊 指标完成情况
                <span style="font-size: 13px; color: #999; font-weight: normal;">（点击指标卡片查看详情）</span>
              </div>
            </div>
            <div v-if="loading" style="padding: 40px; text-align: center; color: #999;">数据加载中...</div>
            <div v-else-if="!selectedMetrics.length" style="padding: 40px; text-align: center; color: #999;">暂无指标数据</div>
            <div v-else class="overview-cards">
              <div
                class="overview-card"
                :class="{ selected: selectedMetric === metric.key }"
                v-for="metric in selectedMetrics"
                :key="metric.key"
                @click="selectMetric(metric.key)"
              >
                <div class="overview-card-header">
                  <span class="overview-card-title">{{ metric.name }}</span>
                  <div class="overview-card-icon" :class="metric.bgClass">
                    <Icon :type="metric.icon" />
                  </div>
                </div>
                <div class="overview-card-value">{{ metric.value }}</div>
                <div class="overview-card-footer">
                  <span style="color: #999;">目标: {{ metric.target }}</span>
                  <span class="rate-badge" :class="getRateClass(metric.rate)" style="margin-left: auto;">{{ metric.rate }}%</span>
                </div>
              </div>
            </div>
          </div>

          <!-- 趋势图表 -->
          <div class="chart-section">
            <div class="chart-header">
              <div class="section-title">
                📈
                <span style="color: #8B5CF6; font-weight: 700;">{{ currentMetricName }}</span>
                趋势分析
              </div>
              <div class="chart-tabs">
                <div class="chart-tab" :class="{ active: compareType === 'yoy' }" @click="switchCompareType('yoy')">同比</div>
                <div class="chart-tab" :class="{ active: compareType === 'mom' }" @click="switchCompareType('mom')">环比</div>
              </div>
            </div>
            <div class="chart-container">
              <svg
                class="mock-chart"
                width="100%"
                height="100%"
                :viewBox="'0 0 ' + chartData.width + ' ' + chartData.height"
                preserveAspectRatio="xMidYMid meet"
                style="background: #fafafa; border-radius: 8px;"
              >
                <g stroke="#e0e0e0" stroke-width="1">
                  <line
                    v-for="(g, gi) in chartGridLines"
                    :key="'grid-' + gi"
                    :x1="chartData.padding.left"
                    :y1="g"
                    :x2="chartData.width - chartData.padding.right"
                    :y2="g"
                  />
                </g>
                <text
                  v-for="(yl, yi) in chartData.yLabels"
                  :key="'yl-' + yi"
                  :x="chartData.padding.left - 10"
                  :y="yl.y + 4"
                  fill="#999"
                  font-size="12"
                  text-anchor="end"
                >{{ yl.value }}</text>
                <text
                  v-for="(ml, mi) in chartMonthLabels"
                  :key="'ml-' + mi"
                  :x="ml.x"
                  :y="chartData.height - 15"
                  fill="#666"
                  font-size="12"
                  text-anchor="middle"
                >{{ ml.label }}</text>
                <polyline
                  fill="none"
                  stroke="#10B981"
                  stroke-width="2"
                  stroke-dasharray="5,5"
                  :points="comparePolyline"
                />
                <polyline
                  fill="none"
                  stroke="#8B5CF6"
                  stroke-width="3"
                  :points="currentPolyline"
                />
                <g fill="#10B981">
                  <circle
                    v-for="(p, pi) in chartData.comparePts"
                    :key="'cp-' + pi"
                    :cx="p.x"
                    :cy="p.y"
                    r="5"
                  />
                </g>
                <g fill="#8B5CF6">
                  <circle
                    v-for="(p, pi) in chartData.currentPts"
                    :key="'ct-' + pi"
                    :cx="p.x"
                    :cy="p.y"
                    r="6"
                  />
                </g>
                <g :transform="'translate(' + (chartData.width - 200) + ', 15)'">
                  <line x1="0" y1="0" x2="30" y2="0" stroke="#8B5CF6" stroke-width="3" />
                  <circle cx="15" cy="0" r="4" fill="#8B5CF6" />
                  <text x="40" y="5" fill="#666" font-size="12">本期</text>
                  <line x1="100" y1="0" x2="130" y2="0" stroke="#10B981" stroke-width="2" stroke-dasharray="5,5" />
                  <circle cx="115" cy="0" r="4" fill="#10B981" />
                  <text x="140" y="5" fill="#666" font-size="12">{{ compareLegendLabel }}</text>
                </g>
              </svg>
            </div>
          </div>

          <!-- 表格区域 -->
          <div class="table-section">
            <div class="table-header">
              <div style="display: flex; align-items: center; gap: 16px;">
                <div class="section-title" style="font-size: 16px;">
                  <span style="color: #8B5CF6; font-weight: 700;">{{ currentMetricName }}</span>
                  <span>{{ tableTab === 'monthly' ? '每月完成情况' : (tableTab === 'store' ? '门店排行' : '员工排行') }}</span>
                </div>
                <div class="table-tabs">
                  <div class="table-tab" :class="{ active: tableTab === 'monthly' }" @click="switchTableTab('monthly')">每月完成情况</div>
                  <div class="table-tab" :class="{ active: tableTab === 'store' }" @click="switchTableTab('store')">门店排行</div>
                  <div class="table-tab" :class="{ active: tableTab === 'employee' }" @click="switchTableTab('employee')">员工排行</div>
                </div>
              </div>
            </div>

            <!-- 每月完成情况 -->
            <div v-if="tableTab === 'monthly'">
              <div class="dynamic-calc-info">
                <Icon type="ios-information-circle" />
                <span>动态目标计算: 每月根据剩余目标除以剩余月份自动调整月度目标。例如: 年度目标1200万，那么每月需要完成100万，首月完成78万，那还剩余22万没有完成，剩余22万需在11个月内完成，则每月需要多完成2万，剩余的每月目标就是原目标值+2万，以此类推。</span>
              </div>
              <div class="monthly-list">
                <div class="monthly-item" v-for="item in monthlyData" :key="item.month">
                  <div class="monthly-item-header">
                    <span class="monthly-item-name">{{ item.month }}月</span>
                    <span class="monthly-item-status" :class="getStatusClass(item.rate)">{{ getStatusText(item.rate) }}</span>
                  </div>
                  <div class="monthly-item-values">
                    <div class="monthly-item-value">
                      <div class="monthly-item-label">目标</div>
                      <div class="monthly-item-number">{{ formatMetricValue(item.target, selectedMetricUnit) }}</div>
                    </div>
                    <div class="monthly-item-value">
                      <div class="monthly-item-label">完成</div>
                      <div class="monthly-item-number completed">{{ formatMetricValue(item.completed, selectedMetricUnit) }}</div>
                    </div>
                  </div>
                  <div class="monthly-item-progress">
                    <div class="monthly-item-progress-bar" :class="getProgressClass(item.rate)" :style="{ width: item.rate + '%' }"></div>
                  </div>
                  <div class="monthly-item-rate" :class="getRateClass(item.rate)">{{ item.rate }}%</div>
                </div>
              </div>
            </div>

            <!-- 门店/员工排行表格 -->
            <div v-else>
              <Table :columns="tableColumns" :data="rankingTableData" :loading="rankingLoading" :border="false"></Table>
              <div class="pagination" v-if="rankingTotal > 0">
                <Page
                  :total="rankingTotal"
                  :current="rankingPage"
                  :page-size="rankingLimit"
                  show-total
                  show-elevator
                  @on-change="onRankingPageChange"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </Card>

    <!-- 目标对象选择弹窗 -->
    <Modal
      v-model="targetObjectModalVisible"
      title="选择目标对象"
      width="400"
      :footer-hide="true"
    >
      <div style="display: flex; flex-direction: column; gap: 8px;">
        <div
          class="target-object-option"
          :class="{ active: tempStoreOption && tempStoreOption.id === opt.id && tempStoreOption.object_type === opt.object_type }"
          v-for="opt in storeOptions"
          :key="opt.object_type + '_' + opt.id"
          @click="pickStoreOption(opt)"
        >
          <span>{{ opt.name }}</span>
          <Icon type="ios-checkmark" v-if="tempStoreOption && tempStoreOption.id === opt.id && tempStoreOption.object_type === opt.object_type" />
        </div>
      </div>
      <div style="margin-top: 16px;">
        <Button type="primary" long @click="confirmTargetObject">确定</Button>
      </div>
    </Modal>

    <!-- 指标配置弹窗 -->
    <Modal
      v-model="metricConfigModalVisible"
      title="配置指标"
      width="450"
      :footer-hide="true"
    >
      <div class="metric-modal-body">
        <div class="metric-group-title">核心指标</div>
        <div class="metric-checkbox-list">
          <label class="metric-checkbox-item" v-for="metric in coreMetrics" :key="metric.key">
            <div class="metric-checkbox-row" @click="toggleMetric(metric.key)">
              <div class="metric-checkbox-box" :class="{ checked: isMetricSelected(metric.key) }">
                <Icon type="ios-checkmark" v-if="isMetricSelected(metric.key)" />
              </div>
              <span>{{ metric.name }}</span>
            </div>
          </label>
        </div>

        <div class="metric-group-title">商品指标</div>
        <div class="product-metric-tip">
          <Icon type="ios-information-circle" />
          <span>商品指标根据所选时间范围和目标对象自动加载已设置的目标</span>
        </div>
        <div class="metric-checkbox-list">
          <label class="metric-checkbox-item" v-for="metric in productMetrics" :key="metric.key">
            <div class="metric-checkbox-row" @click="toggleMetric(metric.key)">
              <div class="metric-checkbox-box" :class="{ checked: isMetricSelected(metric.key) }">
                <Icon type="ios-checkmark" v-if="isMetricSelected(metric.key)" />
              </div>
              <span>{{ metric.name }}</span>
            </div>
          </label>
        </div>
      </div>
      <div class="metric-modal-footer">
        <Button @click="metricConfigModalVisible = false">取消</Button>
        <Button type="primary" @click="confirmMetricConfig">确定 ({{ selectedMetrics.length }})</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  targetAnalysis,
  targetMetricOptions,
  targetRanking,
  targetStoreOptions
} from '@/api/target';
import { buildTrendChartPaths, isTargetAchieved, getTargetAchieveResultText } from './chartUtil';

const METRIC_UI = {
  revenue: { icon: 'md-cash', bgClass: 'bg-purple', label: '实际收款金额' },
  consume: { icon: 'md-flame', bgClass: 'bg-orange', label: '消耗金额' },
  new_customer: { icon: 'md-person-add', bgClass: 'bg-green', label: '新客数' },
  old_customer: { icon: 'md-people', bgClass: 'bg-blue', label: '老客数' },
  service: { icon: 'md-hand', bgClass: 'bg-pink', label: '服务客次' },
  point: { icon: 'md-finger-print', bgClass: 'bg-cyan', label: '点客数' },
  book: { icon: 'md-calendar', bgClass: 'bg-indigo', label: '预约次数' }
};

const METRIC_CONFIG_KEY = 'target_analysis_metric_keys';

function padMonth(n) {
  return n < 10 ? '0' + n : String(n);
}

function defaultMonthDate(offsetMonth) {
  const now = new Date();
  now.setMonth(now.getMonth() + offsetMonth);
  return new Date(now.getFullYear(), now.getMonth(), 1);
}

function formatProductMetricName(p) {
  if (p.display_name) return p.display_name;
  const pn = p.product_name
    || (p.product_items && p.product_items[0] && p.product_items[0].product_name);
  if (pn) return pn.endsWith('目标') ? pn : pn + '目标';
  return '品项目标';
}

export default {
  name: 'target_data_analysis',
  data() {
    return {
      loading: false,
      rankingLoading: false,
      startTime: new Date(new Date().getFullYear(), 0, 1),
      endTime: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
      selectedTargetObject: '全部门店',
      filterStoreId: 0,
      filterObjectType: 3,
      storeOptions: [],
      tempStoreOption: null,
      targetObjectModalVisible: false,
      metricConfigModalVisible: false,
      selectedMetric: 'revenue',
      compareType: 'yoy',
      tableTab: 'monthly',
      coreMetrics: [],
      productMetrics: [],
      selectedMetricKeys: [],
      selectedMetrics: [],
      trendData: { labels: [], current: [], yoy: [], mom: [] },
      monthlyData: [],
      storeRanking: [],
      employeeRanking: [],
      rankingPage: 1,
      rankingTotal: 0,
      rankingLimit: 10,
      primaryTargetId: 0
    };
  },
  computed: {
    currentMetricName() {
      const metric = this.selectedMetrics.find((m) => m.key === this.selectedMetric);
      return metric ? metric.name : '指标';
    },
    selectedMetricUnit() {
      const metric = this.selectedMetrics.find((m) => m.key === this.selectedMetric);
      return metric ? metric.unit : '元';
    },
    compareLegendLabel() {
      return this.compareType === 'yoy' ? '去年同期' : '上月';
    },
    compareSeries() {
      if (!this.trendData) return [];
      return this.compareType === 'yoy' ? (this.trendData.yoy || []) : (this.trendData.mom || []);
    },
    chartData() {
      const current = this.trendData.current || [];
      const compare = this.compareSeries;
      return buildTrendChartPaths(current, compare);
    },
    chartGridLines() {
      const { padding, height } = this.chartData;
      const chartHeight = height - padding.top - padding.bottom;
      return [0, 1, 2, 3, 4].map((i) => padding.top + (i / 4) * chartHeight);
    },
    chartMonthLabels() {
      const labels = this.trendData.labels || [];
      const { padding, width } = this.chartData;
      const chartWidth = width - padding.left - padding.right;
      return labels.map((label, index) => ({
        label,
        x: padding.left + (index / Math.max(labels.length - 1, 1)) * chartWidth
      }));
    },
    currentPolyline() {
      return (this.chartData.currentPts || []).map((p) => p.x + ',' + p.y).join(' ');
    },
    comparePolyline() {
      return (this.chartData.comparePts || []).map((p) => p.x + ',' + p.y).join(' ');
    },
    rankingTableData() {
      const list = this.tableTab === 'employee' ? this.employeeRanking : this.storeRanking;
      const baseRank = (this.rankingPage - 1) * this.rankingLimit;
      return (list || []).map((row, index) => {
        const rankNum = baseRank + index + 1;
        return {
          rank: rankNum <= 3 ? ['🥇', '🥈', '🥉'][rankNum - 1] : String(rankNum),
          name: row.name || row.store_name || row.staff_name,
          target: this.formatMetricValue(row.target != null ? row.target : row.target_value, this.selectedMetricUnit),
          completed: this.formatMetricValue(row.completed != null ? row.completed : row.completed_value, this.selectedMetricUnit),
          rate: (row.rate != null ? row.rate : 0) + '%',
          yoy: this.formatTrend(row.yoy),
          mom: this.formatTrend(row.mom)
        };
      });
    },
    tableColumns() {
      const amountTitle = this.selectedMetricUnit === '元' ? '金额' : '数值';
      return [
        { title: '排名', key: 'rank', width: 80 },
        { title: '名称', key: 'name', minWidth: 150 },
        { title: '目标' + amountTitle, key: 'target' },
        { title: '完成' + amountTitle, key: 'completed' },
        { title: '达成率', key: 'rate' },
        { title: '同比', key: 'yoy' },
        { title: '环比', key: 'mom' }
      ];
    }
  },
  watch: {
    selectedMetric() {
      this.rankingPage = 1;
      this.loadAnalysis();
    },
    compareType() {
      // 图表使用 computed 自动切换
    }
  },
  mounted() {
    this.loadMetricOptions();
    this.loadStoreOptions();
    this.loadAnalysis();
  },
  methods: {
    formatMonthParam(val) {
      if (!val) return '';
      if (typeof val === 'string') return val.length >= 7 ? val.slice(0, 7) : val;
      const d = val instanceof Date ? val : new Date(val);
      return d.getFullYear() + '-' + padMonth(d.getMonth() + 1);
    },
    formatMetricValue(val, unit) {
      const n = Number(val || 0);
      if (unit === '元' && Math.abs(n) >= 10000) {
        return (n / 10000).toFixed(1) + '万';
      }
      if (unit === '元') {
        return n.toLocaleString('zh-CN') + '元';
      }
      return n.toLocaleString('zh-CN') + (unit || '');
    },
    formatTrend(val) {
      const n = Number(val || 0);
      return (n >= 0 ? '+' : '') + n + '%';
    },
    getRateClass(rate) {
      return isTargetAchieved(rate) ? 'rate-high' : 'rate-low';
    },
    getProgressClass(rate) {
      return isTargetAchieved(rate) ? 'progress-green' : 'progress-red';
    },
    getStatusClass(rate) {
      return isTargetAchieved(rate) ? 'completed' : 'unachieved';
    },
    getStatusText(rate) {
      return getTargetAchieveResultText(rate);
    },
    loadMetricOptions() {
      targetMetricOptions()
        .then((res) => {
          const core = (res.data && res.data.core_metrics) || [];
          this.coreMetrics = core.map((m) => ({
            key: m.key,
            name: (METRIC_UI[m.key] && METRIC_UI[m.key].label) || m.name,
            icon: (METRIC_UI[m.key] && METRIC_UI[m.key].icon) || 'md-stats',
            bgClass: (METRIC_UI[m.key] && METRIC_UI[m.key].bgClass) || 'bg-purple',
            unit: m.unit || '元'
          }));
          const saved = localStorage.getItem(METRIC_CONFIG_KEY);
          if (saved) {
            try {
              const keys = JSON.parse(saved);
              if (Array.isArray(keys) && keys.length) {
                this.selectedMetricKeys = keys;
              }
            } catch (e) {
              /* ignore */
            }
          }
          if (!this.selectedMetricKeys.length) {
            this.selectedMetricKeys = this.coreMetrics.map((m) => m.key);
          }
        })
        .catch(() => {});
    },
    loadStoreOptions() {
      targetStoreOptions()
        .then((res) => {
          this.storeOptions = (res.data && Array.isArray(res.data)) ? res.data : [];
          if (!this.storeOptions.length) return;
          const current = this.storeOptions.find(
            (o) => o.id === this.filterStoreId && o.object_type === this.filterObjectType
          ) || this.storeOptions[0];
          this.selectedTargetObject = current.name;
          this.filterStoreId = current.id;
          this.filterObjectType = current.object_type;
          this.tempStoreOption = current;
        })
        .catch(() => {});
    },
    buildQueryParams(extra) {
      return Object.assign({
        start_month: this.formatMonthParam(this.startTime),
        end_month: this.formatMonthParam(this.endTime),
        store_id: this.filterStoreId || '',
        object_type: this.filterObjectType,
        metric_key: this.selectedMetric,
        year: this.formatMonthParam(this.startTime).split('-')[0]
      }, extra || {});
    },
    parseRankingResponse(res) {
      const d = res.data;
      if (d && Array.isArray(d.list)) {
        return {
          list: d.list,
          count: Number(d.count || 0),
          page: Number(d.page || 1)
        };
      }
      if (Array.isArray(d)) {
        return { list: d, count: d.length, page: 1 };
      }
      return { list: [], count: 0, page: 1 };
    },
    loadAnalysis() {
      this.loading = true;
      const params = this.buildQueryParams();
      targetAnalysis(params)
        .then((res) => {
          const d = res.data || {};
          this.primaryTargetId = d.primary_target_id || 0;
          this.trendData = d.trend || { labels: [], current: [], yoy: [], mom: [] };
          this.monthlyData = d.monthly || [];
          this.applyMetricsFromApi(d.metrics || [], d.products || []);
          if (this.tableTab === 'store' || this.tableTab === 'employee') {
            this.loadRanking();
          }
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '加载分析数据失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    applyMetricsFromApi(metrics, products) {
      const all = [];
      const productMetricList = [];
      const productKey = (p) => {
        if (p.allocate_ref_key) return p.allocate_ref_key;
        return 'product_' + (p.product_id || 0) + '_' + (p.metric_key || 'revenue');
      };
      (metrics || []).forEach((m) => {
        const ui = METRIC_UI[m.metric_key] || {};
        all.push({
          key: m.metric_key,
          name: m.metric_name || ui.label || m.metric_key,
          icon: ui.icon || 'md-stats',
          bgClass: ui.bgClass || 'bg-purple',
          unit: m.unit || '元',
          rawCompleted: Number(m.completed_value || 0),
          rawTarget: Number(m.target_value || 0),
          rate: Number(m.rate || 0)
        });
      });
      (products || []).forEach((p) => {
        const key = productKey(p);
        const name = formatProductMetricName(p);
        productMetricList.push({
          key: key,
          name: name,
          unit: p.unit || '元'
        });
        all.push({
          key: key,
          name: name,
          icon: 'md-leaf',
          bgClass: 'bg-purple',
          unit: p.unit || '元',
          rawCompleted: Number(p.completed_value || 0),
          rawTarget: Number(p.target_value || 0),
          rate: Number(p.rate || 0)
        });
      });
      this.productMetrics = productMetricList;
      const keys = this.selectedMetricKeys.length
        ? this.selectedMetricKeys
        : all.map((m) => m.key);
      this.selectedMetrics = all
        .filter((m) => keys.indexOf(m.key) >= 0)
        .map((m) => ({
          ...m,
          value: this.formatMetricValue(m.rawCompleted, m.unit),
          target: this.formatMetricValue(m.rawTarget, m.unit)
        }));
      if (!this.selectedMetrics.length && all.length) {
        this.selectedMetrics = all.map((m) => ({
          ...m,
          value: this.formatMetricValue(m.rawCompleted, m.unit),
          target: this.formatMetricValue(m.rawTarget, m.unit)
        }));
      }
      if (this.selectedMetrics.length && !this.selectedMetrics.find((m) => m.key === this.selectedMetric)) {
        this.selectedMetric = this.selectedMetrics[0].key;
      }
    },
    loadRanking() {
      if (this.tableTab !== 'store' && this.tableTab !== 'employee') {
        return;
      }
      this.rankingLoading = true;
      const rankType = this.tableTab === 'store' ? 'store' : 'employee';
			const params = this.buildQueryParams({
        rank_type: rankType,
        page: this.rankingPage,
        limit: this.rankingLimit
      });
      if (this.primaryTargetId > 0) {
        params.target_id = this.primaryTargetId;
      }
      targetRanking(params)
        .then((res) => {
          const parsed = this.parseRankingResponse(res);
          this.rankingTotal = parsed.count;
          this.rankingPage = parsed.page;
          if (this.tableTab === 'store') {
            this.storeRanking = parsed.list;
          } else {
            this.employeeRanking = parsed.list;
          }
        })
        .catch(() => {
          this.rankingTotal = 0;
          if (this.tableTab === 'store') {
            this.storeRanking = [];
          } else {
            this.employeeRanking = [];
          }
        })
        .finally(() => {
          this.rankingLoading = false;
        });
    },
    loadEmployeeRanking() {
      this.rankingPage = 1;
      this.loadRanking();
    },
    loadStoreRanking() {
      this.rankingPage = 1;
      this.loadRanking();
    },
    onRankingPageChange(page) {
      this.rankingPage = page;
      this.loadRanking();
    },
    onTimeChange() {
      const start = this.formatMonthParam(this.startTime);
      const end = this.formatMonthParam(this.endTime);
      if (start && end && start > end) {
        this.endTime = this.startTime;
      }
      this.rankingPage = 1;
      this.loadAnalysis();
    },
    selectMetric(key) {
      this.selectedMetric = key;
    },
    switchCompareType(type) {
      this.compareType = type;
    },
    switchTableTab(tab) {
      this.tableTab = tab;
      this.rankingPage = 1;
      if (tab === 'store') {
        this.loadStoreRanking();
      } else if (tab === 'employee') {
        this.loadEmployeeRanking();
      }
    },
    openTargetObjectModal() {
      this.tempStoreOption = this.storeOptions.find(
        (o) => o.id === this.filterStoreId && o.object_type === this.filterObjectType
      ) || this.storeOptions[0];
      this.targetObjectModalVisible = true;
    },
    pickStoreOption(opt) {
      this.tempStoreOption = opt;
    },
    confirmTargetObject() {
      if (this.tempStoreOption) {
        this.filterStoreId = this.tempStoreOption.id;
        this.filterObjectType = this.tempStoreOption.object_type;
        this.selectedTargetObject = this.tempStoreOption.name;
      }
      this.targetObjectModalVisible = false;
      this.rankingPage = 1;
      this.loadAnalysis();
    },
    openMetricConfigModal() {
      if (!this.selectedMetricKeys.length && this.selectedMetrics.length) {
        this.selectedMetricKeys = this.selectedMetrics.map((m) => m.key);
      }
      this.metricConfigModalVisible = true;
    },
    isMetricSelected(key) {
      return this.selectedMetricKeys.indexOf(key) >= 0;
    },
    toggleMetric(key) {
      const idx = this.selectedMetricKeys.indexOf(key);
      if (idx >= 0) {
        if (this.selectedMetricKeys.length <= 1) {
          this.$Message.warning('至少保留一个指标');
          return;
        }
        this.selectedMetricKeys.splice(idx, 1);
      } else {
        this.selectedMetricKeys.push(key);
      }
    },
    confirmMetricConfig() {
      try {
        localStorage.setItem(METRIC_CONFIG_KEY, JSON.stringify(this.selectedMetricKeys));
      } catch (e) {
        /* ignore */
      }
      this.metricConfigModalVisible = false;
      this.loadAnalysis();
    },
    goToDetail() {
      this.$router.push({
        name: 'target_monthly_detail',
        query: this.buildDetailRouteQuery()
      });
    },
    goToStoreDetail() {
      this.$router.push({
        name: 'target_store_detail',
        query: this.buildDetailRouteQuery()
      });
    },
    buildDetailRouteQuery() {
      return {
        start_month: this.formatMonthParam(this.startTime),
        end_month: this.formatMonthParam(this.endTime),
        store_id: this.filterStoreId || '',
        object_type: this.filterObjectType,
        object_name: this.selectedTargetObject,
        metric_keys: (this.selectedMetricKeys.length ? this.selectedMetricKeys : this.selectedMetrics.map((m) => m.key)).join(',')
      };
    },
    refreshData() {
      this.loadAnalysis();
      this.$Message.success('数据已刷新');
    }
  }
};
</script>

<style lang="less" scoped>
@purple: #8B5CF6;
@purple-dark: #7C3AED;

.page-header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}

.header-action-btn {
  margin-right: 0;
}

.main-layout {
  display: flex;
}

.filter-sidebar {
  width: 280px;
  background: #fff;
  border-right: 1px solid #e8e8e8;
  padding: 20px;
  flex-shrink: 0;
}

.filter-section {
  margin-bottom: 24px;
}

.filter-title {
  font-size: 14px;
  font-weight: 600;
  color: #333;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.time-filter {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 12px;
}

.target-object-select {
  padding: 10px 12px;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  font-size: 14px;
  background: white;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  transition: all 0.2s;

  &:hover {
    border-color: @purple;
  }
}

.main-content {
  flex: 1;
  padding: 0 0 0 16px;
}

.overview-section {
  margin-bottom: 24px;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.section-title {
  font-size: 18px;
  font-weight: 600;
  color: #333;
}

.overview-cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}

.overview-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  transition: all 0.3s;
  cursor: pointer;
  border: 2px solid transparent;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  }

  &.selected {
    border-color: @purple;
    background: #faf5ff;
    box-shadow: 0 4px 16px rgba(139, 92, 246, 0.2);

    .overview-card-title {
      color: @purple;
      font-weight: 600;
    }
  }
}

.overview-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.overview-card-title {
  font-size: 14px;
  color: #666;
}

.overview-card-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: white;
}

.overview-card-value {
  font-size: 28px;
  font-weight: 700;
  color: #333;
  margin-bottom: 8px;
}

.overview-card-footer {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.rate-badge {
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;

  &.rate-high {
    background: #D1FAE5;
    color: #059669;
  }

  &.rate-medium {
    background: #FEF3C7;
    color: #D97706;
  }

  &.rate-low {
    background: #FEE2E2;
    color: #DC2626;
  }
}

.chart-section {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 24px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.chart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.chart-tabs {
  display: flex;
  gap: 4px;
  background: #f5f5f5;
  padding: 4px;
  border-radius: 8px;
}

.chart-tab {
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s;

  &.active {
    background: #fff;
    color: @purple;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  }
}

.chart-container {
  height: 320px;
  position: relative;
}

.mock-chart {
  width: 100%;
  height: 100%;
}

.table-section {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.table-tabs {
  display: flex;
  gap: 4px;
  background: #f5f5f5;
  padding: 4px;
  border-radius: 8px;
}

.table-tab {
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s;

  &.active {
    background: #fff;
    color: @purple;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  }
}

.dynamic-calc-info {
  background: #FFF8E7;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 16px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  font-size: 13px;
  color: #666;
  line-height: 1.6;
}

.monthly-list {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

.monthly-item {
  background: #f8f9fa;
  border-radius: 12px;
  padding: 16px;
}

.monthly-item-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.monthly-item-name {
  font-size: 15px;
  font-weight: 600;
  color: #333;
}

.monthly-item-status {
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 12px;
  font-weight: 500;

  &.completed {
    background: #D1FAE5;
    color: #059669;
  }

  &.pending {
    background: #FEF3C7;
    color: #D97706;
  }

  &.not-started {
    background: #E5E7EB;
    color: #6B7280;
  }

  &.unachieved {
    background: #FEE2E2;
    color: #DC2626;
  }
}

.monthly-item-values {
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
}

.monthly-item-value {
  flex: 1;
}

.monthly-item-label {
  font-size: 12px;
  color: #999;
  margin-bottom: 4px;
}

.monthly-item-number {
  font-size: 16px;
  font-weight: 700;
  color: #333;

  &.completed {
    color: #10B981;
  }
}

.monthly-item-progress {
  height: 8px;
  background: #e5e7eb;
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 8px;
}

.monthly-item-progress-bar {
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s;
}

.progress-green {
  background: #10B981;
}

.progress-orange {
  background: #F59E0B;
}

.progress-red {
  background: #EF4444;
}

.monthly-item-rate {
  font-size: 13px;
  font-weight: 600;
  color: #666;
  text-align: right;

  &.rate-high {
    color: #10B981;
  }

  &.rate-medium {
    color: #F59E0B;
  }

  &.rate-low {
    color: #EF4444;
  }
}

.pagination {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid #f0f0f0;
}

.target-object-option {
  padding: 12px 16px;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  transition: all 0.2s;
  font-size: 14px;

  &:hover {
    border-color: @purple;
    background: #faf5ff;
  }

  &.active {
    border-color: @purple;
    background: #f5f3ff;
    color: @purple;
  }
}

.metric-modal-body {
  max-height: 60vh;
  overflow-y: auto;
}

.metric-group-title {
  font-size: 14px;
  font-weight: 600;
  color: #333;
  margin-bottom: 12px;
}

.product-metric-tip {
  font-size: 12px;
  color: #999;
  background: #f5f5f5;
  padding: 8px 12px;
  border-radius: 6px;
  margin-bottom: 12px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
}

.metric-checkbox-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 20px;
}

.metric-checkbox-item {
  padding: 10px 0;
  cursor: pointer;
  display: block;
}

.metric-checkbox-row {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: #333;
}

.metric-checkbox-box {
  width: 18px;
  height: 18px;
  border: 2px solid #d9d9d9;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  color: white;
  flex-shrink: 0;

  &.checked {
    background: @purple;
    border-color: @purple;
  }
}

.metric-modal-footer {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid #e8e8e8;
}

.bg-purple {
  background: linear-gradient(135deg, @purple 0%, @purple-dark 100%);
}
.bg-blue {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}
.bg-green {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}
.bg-orange {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}
.bg-pink {
  background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
}
.bg-cyan {
  background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
}
.bg-indigo {
  background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
}

@media (max-width: 1200px) {
  .overview-cards {
    grid-template-columns: repeat(2, 1fr);
  }
  .monthly-list {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 900px) {
  .main-layout {
    flex-direction: column;
  }
  .filter-sidebar {
    width: 100%;
    border-right: none;
    border-bottom: 1px solid #e8e8e8;
    margin-bottom: 16px;
  }
  .main-content {
    padding: 0;
  }
}
</style>
