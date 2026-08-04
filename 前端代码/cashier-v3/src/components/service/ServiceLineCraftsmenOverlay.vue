<script setup>
import { computed, ref, watch } from 'vue'
import { createCashierV3CommandId } from '@/services/cashierV3Bridge'

/**
 * 单个服务项目的实际手艺人确认。
 *
 * 选择人员由外层传入的 onSelectCraftsmen 调用统一 Vue 3「选择人员」控件完成。
 * 本组件只保存人员 ID 与顺序；第一位由后端按既定规则确认为主要手艺人，
 * 不在前端计算任何劳动业绩、核销金额或其他经营结果。
 */
const props = defineProps({
  line: {
    type: Object,
    default: () => ({})
  },
  serviceOrder: {
    type: Object,
    default: () => ({})
  },
  onSelectCraftsmen: {
    type: Function,
    default: null
  },
  isSubmitting: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'request'])

const selectedCraftsmen = ref([])
const isSelecting = ref(false)
const validationMessage = ref('')
const submitCommandId = ref(null)

const lineId = computed(() => firstValue(props.line, ['id', 'lineId', 'serviceLineId', 'projectLineId']))
const lineVersion = computed(() => firstValue(props.line, ['revision', 'recordVersion', 'version', 'lineVersion']))
const serviceOrderId = computed(() => firstValue(props.serviceOrder, ['id', 'serviceOrderId', 'serviceSessionId', 'serviceNo']))
const serviceOrderVersion = computed(() => firstValue(props.serviceOrder, ['revision', 'recordVersion', 'version']))
const lineName = computed(() => firstValue(props.line, ['name', 'projectName', 'productName']) || '该服务项目')
const requiresActualCraftsmen = computed(() => {
  if (props.line?.requiresActualCraftsmen === false || props.line?.requiresActualCraftsman === false) return false
  const status = String(firstValue(props.line, ['completionStatus', 'serviceCompletionStatus', 'actualCompletionStatus'])).toLowerCase()
  return !['not_served', 'unserved', 'notserved', '本次未服务'].includes(status)
})

watch(
  () => props.line,
  (line) => {
    selectedCraftsmen.value = normalizeCraftsmen(readCraftsmen(line))
    validationMessage.value = ''
    submitCommandId.value = null
  },
  { deep: true, immediate: true }
)

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return ''
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return ''
}

function readCraftsmen(line) {
  if (!line || typeof line !== 'object') return []
  const candidates = [
    line.actualCraftsmen,
    line.completedCraftsmen
  ]
  return candidates.find((value) => Array.isArray(value)) || []
}

function craftsmanId(craftsman) {
  return firstValue(craftsman, ['staffId', 'employeeId', 'id', 'userId'])
}

function craftsmanName(craftsman) {
  return firstValue(craftsman, ['name', 'staffName', 'employeeName', 'realName']) || '未命名手艺人'
}

function craftsmanMeta(craftsman) {
  return [
    firstValue(craftsman, ['code', 'staffCode', 'employeeCode']),
    firstValue(craftsman, ['storeName', 'organizationName', 'positionName'])
  ].filter(Boolean).join(' · ')
}

function normalizeCraftsmen(craftsmen) {
  const ids = new Set()
  return (Array.isArray(craftsmen) ? craftsmen : [])
    .filter((item) => item && typeof item === 'object')
    .filter((item) => {
      const id = craftsmanId(item)
      if (!id || ids.has(String(id))) return false
      ids.add(String(id))
      return true
    })
    .map((item) => ({ ...item }))
}

function moveCraftsman(index, delta) {
  const targetIndex = index + delta
  if (targetIndex < 0 || targetIndex >= selectedCraftsmen.value.length) return
  const next = selectedCraftsmen.value.map((item) => ({ ...item }))
  const [item] = next.splice(index, 1)
  next.splice(targetIndex, 0, item)
  selectedCraftsmen.value = next
  validationMessage.value = ''
  submitCommandId.value = null
}

function removeCraftsman(index) {
  selectedCraftsmen.value = selectedCraftsmen.value.filter((_, currentIndex) => currentIndex !== index)
  validationMessage.value = ''
  submitCommandId.value = null
}

function selectedFromResult(result) {
  if (Array.isArray(result)) return result
  if (!result || typeof result !== 'object') return null
  if (Array.isArray(result.craftsmen)) return result.craftsmen
  if (Array.isArray(result.selected)) return result.selected
  if (Array.isArray(result.records)) return result.records
  return null
}

