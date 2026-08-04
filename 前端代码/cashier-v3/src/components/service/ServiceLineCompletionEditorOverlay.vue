<script setup>
import { computed, ref, watch } from 'vue'
import { createCashierV3CommandId } from '@/services/cashierV3Bridge'

/**
 * 单个服务项目的实际完成确认。
 *
 * 本组件只收集操作人员确认的项目完成数量、未服务原因和改约意图；
 * 不计算核销金额、消耗业绩、劳动业绩或任何权益结果。外层收到 request 后
 * 必须由后端重新校验并返回完整服务确认快照。
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
  isSubmitting: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'request'])

const completionMode = ref('completed')
const actualCompletedQuantity = ref(1)
const unservedReason = ref('')
const unservedReasonNote = ref('')
const createReschedule = ref(false)
const validationMessage = ref('')
const submitCommandId = ref(null)

const reasonOptions = [
  { value: 'customer_cancelled', label: '客户取消' },
  { value: 'insufficient_time', label: '时间不足' },
  { value: 'other', label: '其他' }
]

const lineId = computed(() => firstValue(props.line, ['id', 'lineId', 'serviceLineId', 'projectLineId']))
const lineVersion = computed(() => firstValue(props.line, ['revision', 'recordVersion', 'version', 'lineVersion']))
const serviceOrderId = computed(() => firstValue(props.serviceOrder, ['id', 'serviceOrderId', 'serviceSessionId', 'serviceNo']))
const serviceOrderVersion = computed(() => firstValue(props.serviceOrder, ['revision', 'recordVersion', 'version']))
const lineName = computed(() => firstValue(props.line, ['name', 'projectName', 'productName']) || '该服务项目')
const totalQuantity = computed(() => Math.max(1, positiveInteger(firstValue(props.line, ['quantity', 'serviceQuantity', 'selectedTimes', 'totalQuantity']), 1)))
const effectiveCompletedQuantity = computed(() => completionMode.value === 'not_served' ? 0 : positiveInteger(actualCompletedQuantity.value, 0))
const unfinishedQuantity = computed(() => Math.max(totalQuantity.value - effectiveCompletedQuantity.value, 0))
const needsUnservedReason = computed(() => unfinishedQuantity.value > 0)
const isPartial = computed(() => effectiveCompletedQuantity.value > 0 && unfinishedQuantity.value > 0)

watch(
  () => props.line,
  (line) => {
    const status = String(firstValue(line, ['completionStatus', 'serviceCompletionStatus', 'actualCompletionStatus'])).toLowerCase()
    const isNotServed = ['not_served', 'unserved', 'notserved', '本次未服务'].includes(status)
    const configuredCompleted = positiveInteger(firstValue(line, ['actualCompletedQuantity', 'completedQuantity', 'actualQuantity']), isNotServed ? 0 : totalQuantity.value)

    completionMode.value = isNotServed ? 'not_served' : 'completed'
    actualCompletedQuantity.value = isNotServed ? 0 : Math.min(configuredCompleted, totalQuantity.value)
    unservedReason.value = firstValue(line, ['unservedReason', 'notServedReason', 'unfinishedReason']) || ''
    unservedReasonNote.value = firstValue(line, ['unservedReasonNote', 'notServedReasonNote', 'unfinishedReasonNote']) || ''
    createReschedule.value = line?.createReschedule === true || line?.rescheduleRequested === true
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

function positiveInteger(value, fallback) {
  const number = Number(value)
  if (!Number.isFinite(number)) return fallback
  return Math.max(0, Math.floor(number))
}

function setCompletionMode(mode) {
  completionMode.value = mode
  if (mode === 'not_served') actualCompletedQuantity.value = 0
  else if (actualCompletedQuantity.value < 1) actualCompletedQuantity.value = totalQuantity.value
  validationMessage.value = ''
  submitCommandId.value = null
}

function updateCompletedQuantity(value) {
  actualCompletedQuantity.value = value
  if (Number(value) > 0) completionMode.value = 'completed'
  validationMessage.value = ''
  submitCommandId.value = null
}

function clearReasonWhenNoUnfinished() {
  if (!needsUnservedReason.value) {
    unservedReason.value = ''
    unservedReasonNote.value = ''
    createReschedule.value = false
  }
  submitCommandId.value = null
}

function validate() {
  if (!lineId.value) return '未找到该服务项目，请返回后重新打开。'
  if (!serviceOrderId.value) return '未找到本次服务单，请返回后重新打开。'

  if (completionMode.value === 'completed') {
    if (!Number.isInteger(Number(actualCompletedQuantity.value)) || effectiveCompletedQuantity.value < 1) {
      return '请填写实际完成数量。'
    }
    if (effectiveCompletedQuantity.value > totalQuantity.value) {
      return `实际完成数量不能超过本次数量 ${totalQuantity.value}。`
    }
  }

  if (needsUnservedReason.value) {
    if (!unservedReason.value) return '请说明未服务部分的原因。'
    if (unservedReason.value === 'other' && !unservedReasonNote.value.trim()) return '选择“其他”时，请补充原因说明。'
  }

  return ''
}

function submit() {
  validationMessage.value = validate()
  if (validationMessage.value) return
  if (!submitCommandId.value) submitCommandId.value = createCashierV3CommandId('SERVICE_LINE')

  emit('request', {
    action: 'save-service-line-completion',
    payload: {
      serviceOrderId: serviceOrderId.value,
      serviceOrderVersion: serviceOrderVersion.value || null,
      idempotencyKey: submitCommandId.value,
      lineId: lineId.value,
      lineVersion: lineVersion.value || null,
      actualCompletedQuantity: effectiveCompletedQuantity.value,
      unservedReason: needsUnservedReason.value ? unservedReason.value : null,
      unservedReasonNote: needsUnservedReason.value && unservedReason.value === 'other'
        ? unservedReasonNote.value.trim()
        : null,
      createReschedule: needsUnservedReason.value && createReschedule.value
    }
  })
}
</script>

<template>
  <div class="service-line-completion-editor" role="presentation" @click.self="$emit('close')">
    <section class="service-line-completion-editor__dialog" role="dialog" aria-modal="true" aria-label="确认项目完成情况">
      <header class="service-line-completion-editor__header">
        <div>
          <span>项目完成情况</span>
          <h2>{{ lineName }}</h2>
          <p>本次数量：{{ totalQuantity }}</p>
        </div>
        <button type="button" class="button button--secondary" :disabled="isSubmitting" @click="$emit('close')">关闭</button>
      </header>

      <main class="service-line-completion-editor__body">
        <section class="service-line-completion-editor__section">
          <h3>本次服务结果</h3>
          <div class="service-line-completion-editor__mode-list">
            <label>
              <input type="radio" :checked="completionMode === 'completed'" :disabled="isSubmitting" @change="setCompletionMode('completed')">
              <span>已完成或部分完成</span>
            </label>
            <label>
              <input type="radio" :checked="completionMode === 'not_served'" :disabled="isSubmitting" @change="setCompletionMode('not_served')">
              <span>本次未服务</span>
            </label>
          </div>

          <label v-if="completionMode === 'completed'" class="service-line-completion-editor__field">
            <span>实际完成数量</span>
            <input
              :value="actualCompletedQuantity"
              type="number"
              min="1"
              :max="totalQuantity"
              step="1"
              inputmode="numeric"
              :disabled="isSubmitting"
              @input="updateCompletedQuantity($event.target.value)"
              @change="clearReasonWhenNoUnfinished"
            >
          </label>

          <p v-if="isPartial" class="service-line-completion-editor__hint">
            其余 {{ unfinishedQuantity }} 次将按“本次未服务”处理，请填写原因；系统会由后端重新确认权益和后续安排。
          </p>
        </section>

        <section v-if="needsUnservedReason" class="service-line-completion-editor__section">
          <h3>未服务原因</h3>
          <div class="service-line-completion-editor__reason-list">
            <label v-for="option in reasonOptions" :key="option.value">
              <input v-model="unservedReason" type="radio" :value="option.value" :disabled="isSubmitting">
              <span>{{ option.label }}</span>
            </label>
          </div>

          <label v-if="unservedReason === 'other'" class="service-line-completion-editor__field">
            <span>原因说明</span>
            <textarea v-model="unservedReasonNote" rows="3" maxlength="200" :disabled="isSubmitting" placeholder="请简要说明原因"></textarea>
          </label>

          <label class="service-line-completion-editor__reschedule">
            <input v-model="createReschedule" type="checkbox" :disabled="isSubmitting">
            <span>保存后去改约未服务项目</span>
          </label>
        </section>

        <p class="service-line-completion-editor__backend-tip">保存后由系统重新核对实际服务、卡项权益和人员信息；本页面不会计算金额或业绩。</p>
        <p v-if="validationMessage" class="service-line-completion-editor__validation" role="alert">{{ validationMessage }}</p>
      </main>

      <footer class="service-line-completion-editor__footer">
        <button type="button" class="button button--secondary" :disabled="isSubmitting" @click="$emit('close')">取消</button>
        <button type="button" class="button button--primary" :disabled="isSubmitting" @click="submit">
          {{ isSubmitting ? '正在保存…' : (createReschedule && needsUnservedReason ? '保存并去改约' : '保存项目结果') }}
        </button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.service-line-completion-editor {
  position: fixed;
  z-index: 1100;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(15 23 42 / 48%);
}

.service-line-completion-editor__dialog {
  display: flex;
  flex-direction: column;
  width: min(620px, 100%);
  height: min(640px, calc(100vh - 48px));
  min-height: 0;
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 24px 70px rgb(15 23 42 / 24%);
}

.service-line-completion-editor__header,
.service-line-completion-editor__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px;
}

.service-line-completion-editor__header {
  border-bottom: 1px solid #edf1f5;
}

.service-line-completion-editor__header span {
  color: #7b8798;
  font-size: 12px;
  font-weight: 700;
}

.service-line-completion-editor__header h2,
.service-line-completion-editor__header p,
.service-line-completion-editor__section h3 {
  margin: 0;
}

.service-line-completion-editor__header h2 {
  margin-top: 3px;
  color: #172033;
  font-size: 20px;
}

.service-line-completion-editor__header p {
  margin-top: 5px;
  color: #718096;
  font-size: 13px;
}

.service-line-completion-editor__body {
  flex: 1;
  overflow: auto;
  padding: 20px 24px 26px;
}

.service-line-completion-editor__section + .service-line-completion-editor__section {
  margin-top: 20px;
}

.service-line-completion-editor__section h3 {
  color: #2b3545;
  font-size: 15px;
}

.service-line-completion-editor__mode-list,
.service-line-completion-editor__reason-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
}

.service-line-completion-editor__mode-list label,
.service-line-completion-editor__reason-list label {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  min-height: 38px;
  padding: 0 12px;
  border: 1px solid #dbe3ed;
  border-radius: 9px;
  color: #344052;
  background: #fafcff;
  cursor: pointer;
}

.service-line-completion-editor__field {
  display: grid;
  gap: 8px;
  margin-top: 14px;
  color: #4b5b70;
  font-size: 13px;
  font-weight: 700;
}

.service-line-completion-editor__field input,
.service-line-completion-editor__field textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #cfd9e6;
  border-radius: 8px;
  padding: 9px 10px;
  color: #1f2937;
  font: inherit;
  font-weight: 400;
  resize: vertical;
}

.service-line-completion-editor__field input {
  max-width: 160px;
}

.service-line-completion-editor__hint,
.service-line-completion-editor__backend-tip {
  margin: 12px 0 0;
  color: #67778c;
  font-size: 13px;
  line-height: 1.65;
}

.service-line-completion-editor__hint {
  padding: 10px 12px;
  border-radius: 8px;
  color: #8a5a16;
  background: #fff7e8;
}

.service-line-completion-editor__reschedule {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
  color: #334155;
  font-size: 14px;
  cursor: pointer;
}

.service-line-completion-editor__backend-tip {
  padding-top: 16px;
  border-top: 1px solid #edf1f5;
}

.service-line-completion-editor__validation {
  margin: 12px 0 0;
  color: #c2413a;
  font-size: 13px;
}

.service-line-completion-editor__footer {
  border-top: 1px solid #edf1f5;
}

@media (max-width: 640px) {
  .service-line-completion-editor {
    padding: 12px;
  }

  .service-line-completion-editor__header,
  .service-line-completion-editor__footer,
  .service-line-completion-editor__body {
    padding-right: 16px;
    padding-left: 16px;
  }

  .service-line-completion-editor__dialog {
    height: calc(100vh - 24px);
  }
}
</style>
