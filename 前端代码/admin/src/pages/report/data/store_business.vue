<template>
  <div class="business-report">
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div class="report-heading"><div><h2>门店运营报表</h2><p>平台端按当前组织权限查询，选择组织后仍按门店维度展示数据</p></div><span>{{ reportMeta.coverage_start ? '数据覆盖期自 ' + reportMeta.coverage_start : '' }}</span></div>
      <Tabs v-model="activeReport" @on-click="loadReport"><TabPane v-for="item in catalog" :key="item.code" :label="item.name" :name="item.code" /></Tabs>
      <Row type="flex" class="filters">
        <Poptip v-model="scopeVisible" placement="bottom-start" width="480" transfer class="scope-picker">
          <Button icon="ios-git-network-outline">{{ scopeLabel }}</Button>
          <div slot="content" class="scope-panel">
            <div class="scope-panel__title">组织 / 门店</div>
            <div class="scope-panel__body">
              <div class="scope-panel__tree">
                <Spin v-if="scopeLoading" fix />
                <Tree v-else :data="scopeTree" @on-select-change="onOrganizationSelect" />
              </div>
              <div class="scope-panel__stores">
                <div class="scope-panel__stores-title">{{ selectedOrganizationName || '选择组织后查看直属门店' }}</div>
                <Spin v-if="storeLoading" size="small" />
                <div v-else-if="scopeStores.length" class="scope-store-list">
                  <button v-for="store in scopeStores" :key="store.id" type="button" :class="{ active: scope.store_id === Number(store.id) }" @click="selectStore(store)">{{ store.name }}</button>
                </div>
                <div v-else class="scope-panel__empty">该组织暂无直属门店</div>
              </div>
            </div>
            <div class="scope-panel__footer"><Button size="small" @click="selectAllAllowed">当前权限范围</Button><span>选择组织查询其下门店；选择门店仅查询该门店。</span></div>
          </div>
        </Poptip>
        <DatePicker v-model="dateRange" type="daterange" format="yyyy-MM-dd" placeholder="统计日期" style="width:240px" />
        <Select v-if="activeReport === 'customers'" v-model="customerSegment" class="ml10" style="width:150px"><Option v-for="item in customerSegments" :key="item.value" :value="item.value">{{ item.label }}</Option></Select>
        <Select v-if="activeReport === 'customers'" v-model="consumptionMetric" class="ml10" style="width:130px"><Option value="cash">现金业绩</Option><Option value="consume">消耗业绩</Option></Select>
        <Select v-if="activeReport === 'customers'" v-model="sleepMonths" class="ml10" style="width:130px"><Option :value="3">睡眠3个月</Option><Option :value="6">睡眠6个月</Option></Select>
        <InputNumber v-if="activeReport === 'customers'" v-model="reportYear" :min="2020" :max="2100" class="ml10" style="width:100px" />
        <Button type="primary" class="ml10" :loading="loading" @click="loadReport">查询</Button>
        <Button class="ml10" @click="exportReport">导出当前明细</Button>
      </Row>
      <Alert v-if="reportMeta.coverage_start" type="info" show-icon>统计口径版本：{{ reportMeta.metric_version }}；数据更新时间：{{ reportMeta.data_as_of }}。查询范围已按当前账号权限锁定。</Alert>
    </Card>
    <Card v-if="cards.length" :bordered="false" dis-hover class="ivu-mt mt15"><Row :gutter="12"><Col v-for="card in cards" :key="card.code" :xs="12" :sm="8" :md="6" :lg="6"><div class="metric-card"><span>{{ card.name }}</span><strong>{{ card.value }}<small>{{ card.unit }}</small></strong></div></Col></Row></Card>
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div v-if="pendingMetrics.length" class="pending"><Tag color="orange">口径待确认</Tag><span v-for="item in pendingMetrics" :key="item">{{ item }}</span></div>
      <Table :columns="columns" :data="records" :loading="loading" border />
      <Page v-if="total > pageSize" :total="total" :current="page" :page-size="pageSize" show-total class="page" @on-change="changePage" />
    </Card>
  </div>
</template>

<script>
import { unifiedBusinessReportCatalog, unifiedBusinessReportQuery, unifiedBusinessReportExport, reportOrganizationTree, reportOrganizationStores } from '@/api/report'
import exportExcel from '@/utils/newToExcel.js'