async function chooseCraftsmen() {
  if (!props.onSelectCraftsmen || isSelecting.value || props.isSubmitting) {
    if (!props.onSelectCraftsmen) validationMessage.value = '暂时无法打开选择人员，请返回后重试。'
    return
  }

  isSelecting.value = true
  validationMessage.value = ''
  try {
    const result = await props.onSelectCraftsmen({
      line: props.line,
      serviceOrder: props.serviceOrder,
      selectedCraftsmen: selectedCraftsmen.value.map((item) => ({ ...item }))
    })
    const selected = selectedFromResult(result)
    if (Array.isArray(selected)) {
      selectedCraftsmen.value = normalizeCraftsmen(selected)
      submitCommandId.value = null
    }
  } catch (error) {
    validationMessage.value = error?.message || '选择人员暂时不可用，请稍后重试。'
  } finally {
    isSelecting.value = false
  }
}

function validate() {
  if (!lineId.value) return '未找到该服务项目，请返回后重新打开。'
  if (!serviceOrderId.value) return '未找到本次服务单，请返回后重新打开。'
  if (requiresActualCraftsmen.value && !selectedCraftsmen.value.length) return '该项目已完成，请至少选择一名实际手艺人。'
  if (selectedCraftsmen.value.some((item) => !craftsmanId(item))) return '所选手艺人信息不完整，请重新选择。'
  return ''
}

function submit() {
  validationMessage.value = validate()
  if (validationMessage.value) return
  if (!submitCommandId.value) submitCommandId.value = createCashierV3CommandId('SERVICE_LINE')

  emit('request', {
    action: 'save-service-line-craftsmen',
    payload: {
      serviceOrderId: serviceOrderId.value,
      serviceOrderVersion: serviceOrderVersion.value || null,
      idempotencyKey: submitCommandId.value,
      lineId: lineId.value,
      lineVersion: lineVersion.value || null,
      actualCraftsmen: selectedCraftsmen.value.map((craftsman, index) => ({
        staffId: craftsmanId(craftsman),
        sequence: index + 1
      }))
    }
  })
}
</script>

<template>
  <div class="service-line-craftsmen" role="presentation" @click.self="$emit('close')">
    <section class="service-line-craftsmen__dialog" role="dialog" aria-modal="true" aria-label="确认实际手艺人">
      <header class="service-line-craftsmen__header">
        <div>
          <span>实际手艺人</span>
          <h2>{{ lineName }}</h2>
          <p>第一位会自动作为主要手艺人，无需单独选择。</p>
        </div>
        <button type="button" class="button button--secondary" :disabled="isSubmitting || isSelecting" @click="$emit('close')">关闭</button>
      </header>

      <main class="service-line-craftsmen__body">
        <div class="service-line-craftsmen__toolbar">
          <div>
            <strong>{{ selectedCraftsmen.length ? `已选 ${selectedCraftsmen.length} 人` : '暂未选择手艺人' }}</strong>
            <span v-if="requiresActualCraftsmen">完成项目至少需要一名实际手艺人。</span>
            <span v-else>本次未服务项目可以不选择手艺人。</span>
          </div>
          <button type="button" class="button button--secondary" :disabled="isSubmitting || isSelecting" @click="chooseCraftsmen">
            {{ isSelecting ? '正在打开…' : '选择手艺人' }}
          </button>
        </div>

        <ol v-if="selectedCraftsmen.length" class="service-line-craftsmen__list">
          <li v-for="(craftsman, index) in selectedCraftsmen" :key="craftsmanId(craftsman)">
            <span class="service-line-craftsmen__sequence">{{ index + 1 }}</span>
            <div class="service-line-craftsmen__identity">
              <strong>{{ craftsmanName(craftsman) }}</strong>
              <span>{{ craftsmanMeta(craftsman) || '当前有效手艺人' }}</span>
            </div>
            <span v-if="index === 0" class="service-line-craftsmen__primary">主要手艺人</span>
            <div class="service-line-craftsmen__actions">
              <button type="button" :disabled="isSubmitting || isSelecting || index === 0" @click="moveCraftsman(index, -1)">上移</button>
              <button type="button" :disabled="isSubmitting || isSelecting || index === selectedCraftsmen.length - 1" @click="moveCraftsman(index, 1)">下移</button>
              <button type="button" :disabled="isSubmitting || isSelecting" @click="removeCraftsman(index)">移除</button>
            </div>
          </li>
        </ol>
        <div v-else class="service-line-craftsmen__empty">请通过“选择手艺人”添加实际服务人员。</div>

        <p class="service-line-craftsmen__backend-tip">保存后由系统重新校验人员资格、任职状态和服务确认结果；劳动业绩由后端按规则试算。</p>
        <p v-if="validationMessage" class="service-line-craftsmen__validation" role="alert">{{ validationMessage }}</p>
      </main>

      <footer class="service-line-craftsmen__footer">
        <button type="button" class="button button--secondary" :disabled="isSubmitting || isSelecting" @click="$emit('close')">取消</button>
        <button type="button" class="button button--primary" :disabled="isSubmitting || isSelecting" @click="submit">
          {{ isSubmitting ? '正在保存…' : '保存实际手艺人' }}
        </button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.service-line-craftsmen {
  position: fixed;
  z-index: 1100;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(15 23 42 / 48%);
}

