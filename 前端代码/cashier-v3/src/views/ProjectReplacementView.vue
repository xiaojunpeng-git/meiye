<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { formatMoney, useCashierV3State } from '@/services/cashierV3Bridge'

const state = useCashierV3State()
const replacement = computed(() => state.replacement || {})
const selectedCardId = ref('')
const selectedTargetId = ref('')
const sourceLines = ref([])
const targetKeyword = ref('')
const sourceKeyword = ref('')
const remark = ref('')
const validationAttempted = ref(false)
const draftDirty = ref(false)
const isConfirmationOpen = ref(false)
const isRecordsOpen = ref(false)
const activeRecord = ref(null)
const isRecentExpanded = ref(false)

const cards = computed(() => Array.isArray(replacement.value.cards) ? replacement.value.cards : [])
const sourceProjects = computed(() => Array.isArray(replacement.value.sourceProjects) ? replacement.value.sourceProjects : [])
const targets = computed(() => Array.isArray(replacement.value.targets) ? replacement.value.targets : [])
const recentRecords = computed(() => Array.isArray(replacement.value.recentRecords) ? replacement.value.recentRecords : [])
const summary = computed(() => replacement.value.summary || {})
const selectedCard = computed(() => cards.value.find((card) => String(card.id) === String(selectedCardId.value)) || null)
const selectedTarget = computed(() => targets.value.find((target) => String(target.id) === String(selectedTargetId.value)) || null)
const visibleCards = computed(() => {
  const keyword = sourceKeyword.value.trim().toLocaleLowerCase()
  if (!keyword) return cards.value
  const matchedCardIds = new Set(sourceProjects.value
    .filter((project) => `${project.name || ''} ${project.benefitReference || ''}`.toLocaleLowerCase().includes(keyword))
    .map((project) => String(project.cardId)))
  return cards.value.filter((card) => {
    const text = `${card.name || ''} ${card.reference || ''}`.toLocaleLowerCase()
    return text.includes(keyword) || matchedCardIds.has(String(card.id))
  })
})
const visibleSourceProjects = computed(() => {
  if (!selectedCardId.value) return []
  const keyword = sourceKeyword.value.trim().toLocaleLowerCase()
  return sourceProjects.value.filter((project) => {
    const cardMatched = !selectedCardId.value || String(project.cardId) === String(selectedCardId.value)
    const text = `${project.name || ''} ${project.benefitReference || ''}`.toLocaleLowerCase()
    return cardMatched && (!keyword || text.includes(keyword))
  })
})
const visibleTargets = computed(() => {
  const keyword = targetKeyword.value.trim().toLocaleLowerCase()
  return targets.value.filter((target) => !keyword || `${target.name || ''} ${target.category || ''}`.toLocaleLowerCase().includes(keyword))
})
const selectedSourceTimes = computed(() => sourceLines.value.reduce((total, line) => total + Number(line.times || 0), 0))
const hasCompleteDraft = computed(() => Boolean(selectedCardId.value && sourceLines.value.length && selectedTargetId.value))
const displayedAmount = computed(() => draftDirty.value ? null : summary.value.totalAmount)
const sourceEmptyHint = computed(() => selectedCardId.value
  ? '请在左侧第2步添加来源项目'
  : '请先在左侧第1步选择一张卡')
const targetEmptyHint = computed(() => sourceLines.value.length
  ? '请在左侧第3步选择新项目'
  : '请先完成左侧第2步添加来源项目')

function hydrateProjection() {
  selectedCardId.value = replacement.value.selectedCardId || ''
  selectedTargetId.value = replacement.value.selectedTargetId || ''
  sourceLines.value = (Array.isArray(replacement.value.sourceLines) ? replacement.value.sourceLines : []).map((line) => ({ ...line }))
  remark.value = replacement.value.remark || ''
  targetKeyword.value = ''
  sourceKeyword.value = ''
  validationAttempted.value = false
  draftDirty.value = false
  isConfirmationOpen.value = false
  isRecordsOpen.value = false
  activeRecord.value = null
}