export default {
  name: 'PlatformStoreBusinessReport',
  data () { return { catalog: [], activeReport: '', dateRange: [], loading: false, reportMeta: {}, cards: [], columns: [], records: [], columnGroups: [], exportColumns: [], total: 0, page: 1, pageSize: 20, pendingMetrics: [], customerSegment: 'all', consumptionMetric: 'cash', sleepMonths: 3, reportYear: new Date().getFullYear(), customerSegments: [{ value: 'all', label: '全部顾客' }, { value: 'pre_sale', label: '售前（新客）' }, { value: 'post_sale', label: '售后（老客）' }, { value: 'pending_conversion', label: '订单待转换' }, { value: 'guest', label: '嘉宾' }, { value: 'active', label: '活客' }, { value: 'effective', label: '有效顾客' }, { value: 'sleeping', label: '睡眠顾客' }], scopeVisible: false, scopeLoading: false, storeLoading: false, scopeTree: [], scopeStores: [], scope: { org_id: 0, store_id: 0, label: '' }, selectedOrganizationName: '' } },
  created () { this.initialise() },
  computed: {
    scopeLabel () { return this.scope.label || '当前权限范围' }
  },
  watch: {
    '$route.params.report' (value) {
      const requested = String(value || '')
      if (!requested || requested === this.activeReport) return
      if (!this.catalog.some(item => item.code === requested)) return
      this.activeReport = requested
      this.page = 1
      this.loadReport()
    }
  },
  methods: {
    initialise () { Promise.all([this.loadScopeTree(), unifiedBusinessReportCatalog()]).then(([, res]) => { this.catalog = res.data || []; const route = this.$route || {}; const requested = String((route.params || {}).report || (route.query || {}).report || ''); this.activeReport = this.catalog.some(item => item.code === requested) ? requested : ((this.catalog[0] || {}).code || ''); if (this.activeReport) this.loadReport() }).catch(err => this.$Message.error(err.msg || '读取报表目录失败')) },
    params (extra) { const range = this.dateRange || []; const scope = this.scope.store_id > 0 ? { store_id: this.scope.store_id } : (this.scope.org_id > 0 ? { org_id: this.scope.org_id } : {}); return Object.assign({ report: this.activeReport, start_date: this.formatDate(range[0]), end_date: this.formatDate(range[1]), customer_segment: this.customerSegment, consumption_metric: this.consumptionMetric, sleep_months: this.sleepMonths, year: this.reportYear, page: this.page, limit: this.pageSize }, scope, extra || {}) },
    loadReport () { if (!this.activeReport) return; this.loading = true; unifiedBusinessReportQuery(this.params()).then(res => { const data = res.data || {}; const model = this.buildReportModel(data); this.reportMeta = data; this.cards = data.cards || []; this.columns = model.columns; this.exportColumns = model.exportColumns; this.columnGroups = model.groups; this.records = model.records; this.total = data.total || 0; this.pendingMetrics = data.pending_metrics || [] }).catch(err => this.$Message.error(err.msg || '读取报表失败')).finally(() => { this.loading = false }) },
    changePage (page) { this.page = page; this.loadReport() },
    formatDate (value) { if (!value) return ''; if (typeof value === 'string') return value.slice(0, 10); const year = value.getFullYear(); const month = String(value.getMonth() + 1).padStart(2, '0'); const day = String(value.getDate()).padStart(2, '0'); return `${year}-${month}-${day}` },
    exportReport () { unifiedBusinessReportExport(this.params({ page: 1, limit: 100 })).then(res => { const data = res.data || {}; const model = this.buildReportModel(data); exportExcel(model.exportColumns.map(item => item.label), model.exportColumns.map(item => item.key), data.filename || '门店运营报表', model.records) }).catch(err => this.$Message.error(err.msg || '导出失败')) },
    buildReportModel (data) {
      const sourceColumns = Array.isArray(data.columns) ? data.columns : []
      const records = Array.isArray(data.records) ? data.records : []
      const dynamic = this.dynamicPaymentColumns(data, records)
      let columns = this.normaliseColumns(sourceColumns, this.columnGroupMap(data.column_groups || data.columnGroups || []))
      const known = new Set(columns.map(item => item.key))
      dynamic.columns.forEach(item => {
        const existing = columns.find(column => column.key === item.key)
        if (existing) {
          if (!existing.groupLabel && this.activeReport === 'market_performance') { existing.group = item.group; existing.groupLabel = item.groupLabel }
        } else { columns.push(item); known.add(item.key) }
      })

      // Keep the frozen field contract explicit even when an older endpoint omits a field.
      if (this.activeReport === 'store_item_analysis' && !columns.some(item => item.key === 'store_name' || item.key === 'store_name_snapshot')) {
        columns.unshift({ key: 'store_name', label: '门店', minWidth: 120 })
      }
      if (this.activeReport === 'store_salesperson_performance') {
        const allowed = ['store_id', 'store_name', 'store_name_snapshot', 'employee_name', 'salesperson_name', 'order_count', 'member_count', 'performance_amount']
        columns = columns.filter(item => allowed.indexOf(item.key) !== -1)
      }
      const flattened = records.map(row => this.flattenDynamicRecord(row, dynamic.definitions))
      if (this.activeReport === 'market_performance' && dynamic.columns.length) {
        // The total must come from a server-side fact/column. Never sum payment
        // methods in the browser, otherwise the page and export can diverge.
        const serverTotal = columns.find(item => /^(system|payment|cash|receipt).*total$/i.test(item.key))
        if (serverTotal && !serverTotal.groupLabel) {
          serverTotal.group = 'system_operation'
          serverTotal.groupLabel = '系统操作'
        }
      }
      const tableColumns = this.makeTableColumns(columns)
      return { columns: tableColumns, exportColumns: columns.map(item => ({ key: item.key, label: item.groupLabel ? `${item.groupLabel} / ${item.label}` : item.label })), records: flattened, groups: this.makeColumnGroups(columns) }
    },
    normaliseColumns (columns, groupMap) {
      groupMap = groupMap || {}
      const seen = new Set()
      return columns.reduce((result, item) => {
        const key = String(item && (item.key || item.value) || '').trim()
        if (!key || seen.has(key)) return result
        seen.add(key)
        result.push({ key, label: String(item.label || item.title || key), group: item.group || item.section || '', groupLabel: item.groupLabel || item.group_label || item.sectionLabel || groupMap[key] || '', minWidth: Number(item.minWidth || item.min_width || 120) })
        return result
      }, [])
    },
    columnGroupMap (groups) {
      const map = {}
      ;(groups || []).forEach(group => {
        const label = String(group && (group.label || group.title || group.name) || '').trim()
        if (!label) return
        const keys = group.column_keys || group.columnKeys || group.keys || []
        if (Array.isArray(keys)) keys.forEach(key => { map[String(key)] = label })
        ;(group.children || []).forEach(child => { const key = child && (child.key || child.value); if (key) map[String(key)] = label })
      })
      return map
    },
    dynamicPaymentColumns (data, records) {
      const definitions = []
      const byKey = {}
      const existingColumnKeys = new Set((data.columns || []).map(item => String(item && item.key || '').trim()).filter(Boolean))
      const add = (raw, fallbackKey, fallbackLabel) => {
        if (raw === null || raw === undefined) return
        const isObject = typeof raw === 'object' && !Array.isArray(raw)
        const key = String((isObject && (raw.key || raw.code || raw.id || raw.value)) || fallbackKey || '').trim()
        if (!key) return
        if (/^(total|sum|amount|subtotal|合计)$/i.test(key)) return
        const label = String((isObject && (raw.label || raw.name || raw.title || raw.payment_method_label)) || fallbackLabel || key).trim()
        const canonical = this.safeKey(key).replace(/^payment_(method_)?/i, '')
        const matchingExisting = existingColumnKeys.has(key) ? key : (existingColumnKeys.has(`payment_${canonical}`) ? `payment_${canonical}` : '')
        const safe = matchingExisting || `payment_method__${canonical}`
        if (byKey[safe]) return
        byKey[safe] = true
        definitions.push({ sourceKey: key, key: safe, label, group: 'system_operation', groupLabel: '系统操作' })
      }
      const collect = (value) => {
        if (Array.isArray(value)) value.forEach(item => add(item))
        else if (value && typeof value === 'object') Object.keys(value).forEach(key => add(value[key], key, typeof value[key] === 'string' ? value[key] : key))
      }
      ;['payment_columns', 'payment_methods', 'payment_method_columns', 'system_operations'].forEach(key => collect(data[key]))
      ;(data.columns || []).forEach(item => { const key = String(item && item.key || ''); const group = String(item && (item.group || item.section || item.groupLabel || item.group_label) || ''); if (/^(payment|system_payment|payment_method)[_:.]/i.test(key) || /system_operation|系统操作|支付/i.test(group) || item.payment_method || item.dynamic === 'payment') add(item, key, item.label) })
      records.forEach(row => {
        ;['payment_methods', 'payments', 'payment_amounts', 'system_operations', 'system_operation'].forEach(key => {
          const value = row && row[key]
          if (value && typeof value === 'object') collect(value)
        })
        Object.keys(row || {}).forEach(key => { if (/^(payment|system_payment|payment_method)[_:.]/i.test(key)) add(null, key, key.replace(/^(payment|system_payment|payment_method)[_:.]?/i, '') || key) })
      })
      return { definitions, columns: definitions.map(item => ({ key: item.key, label: item.label, group: item.group, groupLabel: item.groupLabel, minWidth: 120 })) }
    },
      flattenDynamicRecord (row, definitions) {
        const output = Object.assign({}, row || {})
        definitions.forEach(item => {
          if (output[item.key] !== undefined) return
          let value
          ;['payment_methods', 'payments', 'payment_amounts', 'system_operations', 'system_operation'].some(container => {
            const source = row && row[container]
            if (!source || typeof source !== 'object') return false
            if (Array.isArray(source)) {
              const matched = source.find(entry => entry && String(entry.key || entry.code || entry.id || entry.value) === item.sourceKey)
              value = matched && (matched.amount === undefined ? (matched.value === undefined ? matched.amount_cents : matched.value) : matched.amount)
            } else value = source[item.sourceKey]
            if (value && typeof value === 'object') value = value.amount === undefined ? (value.value === undefined ? value.amount_cents : value.value) : value.amount
            return value !== undefined
        })
        if (value === undefined && row) {
          const canonical = this.safeKey(item.sourceKey).replace(/^payment_(method_)?/i, '')
          value = row[item.sourceKey] !== undefined ? row[item.sourceKey] : (row[`payment_${item.sourceKey}`] !== undefined ? row[`payment_${item.sourceKey}`] : row[`payment_${canonical}`])
        }
        output[item.key] = value === undefined || value === null ? '' : value
      })
      return output
    },
    safeKey (value) { return String(value).replace(/[^a-zA-Z0-9_-]+/g, '_').replace(/^_+|_+$/g, '') || 'unknown' },
    makeColumnGroups (columns) {
      const groups = []; let current = null
      columns.forEach(item => { const label = item.groupLabel || ''; const key = label || `column_${item.key}`; if (!current || current.key !== key) { current = { key, label: label || item.label, span: 1, group: !!label }; groups.push(current) } else current.span += 1 })
      return groups
    },
    makeTableColumns (columns) {
      const result = []
      columns.forEach(item => {
        const leaf = { title: item.label, key: item.key, minWidth: item.minWidth || 120 }
        const group = String(item.groupLabel || '').trim()
        if (!group) { result.push(leaf); return }
        const previous = result[result.length - 1]
        if (previous && previous.__groupLabel === group) previous.children.push(leaf)
        else result.push({ title: group, key: `group__${this.safeKey(group)}`, __groupLabel: group, children: [leaf] })
      })
      return result.map(item => { if (item.__groupLabel) { const clone = Object.assign({}, item); delete clone.__groupLabel; return clone } return item })
    },
    loadScopeTree () { this.scopeLoading = true; return reportOrganizationTree().then(res => { this.scopeTree = this.normalizeOrganizationTree(res.data || []); }).catch(err => { this.$Message.error(err.msg || '组织树加载失败'); this.scopeTree = [] }).finally(() => { this.scopeLoading = false }) },
    normalizeOrganizationTree (nodes) { return (nodes || []).map(node => ({ title: node.name || node.title || `组织${node.id}`, id: Number(node.id), org_id: Number(node.id), scopeType: 'org', expand: false, children: this.normalizeOrganizationTree(node.children || []) })) },
    onOrganizationSelect (nodes) { const node = (nodes || [])[0]; if (!node) return; if (node.scopeType === 'store') { this.selectStore(node); return } if (!node.org_id) return; const orgId = Number(node.org_id); this.scope = { org_id: orgId, store_id: 0, label: node.title }; this.selectedOrganizationName = node.title; this.loadOrganizationStores(orgId); this.page = 1; this.loadReport() },
    loadOrganizationStores (orgId) { if (!orgId) { this.scopeStores = []; return } this.storeLoading = true; reportOrganizationStores({ org_id: orgId, scope: 'direct', page: 1, limit: 50 }).then(res => { const data = res.data || {}; this.scopeStores = data.list || data || []; const node = this.findOrganizationNode(this.scopeTree, orgId); if (node) { const orgChildren = (node.children || []).filter(child => child.scopeType !== 'store'); node.children = orgChildren.concat(this.scopeStores.map(store => ({ title: store.name || `门店${store.id}`, id: Number(store.id), store_id: Number(store.id), scopeType: 'store', isLeaf: true }))); } }).catch(err => { this.scopeStores = []; this.$Message.error(err.msg || '门店列表加载失败') }).finally(() => { this.storeLoading = false }) },
    findOrganizationNode (nodes, orgId) { for (let i = 0; i < (nodes || []).length; i++) { const node = nodes[i]; if (node.scopeType === 'org' && Number(node.org_id) === Number(orgId)) return node; const found = this.findOrganizationNode(node.children || [], orgId); if (found) return found } return null },
    selectStore (store) { const storeId = Number(store.id); if (!storeId) return; this.scope = { org_id: 0, store_id: storeId, label: store.name || `门店${storeId}` }; this.scopeVisible = false; this.page = 1; this.loadReport() },
    selectAllAllowed () { this.scope = { org_id: 0, store_id: 0, label: '' }; this.selectedOrganizationName = ''; this.scopeStores = []; this.scopeVisible = false; this.page = 1; this.loadReport() }
  }
}
</script>

