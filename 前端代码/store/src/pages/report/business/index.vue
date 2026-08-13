<template>
  <div class="business-report">
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div class="report-heading"><div><h2>门店业务报表</h2><p>仅显示当前登录门店的 V3 正式口径数据</p></div><span>{{ reportMeta.coverage_start ? '数据覆盖期自 ' + reportMeta.coverage_start : '' }}</span></div>
      <Tabs v-model="activeReport" @on-click="loadReport"><TabPane v-for="item in catalog" :key="item.code" :label="item.name" :name="item.code" /></Tabs>
      <Row type="flex" class="filters">
        <DatePicker v-model="dateRange" type="daterange" format="yyyy-MM-dd" placeholder="统计日期" style="width:240px" />
        <Select v-if="activeReport === 'customers'" v-model="customerSegment" class="ml10" style="width:150px"><Option v-for="item in customerSegments" :key="item.value" :value="item.value">{{ item.label }}</Option></Select>
        <Select v-if="activeReport === 'customers'" v-model="consumptionMetric" class="ml10" style="width:130px"><Option value="cash">现金业绩</Option><Option value="consume">消耗业绩</Option></Select>
        <Select v-if="activeReport === 'customers'" v-model="sleepMonths" class="ml10" style="width:130px"><Option :value="3">睡眠3个月</Option><Option :value="6">睡眠6个月</Option></Select>
        <InputNumber v-if="activeReport === 'customers'" v-model="reportYear" :min="2020" :max="2100" class="ml10" style="width:100px" />
        <Button type="primary" class="ml10" :loading="loading" @click="loadReport">查询</Button>
        <Button class="ml10" @click="exportReport">导出当前明细</Button>
      </Row>
      <Alert v-if="reportMeta.coverage_start" type="info" show-icon>统计口径版本：{{ reportMeta.metric_version }}；数据更新时间：{{ reportMeta.data_as_of }}。历史旧订单不会混入本页。</Alert>
    </Card>

    <Card v-if="cards.length" :bordered="false" dis-hover class="ivu-mt mt15">
      <Row :gutter="12"><Col v-for="card in cards" :key="card.code" :xs="12" :sm="8" :md="6" :lg="6"><div class="metric-card"><span>{{ card.name }}</span><strong>{{ card.value }}<small>{{ card.unit }}</small></strong></div></Col></Row>
    </Card>

    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div v-if="pendingMetrics.length" class="pending"><Tag color="orange">口径待确认</Tag><span v-for="item in pendingMetrics" :key="item">{{ item }}</span></div>
      <Table :columns="columns" :data="records" :loading="loading" border />
      <Page v-if="total > pageSize" :total="total" :current="page" :page-size="pageSize" show-total class="page" @on-change="changePage" />
    </Card>
  </div>
</template>

<script>
import { businessReportCatalog, businessReportQuery, businessReportExport } from '@/api/report'
import exportExcel from '@/utils/newToExcel.js'

export default {
  name: 'StoreBusinessReport',
  data () {
    return { catalog: [], activeReport: 'overview', dateRange: [], loading: false, reportMeta: {}, cards: [], columns: [], records: [], total: 0, page: 1, pageSize: 20, pendingMetrics: [], customerSegment: 'all', consumptionMetric: 'cash', sleepMonths: 3, reportYear: new Date().getFullYear(), customerSegments: [{ value: 'all', label: '全部顾客' }, { value: 'pre_sale', label: '售前（新客）' }, { value: 'post_sale', label: '售后（老客）' }, { value: 'pending_conversion', label: '订单待转换' }, { value: 'guest', label: '嘉宾' }, { value: 'active', label: '活客' }, { value: 'effective', label: '有效顾客' }, { value: 'sleeping', label: '睡眠顾客' }] }
  },
  created () { this.initialise() },
  methods: {
    initialise () { businessReportCatalog().then(res => { this.catalog = res.data || []; if (this.catalog.length) this.activeReport = this.catalog[0].code; this.loadReport() }) },
    params (extra) {
      const range = this.dateRange || []
      return Object.assign({ report: this.activeReport, start_date: this.formatDate(range[0]), end_date: this.formatDate(range[1]), customer_segment: this.customerSegment, consumption_metric: this.consumptionMetric, sleep_months: this.sleepMonths, year: this.reportYear, page: this.page, limit: this.pageSize }, extra || {})
    },
    loadReport () {
      this.loading = true
      businessReportQuery(this.params()).then(res => {
        const data = res.data || {}
        this.reportMeta = data; this.cards = data.cards || []; this.columns = (data.columns || []).map(item => ({ title: item.label, key: item.key, minWidth: 120 })); this.records = data.records || []; this.total = data.total || 0; this.pendingMetrics = data.pending_metrics || []
      }).catch(err => this.$Message.error(err.msg || '读取报表失败')).finally(() => { this.loading = false })
    },
    changePage (page) { this.page = page; this.loadReport() },
    formatDate (value) {
      if (!value) return ''
      if (typeof value === 'string') return value.slice(0, 10)
      const year = value.getFullYear(); const month = String(value.getMonth() + 1).padStart(2, '0'); const day = String(value.getDate()).padStart(2, '0')
      return `${year}-${month}-${day}`
    },
    exportReport () {
      businessReportExport(this.params({ page: 1, limit: 100 })).then(res => {
        const data = res.data || {}; exportExcel((data.columns || []).map(item => item.label), (data.columns || []).map(item => item.key), data.filename || '门店业务报表', data.records || [])
      }).catch(err => this.$Message.error(err.msg || '导出失败'))
    }
  }
}
</script>

<style lang="less" scoped>
.report-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.report-heading h2{margin:0;font-size:18px}.report-heading p,.report-heading span{margin:5px 0 0;color:#999;font-size:12px}.filters{margin:8px 0 14px}.metric-card{padding:16px;border-radius:4px;background:#f6f8fb}.metric-card span,.metric-card small{display:block;color:#888;font-size:12px}.metric-card strong{display:block;margin-top:8px;font-size:24px;color:#2d8cf0}.metric-card small{display:inline;margin-left:4px}.pending{margin-bottom:12px;color:#999}.pending span{margin-left:12px}.page{margin-top:16px;text-align:right}
</style>
