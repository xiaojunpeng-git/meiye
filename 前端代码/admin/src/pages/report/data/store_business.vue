<template>
  <div class="business-report">
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div class="report-heading"><div><h2>门店运营报表</h2><p>平台端按当前组织权限查询，选择组织后仍按门店维度展示数据</p></div></div>
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
                <div class="scope-panel__stores-title">{{ selectedOrganizationName || '选择组织后查看组织及下级门店' }}</div>
                <Spin v-if="storeLoading" size="small" />
                <div v-else-if="scopeStores.length" class="scope-store-list">
                  <button v-for="store in scopeStores" :key="store.id" type="button" :class="{ active: scope.store_id === Number(store.id) }" @click="selectStore(store)">{{ store.name }}</button>
                </div>
                <div v-else class="scope-panel__empty">该组织及下级暂无门店</div>
              </div>
            </div>
              <div class="scope-panel__footer"><Button size="small" @click="selectAllAllowed">当前权限范围</Button><span>选择组织查询其全部下级门店；选择门店仅查询该门店。</span></div>
          </div>
        </Poptip>
        <DatePicker v-model="dateRange" type="daterange" format="yyyy-MM-dd" placeholder="统计日期" style="width:240px" />
        <Button class="ml10" @click="advancedFilters = !advancedFilters">{{ advancedFilters ? '收起筛选' : '更多筛选' }}</Button>
        <Button type="primary" class="ml10" :loading="loading" @click="loadReport">查询</Button>
        <Button class="ml10" @click="exportReport">导出当前明细</Button>
      </Row>
      <Row v-if="advancedFilters" type="flex" class="filters filters--advanced">
        <Input v-model.trim="categoryId" placeholder="商品分类编号" style="width:150px" />
        <Input v-model.trim="categoryPath" placeholder="分类路径" style="width:180px" />
        <Select v-model="productType" style="width:130px"><Option value="">全部类型</Option><Option value="card">卡项</Option><Option value="project">项目</Option><Option value="product">产品</Option></Select>
        <Input v-model.trim="partnerName" placeholder="合作方名称" style="width:150px" />
        <Button v-for="item in personFiltersMeta" :key="item.key" @click="openPersonPicker(item)">{{ personFilters[item.key] ? `${item.label}：${personFilters[item.key]}` : `选择${item.label}` }}</Button>
        <Button @click="clearAdvancedFilters">清空筛选</Button>
      </Row>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div v-if="pendingMetrics.length" class="pending"><Tag color="orange">口径待确认</Tag><span v-for="item in pendingMetrics" :key="item">{{ item }}</span></div>
      <Table :columns="columns" :data="records" :loading="loading" border />
      <Page v-if="total > pageSize" :total="total" :current="page" :page-size="pageSize" show-total class="page" @on-change="changePage" />
    </Card>
    <Modal v-model="editingVisible" title="编辑报表补充字段" footer-hide>
      <Form v-if="editing" label-position="top">
        <FormItem label="字段"><Select v-model="editField"><Option v-for="field in editableFields" :key="field.key" :value="field.key">{{ field.label }}</Option></Select></FormItem>
        <FormItem label="内容"><Input v-model.trim="editValue" type="textarea" :maxlength="65535" /></FormItem>
      </Form>
      <div class="person-picker__footer"><Button @click="editing = null">取消</Button><Button type="primary" :loading="savingEdit" @click="saveEdit">保存</Button></div>
    </Modal>
    <Modal v-model="personPicker.visible" :title="`选择${personPicker.label}`" footer-hide width="620">
      <div class="person-picker">
        <Input v-model.trim="personPicker.keyword" search enter-button="搜索" placeholder="输入姓名、工号或手机号" @on-search="searchPersons" @on-enter="searchPersons" />
        <Table :columns="personPickerColumns" :data="personPicker.records" :loading="personPicker.loading" class="mt15" size="small" />
        <div v-if="personPicker.searched && !personPicker.loading && !personPicker.records.length" class="person-picker__empty">当前范围内未找到人员</div>
      </div>
    </Modal>
  </div>
</template>

