<template>
  <div class="target-detail-page">
    <div class="detail-back-bar">
      <Button @click="goBack">
        <Icon type="ios-arrow-back" /> 返回数据分析
      </Button>
    </div>

    <div class="detail-container">
      <div class="detail-header">
        <div class="detail-header-title">月度目标完成明细表</div>
        <div class="detail-header-info">{{ headerInfo }}</div>
      </div>

      <div class="detail-filter-bar">
        <div class="detail-filter-item">
          <span class="detail-filter-label">目标对象：</span>
          <div class="detail-filter-input" @click="openObjectModal">
            <span>{{ selectedTargetObject }}</span>
            <Icon type="ios-arrow-down" />
          </div>
        </div>
        <div class="detail-filter-item">
          <span class="detail-filter-label">周期：</span>
          <div class="detail-period-selector">
            <DatePicker type="month" v-model="startTime" placeholder="开始月份" style="width: 130px;" @on-change="onFilterChange" />
            <span class="detail-period-separator">至</span>
            <DatePicker type="month" v-model="endTime" placeholder="结束月份" style="width: 130px;" @on-change="onFilterChange" />
          </div>
        </div>
        <div class="detail-filter-item">
          <span class="detail-filter-label">配置指标：</span>
          <div class="detail-filter-input" @click="openMetricModal">
            <span>已选{{ selectedMetricKeys.length }}项指标</span>
            <Icon type="ios-arrow-down" />
          </div>
        </div>
      </div>

      <div v-if="loading" class="detail-loading">数据加载中...</div>
      <div v-else class="detail-table-container">
        <table class="detail-data-table">
          <thead>
            <tr>
              <th>项目</th>
              <th v-for="col in columns" :key="col.key">{{ col.name }}</th>
              <th>合计</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="targetRow" class="detail-target-row">
              <td>{{ targetRow.label }}</td>
              <td v-for="col in columns" :key="'t-' + col.key" class="detail-data-cell">{{ formatCell(targetRow.values[col.key]) }}</td>
              <td class="detail-data-cell">{{ formatCell(targetRow.total) }}</td>
            </tr>
            <tr v-for="(row, idx) in dataRows" :key="'r-' + idx">
              <td>{{ row.label }}</td>
              <td
                v-for="col in columns"
                :key="'r-' + idx + '-' + col.key"
                :class="cellClass(row.values[col.key])"
              >{{ formatCell(row.values[col.key], true) }}</td>
              <td :class="['detail-data-cell', row.highlight_total ? 'detail-highlight-total' : '']">{{ formatCell(row.total) }}</td>
            </tr>
            <tr v-if="totalRow" class="detail-total-row">
              <td>{{ totalRow.label }}</td>
              <td v-for="col in columns" :key="'s-' + col.key" class="detail-data-cell">{{ formatCell(totalRow.values[col.key]) }}</td>
              <td class="detail-data-cell">{{ formatCell(totalRow.total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="detail-toolbar">
        <div class="detail-toolbar-left">数据更新时间：{{ updatedAt || '-' }}</div>
        <div class="detail-toolbar-right">
          <Button @click="handlePrint">
            <Icon type="md-print" /> 打印
          </Button>
          <Button type="primary" @click="handleExport">
            <Icon type="md-download" /> 导出Excel
          </Button>
        </div>
      </div>
    </div>

    <Modal v-model="objectModalVisible" title="选择目标对象" width="480">
      <div
        v-for="opt in storeOptions"
        :key="opt.id + '-' + opt.object_type"
        class="target-option"
        :class="{ active: isObjectActive(opt) }"
        @click="pickStoreOption(opt)"
      >
        <span>{{ opt.name }}</span>
        <Icon v-if="isObjectActive(opt)" type="md-checkmark" color="#8B5CF6" />
      </div>
      <div slot="footer">
        <Button @click="objectModalVisible = false">取消</Button>
        <Button type="primary" @click="confirmObject">确定</Button>
      </div>
    </Modal>

    <Modal v-model="metricModalVisible" title="配置指标" width="520">
      <div class="detail-metric-group-title">核心指标</div>
      <div class="detail-metric-list">
        <div class="detail-metric-item" v-for="m in coreMetricOptions" :key="m.key" @click="toggleMetric(m.key)">
          <div class="detail-metric-checkbox" :class="{ checked: isMetricSelected(m.key) }">
            <Icon type="ios-checkmark" v-if="isMetricSelected(m.key)" />
          </div>
          <span class="detail-metric-name">{{ m.name }}</span>
        </div>
      </div>
      <div class="detail-metric-group-title" v-if="productMetricOptions.length">商品指标</div>
      <div class="detail-metric-list" v-if="productMetricOptions.length">
        <div class="detail-metric-item" v-for="m in productMetricOptions" :key="m.key" @click="toggleMetric(m.key)">
          <div class="detail-metric-checkbox" :class="{ checked: isMetricSelected(m.key) }">
            <Icon type="ios-checkmark" v-if="isMetricSelected(m.key)" />
          </div>
          <span class="detail-metric-name">{{ m.name }}</span>
        </div>
      </div>
      <div slot="footer">
        <Button @click="metricModalVisible = false">取消</Button>
        <Button type="primary" @click="confirmMetric">确定 ({{ selectedMetricKeys.length }})</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  targetMonthlyDetail,
  targetMetricOptions,
  targetStoreOptions
} from '@/api/target';

const METRIC_CONFIG_KEY = 'target_analysis_metric_keys';

function padMonth(n) {
  return n < 10 ? '0' + n : String(n);
}

function formatMonthParam(val) {
  if (!val) return '';
  if (typeof val === 'string') return val.length >= 7 ? val.slice(0, 7) : val;
  const d = val instanceof Date ? val : new Date(val);
  return d.getFullYear() + '-' + padMonth(d.getMonth() + 1);
}

export default {
  name: 'target_monthly_detail',
  data() {
    const now = new Date();
    return {
      loading: false,
      headerInfo: '',
      updatedAt: '',
      columns: [],
      targetRow: null,
      dataRows: [],
      totalRow: null,
      startTime: new Date(now.getFullYear(), 0, 1),
      endTime: new Date(now.getFullYear(), 11, 1),
      selectedTargetObject: '全部门店',
      filterStoreId: 0,
      filterObjectType: 3,
      storeOptions: [],
      tempStoreOption: null,
      objectModalVisible: false,
      metricModalVisible: false,
      coreMetricOptions: [],
      productMetricOptions: [],
      selectedMetricKeys: []
    };
  },
  mounted() {
    this.applyRouteQuery();
    this.loadMetricOptions();
    this.loadStoreOptions();
    this.loadData();
  },
  methods: {
    applyRouteQuery() {
      const q = this.$route.query || {};
      if (q.start_month) {
        this.startTime = new Date(String(q.start_month) + '-01');
      }
      if (q.end_month) {
        this.endTime = new Date(String(q.end_month) + '-01');
      }
      if (q.store_id !== undefined && q.store_id !== '') {
        this.filterStoreId = Number(q.store_id) || 0;
      }
      if (q.object_type !== undefined && q.object_type !== '') {
        this.filterObjectType = Number(q.object_type) || 3;
      }
      if (q.object_name) {
        this.selectedTargetObject = q.object_name;
      }
      if (q.metric_keys) {
        this.selectedMetricKeys = String(q.metric_keys).split(',').filter(Boolean);
      }
    },
    buildParams() {
      return {
        start_month: formatMonthParam(this.startTime),
        end_month: formatMonthParam(this.endTime),
        store_id: this.filterStoreId || '',
        object_type: this.filterObjectType,
        object_name: this.selectedTargetObject,
        metric_keys: this.selectedMetricKeys.join(',')
      };
    },
    loadData() {
      this.loading = true;
      targetMonthlyDetail(this.buildParams())
        .then((res) => {
          const d = res.data || {};
          this.headerInfo = d.header_info || '';
          this.updatedAt = d.updated_at || '';
          this.columns = d.columns || [];
          this.targetRow = d.target_row || null;
          this.dataRows = d.rows || [];
          this.totalRow = d.total_row || null;
          this.mergeMetricOptionsFromColumns();
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '加载明细失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    mergeMetricOptionsFromColumns() {
      const exists = {};
      this.coreMetricOptions.forEach((m) => { exists[m.key] = true; });
      const products = [];
      (this.columns || []).forEach((col) => {
        if (!exists[col.key]) {
          products.push({ key: col.key, name: col.name });
          exists[col.key] = true;
        }
      });
      this.productMetricOptions = products;
      if (!this.selectedMetricKeys.length && this.columns.length) {
        this.selectedMetricKeys = this.columns.map((c) => c.key);
      }
    },
    loadMetricOptions() {
      targetMetricOptions().then((res) => {
        const core = (res.data && res.data.core_metrics) || [];
        this.coreMetricOptions = core.map((m) => ({ key: m.key, name: m.name }));
        if (!this.selectedMetricKeys.length) {
          const saved = localStorage.getItem(METRIC_CONFIG_KEY);
          if (saved) {
            try {
              const keys = JSON.parse(saved);
              if (Array.isArray(keys) && keys.length) {
                this.selectedMetricKeys = keys;
              }
            } catch (e) { /* ignore */ }
          }
        }
        if (!this.selectedMetricKeys.length) {
          this.selectedMetricKeys = this.coreMetricOptions.map((m) => m.key);
        }
      });
    },
    loadStoreOptions() {
      targetStoreOptions().then((res) => {
        this.storeOptions = (res.data && Array.isArray(res.data)) ? res.data : [];
        if (!this.selectedTargetObject && this.storeOptions.length) {
          const current = this.storeOptions.find(
            (o) => o.id === this.filterStoreId && o.object_type === this.filterObjectType
          ) || this.storeOptions[0];
          this.selectedTargetObject = current.name;
          this.filterStoreId = current.id;
          this.filterObjectType = current.object_type;
        }
      });
    },
    onFilterChange() {
      const start = formatMonthParam(this.startTime);
      const end = formatMonthParam(this.endTime);
      if (start && end && start > end) {
        this.endTime = this.startTime;
      }
      this.loadData();
    },
    formatCell(val, allowEmpty) {
      const n = Number(val);
      if (!n && allowEmpty && (val === null || val === undefined || val === '' || n === 0)) {
        return '-';
      }
      if (!n && n !== 0) return allowEmpty ? '-' : '';
      return Number.isInteger(n) ? String(n) : n.toFixed(1);
    },
    cellClass(val) {
      const n = Number(val);
      if (!n) return 'detail-empty-cell';
      return 'detail-data-cell';
    },
    openObjectModal() {
      this.tempStoreOption = this.storeOptions.find(
        (o) => o.id === this.filterStoreId && o.object_type === this.filterObjectType
      ) || this.storeOptions[0];
      this.objectModalVisible = true;
    },
    pickStoreOption(opt) {
      this.tempStoreOption = opt;
    },
    isObjectActive(opt) {
      const t = this.tempStoreOption;
      return t && t.id === opt.id && t.object_type === opt.object_type;
    },
    confirmObject() {
      if (this.tempStoreOption) {
        this.filterStoreId = this.tempStoreOption.id;
        this.filterObjectType = this.tempStoreOption.object_type;
        this.selectedTargetObject = this.tempStoreOption.name;
      }
      this.objectModalVisible = false;
      this.loadData();
    },
    openMetricModal() {
      this.metricModalVisible = true;
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
    confirmMetric() {
      try {
        localStorage.setItem(METRIC_CONFIG_KEY, JSON.stringify(this.selectedMetricKeys));
      } catch (e) { /* ignore */ }
      this.metricModalVisible = false;
      this.loadData();
    },
    handlePrint() {
      window.print();
    },
    handleExport() {
      const lines = [];
      const header = ['项目'].concat(this.columns.map((c) => c.name), ['合计']);
      lines.push(header.join(','));
      const pushRow = (row) => {
        if (!row) return;
        const cells = [row.label];
        this.columns.forEach((col) => {
          cells.push(this.formatCell(row.values[col.key]) || '0');
        });
        cells.push(this.formatCell(row.total) || '0');
        lines.push(cells.join(','));
      };
      pushRow(this.targetRow);
      this.dataRows.forEach(pushRow);
      pushRow(this.totalRow);
      const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = '月度目标完成明细.csv';
      link.click();
    },
    goBack() {
      this.$router.push({ name: 'target_data_analysis' });
    }
  }
};
</script>

<style lang="less" scoped>
@import '../detail_common.less';

.target-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  border-radius: 8px;
  cursor: pointer;
  margin-bottom: 8px;
  border: 1px solid #f0f0f0;

  &:hover,
  &.active {
    background: #f5f0ff;
    border-color: #d8c4ff;
  }
}
</style>
