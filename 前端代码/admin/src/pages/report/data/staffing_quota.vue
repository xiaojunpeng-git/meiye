<template>
  <section class="staffing-page">
    <div class="page-head">
      <div><h1>岗位编制</h1><p>统一维护组织和门店的岗位编制人数，员工看板与报表使用同一份配置。</p></div>
      <span class="updated">数据截至 {{ dataAsOf || '--' }}</span>
    </div>
    <div class="toolbar">
      <label class="filter-label" for="staffing-scope">范围</label>
      <select id="staffing-scope" v-model="scopeType" class="filter-select" @change="changeScope"><option value="organization">组织</option><option value="store">门店</option></select>
      <span class="filter-label">组织架构</span>
      <OrganizationStoreScopePicker :value="[]" :load-scope="loadOrganizationScope" selection-mode="org_only" :selected-organization-id="organizationId" :selected-label="organizationName" empty-label="全部组织" clear-label="全部组织" @change="onOrganizationChange" />
      <label class="filter-label" for="staffing-position">岗位</label>
      <select id="staffing-position" v-model.number="positionId" class="filter-select position-select" @change="changeFilter"><option :value="0">全部岗位</option><option v-for="position in positions" :key="position.id" :value="Number(position.id)">{{ position.name }}</option></select>
      <input v-model.trim="keyword" class="keyword" :placeholder="scopeType === 'organization' ? '搜索组织' : '搜索门店'" @keyup.enter="changeFilter">
      <button class="plain" @click="load(1)">查询</button>
      <button v-if="!editing" class="primary" @click="startEdit">一键编辑</button>
      <template v-else><button class="primary" :disabled="saving" @click="saveAll">{{ saving ? '保存中...' : '一键保存' }}</button><button class="plain" :disabled="saving" @click="cancelEdit">取消编辑</button></template>
    </div>
    <div v-if="loading" class="state">正在读取岗位编制...</div>
    <div v-else-if="error" class="state error"><b>{{ error }}</b><button class="primary" @click="load(page)">重试</button></div>
    <div v-else class="table-wrap">
      <table><thead><tr><th>范围</th><th>{{ scopeType === 'organization' ? '组织' : '门店' }}</th><th>岗位</th><th>当前在职人数</th><th class="quota-col">岗位编制</th><th>缺口</th><th>满岗率</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.row_key"><td>{{ scopeType === 'organization' ? '组织' : '门店' }}</td><td>{{ row.scope_name }}</td><td>{{ row.position_name }}</td><td>{{ row.active_count }}人</td><td class="quota-col"><input v-if="editing" v-model.number="row.quota_count" type="number" min="0" step="1"><strong v-else>{{ row.quota_count }}人</strong></td><td :class="{ over: row.gap < 0 }">{{ row.gap < 0 ? `超编 ${Math.abs(row.gap)}人` : `${row.gap}人` }}</td><td>{{ row.fill_rate == null ? '--' : `${row.fill_rate}%` }}</td></tr><tr v-if="!rows.length"><td colspan="7" class="empty">当前筛选范围暂无组织、门店或启用岗位</td></tr></tbody>
      </table>
    </div>
    <div class="footer"><span>当前表格共 {{ total }} 条记录，编辑后点击“一键保存”提交全部修改。</span><span>岗位编制数据统一维护</span></div>
  </section>
</template>

<script>
import { staffingQuotaList, saveStaffingQuota, reportOrganizationTree } from '@/api/report'
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue'

