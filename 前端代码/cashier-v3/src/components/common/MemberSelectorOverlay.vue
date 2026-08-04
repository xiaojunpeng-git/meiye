<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import MemberCreatorPanel from '@/components/member/MemberCreatorPanel.vue'

/**
 * 会员记录由后端查询接口提供；组件不计算会员权益、余额或业务状态。
 * 建议记录字段：id、name、phone、memberNo、status、storeName、organizationName、selectable（可选）。
 */
const props = defineProps({
  records: {
    type: Array,
    default: () => []
  },
  total: {
    type: Number,
    default: 0
  },
  page: {
    type: Number,
    default: 1
  },
  pageSize: {
    type: Number,
    default: 20
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    default: '选择会员'
  },
  onQuery: {
    type: Function,
    default: null
  },
  onSelect: {
    type: Function,
    default: null
  },
  allowGuest: {
    type: Boolean,
    default: false
  },
  allowCreate: {
    type: Boolean,
    default: false
  },
  currentStoreName: {
    type: String,
    default: ''
  },
  creatorSchema: {
    type: Object,
    default: () => ({})
  },
  initialView: {
    type: String,
    default: 'selector'
  },
  onSelectGuest: {
    type: Function,
    default: null
  },
  onCreateMember: {
    type: Function,
    default: null
  },
  onSelectServicePerson: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['close'])

const keyword = ref('')
const isQuerying = ref(false)
const selectingRecordId = ref(null)
const queryError = ref('')
const selectError = ref('')
const activeView = ref(props.initialView === 'creator' ? 'creator' : 'selector')
const isSelectingGuest = ref(false)
let keywordQueryTimer = null
let querySequence = 0

const isBusy = computed(() => props.isLoading || isQuerying.value || isSelectingGuest.value)
const pageCount = computed(() => Math.max(1, Math.ceil(props.total / Math.max(1, props.pageSize))))
const canGoPrevious = computed(() => !isBusy.value && props.page > 1)
const canGoNext = computed(() => !isBusy.value && props.page < pageCount.value)
const dialogTitle = computed(() => activeView.value === 'creator' ? '添加会员' : props.title)

watch(() => props.initialView, (view) => {
  activeView.value = view === 'creator' ? 'creator' : 'selector'
})

function memberStatus(record) {
  return record?.status || record?.statusLabel || record?.statusCode || '—'
}

function isUnavailable(record) {
  if (typeof record?.selectable === 'boolean') return !record.selectable
  const status = String(memberStatus(record)).trim().toLowerCase()
  return ['停用', '已停用', '注销', '已注销', 'disabled', 'cancelled', 'canceled'].includes(status)
}

function statusClass(record) {
  if (isUnavailable(record)) return 'member-selector__status--unavailable'
  return 'member-selector__status--normal'
}

function resultError(result, fallback) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  const outcome = response?.result && typeof response.result === 'object' ? response.result : response
  const status = String(outcome?.status || '').toLowerCase()
  if (result === false || response?.success === false || response?.ok === false || ['failed', 'conflict', 'result_unknown'].includes(status)) {
    return outcome?.message || response?.message || response?.errorMessage || response?.error || fallback
  }
  return ''
}

async function runQuery(targetPage = 1) {
  if (props.isLoading || isSelectingGuest.value || selectingRecordId.value !== null) return
  const sequence = ++querySequence
  queryError.value = ''

  if (typeof props.onQuery !== 'function') {
    queryError.value = '暂未接入会员查询服务。'
    return
  }

  isQuerying.value = true
  try {
    const result = await props.onQuery({
      keyword: keyword.value.trim(),
      page: targetPage,
      pageSize: props.pageSize
    })
    if (sequence === querySequence) queryError.value = resultError(result, '会员查询失败，请稍后重试。')
  } catch (error) {
    if (sequence === querySequence) queryError.value = error?.message || '会员查询失败，请稍后重试。'
  } finally {
    if (sequence === querySequence) isQuerying.value = false
  }
}

function submitQuery() {
  if (keywordQueryTimer) clearTimeout(keywordQueryTimer)
  runQuery(1)
}

function clearQuery() {
  keyword.value = ''
  if (keywordQueryTimer) clearTimeout(keywordQueryTimer)
  runQuery(1)
}

function scheduleKeywordQuery() {
  if (keywordQueryTimer) clearTimeout(keywordQueryTimer)
  if (activeView.value !== 'selector') return
  keywordQueryTimer = setTimeout(() => runQuery(1), 300)
}