<style lang="less" scoped>
.report-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.report-heading h2{margin:0;font-size:18px}.report-heading p,.report-heading span{margin:5px 0 0;color:#999;font-size:12px}.filters{margin:8px 0 14px}.scope-picker{margin-right:10px}.scope-panel{width:448px}.scope-panel__title{padding-bottom:8px;border-bottom:1px solid #edf0f5;font-weight:600}.scope-panel__body{display:flex;min-height:210px;padding-top:10px}.scope-panel__tree{position:relative;flex:1;max-height:260px;overflow:auto;padding-right:10px;border-right:1px solid #edf0f5}.scope-panel__stores{width:205px;padding-left:12px}.scope-panel__stores-title{margin-bottom:8px;color:#666;font-size:12px}.scope-store-list{max-height:220px;overflow:auto}.scope-store-list button{display:block;width:100%;padding:6px 8px;border:0;border-radius:3px;background:transparent;text-align:left;cursor:pointer}.scope-store-list button:hover,.scope-store-list button.active{background:#edf5ff;color:#2d8cf0}.scope-panel__empty{color:#bbb;font-size:12px}.scope-panel__footer{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid #edf0f5;color:#999;font-size:12px}.metric-card{padding:16px;border-radius:4px;background:#f6f8fb}.metric-card span,.metric-card small{display:block;color:#888;font-size:12px}.metric-card strong{display:block;margin-top:8px;font-size:24px;color:#2d8cf0}.metric-card small{display:inline;margin-left:4px}.pending{margin-bottom:12px;color:#999}.pending span{margin-left:12px}.report-column-groups{display:flex;align-items:stretch;min-height:30px;margin-bottom:-1px;border:1px solid #dcdee2;border-bottom:0;background:#f8f8f9;color:#515a6e;font-size:12px;text-align:center}.report-column-group{display:flex;align-items:center;justify-content:center;padding:6px 8px;border-right:1px solid #dcdee2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.report-column-group:last-child{border-right:0}.report-column-group.is-grouped{font-weight:600;color:#2d8cf0}.page{margin-top:16px;text-align:right}
</style>