watch(
  () => [replacement.value.revision, replacement.value.member?.id, state.stateContextId],
  hydrateProjection,
  { immediate: true }
)

function chooseCard(card) {
  if (String(selectedCardId.value) === String(card.id)) return
  selectedCardId.value = card.id
  sourceLines.value = []
  selectedTargetId.value = ''
  draftDirty.value = true
}

function sourceAddedTimes(project) {
  return sourceLines.value
    .filter((line) => String(line.sourceId) === String(project.id))
    .reduce((total, line) => total + Number(line.times || 0), 0)
}

function addSource(project) {
  if (!selectedCardId.value || sourceAddedTimes(project) >= Number(project.remainingTimes || 0)) return
  sourceLines.value = [
    ...sourceLines.value,
    {
      key: `local-${project.id}-${Date.now()}`,
      sourceId: project.id,
      name: project.name,
      benefitReference: project.benefitReference,
      times: 1,
      maxTimes: project.remainingTimes,
      amount: null
    }
  ]
  draftDirty.value = true
}

function changeSourceTimes(line, delta) {
  const next = Number(line.times || 0) + delta
  if (next < 1 || next > Number(line.maxTimes || 1)) return
  sourceLines.value = sourceLines.value.map((item) => item.key === line.key ? { ...item, times: next, amount: null } : item)
  draftDirty.value = true
}

function removeSource(line) {
  sourceLines.value = sourceLines.value.filter((item) => item.key !== line.key)
  draftDirty.value = true
}

function chooseTarget(target) {
  selectedTargetId.value = target.id
  draftDirty.value = true
}

function openConfirmation() {
  validationAttempted.value = true
  if (!hasCompleteDraft.value) {
    const firstInvalid = document.querySelector('.replacement-step--invalid')
    firstInvalid?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    return
  }
  isConfirmationOpen.value = true
}

function openRecord(record) {
  activeRecord.value = record
}

function handleContextChange() {
  hydrateProjection()
}