watch(keyword, scheduleKeywordQuery)

// 打开选择器即加载当前数据权限范围内的第一页。此前只有手动点击“查询”
// 才会发起请求，首次打开会把尚未加载误显示成“暂无可显示的会员”。
onMounted(() => {
  runQuery(1)
})

onBeforeUnmount(() => {
  if (keywordQueryTimer) clearTimeout(keywordQueryTimer)
  querySequence += 1
})

async function selectMember(record) {
  if (isUnavailable(record) || selectingRecordId.value || isBusy.value) return
  selectError.value = ''

  if (typeof props.onSelect !== 'function') {
    selectError.value = '暂未接入会员选择服务。'
    return
  }

  selectingRecordId.value = record.id ?? record.memberId ?? record.uid ?? record.memberNo
  try {
    const result = await props.onSelect(record)
    const error = resultError(result, '选择会员失败，请稍后重试。')
    if (error) {
      selectError.value = error
      return
    }

    // 选择成功只关闭当前弹窗；由调用方决定后续页面状态，不在此组件内跳转路由。
    emit('close', { reason: 'selected', record })
  } catch (error) {
    selectError.value = error?.message || '选择会员失败，请稍后重试。'
  } finally {
    selectingRecordId.value = null
  }
}

async function selectGuest() {
  if (!props.allowGuest || isBusy.value || selectingRecordId.value !== null) return
  selectError.value = ''
  if (typeof props.onSelectGuest !== 'function') {
    selectError.value = '暂未接入游客开单服务。'
    return
  }

  isSelectingGuest.value = true
  try {
    const result = await props.onSelectGuest()
    const error = resultError(result, '切换游客失败，请稍后重试。')
    if (error) {
      selectError.value = error
      return
    }
    emit('close', { reason: 'guest-selected' })
  } catch (error) {
    selectError.value = error?.message || '切换游客失败，请稍后重试。'
  } finally {
    isSelectingGuest.value = false
  }
}

function openCreator() {
  if (!props.allowCreate || isBusy.value || selectingRecordId.value !== null) return
  queryError.value = ''
  selectError.value = ''
  activeView.value = 'creator'
}

function returnToSelector() {
  activeView.value = 'selector'
}

async function handleCreated(payload = {}) {
  const record = payload.member || payload.record || null
  if (!record) {
    selectError.value = '会员已创建，但未取得可回填的会员资料，请重新查询。'
    activeView.value = 'selector'
    return
  }
  await selectMember(record)
}
</script>

