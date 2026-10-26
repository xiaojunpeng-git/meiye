<script setup>
import { computed, onMounted, ref } from 'vue'
import ArrowDown from '@lucide/vue/dist/esm/icons/arrow-down.mjs'
import ArrowUp from '@lucide/vue/dist/esm/icons/arrow-up.mjs'
import Plus from '@lucide/vue/dist/esm/icons/plus.mjs'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import { queryConsumptionTiers, saveConsumptionTier, sortConsumptionTiers } from '@/services/sixDimensionReportConfigApi'

const records = ref([])
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const editorOpen = ref(false)
const editingTier = ref(null)
const removingTier = ref(null)
const draft = ref(emptyDraft())

const sortedRecords = computed(() => [...records.value].sort((left, right) => Number(left.sort_order) - Number(right.sort_order) || Number(left.id) - Number(right.id)))

function emptyDraft() {
  return { tier_name: '', lower_yuan: '0', upper_yuan: '', enabled: true }
}

function normalizeRows(payload) {
  const rows = Array.isArray(payload) ? payload : (Array.isArray(payload?.records) ? payload.records : [])
  return rows.map((row) => ({
    ...row,
    tier_code: String(row?.tier_code || ''),
    tier_name: String(row?.tier_name || row?.name || ''),
    lower_bound_cents: Number(row?.lower_bound_cents || 0),
    upper_bound_cents: row?.upper_bound_cents === null || row?.upper_bound_cents === '' ? null : Number(row.upper_bound_cents),
    sort_order: Number(row?.sort_order || 0),
    enabled: Number(row?.enabled ?? 1) === 1,
    version: Number(row?.version || 0)
  }))
}

function centsToYuan(value) {
  if (value === null || value === undefined || value === '') return ''
  return (Number(value) / 100).toFixed(2).replace(/\.00$/, '')
}

function yuanToCents(value, fieldName, allowEmpty = false) {
  const text = String(value ?? '').trim()
  if (allowEmpty && text === '') return null
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) throw new Error(`${fieldName}必须是非负金额，最多保留两位小数。`)
  const cents = Math.round(Number(text) * 100)
  if (!Number.isSafeInteger(cents)) throw new Error(`${fieldName}超出可保存范围。`)
  return cents
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    records.value = normalizeRows(await queryConsumptionTiers())
  } catch (error) {
    errorMessage.value = error?.message || '消费分级读取失败，请稍后重试。'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingTier.value = null
  draft.value = emptyDraft()
  editorOpen.value = true
  errorMessage.value = ''
}

function openEdit(tier) {
  editingTier.value = tier
  draft.value = {
    tier_name: tier.tier_name,
    lower_yuan: centsToYuan(tier.lower_bound_cents),
    upper_yuan: centsToYuan(tier.upper_bound_cents),
    enabled: tier.enabled
  }
  editorOpen.value = true
  errorMessage.value = ''
}

async function saveEditor() {
  const name = String(draft.value.tier_name || '').trim()
  if (!name) {
    errorMessage.value = '请输入消费分级名称。'
    return
  }
  let lower
  let upper
  try {
    lower = yuanToCents(draft.value.lower_yuan, '区间下限')
    upper = yuanToCents(draft.value.upper_yuan, '区间上限', true)
    if (upper !== null && upper <= lower) throw new Error('区间上限必须大于区间下限。')
  } catch (error) {
    errorMessage.value = error.message
    return
  }
  const current = editingTier.value
  saving.value = true
  errorMessage.value = ''
  try {
    await saveConsumptionTier({
      tier_code: current?.tier_code || `tier_${Date.now().toString(36)}`,
      tier_name: name,
      lower_bound_cents: lower,
      upper_bound_cents: upper,
      sort_order: current?.sort_order ?? (Math.max(0, ...sortedRecords.value.map((tier) => Number(tier.sort_order) || 0)) + 10),
      enabled: draft.value.enabled ? 1 : 0,
      expected_version: current?.version || 0
    })
    editorOpen.value = false
    await load()
  } catch (error) {
    errorMessage.value = error?.message || '消费分级保存失败，请稍后重试。'
  } finally {
    saving.value = false
  }
}

async function setTierEnabled(tier, enabled, remove = false) {
  saving.value = true
  errorMessage.value = ''
  try {
    await saveConsumptionTier({
      tier_code: tier.tier_code,
      tier_name: tier.tier_name,
      lower_bound_cents: tier.lower_bound_cents,
      upper_bound_cents: tier.upper_bound_cents,
      sort_order: tier.sort_order,
      enabled: enabled ? 1 : 0,
      expected_version: tier.version,
      ...(remove ? { delete: 1 } : {})
    })
    await load()
  } catch (error) {
    errorMessage.value = error?.message || '消费分级状态保存失败，请稍后重试。'
  } finally {
    saving.value = false
  }
}

