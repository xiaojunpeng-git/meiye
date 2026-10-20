<template>
  <div class="target-scheme-page">
    <header class="target-scheme-page__heading"><button type="button" @click="backToList">返回方案列表</button><h1>{{ isCreate ? '新增目标方案' : (isDraft ? '编辑目标方案' : '查看目标方案') }}</h1><p>按月为有权限的门店设置指标目标；关联报表后统一生效。</p></header>
    <div class="target-scheme-page__layout">
      <main class="target-scheme-page__main">
        <p v-if="error" class="scheme-error" role="alert">{{ error }}</p>
        <div v-if="isCreate" class="scheme-new"><label>方案名称<input v-model.trim="newName" maxlength="120" placeholder="例如：2026年10月经营目标" /></label><label>方案说明<input v-model.trim="newDescription" maxlength="500" placeholder="选填" /></label><button type="button" :disabled="busy || !newName" @click="create">创建草稿并继续编辑</button></div>
        <p v-else-if="!detail">正在加载方案…</p>
        <template v-else>
          <div class="scheme-summary"><div><h2>{{ detail.scheme.name }}</h2><p>版本 {{ detail.scheme.version }} · {{ schemeStatusLabel }} · {{ lines.length }} 条目标</p></div><div class="scheme-summary__actions"><button type="button" @click="downloadTemplate">下载 Excel 模板</button><label v-if="isDraft" class="scheme-upload">导入 Excel<input type="file" accept=".xlsx,.xls" @change="importExcel" /></label><button v-if="isDraft" type="button" @click="addLine">添加目标</button></div></div>
          <p v-if="detail.void_info" class="scheme-note">作废原因：{{ detail.void_info.reason }} · 操作人 ID：{{ detail.void_info.actor_id }} · 作废时间：{{ voidTime }}</p>
          <div v-if="isDraft" class="scheme-metadata"><label>方案名称<input v-model.trim="editName" maxlength="120" /></label><label>方案说明<input v-model.trim="editDescription" maxlength="500" /></label><button type="button" :disabled="busy || !editName" @click="saveMetadata">保存方案信息</button></div>
          <p class="scheme-note">目标金额以元录入，保存后按分精度存储。选择组织时按授权门店分别设置；全店、分类、商品目标独立展示，不相加。</p>
          <div v-if="isDraft" class="scheme-batch">
            <div class="scheme-batch__header"><div><h3>批量设置目标</h3><p>先选门店范围，再填写共同适用的月份、指标和目标值。</p></div><button type="button" :disabled="!batchStores.length" @click="addForSelectedStores">仅生成空白明细</button></div>
            <div class="scheme-batch__scope"><span class="scheme-batch__label">目标门店</span><div class="scheme-batch__stores"><OrganizationStoreScopePicker v-model="batchStores" :load-scope="loadScope" /><span>已选 {{ batchStores.length }} 家门店</span></div></div>
            <div class="scheme-batch__fields" :class="{ 'scheme-batch__fields--scoped': batchScopeType !== 'store' }">
              <label>月份<input v-model="batchMonth" type="month" /></label>
              <label>指标<select v-model="batchMetricCode" @change="onBatchMetricChanged"><option v-for="metric in catalog.metrics" :key="metric.code" :value="metric.code">{{ metric.name }}</option></select></label>
              <label>对象<select v-model="batchScopeType" @change="batchScopeId = 0"><option v-for="scope in batchScopes" :key="scope" :value="scope">{{ scopeLabel(scope) }}</option></select></label>
              <label v-if="batchScopeType === 'category'">商品分类<select v-model.number="batchScopeId"><option :value="0">请选择分类</option><option v-for="category in catalog.categories" :key="category.id" :value="Number(category.id)">{{ category.cate_name }}</option></select></label>
              <label v-else-if="batchScopeType === 'product'">商品 ID<input v-model.number="batchScopeId" type="number" min="1" placeholder="输入商品 ID" /></label>
              <label>目标值<input v-model.trim="batchDisplayValue" type="text" inputmode="decimal" placeholder="请输入目标值" /></label>
            </div>
            <div class="scheme-batch__actions"><label class="scheme-batch__overwrite"><input v-model="batchOverwrite" type="checkbox" />覆盖已有值</label><button class="scheme-batch__apply" type="button" :disabled="!batchStores.length" @click="applyBatchValue">批量应用到所选 {{ batchStores.length }} 家门店</button></div>
            <p v-if="batchMessage" class="scheme-batch__message" role="status">{{ batchMessage }}</p>
            <p class="scheme-batch__hint">仅修改当前页面草稿明细；检查后点击“保存草稿”才会写入数据库。默认不覆盖已填写的目标值。商品 ID 属于门店商品，只能对单店应用。</p>
          </div>
          <div class="scheme-table-wrap"><table class="scheme-table"><thead><tr><th>月份</th><th>门店</th><th>指标</th><th>对象</th><th>商品或分类</th><th>目标值</th><th v-if="isDraft">操作</th><th v-else>当前生效报表</th></tr></thead><tbody>
            <tr v-for="(row, index) in lines" :key="row.localKey">
              <td><input v-model="row.month_key" type="month" :disabled="!isDraft" /></td>
              <td><select v-model.number="row.store_id" :disabled="!isDraft" @change="onStoreChanged(row)"><option :value="0">请选择</option><option v-for="store in catalog.stores" :key="store.id" :value="Number(store.id)">{{ store.name }}</option></select></td>
              <td><select v-model="row.metric_code" :disabled="!isDraft" @change="onMetricChanged(row)"><option v-for="metric in catalog.metrics" :key="metric.code" :value="metric.code">{{ metric.name }}</option></select></td>
              <td><select v-model="row.scope_type" :disabled="!isDraft" @change="onScopeChanged(row)"><option v-for="scope in scopesFor(row)" :key="scope" :value="scope">{{ scopeLabel(scope) }}</option></select></td>
              <td><span v-if="row.scope_type === 'store'">全店</span><select v-else-if="row.scope_type === 'category'" v-model.number="row.scope_id" :disabled="!isDraft"><option :value="0">请选择分类</option><option v-for="category in catalog.categories" :key="category.id" :value="Number(category.id)">{{ category.cate_name }}</option></select><div v-else class="scheme-product"><input v-model.number="row.scope_id" type="number" min="1" :disabled="!isDraft" placeholder="商品 ID" /><button v-if="isDraft" type="button" @click="searchProducts(row)">查找</button><select v-if="row.productOptions && row.productOptions.length" v-model.number="row.scope_id" @change="onProductSelected(row)"><option v-for="product in row.productOptions" :key="product.id" :value="Number(product.id)">{{ product.store_name }}</option></select><span v-else-if="row.scope_name">{{ row.scope_name }}</span></div></td>
              <td><input v-model.trim="row.displayValue" type="text" inputmode="decimal" :disabled="!isDraft" :aria-label="`第${index+1}行目标值`" /><small>{{ unitFor(row) }}</small></td>
              <td v-if="isDraft"><button type="button" @click="lines.splice(index, 1)">移除</button></td>
              <td v-else>{{ (row.active_report_codes || []).map(code => catalog.reports[code] || code).join('、') || '无' }}</td>
            </tr>
            <tr v-if="!lines.length"><td colspan="7" class="scheme-empty">暂无目标明细</td></tr>
          </tbody></table></div>
          <div class="scheme-footer"><button v-if="isDraft" type="button" :disabled="busy || !lines.length" @click="saveLines">保存草稿</button><span v-if="isDraft">导入后请先检查明细，再保存和启用。</span></div>
          <section class="scheme-reports"><h3>引用此方案的报表</h3><p>启用时只切换所选报表；其他报表继续使用各自绑定的方案。</p><div class="scheme-reports__grid"><label v-for="(name, code) in catalog.reports" :key="code"><input v-model="selectedReports" type="checkbox" :value="code" :disabled="!isDraft" />{{ name }}</label></div><button v-if="isDraft" type="button" :disabled="busy || !lines.length || !selectedReports.length" @click="activate">启用并应用到所选报表</button></section>
        </template>
      </main>
    </div>
    <Modal v-model="activationSucceeded" title="启用成功" :closable="false" :mask-closable="false" :width="420">
      <p>方案已成功启用并应用到所选报表。</p>
      <div slot="footer"><Button type="primary" @click="backToList">确认</Button></div>
    </Modal>
    <div v-if="activationConflict" class="scheme-conflict-backdrop">
      <section class="scheme-conflict-dialog" role="dialog" aria-modal="true" aria-labelledby="scheme-conflict-title">
        <h2 id="scheme-conflict-title">确认替换相同目标</h2>
        <p>以下 {{ activationConflict.conflicts.length }} 项已有生效目标。确认后仅替换这些引用，其他月份、门店和目标保持不变。</p>
        <div class="scheme-conflict-table"><table><thead><tr><th>报表</th><th>月份</th><th>门店</th><th>指标</th><th>对象</th><th>原方案编号</th></tr></thead><tbody><tr v-for="row in activationConflict.conflicts" :key="row.binding_id"><td>{{ catalog.reports[row.report_code] }}</td><td>{{ row.month_key }}</td><td>{{ conflictStore(row) }}</td><td>{{ conflictMetric(row) }}</td><td>{{ scopeLabel(row.scope_type) }}{{ row.scope_id ? ` #${row.scope_id}` : '' }}</td><td>{{ row.old_scheme_id }}</td></tr></tbody></table></div>
        <p v-if="error" class="scheme-error" role="alert">{{ error }}</p>
        <footer><button type="button" :disabled="busy" @click="activationConflict = null">取消</button><button type="button" :disabled="busy" @click="submitActivation(activationConflict.conflict_token)">确认替换并启用</button></footer>
      </section>
    </div>
  </div>