<template>
  <Teleport to="body">
    <div class="member-selector" role="presentation" @click.self="$emit('close', { reason: 'dismiss' })">
      <section class="member-selector__dialog" role="dialog" aria-modal="true" :aria-label="dialogTitle">
      <header class="member-selector__header">
        <div class="member-selector__heading">
          <button v-if="activeView === 'creator'" type="button" class="member-selector__back" aria-label="返回选择会员" @click="returnToSelector">←</button>
          <h2>{{ dialogTitle }}</h2>
        </div>
        <button type="button" class="member-selector__close" :disabled="isBusy || selectingRecordId !== null" aria-label="关闭选择会员" @click="$emit('close', { reason: 'close' })">×</button>
      </header>

      <template v-if="activeView === 'selector'">
      <form class="member-selector__search" @submit.prevent="submitQuery">
        <label class="member-selector__search-field">
          <span>查询会员</span>
          <input v-model="keyword" type="search" autocomplete="off" placeholder="姓名、完整手机号或会员编号" :disabled="isBusy || selectingRecordId !== null">
        </label>
        <button type="submit" class="member-selector__button member-selector__button--primary" :disabled="isBusy || selectingRecordId !== null">
          {{ isBusy ? '查询中…' : '查询' }}
        </button>
        <button type="button" class="member-selector__button" :disabled="isBusy || selectingRecordId !== null" @click="clearQuery">清空</button>
        <span class="member-selector__search-divider" aria-hidden="true" />
        <button v-if="allowGuest" type="button" class="member-selector__button member-selector__button--guest" :disabled="isBusy || selectingRecordId !== null" @click="selectGuest">
          {{ isSelectingGuest ? '切换中…' : '游客' }}
        </button>
        <button v-if="allowCreate" type="button" class="member-selector__button member-selector__button--create" :disabled="isBusy || selectingRecordId !== null" @click="openCreator">添加会员</button>
      </form>

      <div class="member-selector__feedback" aria-live="polite">
        <p v-if="queryError" class="member-selector__message member-selector__message--error" role="alert">{{ queryError }}</p>
        <p v-if="selectError" class="member-selector__message member-selector__message--error" role="alert">{{ selectError }}</p>
      </div>

      <div class="member-selector__content" :aria-busy="isBusy">
        <div v-if="isBusy && !records.length" class="member-selector__loading">正在加载会员…</div>
        <div v-else-if="!records.length" class="member-selector__empty">
          <strong>暂无可显示的会员</strong>
          <span>可直接查询，或清空条件后分页浏览全部会员。</span>
        </div>

        <table v-else class="member-selector__table">
          <thead>
            <tr>
              <th>会员姓名</th>
              <th>完整手机号</th>
              <th>会员编号</th>
              <th>会员状态</th>
              <th>归属门店</th>
              <th>所属组织</th>
              <th class="member-selector__action-column">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in records" :key="record.id ?? record.memberId ?? record.uid ?? record.memberNo" :class="{ 'member-selector__row--unavailable': isUnavailable(record) }">
              <td><strong>{{ record.name || '—' }}</strong></td>
              <td>{{ record.phone || '—' }}</td>
              <td>{{ record.memberNo || '—' }}</td>
              <td><span class="member-selector__status" :class="statusClass(record)">{{ memberStatus(record) }}</span></td>
              <td>{{ record.storeName || '—' }}</td>
              <td>{{ record.organizationName || '—' }}</td>
              <td class="member-selector__action-column">
                <button
                  type="button"
                  class="member-selector__button member-selector__button--text"
                  :disabled="isUnavailable(record) || isBusy || selectingRecordId !== null"
                  :title="isUnavailable(record) ? '停用或已注销会员不可选择' : '选择该会员'"
                  @click="selectMember(record)"
                >
                  {{ isUnavailable(record) ? '不可选择' : selectingRecordId === (record.id ?? record.memberId ?? record.uid ?? record.memberNo) ? '选择中…' : '选择' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="member-selector__footer">
        <span>共 {{ total }} 位会员，第 {{ page }} / {{ pageCount }} 页</span>
        <div class="member-selector__pagination">
          <button type="button" class="member-selector__button" :disabled="!canGoPrevious || selectingRecordId !== null" @click="runQuery(page - 1)">上一页</button>
          <button type="button" class="member-selector__button" :disabled="!canGoNext || selectingRecordId !== null" @click="runQuery(page + 1)">下一页</button>
        </div>
      </footer>
      </template>

      <MemberCreatorPanel
        v-else
        :initial-keyword="keyword"
        :current-store-name="currentStoreName"
        :profile-fields="creatorSchema.profileFields || creatorSchema.fields || []"
        :member-levels="creatorSchema.memberLevels || creatorSchema.levels || []"
        :member-tags="creatorSchema.memberTags || creatorSchema.tags || []"
        :on-submit="onCreateMember"
        :on-select-service-person="onSelectServicePerson"
        @cancel="returnToSelector"
        @created="handleCreated"
        @select-existing="selectMember"
      />
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.member-selector {
  position: fixed;
  z-index: 1200;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 32px;
  background: rgb(16 24 40 / 46%);
}

.member-selector__dialog {
  display: flex;
  width: min(1100px, 100%);
  height: min(720px, calc(100vh - 64px));
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #dfe5ef;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 24px 60px rgb(20 32 54 / 24%);
}

.member-selector__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding: 24px 28px 18px;
  border-bottom: 1px solid #edf0f5;
}

.member-selector__header h2 {
  margin: 0;
  color: #172033;
  font-size: 20px;
  line-height: 28px;
}

.member-selector__heading {
  display: flex;
  align-items: center;
  gap: 10px;
}

.member-selector__back {
  display: grid;
  width: 32px;
  height: 32px;
  place-items: center;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font-size: 18px;
}

.member-selector__back:hover {
  border-color: #a7b6df;
  background: #f7f9ff;
}

.member-selector__header p {
  max-width: 760px;
  margin: 6px 0 0;
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}

.member-selector__close {
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 28px;
  line-height: 28px;
}

.member-selector__close:hover:not(:disabled) {
  background: #f2f4f7;
  color: #344054;
}

.member-selector__search {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  padding: 18px 28px;
  border-bottom: 1px solid #edf0f5;
  background: #fbfcfe;
}

.member-selector__search-field {
  display: grid;
  min-width: 360px;
  flex: 1;
  gap: 6px;
  color: #475467;
  font-size: 13px;
  font-weight: 600;
}

.member-selector__search-field input {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 12px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  outline: none;
  color: #172033;
  font: inherit;
  font-weight: 400;
}

.member-selector__search-field input:focus {
  border-color: #4b77ff;
  box-shadow: 0 0 0 3px rgb(75 119 255 / 12%);
}

.member-selector__button {
  min-height: 36px;
  padding: 0 14px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}

.member-selector__button:hover:not(:disabled) {
  border-color: #a7b6df;
  background: #f7f9ff;
}

.member-selector__button--primary {
  border-color: #3b63e6;
  background: #3b63e6;
  color: #fff;
}

.member-selector__button--primary:hover:not(:disabled) {
  border-color: #2b50cf;
  background: #2b50cf;
}

.member-selector__button--text {
  min-height: 32px;
  border-color: transparent;
  color: #315fd6;
}

.member-selector__button--guest {
  border-color: #b8c5da;
  background: #f7f9fc;
  color: #344054;
}

.member-selector__button--create {
  border-color: #315fd6;
  background: #fff;
  color: #315fd6;
}

.member-selector__search-divider {
  width: 1px;
  height: 28px;
  margin: 4px 2px;
  background: #dfe5ef;
}

.member-selector__button:disabled,
.member-selector__close:disabled {
  cursor: not-allowed;
  opacity: .52;
}

.member-selector__feedback {
  box-sizing: border-box;
  min-height: 45px;
  padding: 8px 28px;
  border-bottom: 1px solid #edf0f5;
  background: #fff;
}

.member-selector__message {
  margin: 0;
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 20px;
}

.member-selector__message + .member-selector__message {
  margin-top: 6px;
}

.member-selector__message--error {
  background: #fff1f1;
  color: #b42318;
}

.member-selector__content {
  min-height: 220px;
  flex: 1;
  overflow: auto;
  scrollbar-gutter: stable;
}

.member-selector__table {
  width: 100%;
  min-width: 940px;
  border-collapse: collapse;
  color: #344054;
  font-size: 13px;
}

.member-selector__table th,
.member-selector__table td {
  padding: 13px 16px;
  border-bottom: 1px solid #edf0f5;
  text-align: left;
  vertical-align: middle;
  white-space: nowrap;
}

.member-selector__table th {
  position: sticky;
  z-index: 1;
  top: 0;
  background: #f8fafc;
  color: #667085;
  font-size: 12px;
  font-weight: 600;
}

.member-selector__table td:first-child {
  color: #172033;
}

.member-selector__row--unavailable {
  background: #fafafa;
  color: #98a2b3;
}

.member-selector__row--unavailable td:first-child {
  color: #667085;
}

.member-selector__status {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}

.member-selector__status--normal {
  background: #ecfdf3;
  color: #027a48;
}

.member-selector__status--unavailable {
  background: #f2f4f7;
  color: #667085;
}

.member-selector__action-column {
  position: sticky;
  right: 0;
  z-index: 2;
  width: 100px;
  min-width: 100px;
  background: #fff;
  box-shadow: -10px 0 16px -16px rgb(16 24 40 / 60%);
  text-align: right !important;
}

.member-selector__table th.member-selector__action-column {
  z-index: 3;
  background: #f8fafc;
}

.member-selector__row--unavailable .member-selector__action-column {
  background: #fafafa;
}

.member-selector__loading,
.member-selector__empty {
  display: grid;
  min-height: 220px;
  place-content: center;
  gap: 8px;
  padding: 28px;
  color: #667085;
  text-align: center;
}

.member-selector__empty strong {
  color: #344054;
  font-size: 15px;
}

.member-selector__empty span {
  font-size: 13px;
}

.member-selector__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 15px 28px;
  border-top: 1px solid #edf0f5;
  color: #667085;
  font-size: 13px;
}

.member-selector__pagination {
  display: flex;
  gap: 8px;
}

@media (max-width: 760px) {
  .member-selector {
    padding: 12px;
  }

  .member-selector__dialog {
    height: calc(100vh - 24px);
  }

  .member-selector__header,
  .member-selector__search,
  .member-selector__feedback,
  .member-selector__footer {
    padding-right: 16px;
    padding-left: 16px;
  }

  .member-selector__header {
    gap: 12px;
  }

  .member-selector__search {
    align-items: stretch;
    flex-wrap: wrap;
  }

  .member-selector__search-divider {
    display: none;
  }

  .member-selector__search-field {
    min-width: 100%;
  }

  .member-selector__footer {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