<script>
import { unifiedBusinessReportCatalog, unifiedBusinessReportQuery, unifiedBusinessReportExport, saveUnifiedBusinessReportAnnotation, reportOrganizationTree, reportOrganizationStores } from '@/api/report'
import { merchantStaffList } from '@/api/setting'
import exportExcel from '@/utils/newToExcel.js'

export default {
  name: 'PlatformStoreBusinessReport',
  data () { return { catalog: [], activeReport: '', dateRange: [], loading: false, reportMeta: {}, cards: [], columns: [], records: [], columnGroups: [], exportColumns: [], total: 0, page: 1, pageSize: 20, pendingMetrics: [], advancedFilters: false, categoryId: '', categoryPath: '', productType: '', partnerName: '', personFilters: {}, personPicker: { visible: false, key: '', label: '', keyword: '', records: [], loading: false, searched: false }, editing: null, editValue: '', editField: 'remark', savingEdit: false, scopeVisible: false, scopeLoading: false, storeLoading: false, scopeStoresResolved: false, scopeTree: [], scopeStores: [], scope: { org_id: 0, store_id: 0, label: '' }, selectedOrganizationName: '' } },
  created () { this.initialise() },
  computed: {
    scopeLabel () { return this.scope.label || '当前权限范围' },
    editingVisible: {
      get () { return !!this.editing },
      set (value) { if (!value) this.editing = null }
    },
    editableReport () { return ['partner_item_detail', 'market_performance'].indexOf(this.activeReport) !== -1 },
    editableFields () { return this.activeReport === 'market_performance' ? [{ key: 'remark', label: '备注' }, { key: 'walk_in_manual_count', label: '手动进店人次' }] : [{ key: 'remark', label: '备注' }, { key: 'expert_name', label: '专家' }] },
    personFiltersMeta () { return [{ key: 'salesperson_id', label: '销售人' }, { key: 'sales_manager_id', label: '销售经理' }, { key: 'guide_id', label: '导购' }, { key: 'craftsman_id', label: '手艺人' }] },
    personPickerColumns () { return [{ title: '人员', key: 'staff_name', minWidth: 150, render: (h, params) => h('span', params.row.staff_name || '-') }, { title: '门店', key: 'store_name', minWidth: 160, render: (h, params) => h('span', params.row.store_name || (params.row.is_organization_direct == 1 ? '无店直属' : '-')) }, { title: '工号', key: 'employee_id', width: 110, render: (h, params) => h('span', String(params.row.employee_id || params.row.id || '-')) }, { title: '操作', key: '__action', width: 80, render: (h, params) => h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.choosePerson(params.row) } }, '选择') }] }
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
    params (extra) { const range = this.dateRange || []; const scope = this.scope.store_id > 0 ? { store_id: this.scope.store_id } : (this.scope.org_id > 0 ? { org_id: this.scope.org_id } : {}); return Object.assign({ report: this.activeReport, start_date: this.formatDate(range[0]), end_date: this.formatDate(range[1]), category_id: this.categoryId, category_path: this.categoryPath, product_type: this.productType, partner_name: this.partnerName, page: this.page, limit: this.pageSize }, this.personFilters, scope, extra || {}) },
    loadReport () { if (!this.activeReport) return; if (this.scope.org_id > 0 && this.scopeStoresResolved && !this.scopeStores.length) { this.clearReport(); this.$Message.warning('当前组织及下级暂无可查询门店'); return } this.loading = true; unifiedBusinessReportQuery(this.params()).then(res => { const data = res.data || {}; const model = this.buildReportModel(data); this.reportMeta = data; this.cards = data.cards || []; this.columns = model.columns; this.exportColumns = model.exportColumns; this.columnGroups = model.groups; this.records = model.records; this.total = data.total || 0; this.pendingMetrics = data.pending_metrics || [] }).catch(err => this.$Message.error(err.msg || '读取报表失败')).finally(() => { this.loading = false }) },
    changePage (page) { this.page = page; this.loadReport() },
    clearReport () { this.reportMeta = {}; this.cards = []; this.columns = []; this.exportColumns = []; this.columnGroups = []; this.records = []; this.total = 0; this.pendingMetrics = [] },
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
      const tableColumns = result.map(item => { if (item.__groupLabel) { const clone = Object.assign({}, item); delete clone.__groupLabel; return clone } return item })
      if (this.editableReport) tableColumns.push({ title: '操作', key: '__actions', width: 88, fixed: 'right', render: (h, params) => h('Button', { props: { type: 'text', size: 'small' }, on: { click: () => this.beginEdit(params.row) } }, '编辑') })
      return tableColumns
    },
    clearAdvancedFilters () { this.categoryId = ''; this.categoryPath = ''; this.productType = ''; this.partnerName = ''; this.personFilters = {}; this.page = 1; this.loadReport() },
    openPersonPicker (item) { this.personPicker = { visible: true, key: item.key, label: item.label, keyword: '', records: [], loading: false, searched: false } },
    searchPersons () {
      const keyword = String(this.personPicker.keyword || '').trim()
      if (keyword.length < 2) { this.$Message.warning('请输入至少 2 个字符后搜索'); return }
      this.personPicker.loading = true
      const scope = this.scope.store_id > 0 ? { store_id: this.scope.store_id } : (this.scope.org_id > 0 ? { org_id: this.scope.org_id } : {})
      merchantStaffList(Object.assign({ keyword, status: 1, page: 1, limit: 20 }, scope)).then(res => { this.personPicker.records = ((res.data || {}).list || []).filter(row => Number(row.employee_id || row.id || 0) > 0); this.personPicker.searched = true }).catch(err => this.$Message.error(err.msg || '人员搜索失败')).finally(() => { this.personPicker.loading = false })
    },
    choosePerson (row) { const id = Number(row.employee_id || row.id || 0); if (!id || !this.personPicker.key) return; this.personFilters = Object.assign({}, this.personFilters, { [this.personPicker.key]: id }); this.personPicker.visible = false },
    beginEdit (row) { if (!this.editableReport) return; if (this.scope.store_id <= 0) { this.$Message.warning('请先在组织 / 门店中选择具体门店后再编辑'); return } this.editing = row; this.editField = this.editableFields[0] ? this.editableFields[0].key : 'remark'; this.editValue = '' },
    saveEdit () {
      if (!this.editing || !String(this.editValue || '').trim()) { this.$Message.warning('请输入补充内容'); return }
      this.savingEdit = true
      const row = this.editing
      const subjectKey = String(row.order_no_snapshot || row.source_line_id || `${row.store_id || ''}:${row.business_source_primary_id || ''}`)
      saveUnifiedBusinessReportAnnotation({ store_id: this.scope.store_id, report_code: this.activeReport, subject_type: 'report_row', subject_key: subjectKey, field_key: this.editField, field_value: String(this.editValue).trim(), expected_version: 0, idempotency_key: `platform-${this.activeReport}-${Date.now()}-${Math.random().toString(16).slice(2)}` }).then(() => { this.editing = null; this.editValue = ''; this.loadReport() }).catch(err => this.$Message.error(err.msg || '保存报表补充内容失败')).finally(() => { this.savingEdit = false })
    },
    loadScopeTree () { this.scopeLoading = true; return reportOrganizationTree().then(res => { this.scopeTree = this.normalizeOrganizationTree(res.data || []); }).catch(err => { this.$Message.error(err.msg || '组织树加载失败'); this.scopeTree = [] }).finally(() => { this.scopeLoading = false }) },
    normalizeOrganizationTree (nodes) { return (nodes || []).map(node => ({ title: node.name || node.title || `组织${node.id}`, id: Number(node.id), org_id: Number(node.id), scopeType: 'org', expand: false, children: this.normalizeOrganizationTree(node.children || []) })) },
    onOrganizationSelect (nodes) { const node = (nodes || [])[0]; if (!node) return; if (node.scopeType === 'store') { this.selectStore(node); return } if (!node.org_id) return; const orgId = Number(node.org_id); this.scope = { org_id: orgId, store_id: 0, label: node.title }; this.selectedOrganizationName = node.title; this.scopeStoresResolved = false; this.page = 1; this.loadOrganizationStores(orgId).then(stores => { if (stores.length) this.loadReport(); else { this.clearReport(); this.$Message.warning('当前组织及下级暂无可查询门店') } }) },
    loadOrganizationStores (orgId) { if (!orgId) { this.scopeStores = []; this.scopeStoresResolved = true; return Promise.resolve([]) } this.storeLoading = true; return reportOrganizationStores({ org_id: orgId, scope: 'all', page: 1, limit: 50 }).then(res => { const data = res.data || {}; this.scopeStores = data.list || data || []; const node = this.findOrganizationNode(this.scopeTree, orgId); if (node) { const orgChildren = (node.children || []).filter(child => child.scopeType !== 'store'); node.children = orgChildren.concat(this.scopeStores.map(store => ({ title: store.name || `门店${store.id}`, id: Number(store.id), store_id: Number(store.id), scopeType: 'store', isLeaf: true }))); } return this.scopeStores }).catch(err => { this.scopeStores = []; this.$Message.error(err.msg || '门店列表加载失败'); return [] }).finally(() => { this.scopeStoresResolved = true; this.storeLoading = false }) },
    findOrganizationNode (nodes, orgId) { for (let i = 0; i < (nodes || []).length; i++) { const node = nodes[i]; if (node.scopeType === 'org' && Number(node.org_id) === Number(orgId)) return node; const found = this.findOrganizationNode(node.children || [], orgId); if (found) return found } return null },
    selectStore (store) { const storeId = Number(store.id); if (!storeId) return; this.scope = { org_id: 0, store_id: storeId, label: store.name || `门店${storeId}` }; this.scopeStoresResolved = true; this.scopeVisible = false; this.page = 1; this.loadReport() },
    selectAllAllowed () { this.scope = { org_id: 0, store_id: 0, label: '' }; this.selectedOrganizationName = ''; this.scopeStores = []; this.scopeStoresResolved = false; this.scopeVisible = false; this.page = 1; this.loadReport() }
  }
}
</script>