</template>

<script>
import * as XLSX from 'xlsx';
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import { schemeCatalog, schemeProducts, schemeDetail, schemeCreate, schemeUpdate, schemeSaveLines, schemeActivate } from '@/api/target';

const DEFAULT_REPORTS = ['group_dashboard', 'store_target', 'target_analysis', 'target_monthly', 'target_store', 'merchant_target_analysis'];
const randomKey = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

export default {
  name: 'TargetSchemeEditPage',
  components: { OrganizationStoreScopePicker },
  // 冲突确认绑定服务端版本与请求快照，不能在用户确认期间悄悄更换目标报表。
  beforeCreate() { this.activationPayload = null; this.activationRequest = null; },
  data() { return { activationSucceeded: false, activationConflict: null, catalog: { stores: [], metrics: [], categories: [], reports: {}}, detail: null, selectedId: 0, lines: [], batchStores: [], batchMonth: new Date().toLocaleDateString('sv-SE').slice(0, 7), batchMetricCode: '', batchScopeType: 'store', batchScopeId: 0, batchDisplayValue: '', batchOverwrite: false, batchMessage: '', selectedReports: [], newName: '', newDescription: '', editName: '', editDescription: '', busy: false, error: '' }; },
  computed: { isDraft() { return this.detail && this.detail.scheme.status === 'draft'; }, isCreate() { return this.$route.name === 'target_scheme_create'; }, schemeStatusLabel() { const status = this.detail && this.detail.scheme.status; return status === 'active' ? '已生效' : (status === 'void' ? '已作废' : '草稿'); }, voidTime() { const seconds = Number(this.detail && this.detail.void_info && this.detail.void_info.occurred_at); return seconds ? new Date(seconds * 1000).toLocaleString('zh-CN', { hour12: false, timeZone: 'Asia/Shanghai' }) : '—'; }, batchScopes() { const metric = this.catalog.metrics.find(item => item.code === this.batchMetricCode); return metric ? metric.scopes : ['store']; } },
  watch: { '$route.params.id'(id) { if (id) this.openScheme(id); } },
  async created() { await this.load(); },
  methods: {
    async load() {
      try { const catalog = await schemeCatalog(); this.catalog = catalog.data || this.catalog; this.batchMetricCode = this.catalog.metrics[0] ? this.catalog.metrics[0].code : ''; if (!this.isCreate) await this.openScheme(this.$route.params.id); } catch (error) { this.error = error.message || '目标方案加载失败'; }
    },
    backToList() { this.$router.push({ name: 'target_scheme' }); },
    loadScope() { return schemeCatalog(); },
    async openScheme(id) {
      this.error = ''; this.selectedId = Number(id);
      try { const result = await schemeDetail(id); this.detail = result.data; this.editName = this.detail.scheme.name; this.editDescription = this.detail.scheme.description || ''; this.selectedReports = this.detail.report_codes && this.detail.report_codes.length ? this.detail.report_codes.slice() : (this.isDraft ? DEFAULT_REPORTS.slice() : []); this.lines = (this.detail.lines || []).map(line => ({ ...line, localKey: randomKey(), displayValue: this.displayValue(line), productOptions: [] })); } catch (error) { this.error = error.message || '方案详情加载失败'; }
    },
    async create() {
      this.error = ''; this.busy = true;
      try { const result = await schemeCreate({ name: this.newName, description: this.newDescription, idempotency_key: randomKey() }); this.newName = ''; this.newDescription = ''; await this.$router.replace({ name: 'target_scheme_edit', params: { id: result.data.id }}); } catch (error) { this.error = error.msg || error.message || '新建方案失败'; } finally { this.busy = false; }
    },
    /** 信息修改先校验方案版本，防止覆盖另一个编辑者已保存的草稿。 */
    async saveMetadata() {
      this.error = ''; this.busy = true;
      try { await schemeUpdate({ scheme_id: this.selectedId, expected_version: Number(this.detail.scheme.version), name: this.editName, description: this.editDescription, idempotency_key: randomKey() }); await this.openScheme(this.selectedId); } catch (error) { this.error = error.msg || error.message || '保存方案信息失败'; } finally { this.busy = false; }
    },
    addLine() { const metric = this.catalog.metrics[0]; this.lines.push({ localKey: randomKey(), store_id: 0, month_key: this.batchMonth, metric_code: metric ? metric.code : '', scope_type: 'store', scope_id: 0, displayValue: '', productOptions: [] }); },
    onBatchMetricChanged() { if (!this.batchScopes.includes(this.batchScopeType)) { this.batchScopeType = 'store'; this.batchScopeId = 0; } },
    /** 空白行用于逐店录入；重复点击不能产生相同目标键，否则草稿会被后端拒绝保存。 */
    addForSelectedStores() {
      this.error = ''; this.batchMessage = '';
      if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(this.batchMonth) || !this.batchMetricCode || !this.batchScopes.includes(this.batchScopeType)) { this.error = '请先选择有效月份、指标和对象'; return; }
      const scopeId = this.batchScopeType === 'store' ? 0 : Number(this.batchScopeId);
      if (this.batchScopeType !== 'store' && (!Number.isInteger(scopeId) || scopeId <= 0)) { this.error = '请先选择商品或分类'; return; }
      const allowed = new Set(this.catalog.stores.map(store => Number(store.id)));
      const stores = [...new Set(this.batchStores.map(Number))];
      if (stores.some(id => !allowed.has(id))) { this.error = '门店超出当前权限范围'; return; }
      if (this.batchScopeType === 'product' && stores.length > 1) { this.error = '商品 ID 按门店管理，请只选择一家门店'; return; }
      const missing = stores.filter(id => !this.lines.some(row => Number(row.store_id) === id && row.month_key === this.batchMonth && row.metric_code === this.batchMetricCode && row.scope_type === this.batchScopeType && Number(row.scope_id || 0) === scopeId));
      if (this.lines.length + missing.length > 3000) { this.error = '目标明细不能超过 3000 条，请缩小门店范围'; return; }
      this.lines.push(...missing.map(id => ({ localKey: randomKey(), store_id: id, month_key: this.batchMonth, metric_code: this.batchMetricCode, scope_type: this.batchScopeType, scope_id: scopeId, displayValue: '', productOptions: [] })));
      this.batchMessage = `已新增 ${missing.length} 条空白明细，跳过 ${stores.length - missing.length} 家已有相同目标的门店。`;
    },
    /** 批量修改只作用于当前授权门店与同一目标键；相同键的空白重复行可合并，冲突值必须人工确认。 */
    applyBatchValue() {
      this.error = ''; this.batchMessage = '';
      try {
        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(this.batchMonth)) throw new Error('请选择有效月份');
        if (!this.batchMetricCode || !this.batchScopes.includes(this.batchScopeType)) throw new Error('请选择有效指标和对象');
        if (this.batchScopeType !== 'store' && (!Number.isInteger(Number(this.batchScopeId)) || Number(this.batchScopeId) <= 0)) throw new Error('请选择有效商品或分类');
        if (this.batchScopeType === 'category' && !this.catalog.categories.some(item => Number(item.id) === Number(this.batchScopeId))) throw new Error('请选择有效商品分类');
        const targetValue = String(this.batchDisplayValue).trim();
        this.valueFor({ metric_code: this.batchMetricCode, displayValue: targetValue });
        const allowed = new Set(this.catalog.stores.map(store => Number(store.id)));
        const stores = [...new Set(this.batchStores.map(Number))];
        if (!stores.length || stores.some(id => !allowed.has(id))) throw new Error('请在当前权限范围内选择门店');
        if (this.batchScopeType === 'product' && stores.length > 1) throw new Error('商品 ID 按门店管理，请只选一家门店批量应用商品目标');
        const scopeId = this.batchScopeType === 'store' ? 0 : Number(this.batchScopeId);
        const sameTarget = (row, storeId) => Number(row.store_id) === storeId && row.month_key === this.batchMonth && row.metric_code === this.batchMetricCode && row.scope_type === this.batchScopeType && Number(row.scope_id || 0) === scopeId;
        const duplicateKeys = new Set();
        const additions = [];
        const updates = [];
        let applied = 0; let skipped = 0;
        stores.forEach(storeId => {
          const matches = this.lines.filter(row => sameTarget(row, storeId));
          const filled = matches.filter(row => String(row.displayValue || '').trim() !== '');
          const distinctValues = [...new Set(filled.map(row => this.valueFor(row)))];
          if (distinctValues.length > 1) throw new Error(`门店 ${this.catalog.stores.find(store => Number(store.id) === storeId).name} 存在目标值冲突，请先手工处理重复行`);
          if (!matches.length) {
            additions.push({ localKey: randomKey(), store_id: storeId, month_key: this.batchMonth, metric_code: this.batchMetricCode, scope_type: this.batchScopeType, scope_id: scopeId, displayValue: targetValue, productOptions: [] });
            applied += 1;
            return;
          }
          const keeper = filled[0] || matches[0];
          matches.forEach(row => { if (row !== keeper) duplicateKeys.add(row.localKey); });
          if (this.batchOverwrite || !filled.length) { updates.push(keeper); applied += 1; } else skipped += 1;
        });
        // 后端以门店、月份、指标和对象联合判重；在保存前移除本次批量范围内的空白重复行。
        if (this.lines.length - duplicateKeys.size + additions.length > 3000) throw new Error('目标明细不能超过 3000 条，请缩小门店范围');
        updates.forEach(row => { row.displayValue = targetValue; });
        this.lines = this.lines.filter(row => !duplicateKeys.has(row.localKey)).concat(additions);
        this.batchMessage = `已应用 ${applied} 家门店，保留原值 ${skipped} 家，合并重复行 ${duplicateKeys.size} 条；请检查明细并保存草稿。`;
      } catch (error) { this.error = error.message || '批量应用失败'; }
    },
    onStoreChanged(row) { row.productOptions = []; row.scope_name = ''; if (row.scope_type === 'product') row.scope_id = 0; },
    onMetricChanged(row) { if (!this.scopesFor(row).includes(row.scope_type)) { row.scope_type = 'store'; row.scope_id = 0; } },
    onScopeChanged(row) { row.scope_id = 0; row.productOptions = []; row.scope_name = ''; },
    onProductSelected(row) { const selected = row.productOptions.find(item => Number(item.id) === Number(row.scope_id)); row.scope_name = selected ? selected.store_name : ''; },
    scopeLabel(scope) { return { store: '全店', product: '商品', category: '商品分类' }[scope] || scope; },
    scopesFor(row) { const metric = this.catalog.metrics.find(item => item.code === row.metric_code); return metric ? metric.scopes : ['store']; },
    unitFor(row) { const metric = this.catalog.metrics.find(item => item.code === row.metric_code); return metric && metric.unit === 'fen' ? '元' : '个/人次'; },
    displayValue(row) { return row.unit_code === 'fen' ? String(Number(row.target_value || 0) / 100) : String(row.target_value || 0); },
    valueFor(row) {
      const raw = String(row.displayValue || '').trim();
      const unit = this.unitFor(row);
      if (unit === '元') { if (!/^\d+(\.\d{1,2})?$/.test(raw)) throw new Error('金额目标最多两位小数，且不能为负'); const parts = raw.split('.'); return String(Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'))); }
      if (!/^\d+$/.test(raw)) throw new Error('数量目标必须为非负整数');
      return raw;
    },
    normalizedLines() { return this.lines.map(row => ({ store_id: Number(row.store_id), month_key: row.month_key, metric_code: row.metric_code, scope_type: row.scope_type, scope_id: Number(row.scope_id || 0), target_value: this.valueFor(row) })); },
    async searchProducts(row) {
      if (!row.store_id) { this.error = '请先选择门店'; return; }
      try { const result = await schemeProducts({ store_id: row.store_id }); row.productOptions = result.data || []; } catch (error) { this.error = error.message || '商品查询失败'; }
    },
    async saveLines() {
      this.error = ''; this.busy = true;
      try { const payload = { scheme_id: this.selectedId, expected_version: Number(this.detail.scheme.version), idempotency_key: randomKey(), lines: this.normalizedLines() }; await schemeSaveLines(payload); await this.openScheme(this.selectedId); } catch (error) { this.error = error.msg || error.message || '保存草稿失败'; } finally { this.busy = false; }
    },
    conflictStore(row) { const store = this.catalog.stores.find(item => Number(item.id) === Number(row.store_id)); return store ? store.name : `门店#${row.store_id}`; },
    conflictMetric(row) { const metric = this.catalog.metrics.find(item => item.code === row.metric_code); return metric ? metric.name : row.metric_code; },
    async activate() {
      if (this.busy || this.activationSucceeded) return;
      this.activationPayload = { scheme_id: this.selectedId, expected_version: Number(this.detail.scheme.version), report_codes: this.selectedReports.slice() };
      this.activationRequest = null;
      await this.submitActivation('');
    },
    /** 每次冲突变化都重新展示明细；旧确认令牌绝不能直接覆盖另一个人的新方案。 */
    async submitActivation(token) {
      if (this.busy || !this.activationPayload) return;
      this.error = ''; this.busy = true;
      try {
        // 确认请求失败重试沿用同一键；新的冲突清单使用新键，避免重放旧预览。
        if (!this.activationRequest || this.activationRequest.conflict_token !== token) this.activationRequest = { ...this.activationPayload, conflict_preview: true, conflict_token: token, idempotency_key: randomKey() };
        const result = await schemeActivate(this.activationRequest);
        if (result.data.status === 'confirmation_required') { this.activationConflict = result.data; if (token) this.error = '目标引用已变化，请重新核对后确认。'; return; }
        if (result.data.status !== 'active') throw new Error('未收到启用成功结果，请刷新后核对方案状态');
        this.activationConflict = null;
        // 只有启用接口明确成功才提示；不再依赖二次详情请求，避免保存成功却因刷新失败误报失败。
        // 保留当前页直到用户点击确认，普通启用与冲突确认共用这一出口。
        this.detail.scheme.status = 'active';
        if (result.data.version) this.detail.scheme.version = result.data.version;
        this.activationPayload = null; this.activationRequest = null;
        this.activationSucceeded = true;
      } catch (error) { this.error = error.msg || error.message || '启用方案失败'; } finally { this.busy = false; }
    },
    downloadTemplate() {
      const sheet = XLSX.utils.aoa_to_sheet([['月份', '门店ID', '指标代码', '对象类型', '对象ID', '目标值（元或个）'], ['2026-10', '', 'actual_performance', 'store', '0', '10000']]);
      const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book, sheet, '目标明细');
      // 辅助页只帮助填写对象 ID，不参与导入；以当前可管理门店为上限，不能导出越权门店目录。
      XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet([['门店ID', '门店名称'], ...this.catalog.stores.map(store => [store.id, store.name])]), '可选门店');
      XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet([['指标代码', '指标名称', '单位', '支持对象'], ...this.catalog.metrics.map(metric => [metric.code, metric.name, metric.unit === 'fen' ? '元' : '个/人次', metric.scopes.join(',')])]), '可选指标');
      XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet([['分类ID', '分类名称'], ...this.catalog.categories.map(category => [category.id, category.cate_name])]), '可选分类');
      XLSX.writeFile(book, '目标方案导入模板.xlsx');
    },
    async importExcel(event) {
      const file = event.target.files && event.target.files[0]; event.target.value = ''; if (!file) return;
      try {
        const bytes = await file.arrayBuffer(); const book = XLSX.read(bytes, { type: 'array' });
        const sheet = XLSX.utils.sheet_to_json(book.Sheets[book.SheetNames[0]], { header: 1, raw: false });
        if (!sheet.length || sheet[0].join('|') !== '月份|门店ID|指标代码|对象类型|对象ID|目标值（元或个）') throw new Error('Excel 表头与模板不一致');
        if (sheet.length < 2 || sheet.length > 3001) throw new Error('请导入1至3000条目标');
        this.lines = sheet.slice(1).filter(row => row.some(cell => String(cell || '').trim() !== '')).map(row => ({ localKey: randomKey(), month_key: String(row[0] || ''), store_id: Number(row[1] || 0), metric_code: String(row[2] || ''), scope_type: String(row[3] || 'store'), scope_id: Number(row[4] || 0), displayValue: String(row[5] || ''), productOptions: [] }));
        this.error = '';
      } catch (error) { this.error = error.message || 'Excel 读取失败'; }
    }
  }
};
</script>

