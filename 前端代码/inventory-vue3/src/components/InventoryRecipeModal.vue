<script setup>
import { computed, onMounted, ref } from 'vue'
import { FlaskConical, Plus, X } from '@lucide/vue'
import InventoryRecipeProductSelector from './InventoryRecipeProductSelector.vue'
import { platformInventoryApi } from '../services/inventoryApi'

const props = defineProps({ recipeId: { type: Number, default: 0 } })
const emit = defineEmits(['close', 'saved'])

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const projectSelectorVisible = ref(false)
const consumableSelectorVisible = ref(false)
const form = ref({
  project_product_id: 0,
  project_unique: '',
  project_name: '',
  project_spec: '',
  status: 1,
  details: []
})

const isEdit = computed(() => Number(props.recipeId) > 0)
const selectedProjectRows = computed(() => form.value.project_product_id ? [{
  product_id: form.value.project_product_id,
  product_name: form.value.project_name,
  sku_unique: form.value.project_unique,
  sku_name: form.value.project_spec
}] : [])
const selectedConsumableRows = computed(() => form.value.details.map((item) => ({
  product_id: item.consumable_product_id,
  product_name: item.consumable_name,
  sku_unique: item.consumable_unique,
  sku_name: item.consumable_spec,
  stock_unit: item.stock_unit
})))

async function loadRecipe() {
  if (!isEdit.value) return
  loading.value = true
  error.value = ''
  try {
    const detail = await platformInventoryApi.recipeInfo(props.recipeId)
    form.value = {
      project_product_id: Number(detail?.project_product_id || 0),
      project_unique: String(detail?.project_unique || ''),
      project_name: String(detail?.project_name || ''),
      project_spec: String(detail?.project_unique || ''),
      status: Number(detail?.status) === 0 ? 0 : 1,
      details: (Array.isArray(detail?.details) ? detail.details : []).map((item) => ({
        consumable_product_id: Number(item.consumable_product_id),
        consumable_unique: String(item.consumable_unique || ''),
        consumable_name: String(item.consumable_name || ''),
        consumable_spec: String(item.consumable_unique || ''),
        stock_unit: String(item.stock_unit || ''),
        qty_per_writeoff: String(item.qty_per_writeoff || '')
      }))
    }
  } catch (loadError) {
    error.value = loadError instanceof Error ? loadError.message : '配方详情读取失败。'
  } finally {
    loading.value = false
  }
}

function selectProject(rows) {
  const project = rows[0]
  if (!project) return
  form.value.project_product_id = Number(project.product_id)
  form.value.project_unique = String(project.sku_unique || '')
  form.value.project_name = String(project.product_name || '')
  form.value.project_spec = String(project.sku_name || project.sku_unique || '')
  projectSelectorVisible.value = false
}

function selectConsumables(rows) {
  const quantityMap = new Map(form.value.details.map((item) => [
    `${item.consumable_product_id}:${item.consumable_unique}`,
    item.qty_per_writeoff
  ]))
  form.value.details = rows.map((item) => ({
    consumable_product_id: Number(item.product_id),
    consumable_unique: String(item.sku_unique || ''),
    consumable_name: String(item.product_name || ''),
    consumable_spec: String(item.sku_name || item.sku_unique || ''),
    stock_unit: String(item.stock_unit || ''),
    qty_per_writeoff: quantityMap.get(`${Number(item.product_id)}:${String(item.sku_unique || '')}`) || ''
  }))
  consumableSelectorVisible.value = false
}

function removeConsumable(index) {
  form.value.details.splice(index, 1)
}

function normalizedQuantity(value) {
  const raw = String(value ?? '').trim()
  if (!/^\d+(\.\d{1,2})?$/.test(raw) || Number(raw) <= 0) return null
  return raw
}

async function save() {
  error.value = ''
  if (!form.value.project_product_id || !form.value.project_unique) {
    error.value = '请选择项目。'
    return
  }
  if (!form.value.details.length) {
    error.value = '请至少添加一项耗材。'
    return
  }
  const invalid = form.value.details.find((item) => normalizedQuantity(item.qty_per_writeoff) === null)
  if (invalid) {
    error.value = `请正确填写“${invalid.consumable_name || invalid.consumable_unique}”的单次用量，必须大于 0 且最多保留两位小数。`
    return
  }
  saving.value = true
  try {
    await platformInventoryApi.saveRecipe(props.recipeId, {
      project_product_id: form.value.project_product_id,
      project_unique: form.value.project_unique,
      status: form.value.status,
      details: form.value.details.map((item) => ({
        consumable_product_id: item.consumable_product_id,
        consumable_unique: item.consumable_unique,
        qty_per_writeoff: normalizedQuantity(item.qty_per_writeoff)
      }))
    })
    emit('saved')
  } catch (saveError) {
    error.value = saveError instanceof Error ? saveError.message : '配方保存失败。'
  } finally {
    saving.value = false
  }
}