<style lang="less" scoped>
.report-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.report-heading h2{margin:0;font-size:18px}.report-heading p,.report-heading span{margin:5px 0 0;color:#999;font-size:12px}.filters{margin:8px 0 14px}.scope-picker{margin-right:10px}.scope-panel{width:448px}.scope-panel__title{padding-bottom:8px;border-bottom:1px solid #edf0f5;font-weight:600}.scope-panel__body{display:flex;min-height:210px;padding-top:10px}.scope-panel__tree{position:relative;flex:1;max-height:260px;overflow:auto;padding-right:10px;border-right:1px solid #edf0f5}.scope-panel__stores{width:205px;padding-left:12px}.scope-panel__stores-title{margin-bottom:8px;color:#666;font-size:12px}.scope-store-list{max-height:220px;overflow:auto}.scope-store-list button{display:block;width:100%;padding:6px 8px;border:0;border-radius:3px;background:transparent;text-align:left;cursor:pointer}.scope-store-list button:hover,.scope-store-list button.active{background:#edf5ff;color:#2d8cf0}.scope-panel__empty{color:#bbb;font-size:12px}.scope-panel__footer{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid #edf0f5;color:#999;font-size:12px}.person-picker__footer{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.person-picker__empty{padding:16px 0;color:#999;text-align:center}.metric-card{padding:16px;border-radius:4px;background:#f6f8fb}.metric-card span,.metric-card small{display:block;color:#888;font-size:12px}.metric-card strong{display:block;margin-top:8px;font-size:24px;color:#2d8cf0}.metric-card small{display:inline;margin-left:4px}.pending{margin-bottom:12px;color:#999}.pending span{margin-left:12px}.report-column-groups{display:flex;align-items:stretch;min-height:30px;margin-bottom:-1px;border:1px solid #dcdee2;border-bottom:0;background:#f8f8f9;color:#515a6e;font-size:12px;text-align:center}.report-column-group{display:flex;align-items:center;justify-content:center;padding:6px 8px;border-right:1px solid #dcdee2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.report-column-group:last-child{border-right:0}.report-column-group.is-grouped{font-weight:600;color:#2d8cf0}.page{margin-top:16px;text-align:right}
</style>