onMounted(() => {
  window.addEventListener('cashier-v3:state-context-changing', handleContextChange)
  window.addEventListener('cashier-v3:state-context-changed', handleContextChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:state-context-changing', handleContextChange)
  window.removeEventListener('cashier-v3:state-context-changed', handleContextChange)
})
</script>

<template>
  <section class="replacement-page" aria-label="项目替换工作台">
    <div class="replacement-page__body">
      <main class="replacement-workspace">
        <header class="replacement-workspace__intro">
          <p class="replacement-selection-prompt">请选择客户需要服务的项目</p>
          <label class="replacement-global-search">
            <span class="sr-only">搜索卡名称、卡号或原项目</span>
            <input v-model="sourceKeyword" type="search" placeholder="搜索卡名称、卡号或原项目" autocomplete="off">
          </label>
        </header>

        <section class="replacement-step" :class="{ 'replacement-step--complete': selectedCardId, 'replacement-step--invalid': validationAttempted && !selectedCardId }">
          <header class="replacement-step__header">
            <span>1</span>
            <div><h2>选择一张卡</h2><p>项目替换不能跨卡，来源项目必须属于同一张卡。</p></div>
          </header>
          <p v-if="validationAttempted && !selectedCardId" class="replacement-step__error">请先选择一张卡。</p>
          <div class="replacement-card-grid">
            <button v-for="card in visibleCards" :key="card.id" type="button" :class="{ active: String(selectedCardId) === String(card.id) }" :title="`${card.name || '未命名卡项'} · ${card.reference || '暂无完整卡号'}`" @click="chooseCard(card)">
              <span class="replacement-card-grid__icon" aria-hidden="true">卡</span>
              <span><strong>{{ card.name }}</strong><small>{{ card.reference || '暂无完整卡号' }} · {{ card.expiryText || card.status || '—' }}</small></span>
              <i v-if="String(selectedCardId) === String(card.id)" aria-hidden="true">✓</i>
            </button>
          </div>
          <div v-if="!visibleCards.length" class="replacement-empty">{{ cards.length ? '没有符合搜索条件的卡项。' : '当前会员暂无可用于项目替换的卡项。' }}</div>
        </section>

        <section class="replacement-step" :class="{ 'replacement-step--complete': sourceLines.length, 'replacement-step--invalid': validationAttempted && selectedCardId && !sourceLines.length }">
          <header class="replacement-step__header">
            <span>2</span>
            <div><h2>添加来源项目</h2><p>同一项目可以分多条添加，合计次数不能超过剩余次数。</p></div>
            <strong v-if="sourceLines.length">{{ sourceLines.length }} 条</strong>
          </header>
          <p v-if="validationAttempted && selectedCardId && !sourceLines.length" class="replacement-step__error">请至少添加一条来源项目。</p>
          <div class="replacement-source-grid">
            <button v-for="project in visibleSourceProjects" :key="project.id" type="button" :disabled="!selectedCardId || sourceAddedTimes(project) >= Number(project.remainingTimes || 0)" @click="addSource(project)">
              <strong :title="project.name">{{ project.name }}</strong>
              <span class="replacement-source-grid__meta"><i>剩余次数</i><b>{{ project.remainingTimes || 0 }} 次</b><em>·</em><span>单次核销 {{ formatMoney(project.unitAmount) }}</span></span>
              <em>{{ sourceAddedTimes(project) ? `已添加 ${sourceAddedTimes(project)} 次` : '＋ 添加一次' }}</em>
            </button>
          </div>
          <div v-if="selectedCardId && !visibleSourceProjects.length" class="replacement-empty">当前卡没有符合条件的可替换项目。</div>

          <div v-if="sourceLines.length" class="replacement-source-table">
            <div class="replacement-source-table__summary">已选 {{ sourceLines.length }} 条 · 合计扣减 {{ selectedSourceTimes }} 次 · 金额以服务端核对为准</div>
            <header><span>序号</span><span>项目名称／权益号</span><span>本次次数</span><span>扣减金额</span><span>操作</span></header>
            <div v-for="(line, index) in sourceLines" :key="line.key" class="replacement-source-table__row">
              <span>{{ index + 1 }}</span>
              <span><strong>{{ line.name }}</strong><small>{{ line.benefitReference || line.sourceId }}</small></span>
              <span class="replacement-quantity">
                <button type="button" :disabled="Number(line.times) <= 1" @click="changeSourceTimes(line, -1)">−</button>
                <b>{{ line.times }}</b>
                <button type="button" :disabled="Number(line.times) >= Number(line.maxTimes || 1)" @click="changeSourceTimes(line, 1)">＋</button>
              </span>
              <strong>{{ line.amount === null || draftDirty ? '待核对' : formatMoney(line.amount) }}</strong>
              <button type="button" class="replacement-remove" :aria-label="`删除${line.name}`" @click="removeSource(line)">×</button>
            </div>
          </div>
        </section>

        <section class="replacement-step" :class="{ 'replacement-step--complete': selectedTargetId, 'replacement-step--invalid': validationAttempted && sourceLines.length && !selectedTargetId }">
          <header class="replacement-step__header">
            <span>3</span>
            <div><h2>选择新项目</h2><p>仅展示后端确认当前门店可用的上架项目，只能选择一个。</p></div>
          </header>
          <p v-if="validationAttempted && sourceLines.length && !selectedTargetId" class="replacement-step__error">请选择一个新项目。</p>
          <label class="replacement-target-search"><span class="sr-only">搜索新项目名称</span><input v-model="targetKeyword" type="search" placeholder="搜索新项目名称" autocomplete="off"></label>
          <div class="replacement-target-grid">
            <button v-for="target in visibleTargets" :key="target.id" type="button" :class="{ active: String(selectedTargetId) === String(target.id) }" @click="chooseTarget(target)">
              <span><strong>{{ target.name }}</strong><small>{{ target.category || '未分类' }}</small></span>
              <i v-if="String(selectedTargetId) === String(target.id)" aria-hidden="true">✓</i>
            </button>
          </div>
          <div v-if="!visibleTargets.length" class="replacement-empty">没有符合条件的新项目。</div>
        </section>
      </main>

      <aside class="replacement-summary-panel">
        <div class="replacement-summary-panel__scroll">
          <header><div><span aria-hidden="true">↔</span><div><h2>替换结果预览</h2><p>最终次数与金额以服务端核对结果为准。</p></div></div></header>
          <section class="replacement-flow">
            <h3>扣减原项目</h3>
            <div v-if="sourceLines.length" class="replacement-flow__sources">
              <div v-for="line in sourceLines" :key="line.key"><span>{{ line.name }}</span><strong>−{{ line.times }} 次</strong></div>
            </div>
            <div v-else class="replacement-flow__empty">{{ sourceEmptyHint }}</div>
            <div class="replacement-flow__arrow" aria-hidden="true">↓</div>
            <h3>生成新项目</h3>
            <div v-if="selectedTarget" class="replacement-flow__target"><span><strong>{{ selectedTarget.name }}</strong><small>新增 {{ summary.targetBenefitCount || 1 }} 次项目权益</small></span><b>{{ displayedAmount === null || displayedAmount === undefined ? '待核对' : formatMoney(displayedAmount) }}</b></div>
            <div v-else class="replacement-flow__empty">{{ targetEmptyHint }}</div>
          </section>

          <section class="replacement-notice"><span aria-hidden="true">i</span><div><strong>本操作不是消费</strong><p>不产生核销订单，不进入消耗统计；只生成一条可追溯的项目替换变动记录。</p></div></section>
          <label class="replacement-remark"><span>替换备注（选填）</span><textarea v-model="remark" maxlength="100" placeholder="例如：顾客护理方案调整" @input="draftDirty = true" /></label>

          <section class="replacement-records-preview">
            <header><button type="button" @click="isRecentExpanded = !isRecentExpanded">近期替换记录（{{ recentRecords.length }}）<span>{{ isRecentExpanded ? '收起' : '展开' }}</span></button><button type="button" @click="isRecordsOpen = true">查看全部</button></header>
            <div v-if="isRecentExpanded">
              <button v-for="record in recentRecords" :key="record.id" type="button" @click="openRecord(record)"><span><strong>{{ record.replacementNo || record.id }}</strong><small>{{ record.operatedAt || '—' }} · → {{ record.targetName || '—' }}</small></span><b>{{ formatMoney(record.amount) }}</b></button>
              <div v-if="!recentRecords.length" class="replacement-empty">暂无近期替换记录。</div>
            </div>
          </section>
        </div>

        <footer><span>扣减 {{ selectedSourceTimes }} 次 · 生成 {{ selectedTargetId ? 1 : 0 }} 个新项目</span><button type="button" @click="openConfirmation">核对项目替换</button></footer>
      </aside>
    </div>

    <Teleport to="body">
      <div v-if="isConfirmationOpen" class="replacement-modal" role="dialog" aria-modal="true" aria-label="确认项目替换">
        <button type="button" class="replacement-modal__backdrop" aria-label="关闭确认窗口" @click="isConfirmationOpen = false" />
        <section class="replacement-modal__card">
          <header><div><span aria-hidden="true">↔</span><div><h2>确认项目替换</h2><p>确认后扣减来源权益，并生成一条新项目权益。</p></div></div><button type="button" aria-label="关闭" @click="isConfirmationOpen = false">×</button></header>
          <div class="replacement-confirm-flow"><div><span>来源卡</span><strong>{{ selectedCard?.name || '—' }}</strong></div><b>→</b><div><span>来源明细</span><strong>{{ sourceLines.length }} 条／{{ selectedSourceTimes }} 次</strong></div><b>→</b><div><span>新项目</span><strong>{{ selectedTarget?.name || '—' }}</strong></div></div>
          <p class="replacement-confirm-warning">本页不计算正式金额；提交前必须由后端返回与当前会员、草稿版本一致的确认令牌。</p>
          <footer><button type="button" @click="isConfirmationOpen = false">返回修改</button><button type="button" disabled title="等待后端确认合同接入">确认项目替换</button></footer>
        </section>
      </div>

      <div v-if="isRecordsOpen" class="replacement-modal" role="dialog" aria-modal="true" aria-label="项目替换记录">
        <button type="button" class="replacement-modal__backdrop" aria-label="关闭记录窗口" @click="isRecordsOpen = false" />
        <section class="replacement-modal__card replacement-modal__card--records">
          <header><div><h2>项目替换记录</h2><p>只展示项目替换变动，不混入销售订单或核销记录。</p></div><button type="button" aria-label="关闭" @click="isRecordsOpen = false">×</button></header>
          <div class="replacement-record-list"><button v-for="record in recentRecords" :key="record.id" type="button" @click="openRecord(record)"><span><strong>{{ record.replacementNo || record.id }}</strong><small>{{ record.operatedAt || '—' }}</small></span><span>{{ record.targetName || '—' }}</span><b>{{ formatMoney(record.amount) }}</b></button><div v-if="!recentRecords.length" class="replacement-empty">当前会员暂无项目替换记录。</div></div>
        </section>
      </div>

      <div v-if="activeRecord" class="replacement-modal" role="dialog" aria-modal="true" aria-label="项目替换记录详情">
        <button type="button" class="replacement-modal__backdrop" aria-label="关闭记录详情" @click="activeRecord = null" />
        <section class="replacement-modal__card replacement-modal__card--detail">
          <header><div><h2>项目替换明细</h2><p>{{ activeRecord.replacementNo || activeRecord.id }}</p></div><button type="button" aria-label="关闭" @click="activeRecord = null">×</button></header>
          <dl><div><dt>操作时间</dt><dd>{{ activeRecord.operatedAt || '—' }}</dd></div><div><dt>目标项目</dt><dd>{{ activeRecord.targetName || '—' }}</dd></div><div><dt>替换金额</dt><dd>{{ formatMoney(activeRecord.amount) }}</dd></div><div><dt>记录状态</dt><dd>{{ activeRecord.status || '有效' }}</dd></div></dl>
          <p>该记录仅表示项目权益替换，不产生核销订单，也不进入普通订单列表。</p>
        </section>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.replacement-page { position:absolute; inset:0; min-width:0; min-height:0; background:#f2f1ef; }
.replacement-page__body { display:grid; grid-template-columns:minmax(620px,1.45fr) minmax(360px,.75fr); gap:12px; height:100%; min-height:0; padding:10px 12px 8px 10px; }
.replacement-workspace,.replacement-summary-panel { min-width:0; min-height:0; overflow:hidden; border:1px solid #e3e8ef; border-radius:10px; background:#fff; }
.replacement-workspace { overflow:auto; padding:14px; }
.replacement-workspace__intro { margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #edf0f4; }
.replacement-selection-prompt { display:flex; align-items:center; gap:8px; margin:0 0 9px; color:#1f2329; font-size:17px; font-weight:700; line-height:24px; }
.replacement-selection-prompt::before { width:4px; height:18px; border-radius:2px; background:#1687ed; content:""; }
.replacement-global-search input,.replacement-target-search input { width:100%; height:38px; padding:0 12px; border:1px solid #d7e0ea; border-radius:8px; outline:0; color:#303133; }
.replacement-global-search input:focus,.replacement-target-search input:focus { border-color:#4096ff; box-shadow:0 0 0 3px rgba(24,144,255,.1); }
.replacement-step { margin-top:12px; overflow:hidden; border:1px solid #dde4ed; border-radius:9px; background:#fff; }
.replacement-step--complete { border-color:#b9d4ff; }
.replacement-step--invalid { border-color:#ffaaa3; box-shadow:0 0 0 2px rgba(207,19,34,.06); }
.replacement-step__header { display:flex; align-items:center; gap:10px; min-height:54px; padding:9px 12px; border-bottom:1px solid #edf0f4; background:#f8fafc; }
.replacement-step__header>span { display:grid; flex:none; width:28px; height:28px; place-items:center; border-radius:50%; background:#3d73db; color:#fff; font-weight:700; }
.replacement-step__header>div { min-width:0; }
.replacement-step__header h2 { margin:0; color:#1f2329; font-size:15px; }
.replacement-step__header p { margin:2px 0 0; color:#7c8798; font-size:12px; }
.replacement-step__header>strong { margin-left:auto; color:#1677cc; font-size:12px; }
.replacement-step__error { margin:0; padding:8px 12px; background:#fff1f0; color:#cf1322; font-size:12px; }
.replacement-card-grid,.replacement-source-grid,.replacement-target-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:8px; padding:11px; }
.replacement-card-grid button,.replacement-source-grid button,.replacement-target-grid button { position:relative; display:flex; align-items:center; gap:9px; min-width:0; min-height:64px; padding:10px; border:1px solid #dce3ec; border-radius:8px; background:#fff; color:#303133; text-align:left; }
.replacement-card-grid button:hover:not(:disabled),.replacement-source-grid button:hover:not(:disabled),.replacement-target-grid button:hover:not(:disabled) { border-color:#91caff; background:#f7fbff; }
.replacement-card-grid button.active,.replacement-target-grid button.active { border-color:#3d73db; background:#f1f7ff; box-shadow:inset 3px 0 0 #3d73db; }
.replacement-card-grid button>span:nth-child(2),.replacement-target-grid button>span { display:grid; min-width:0; gap:3px; }
.replacement-card-grid strong,.replacement-source-grid strong,.replacement-target-grid strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px; }
.replacement-card-grid small,.replacement-source-grid span,.replacement-target-grid small { overflow:hidden; color:#7c8798; font-size:11px; text-overflow:ellipsis; white-space:nowrap; }
.replacement-card-grid i,.replacement-target-grid i { margin-left:auto; color:#1677cc; font-style:normal; font-weight:700; }
.replacement-card-grid__icon { display:grid; flex:none; width:30px; height:30px; place-items:center; border-radius:7px; background:#eaf4ff; color:#1677cc; font-size:12px; }
.replacement-source-grid button { display:grid; align-content:center; gap:4px; }
.replacement-source-grid em { color:#1677cc; font-size:11px; font-style:normal; font-weight:600; }
.replacement-source-grid__meta { display:flex; align-items:center; gap:4px; min-width:0; }
.replacement-source-grid__meta i,.replacement-source-grid__meta em,.replacement-source-grid__meta span { flex:none; color:#7c8798; font-size:11px; font-style:normal; font-weight:400; }
.replacement-source-grid__meta b { flex:none; color:#1677cc; font-size:12px; font-weight:700; }
.replacement-source-table { min-width:640px; margin:0 11px 11px; overflow:hidden; border:1px solid #e4e9f0; border-radius:8px; }
.replacement-source-table__summary { min-height:34px; padding:8px 10px; border-bottom:1px solid #e4e9f0; background:#f4f9ff; color:#526071; font-size:11px; line-height:18px; }
.replacement-source-table>header,.replacement-source-table__row { display:grid; grid-template-columns:42px minmax(160px,1fr) 120px 90px 48px; align-items:center; gap:8px; min-height:38px; padding:0 10px; }
.replacement-source-table>header { background:#f7f9fc; color:#7c8798; font-size:11px; }
.replacement-source-table__row { min-height:52px; border-top:1px solid #edf0f4; color:#526071; font-size:12px; }
.replacement-source-table__row>span:nth-child(2) { display:grid; gap:2px; }
.replacement-source-table__row small { color:#98a2b3; }
.replacement-quantity { display:inline-flex!important; justify-self:start; align-items:center; overflow:hidden; border:1px solid #d7e0ea; border-radius:7px; }
.replacement-quantity button { width:28px; height:28px; border:0; background:#f7f9fc; color:#526071; }
.replacement-quantity b { min-width:34px; text-align:center; }
.replacement-remove { display:grid; width:24px; height:24px; place-items:center; padding:0; border:1px solid #e3e6ea; border-radius:50%; background:#eef1f4; color:#697586; font-size:18px; }
.replacement-target-search { display:block; padding:11px 11px 0; }
.replacement-empty { padding:20px 12px; color:#98a2b3; font-size:12px; text-align:center; }
.replacement-summary-panel { display:grid; grid-template-rows:minmax(0,1fr) auto; }
.replacement-summary-panel__scroll { min-height:0; overflow:auto; padding:14px; }
.replacement-summary-panel__scroll>header>div { display:flex; align-items:center; gap:9px; }
.replacement-summary-panel__scroll>header>div>span { display:grid; width:34px; height:34px; place-items:center; border-radius:8px; background:#eaf4ff; color:#1677cc; font-size:19px; }
.replacement-summary-panel h2 { margin:0; color:#1f2329; font-size:17px; }
.replacement-summary-panel p { margin:3px 0 0; color:#7c8798; font-size:12px; line-height:18px; }
.replacement-flow,.replacement-notice,.replacement-remark,.replacement-records-preview { margin-top:12px; border:1px solid #e3e8ef; border-radius:9px; background:#fff; }
.replacement-flow { padding:12px; }
.replacement-flow h3 { margin:0 0 7px; color:#526071; font-size:12px; }
.replacement-flow__sources { display:grid; gap:6px; }
.replacement-flow__sources>div { display:flex; justify-content:space-between; gap:8px; padding:8px 9px; border-radius:7px; background:#f7f9fc; font-size:12px; }
.replacement-flow__sources strong { color:#cf1322; }
.replacement-flow__empty { padding:16px 10px; border:1px dashed #d7e0ea; border-radius:7px; color:#98a2b3; font-size:12px; text-align:center; }
.replacement-flow__arrow { padding:5px 0; color:#3d73db; text-align:center; }
.replacement-flow__target { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px; border:1px solid #b9d4ff; border-radius:8px; background:#f4f9ff; }
.replacement-flow__target>span { display:grid; gap:3px; }
.replacement-flow__target small { color:#7c8798; font-size:11px; }
.replacement-flow__target>b { color:#1677cc; font-size:15px; }
.replacement-notice { display:flex; gap:9px; padding:11px; border-color:#ffe2a8; background:#fffaf0; }
.replacement-notice>span { display:grid; flex:none; width:22px; height:22px; place-items:center; border-radius:50%; background:#faad14; color:#fff; font-weight:700; }
.replacement-notice strong { color:#8a5a00; font-size:12px; }
.replacement-notice p { color:#8a6d3b; }
.replacement-remark { display:grid; gap:7px; padding:11px; color:#526071; font-size:12px; }
.replacement-remark textarea { min-height:66px; resize:vertical; padding:8px 10px; border:1px solid #d7e0ea; border-radius:7px; outline:0; font:inherit; }
.replacement-records-preview>header { display:flex; justify-content:space-between; min-height:40px; padding:0 10px; border-bottom:1px solid #edf0f4; }
.replacement-records-preview>header button { border:0; background:transparent; color:#526071; font-size:12px; font-weight:600; }
.replacement-records-preview>header button:last-child { color:#1677cc; }
.replacement-records-preview>div>button { display:flex; width:100%; justify-content:space-between; gap:8px; padding:9px 10px; border:0; border-top:1px solid #f0f2f5; background:#fff; text-align:left; }
.replacement-records-preview>div>button>span { display:grid; gap:2px; }
.replacement-records-preview small { color:#98a2b3; font-size:10px; }
.replacement-records-preview b { color:#cf1322; }
.replacement-summary-panel>footer { display:flex; align-items:center; justify-content:space-between; gap:10px; min-height:66px; padding:0 14px; border-top:1px solid #dfe5ed; background:#fff; }
.replacement-summary-panel>footer span { color:#697586; font-size:12px; }
.replacement-summary-panel>footer button { min-width:130px; height:40px; border:1px solid #2f76df; border-radius:8px; background:#3d73db; color:#fff; font-weight:700; }
.replacement-modal { position:fixed; z-index:1500; inset:0; display:grid; place-items:center; padding:24px; }
.replacement-modal__backdrop { position:absolute; inset:0; width:100%; height:100%; border:0; background:rgba(16,24,40,.48); }
.replacement-modal__card { position:relative; z-index:1; width:min(780px,100%); overflow:hidden; border:1px solid #dfe5ef; border-radius:12px; background:#fff; box-shadow:0 28px 80px rgba(16,24,40,.26); }
.replacement-modal__card>header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:18px 20px; border-bottom:1px solid #eaecf0; }
.replacement-modal__card>header>div { display:flex; gap:10px; }
.replacement-modal__card>header h2 { margin:0; font-size:18px; }
.replacement-modal__card>header p { margin:3px 0 0; color:#7c8798; font-size:12px; }
.replacement-modal__card>header>button { width:30px; height:30px; border:0; border-radius:7px; background:#f2f4f7; color:#667085; font-size:20px; }
.replacement-confirm-flow { display:grid; grid-template-columns:1fr auto 1fr auto 1fr; align-items:center; gap:10px; padding:20px; }
.replacement-confirm-flow>div { display:grid; gap:5px; min-height:74px; place-content:center; padding:10px; border:1px solid #e3e8ef; border-radius:8px; text-align:center; }
.replacement-confirm-flow span { color:#98a2b3; font-size:11px; }
.replacement-confirm-warning { margin:0 20px 20px; padding:10px 12px; border-radius:7px; background:#fff7e6; color:#8a5a00!important; }
.replacement-modal__card>footer { display:flex; justify-content:flex-end; gap:8px; padding:12px 20px; border-top:1px solid #eaecf0; }
.replacement-modal__card>footer button { min-width:100px; height:36px; border:1px solid #d7e0ea; border-radius:8px; background:#fff; }
.replacement-modal__card>footer button:last-child { border-color:#3d73db; background:#3d73db; color:#fff; }
.replacement-modal__card>footer button:disabled { opacity:.48; }
.replacement-modal__card--records { width:min(900px,100%); }
.replacement-record-list { display:grid; max-height:520px; overflow:auto; }
.replacement-record-list>button { display:grid; grid-template-columns:1.2fr 1fr 100px; align-items:center; gap:12px; min-height:62px; padding:9px 20px; border:0; border-bottom:1px solid #edf0f4; background:#fff; text-align:left; }
.replacement-record-list>button>span:first-child { display:grid; gap:3px; }
.replacement-record-list small { color:#98a2b3; }
.replacement-record-list b { color:#cf1322; text-align:right; }
.replacement-modal__card--detail { width:min(620px,100%); }
.replacement-modal__card--detail dl { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin:0; padding:20px; }
.replacement-modal__card--detail dl>div { padding:10px; border-radius:8px; background:#f7f9fc; }
.replacement-modal__card--detail dt { color:#98a2b3; font-size:11px; }
.replacement-modal__card--detail dd { margin:4px 0 0; color:#344054; font-weight:600; }
.replacement-modal__card--detail>p { margin:0 20px 20px; padding:10px; border-radius:7px; background:#fffaf0; color:#8a6d3b; }
@media (max-width:1100px) {
  .replacement-page__body { grid-template-columns:minmax(0,1fr) 292px; gap:8px; padding:8px; }
  .replacement-workspace,.replacement-summary-panel__scroll { padding:10px; }
  .replacement-card-grid,.replacement-source-grid,.replacement-target-grid { grid-template-columns:repeat(auto-fill,minmax(148px,1fr)); padding:9px; }
  .replacement-source-table { min-width:0; margin:0 9px 9px; }
  .replacement-source-table>header,.replacement-source-table__row { grid-template-columns:34px minmax(118px,1fr) 104px 76px 34px; gap:5px; padding:0 7px; }
  .replacement-summary-panel>footer { align-items:stretch; flex-direction:column; min-height:86px; padding:9px 10px; }
  .replacement-summary-panel>footer button { width:100%; min-width:0; }
  .replacement-confirm-flow { grid-template-columns:minmax(0,1fr); }
  .replacement-confirm-flow>b { transform:rotate(90deg); text-align:center; }
}
</style>