.service-line-craftsmen__dialog {
  display: flex;
  flex-direction: column;
  width: min(700px, 100%);
  max-height: min(760px, calc(100vh - 48px));
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 24px 70px rgb(15 23 42 / 24%);
}

.service-line-craftsmen__header,
.service-line-craftsmen__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px;
}

.service-line-craftsmen__header {
  border-bottom: 1px solid #edf1f5;
}

.service-line-craftsmen__header span {
  color: #7b8798;
  font-size: 12px;
  font-weight: 700;
}

.service-line-craftsmen__header h2,
.service-line-craftsmen__header p {
  margin: 0;
}

.service-line-craftsmen__header h2 {
  margin-top: 3px;
  color: #172033;
  font-size: 20px;
}

.service-line-craftsmen__header p {
  margin-top: 5px;
  color: #718096;
  font-size: 13px;
}

.service-line-craftsmen__body {
  flex: 1;
  overflow: auto;
  padding: 20px 24px 26px;
}

.service-line-craftsmen__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 14px 16px;
  border: 1px solid #dde6f0;
  border-radius: 10px;
  background: #fafcff;
}

.service-line-craftsmen__toolbar strong,
.service-line-craftsmen__toolbar span {
  display: block;
}

.service-line-craftsmen__toolbar strong {
  color: #2b3545;
  font-size: 14px;
}

.service-line-craftsmen__toolbar span {
  margin-top: 4px;
  color: #718096;
  font-size: 13px;
}

.service-line-craftsmen__list {
  display: grid;
  gap: 10px;
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
}

.service-line-craftsmen__list li {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 66px;
  padding: 12px 14px;
  border: 1px solid #e0e7ef;
  border-radius: 10px;
}

.service-line-craftsmen__sequence {
  display: inline-grid;
  width: 24px;
  height: 24px;
  flex: 0 0 auto;
  place-items: center;
  border-radius: 50%;
  color: #2d67b1;
  background: #eaf3ff;
  font-size: 12px;
  font-weight: 800;
}

.service-line-craftsmen__identity {
  min-width: 0;
  flex: 1;
}

.service-line-craftsmen__identity strong,
.service-line-craftsmen__identity span {
  display: block;
}

.service-line-craftsmen__identity strong {
  overflow: hidden;
  color: #283548;
  font-size: 14px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.service-line-craftsmen__identity span {
  margin-top: 4px;
  overflow: hidden;
  color: #768498;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.service-line-craftsmen__primary {
  flex: 0 0 auto;
  padding: 4px 8px;
  border-radius: 999px;
  color: #7b4d0f;
  background: #fff2dc;
  font-size: 12px;
  font-weight: 700;
}

.service-line-craftsmen__actions {
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
}

.service-line-craftsmen__actions button {
  border: 0;
  border-radius: 7px;
  padding: 6px 8px;
  color: #4f6177;
  background: #f1f5f9;
  font-size: 12px;
  cursor: pointer;
}

.service-line-craftsmen__actions button:disabled {
  cursor: not-allowed;
  opacity: .48;
}

.service-line-craftsmen__empty {
  margin-top: 16px;
  padding: 26px 16px;
  border: 1px dashed #ccd8e6;
  border-radius: 10px;
  color: #758397;
  text-align: center;
  font-size: 13px;
}

.service-line-craftsmen__backend-tip {
  margin: 18px 0 0;
  padding-top: 16px;
  border-top: 1px solid #edf1f5;
  color: #67778c;
  font-size: 13px;
  line-height: 1.65;
}

.service-line-craftsmen__validation {
  margin: 12px 0 0;
  color: #c2413a;
  font-size: 13px;
}

.service-line-craftsmen__footer {
  border-top: 1px solid #edf1f5;
}

@media (max-width: 640px) {
  .service-line-craftsmen {
    padding: 12px;
  }

  .service-line-craftsmen__header,
  .service-line-craftsmen__footer,
  .service-line-craftsmen__body {
    padding-right: 16px;
    padding-left: 16px;
  }

  .service-line-craftsmen__toolbar,
  .service-line-craftsmen__list li {
    align-items: flex-start;
    flex-direction: column;
  }

  .service-line-craftsmen__actions {
    width: 100%;
  }
}
</style>