onMounted(loadRecipe)
</script>

<template>
  <Teleport to="body">
    <div class="recipe-modal-layer" role="presentation">
      <button class="recipe-modal-mask" aria-label="关闭配方窗口" :disabled="saving" @click="emit('close')" />
      <section class="recipe-modal" role="dialog" aria-modal="true" :aria-label="isEdit ? '编辑配方' : '新建配方'">
        <header>
          <div><p>项目配方</p><h2>{{ isEdit ? '编辑配方' : '新建配方' }}</h2></div>
          <button class="recipe-icon-button" title="关闭" :disabled="saving" @click="emit('close')"><X :size="20" /></button>
        </header>
        <main>
          <div v-if="loading" class="recipe-loading"><FlaskConical :size="28" /><span>正在读取配方...</span></div>
          <template v-else>
            <p class="recipe-tip">配方按项目维护。核销项目时按这里设置的单次用量自动扣减耗材，后来修改配方不会改变历史核销记录。</p>
            <section class="recipe-form-section">
              <label class="recipe-label"><span><b>*</b> 项目</span>
                <div class="recipe-project-picker">
                  <div v-if="form.project_product_id"><strong>{{ form.project_name || `项目 ${form.project_product_id}` }}</strong><small>{{ form.project_spec || form.project_unique }}</small></div>
                  <span v-else>尚未选择项目</span>
                  <button v-if="!isEdit" class="recipe-secondary" @click="projectSelectorVisible = true">{{ form.project_product_id ? '重新选择' : '选择项目' }}</button>
                </div>
                <small>同一项目规格只能维护一条配方；编辑时不允许改绑项目。</small>
              </label>
              <label class="recipe-label recipe-status"><span>状态</span><input v-model="form.status" type="checkbox" :true-value="1" :false-value="0" /><strong>{{ form.status === 1 ? '启用' : '停用' }}</strong></label>
            </section>
            <section class="recipe-lines">
              <header><div><h3>耗材配方</h3><p>只显示已参与库存并开启“可作为院装耗材”的商品；用量为每核销 1 次消耗的库存单位数量。</p></div><button class="recipe-secondary" @click="consumableSelectorVisible = true"><Plus :size="15" />添加耗材</button></header>
              <div class="recipe-table-scroll">
                <table>
                  <thead><tr><th>耗材</th><th>规格</th><th>库存单位</th><th>单次用量</th><th>操作</th></tr></thead>
                  <tbody>
                    <tr v-if="!form.details.length"><td colspan="5" class="recipe-empty">请添加至少一项耗材</td></tr>
                    <tr v-for="(item, index) in form.details" :key="`${item.consumable_product_id}:${item.consumable_unique}`">
                      <td><strong>{{ item.consumable_name }}</strong></td><td>{{ item.consumable_spec || item.consumable_unique }}</td><td>{{ item.stock_unit || '-' }}</td>
                      <td><input v-model="item.qty_per_writeoff" class="quantity-input" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="大于 0" /></td>
                      <td><button class="recipe-danger" @click="removeConsumable(index)">移除</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
            <p v-if="error" class="recipe-error">{{ error }}</p>
          </template>
        </main>
        <footer><button class="recipe-secondary" :disabled="saving" @click="emit('close')">取消</button><button class="recipe-primary" :disabled="loading || saving" @click="save">{{ saving ? '保存中...' : '保存' }}</button></footer>
      </section>
    </div>
  </Teleport>
  <InventoryRecipeProductSelector :visible="projectSelectorVisible" kind="project" :selected-rows="selectedProjectRows" @close="projectSelectorVisible = false" @confirm="selectProject" />
  <InventoryRecipeProductSelector :visible="consumableSelectorVisible" kind="consumable" :selected-rows="selectedConsumableRows" @close="consumableSelectorVisible = false" @confirm="selectConsumables" />