function requestRemove(tier) {
  removingTier.value = tier
}

async function confirmRemove() {
  const tier = removingTier.value
  if (!tier) return
  await setTierEnabled(tier, false, true)
  if (!errorMessage.value) removingTier.value = null
}

async function move(index, offset) {
  const target = index + offset
  if (target < 0 || target >= sortedRecords.value.length || saving.value) return
  const rows = [...sortedRecords.value]
  ;[rows[index], rows[target]] = [rows[target], rows[index]]
  saving.value = true
  errorMessage.value = ''
  try {
    await sortConsumptionTiers(rows)
    await load()
  } catch (error) {
    const message = error?.message || '消费分级排序保存失败，请稍后重试。'
    await load()
    errorMessage.value = message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <main class="consumption-tier-config" aria-label="消费分级设置">
    <header class="consumption-tier-config__header">
      <div><h1>消费分级设置</h1><p>现金消费分析表按当前启用区间匹配会员分级。</p></div>
      <div class="consumption-tier-config__header-actions">
        <button type="button" class="button button--secondary" :disabled="loading" title="刷新" @click="load"><RefreshCw :size="16" aria-hidden="true" />刷新</button>
        <button type="button" class="button button--primary" :disabled="saving" @click="openCreate"><Plus :size="16" aria-hidden="true" />新增分级</button>
      </div>
    </header>

    <p v-if="errorMessage" class="consumption-tier-config__error" role="alert">{{ errorMessage }}</p>

    <section class="consumption-tier-config__table-panel">
      <div class="consumption-tier-config__table-scroll">
        <table>
          <thead><tr><th>排序</th><th>消费分级</th><th>区间下限（元）</th><th>区间上限（元）</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="(tier, index) in sortedRecords" :key="tier.tier_code">
              <td><div class="consumption-tier-config__sort"><span>{{ index + 1 }}</span><button type="button" title="上移" :disabled="saving || index === 0" @click="move(index, -1)"><ArrowUp :size="14" aria-hidden="true" /></button><button type="button" title="下移" :disabled="saving || index === sortedRecords.length - 1" @click="move(index, 1)"><ArrowDown :size="14" aria-hidden="true" /></button></div></td>
              <td><strong>{{ tier.tier_name }}</strong></td>
              <td>{{ centsToYuan(tier.lower_bound_cents) }}</td>
              <td>{{ tier.upper_bound_cents === null ? '以上' : centsToYuan(tier.upper_bound_cents) }}</td>
              <td><span class="consumption-tier-config__status" :class="{ 'consumption-tier-config__status--off': !tier.enabled }">{{ tier.enabled ? '已启用' : '已停用' }}</span></td>
              <td class="consumption-tier-config__actions">
                <button type="button" class="button button--text" :disabled="saving" @click="openEdit(tier)">编辑</button>
                <button type="button" class="button button--text" :disabled="saving" @click="setTierEnabled(tier, !tier.enabled)">{{ tier.enabled ? '停用' : '启用' }}</button>
                <button type="button" class="button button--text button--danger" :disabled="saving" @click="requestRemove(tier)">删除</button>
              </td>
            </tr>
            <tr v-if="!loading && !sortedRecords.length"><td colspan="6" class="consumption-tier-config__empty">暂无消费分级。</td></tr>
          </tbody>
        </table>
        <p v-if="loading" class="consumption-tier-config__empty">正在读取消费分级。</p>
      </div>
    </section>

    <div v-if="editorOpen" class="consumption-tier-config__modal" role="dialog" aria-modal="true" aria-label="消费分级资料">
      <form class="consumption-tier-config__editor" @submit.prevent="saveEditor">
        <header><h2>{{ editingTier ? '编辑消费分级' : '新增消费分级' }}</h2></header>
        <label><span>消费分级</span><input v-model.trim="draft.tier_name" maxlength="128" autofocus /></label>
        <div class="consumption-tier-config__range">
          <label><span>区间下限（元）</span><input v-model.trim="draft.lower_yuan" inputmode="decimal" placeholder="0" /></label>
          <label><span>区间上限（元）</span><input v-model.trim="draft.upper_yuan" inputmode="decimal" placeholder="留空表示以上" /></label>
        </div>
        <label class="consumption-tier-config__toggle"><input v-model="draft.enabled" type="checkbox" /><span>启用该分级</span></label>
        <footer><button type="button" class="button button--secondary" :disabled="saving" @click="editorOpen = false">取消</button><button type="submit" class="button button--primary" :disabled="saving">{{ saving ? '保存中' : '保存' }}</button></footer>
      </form>
    </div>
    <div v-if="removingTier" class="consumption-tier-config__modal" role="dialog" aria-modal="true" aria-label="删除消费分级">
      <section class="consumption-tier-config__confirm">
        <h2>删除消费分级</h2>
        <p>确认删除“{{ removingTier.tier_name }}”？历史记录仍保留，该分级将停止参与后续匹配。</p>
        <footer><button type="button" class="button button--secondary" :disabled="saving" @click="removingTier = null">取消</button><button type="button" class="button button--danger" :disabled="saving" @click="confirmRemove">{{ saving ? '处理中' : '确认删除' }}</button></footer>
      </section>
    </div>
  </main>
</template>

<style scoped>
.consumption-tier-config { display: grid; align-content: start; gap: 14px; min-height: 100%; box-sizing: border-box; padding: 20px; background: #f5f7fa; color: #252a34; }
.consumption-tier-config h1, .consumption-tier-config h2, .consumption-tier-config p { margin: 0; }
.consumption-tier-config__header, .consumption-tier-config__table-panel { border: 1px solid #dde4ed; border-radius: 8px; background: #fff; }
.consumption-tier-config__header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 20px; }
.consumption-tier-config__header h1 { font-size: 20px; }
.consumption-tier-config__header p { margin-top: 6px; color: #697586; font-size: 13px; }
.consumption-tier-config__header-actions, .consumption-tier-config__sort, .consumption-tier-config__actions, .consumption-tier-config__editor footer { display: flex; align-items: center; gap: 8px; }
.consumption-tier-config__header-actions .button { display: inline-flex; align-items: center; gap: 6px; }
.consumption-tier-config__error { border: 1px solid #ffcaca; border-radius: 7px; padding: 10px 14px; background: #fff5f5; color: #b42318; font-size: 13px; }
.consumption-tier-config__table-panel { overflow: hidden; }
.consumption-tier-config__table-scroll { overflow: auto; }
.consumption-tier-config table { width: 100%; border-collapse: collapse; white-space: nowrap; font-size: 13px; }
.consumption-tier-config th, .consumption-tier-config td { border-bottom: 1px solid #edf1f5; padding: 12px 14px; text-align: left; }
.consumption-tier-config th { background: #fafbfd; color: #697586; font-weight: 600; }
.consumption-tier-config__sort button { display: grid; width: 26px; height: 26px; place-items: center; border: 1px solid #d6dfe9; border-radius: 4px; background: #fff; color: #526071; cursor: pointer; }
.consumption-tier-config__sort button:disabled { cursor: not-allowed; opacity: .4; }
.consumption-tier-config__status { display: inline-block; border-radius: 4px; padding: 3px 8px; background: #edf8f1; color: #227447; }
.consumption-tier-config__status--off { background: #f1f3f5; color: #7a8694; }
.consumption-tier-config__empty { padding: 42px 20px !important; color: #8290a2; text-align: center !important; }
.button--danger { color: #c83c3c; }
.consumption-tier-config__modal { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(24, 39, 58, .38); }
.consumption-tier-config__editor { display: grid; width: min(480px, 100%); gap: 16px; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 16px 50px rgba(24, 39, 58, .2); }
.consumption-tier-config__confirm { display: grid; width: min(420px, 100%); gap: 14px; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 16px 50px rgba(24, 39, 58, .2); }
.consumption-tier-config__confirm p { color: #526071; font-size: 13px; line-height: 1.7; }
.consumption-tier-config__confirm footer { display: flex; justify-content: flex-end; gap: 8px; }
.consumption-tier-config__editor h2 { font-size: 18px; }
.consumption-tier-config__editor label { display: grid; gap: 6px; color: #526071; font-size: 13px; }
.consumption-tier-config__editor input { min-height: 36px; box-sizing: border-box; border: 1px solid #cfd8e3; border-radius: 6px; padding: 0 10px; font: inherit; }
.consumption-tier-config__range { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.consumption-tier-config__editor .consumption-tier-config__toggle { display: flex; align-items: center; gap: 8px; }
.consumption-tier-config__toggle input { min-height: auto; }
.consumption-tier-config__editor footer { justify-content: flex-end; }
@media (max-width: 680px) { .consumption-tier-config { padding: 12px; }.consumption-tier-config__header { align-items: flex-start; flex-direction: column; }.consumption-tier-config__range { grid-template-columns: 1fr; } }
</style>