export default {
  name: 'StaffingQuota',
  components: { OrganizationStoreScopePicker },
  data () { return { scopeType: 'store', keyword: '', organizationId: 0, organizationName: '', positionId: 0, positions: [], page: 1, limit: 100000, total: 0, rows: [], loading: false, saving: false, editing: false, error: '', dataAsOf: '', loadSequence: 0 } },
  mounted () { this.load(1) },
  methods: {
    /** 组织架构接口直接返回节点数组，适配共用选择器的 tree 结构。 */
    async loadOrganizationScope () { const res = await reportOrganizationTree(); const data = res.data || res; return { tree: Array.isArray(data) ? data : (data.tree || []) } },
    async load (page) {
      const sequence = ++this.loadSequence
      this.loading = true; this.error = ''; this.page = Math.max(1, page)
      // 组织、岗位连续切换时，只展示最后一次查询，避免慢请求覆盖新的筛选结果。
      try { const res = await staffingQuotaList({ scope_type: this.scopeType, org_id: this.organizationId, position_id: this.positionId, keyword: this.keyword, page: this.page, limit: this.limit }); if (sequence !== this.loadSequence) return; const data = res.data || res; this.rows = (data.rows || []).map(row => ({ ...row })); this.positions = data.positions || []; this.total = Number(data.total || 0); this.dataAsOf = data.data_as_of || ''; this.editing = false } catch (e) { if (sequence === this.loadSequence) this.error = (e && e.message) || '岗位编制读取失败' } finally { if (sequence === this.loadSequence) this.loading = false }
    },
    /** 切换组织或门店时重新读取适用岗位，避免保留另一范围的岗位 ID。 */
    changeScope () { this.positionId = 0; this.load(1) },
    /** 组织树只传稳定 ID；后端负责展开下级组织并裁剪门店范围。 */
    onOrganizationChange (selection) { this.organizationId = Number(selection.orgId || 0); this.organizationName = this.organizationId ? selection.label : ''; this.load(1) },
    changeFilter () { this.load(1) },
    startEdit () { this.editing = true },
    cancelEdit () { this.editing = false; this.load(this.page) },
    async saveAll () {
      this.saving = true
      try { await saveStaffingQuota({ request_id: `staffing-${Date.now()}-${Math.random().toString(16).slice(2)}`, rows: this.rows.map(row => ({ scope_type: row.scope_type, scope_id: row.scope_id, position_id: row.position_id, quota_count: Number(row.quota_count), version: row.version })) }); this.$Message.success('岗位编制已保存'); await this.load(this.page) } catch (e) { this.$Message.error((e && e.message) || '岗位编制保存失败') } finally { this.saving = false }
    }
  }
}
</script>

<style lang="less" scoped>
.staffing-page { min-height: calc(100vh - 120px); padding: 24px; background: #f5f7f9; color: #2b4358; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; } h1 { margin: 0 0 7px; font-size: 24px; color: #1f5f8b; } p { margin: 0; color: #7d8d9b; } .updated { color: #8b9aa5; font-size: 12px; }
.toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 13px; margin-bottom: 14px; background: #fff; border: 1px solid #dce6ef; border-radius: 6px; } button { min-height: 32px; padding: 5px 14px; border: 1px solid #d5e0e8; background: #fff; color: #526b7e; cursor: pointer; } .primary { background: #236f9e; color: #fff; border-color: #236f9e; } button:disabled { opacity: .55; cursor: not-allowed; } .filter-label { color: #526b7e; font-size: 13px; white-space: nowrap; } .filter-select, .keyword { min-height: 32px; padding: 5px 9px; border: 1px solid #d5e0e8; border-radius: 4px; background: #fff; color: #526b7e; } .position-select { width: 150px; } .keyword { width: 180px; }
.table-wrap { overflow: auto; background: #fff; border: 1px solid #dce6ef; } table { width: 100%; min-width: 860px; border-collapse: collapse; } th, td { height: 44px; padding: 7px 12px; border-bottom: 1px solid #edf1f5; text-align: left; white-space: nowrap; } th { position: sticky; top: 0; z-index: 1; background: #edf5fa; color: #486477; font-weight: 700; } td { color: #526b7e; } .quota-col { width: 170px; background: #fffaf0; } .quota-col input { width: 110px; padding: 6px 8px; border: 1px solid #d7a44c; border-radius: 4px; } .over { color: #b45749; } .empty, .state { padding: 70px 20px; text-align: center; color: #8c9aa5; } .state.error { color: #b45749; } .state.error button { margin-left: 12px; } .footer { display: flex; align-items: center; gap: 12px; padding: 13px 0; color: #83929d; font-size: 12px; } .footer span:first-child { margin-right: auto; } .footer button { min-height: 28px; padding: 3px 10px; }
</style>