</template>

<style scoped>
.recipe-modal-layer { position: fixed; z-index: 120; inset: 0; display: grid; place-items: center; padding: 24px; }.recipe-modal-mask { position: absolute; inset: 0; width: 100%; border: 0; background: rgba(17, 30, 46, .46); }.recipe-modal { position: relative; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; width: min(1120px, calc(100vw - 48px)); max-height: calc(100vh - 48px); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #f7f9fb; box-shadow: 0 24px 60px rgba(8, 25, 45, .28); }.recipe-modal > header, .recipe-modal > footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; background: #fff; }.recipe-modal header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.recipe-modal header h2 { margin: 0; color: #253a50; font-size: 18px; }.recipe-icon-button { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 6px; background: #fff; color: #47627d; }.recipe-modal > main { overflow: auto; padding: 18px; }.recipe-modal > footer { justify-content: flex-end; border-top: 1px solid #e0e7ef; border-bottom: 0; }.recipe-tip { margin: 0 0 14px; padding: 11px 13px; border: 1px solid #cfe1f5; border-radius: 7px; background: #f2f8ff; color: #52718e; font-size: 12px; line-height: 1.55; }.recipe-form-section { display: grid; grid-template-columns: minmax(0, 1fr) 190px; gap: 16px; margin-bottom: 14px; padding: 16px; border: 1px solid #dfe8f0; border-radius: 8px; background: #fff; }.recipe-label { display: grid; gap: 7px; color: #52687e; font-size: 12px; }.recipe-label > span { color: #344b62; font-weight: 600; }.recipe-label b { color: #cf4e4e; }.recipe-label > small { color: #8795a5; }.recipe-project-picker { display: flex; align-items: center; justify-content: space-between; gap: 14px; min-height: 44px; padding: 7px 9px; border: 1px solid #d8e3ed; border-radius: 7px; color: #8492a2; }.recipe-project-picker div { display: grid; gap: 3px; }.recipe-project-picker strong { color: #30475f; font-size: 13px; }.recipe-project-picker small { color: #8492a2; }.recipe-status { display: grid; grid-template-columns: auto 18px 1fr; align-content: center; align-items: center; }.recipe-status > span { grid-column: 1 / -1; }.recipe-status input { width: 17px; height: 17px; margin: 0; }.recipe-status strong { color: #40566d; }.recipe-lines { overflow: hidden; border: 1px solid #dfe8f0; border-radius: 8px; background: #fff; }.recipe-lines > header { display: flex; align-items: start; justify-content: space-between; gap: 12px; padding: 14px 15px; border-bottom: 1px solid #e8eef4; }.recipe-lines h3 { margin: 0; color: #30465d; font-size: 14px; }.recipe-lines p { margin: 4px 0 0; color: #8795a5; font-size: 11px; }.recipe-table-scroll { overflow: auto; }.recipe-table-scroll table { width: 100%; min-width: 760px; border-collapse: collapse; }.recipe-table-scroll th, .recipe-table-scroll td { height: 48px; padding: 0 12px; border-bottom: 1px solid #edf1f5; color: #40566d; font-size: 12px; text-align: left; white-space: nowrap; }.recipe-table-scroll th { height: 39px; background: #f7f9fc; color: #60748a; font-size: 11px; }.quantity-input { width: 120px; height: 31px; padding: 0 8px; border: 1px solid #bcd6ed; border-radius: 6px; color: #344d68; }.recipe-empty { height: 110px !important; color: #8798a9 !important; text-align: center !important; }.recipe-loading { display: grid; min-height: 320px; place-items: center; align-content: center; gap: 10px; color: #71869b; }.recipe-error { margin: 12px 0 0; padding: 10px 12px; border: 1px solid #f0cccc; border-radius: 7px; background: #fff7f7; color: #bd4b4b; font-size: 12px; }.recipe-primary, .recipe-secondary { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 34px; padding: 0 13px; border-radius: 7px; font-size: 12px; }.recipe-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.recipe-primary:disabled { cursor: default; opacity: .55; }.recipe-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }.recipe-danger { border: 0; background: transparent; color: #c84d4d; font-size: 12px; }
@media (max-width: 760px) { .recipe-modal-layer { padding: 12px; }.recipe-modal { width: 100%; max-height: calc(100vh - 24px); }.recipe-form-section { grid-template-columns: 1fr; } }
</style>