<style scoped>
.target-scheme-page { padding: 20px; color: #24344b; }
.scheme-conflict-backdrop { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(20, 34, 56, .45); }
.scheme-conflict-dialog { width: 900px; max-width: 100%; max-height: 85vh; overflow: auto; padding: 20px; background: #fff; border-radius: 8px; }
.scheme-conflict-dialog h2 { font-size: 18px; margin: 0 0 12px; }
.scheme-conflict-table { max-height: 50vh; overflow: auto; margin: 12px 0; }
.scheme-conflict-table table { width: 100%; min-width: 680px; border-collapse: collapse; }
.scheme-conflict-table th, .scheme-conflict-table td { text-align: left; padding: 8px; border-bottom: 1px solid #e4eaf2; }
.scheme-conflict-dialog footer { display: flex; justify-content: flex-end; gap: 8px; }
.target-scheme-page__heading h1 { font-size: 22px; margin: 0 0 5px; }
.target-scheme-page__heading p, .scheme-note, .scheme-reports p { color: #718198; }
.target-scheme-page__layout { display: block; margin-top: 16px; }
.target-scheme-page__main { background: white; border: 1px solid #e4eaf2; border-radius: 10px; padding: 16px; }
.scheme-summary h2 { font-size: 17px; margin: 0 0 12px; }
.scheme-new { border-top: 1px solid #e6ebf2; margin-top: 20px; padding-top: 12px; }
.scheme-new label { display: block; margin-bottom: 10px; }
.scheme-new input { display: block; width: 100%; margin-top: 4px; }
.scheme-metadata { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; padding: 12px 0; }
.scheme-metadata label { display: flex; flex-direction: column; min-width: 220px; gap: 5px; }
.scheme-metadata input { min-height: 32px; border: 1px solid #d4deea; border-radius: 5px; padding: 4px 8px; }
.scheme-summary { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.scheme-summary__actions { display: flex; flex-wrap: wrap; gap: 7px; }
.scheme-batch { display: grid; gap: 12px; padding: 16px; margin: 8px 0 14px; border: 1px solid #e4eaf2; border-radius: 7px; background: #fbfdff; }
.scheme-batch__header { display: flex; justify-content: space-between; align-items: start; gap: 16px; padding-bottom: 12px; border-bottom: 1px solid #e6edf5; }
.scheme-batch__header h3 { margin: 0; font-size: 15px; }
.scheme-batch__header p { margin: 4px 0 0; color: #718198; font-size: 12px; }
.scheme-batch__scope { display: grid; grid-template-columns: 70px minmax(0, 1fr); align-items: center; gap: 10px; }
.scheme-batch__label { color: #52647b; font-size: 12px; }
.scheme-batch__stores { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; min-width: 0; }
.scheme-batch__stores span, .scheme-batch__hint { color: #718198; }
.scheme-batch__fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.scheme-batch__fields--scoped { grid-template-columns: repeat(5, minmax(0, 1fr)); }
.scheme-batch__fields label { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.scheme-batch__fields input, .scheme-batch__fields select { width: 100%; min-width: 0; min-height: 32px; border: 1px solid #d4deea; border-radius: 5px; padding: 4px 6px; }
.scheme-batch__actions { display: flex; justify-content: flex-end; align-items: center; gap: 14px; }
.scheme-batch__overwrite { display: inline-flex; align-items: center; gap: 5px; margin: 0; white-space: nowrap; }
.scheme-batch__apply { background: #2d7bdd; border-color: #2d7bdd; color: white; font-weight: 600; }
.scheme-batch__apply:disabled { background: #a9bdd6; border-color: #a9bdd6; }
.scheme-batch__message { color: #26724a; margin: 0; }
.scheme-batch__hint { margin: 0; padding-top: 10px; border-top: 1px solid #e6edf5; font-size: 12px; }
@media (max-width: 900px) { .scheme-batch__fields, .scheme-batch__fields--scoped { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 600px) { .scheme-batch__header { flex-direction: column; } .scheme-batch__scope { grid-template-columns: 1fr; } .scheme-batch__fields, .scheme-batch__fields--scoped { grid-template-columns: 1fr; } .scheme-batch__actions { justify-content: space-between; flex-wrap: wrap; } }
.scheme-upload { position: relative; border: 1px solid #d4e0ef; border-radius: 5px; padding: 6px 10px; cursor: pointer; }
.scheme-upload input { position: absolute; opacity: 0; width: 1px; }
.scheme-table-wrap { overflow: auto; max-height: 55vh; }
.scheme-table { border-collapse: collapse; width: 100%; min-width: 1040px; }
.scheme-table th, .scheme-table td { border-bottom: 1px solid #e8edf4; padding: 8px 5px; text-align: left; }
.scheme-table th { position: sticky; top: 0; background: #f2f6fb; }
.scheme-table input, .scheme-table select { max-width: 165px; min-height: 32px; border: 1px solid #d4deea; border-radius: 5px; padding: 4px; }
.scheme-table td small { display: block; color: #8190a4; }
.scheme-product { display: flex; align-items: center; gap: 4px; }
.scheme-empty { text-align: center !important; color: #8390a0; }
.scheme-footer, .scheme-reports { padding-top: 14px; }
.scheme-footer span { margin-left: 10px; color: #8793a2; }
.scheme-reports { border-top: 1px solid #e8edf4; margin-top: 12px; }
.scheme-reports__grid { display: flex; flex-wrap: wrap; gap: 12px; margin: 12px 0; }
.scheme-error { background: #fff2f1; color: #bd403b; padding: 9px; border-radius: 5px; }
button { border: 1px solid #c9d8e9; border-radius: 5px; background: #f7faff; color: #175ba0; cursor: pointer; padding: 6px 10px; }
button:disabled { opacity: .5; cursor: default; }
@media (max-width: 800px) { .scheme-summary { display: block; } }
</style>
