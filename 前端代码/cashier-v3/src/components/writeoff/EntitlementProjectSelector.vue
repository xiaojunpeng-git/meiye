<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  member: {
    type: Object,
    default: null
  },
  sources: {
    type: Array,
    default: () => []
  },
  title: {
    type: String,
    default: '选择卡内项目'
  },
  description: {
    type: String,
    default: '同名项目按具体权益来源分别选择。'
  },
  busy: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'focus-source',
  'toggle-project',
  'change-times',
  'clear'
])

const keyword = ref('')
const sourceFilter = ref('valid')

function isSourceAvailable(source = {}) {
  const status = String(source.statusCode || source.status || '').trim().toLowerCase()
  return source.disabled !== true
    && source.selectable !== false
    && (!status || ['可用', 'available', 'valid'].includes(status))
}

function isProjectSelected(project = {}) {
  return project.selected === true && Number(project.selectedTimes || project.quantity || 0) > 0
}

function sourceSelectedProjects(source = {}) {
  return (Array.isArray(source.projects) ? source.projects : []).filter(isProjectSelected)
}

function sourceSelectedTimes(source = {}) {
  return sourceSelectedProjects(source).reduce((total, project) => (
    total + Number(project.selectedTimes || project.quantity || 0)
  ), 0)
}

function sourceAvailableProjectCount(source = {}) {
  if (source.availableProjectCount !== undefined) return Number(source.availableProjectCount || 0)
  return (Array.isArray(source.projects) ? source.projects : []).filter((project) => (
    project.disabled !== true && project.selectable !== false && Number(project.availableTimes || 0) > 0
  )).length
}

function sourceRemainingTimes(source = {}) {
  if (source.remainingTimes !== undefined) return source.remainingTimes
  return (Array.isArray(source.projects) ? source.projects : []).reduce((total, project) => (
    total + Number(project.remainingTimes || 0)
  ), 0)
}

function sourceOccupiedTimes(source = {}) {
  if (source.occupiedTimes !== undefined) return Number(source.occupiedTimes || 0)
  return (Array.isArray(source.projects) ? source.projects : []).reduce((total, project) => (
    total + Number(project.occupiedTimes ?? project.reservedTimes ?? 0)
  ), 0)
}

function sourceAvailableTimes(source = {}) {
  if (source.availableTimes !== undefined) return Number(source.availableTimes || 0)
  return (Array.isArray(source.projects) ? source.projects : []).reduce((total, project) => (
    total + Number(project.availableTimes || 0)
  ), 0)
}

const sourceFilters = computed(() => [
  { key: 'valid', label: '有效卡项', count: props.sources.filter(isSourceAvailable).length },
  { key: 'expiring', label: '即将到期', count: props.sources.filter((source) => source.expiring === true).length },
  { key: 'selected', label: '只看已选', count: props.sources.filter((source) => sourceSelectedProjects(source).length > 0).length },
  { key: 'all', label: '全部', count: props.sources.length }
])

const visibleSources = computed(() => {
  const normalizedKeyword = keyword.value.trim().toLocaleLowerCase()
  return props.sources.filter((source) => {
    const searchable = `${source.name || ''} ${source.reference || source.cardNo || ''} ${(source.projects || []).map((project) => project.name).join(' ')}`
      .toLocaleLowerCase()
    const selectedMatched = sourceFilter.value !== 'selected' || sourceSelectedProjects(source).length > 0
    const statusMatched = sourceFilter.value === 'all'
      || sourceFilter.value === 'selected'
      || (sourceFilter.value === 'expiring' ? source.expiring === true : isSourceAvailable(source))
    return selectedMatched && statusMatched && (!normalizedKeyword || searchable.includes(normalizedKeyword))
  })
})

const selectedSources = computed(() => props.sources.filter((source) => sourceSelectedProjects(source).length > 0))
const selectedProjectCount = computed(() => props.sources.reduce((total, source) => (
  total + sourceSelectedProjects(source).length
), 0))
const selectedTimes = computed(() => props.sources.reduce((total, source) => (
  total + sourceSelectedTimes(source)
), 0))
const hasSelectedProject = computed(() => selectedProjectCount.value > 0)

function sourceTypeLabel(source = {}) {
  const type = String(source.sourceType || source.entitlementInstanceType || '')
  if (type === 'card' || type === 'card_project') return '单卡项目'
  if (type === 'times_card' || type === 'times_card_project') return '次数卡'
  if (type === 'independent_gift') return '独立赠送'
  if (type === 'order_attached_gift' || type === 'batch_gift') return '随单赠送'
  return source.sourceTypeLabel || '权益项目'
}

function sourceReference(source = {}) {
  return source.reference || source.cardNo || source.fullCardNo || source.sourceNo || ''
}

function sourceExpiryText(source = {}) {
  return source.expiryText || source.expiresAt || source.expireAt || '长期有效'
}

function sourceSummary(source = {}) {
  const remaining = sourceRemainingTimes(source)
  const occupied = sourceOccupiedTimes(source)
  const available = sourceAvailableTimes(source)
  return `可用项目 ${sourceAvailableProjectCount(source)} · 剩余 ${remaining === '不限' ? '不限' : remaining} · 占用 ${occupied} · 当前可用 ${available}`
}

function projectOccupiedTimes(project = {}) {
  return Number(project.occupiedTimes ?? project.reservedTimes ?? 0)
}

function projectAvailableTimes(project = {}) {
  return Number(project.availableTimes ?? Math.max(0, Number(project.remainingTimes || 0) - projectOccupiedTimes(project)))
}

function projectUnavailableReason(project = {}) {
  return project.disabledReason || project.unavailableReason || ''
}

function toggleProject(source, project) {
  if (props.busy || !isSourceAvailable(source) || project.disabled === true || project.selectable === false) return
  emit('toggle-project', { source, project })
}

function changeTimes(source, project, delta) {
  if (props.busy || !isProjectSelected(project)) return
  emit('change-times', { source, project, delta })
}

</script>

<template>
  <div class="entitlement-selector">
    <div class="writeoff-workbench__body entitlement-selector__body">
      <aside class="writeoff-sources-panel" aria-label="会员权益来源">
        <div class="writeoff-source-tools">
          <label class="search-field">
            <span class="sr-only">请选择客户需要服务的项目</span>
            <input v-model="keyword" type="search" placeholder="请选择客户需要服务的项目" autocomplete="off">
          </label>
          <div class="writeoff-source-filters" role="group" aria-label="权益范围">
            <button
              v-for="filter in sourceFilters"
              :key="filter.key"
              type="button"
              :class="{ 'writeoff-source-filter--active': sourceFilter === filter.key }"
              @click="sourceFilter = filter.key"
            >{{ filter.label }}<span>{{ filter.count }}</span></button>
          </div>
        </div>

        <div class="writeoff-source-list">
          <button
            v-for="source in visibleSources"
            :key="source.id || source.entitlementInstanceId"
            type="button"
            class="writeoff-source-card"
            :class="{
              'writeoff-source-card--selected': sourceSelectedProjects(source).length > 0,
              'writeoff-source-card--disabled': !isSourceAvailable(source)
            }"
            :disabled="!isSourceAvailable(source)"
            @click="emit('focus-source', { source })"
          >
            <span class="writeoff-source-card__header">
              <span>
                <em>{{ sourceTypeLabel(source) }}</em>
                <strong :title="source.name">{{ source.name }}</strong>
              </span>
              <span>{{ isSourceAvailable(source) ? '可用' : (source.statusLabel || source.status || '不可用') }}</span>
            </span>
            <span class="writeoff-source-card__reference" :title="sourceReference(source)">{{ sourceReference(source) }}</span>
            <span class="writeoff-source-card__summary">{{ sourceSummary(source) }}</span>
            <span class="writeoff-source-card__expiry" :class="{ 'writeoff-source-card__expiry--warning': source.expiring }">{{ sourceExpiryText(source) }}</span>
            <span v-if="source.debtRestrictionLabel || source.debtRestrictionReason" class="writeoff-source-card__reason">
              {{ source.debtRestrictionLabel || source.debtRestrictionReason }}
            </span>
            <span v-if="source.disabledReason" class="writeoff-source-card__reason">{{ source.disabledReason }}</span>
          </button>

          <div v-if="!visibleSources.length" class="writeoff-source-empty">暂无符合条件的权益来源。</div>
        </div>
      </aside>

      <main class="writeoff-projects-panel" aria-label="权益项目">
        <div class="writeoff-projects-panel__header">
          <div>
            <h2>{{ title }}</h2>
            <p>{{ description }}</p>
          </div>
          <button type="button" class="button button--text" :disabled="!hasSelectedProject || busy" @click="emit('clear')">清空已选</button>
        </div>

        <div v-if="member" class="writeoff-source-groups">
          <section v-for="source in visibleSources" :key="source.id || source.entitlementInstanceId" class="writeoff-source-group">
            <header class="writeoff-source-group__header" :class="{ 'writeoff-source-group__header--selected': sourceSelectedProjects(source).length > 0 }">
              <div>
                <strong :title="source.name">{{ source.name }}</strong>
                <span :title="sourceReference(source)">{{ sourceReference(source) }}</span>
              </div>
              <span>已选 {{ sourceSelectedProjects(source).length }} 项 / {{ sourceSelectedTimes(source) }} 次</span>
            </header>

            <div class="writeoff-project-list entitlement-project-list">
              <div class="writeoff-project-list__header" aria-hidden="true">
                <span />
                <span>项目</span>
                <span>剩余／占用／可用</span>
                <span>本次次数</span>
              </div>
              <article
                v-for="project in source.projects || []"
                :key="project.id || project.projectId"
                class="writeoff-project-row"
                :class="{
                  'writeoff-project-row--selected': isProjectSelected(project),
                  'writeoff-project-row--disabled': project.disabled || project.selectable === false
                }"
              >
                <button
                  type="button"
                  class="writeoff-project-row__check"
                  :class="{ 'writeoff-project-row__check--selected': isProjectSelected(project) }"
                  :disabled="busy || !isSourceAvailable(source) || project.disabled || project.selectable === false"
                  :aria-label="`${isProjectSelected(project) ? '取消选择' : '选择'} ${project.name}`"
                  @click="toggleProject(source, project)"
                >{{ isProjectSelected(project) ? '✓' : '' }}</button>
                <div class="writeoff-project-row__info">
                  <strong>{{ project.name }}</strong>
                  <span v-if="projectUnavailableReason(project)" class="writeoff-project-row__reason">{{ projectUnavailableReason(project) }}</span>
                  <span v-else>{{ project.sourceTypeLabel || sourceTypeLabel(source) }}<template v-if="project.validThroughLabel"> · {{ project.validThroughLabel }}</template></span>
                </div>
                <span class="writeoff-project-row__availability">
                  <strong>{{ project.remainingTimes || 0 }}</strong>
                  <span>／{{ projectOccupiedTimes(project) }}／{{ projectAvailableTimes(project) }}</span>
                </span>
                <div class="quantity-stepper" aria-label="本次使用次数">
                  <button type="button" aria-label="减少本次使用次数" :disabled="busy || !isProjectSelected(project)" @click="changeTimes(source, project, -1)">−</button>
                  <span>{{ project.selectedTimes || 0 }}次</span>
                  <button type="button" aria-label="增加本次使用次数" :disabled="busy || !isProjectSelected(project) || Number(project.selectedTimes || 0) >= projectAvailableTimes(project)" @click="changeTimes(source, project, 1)">＋</button>
                </div>
              </article>
            </div>
          </section>
        </div>

        <div v-else class="writeoff-project-empty">
          <strong>请先选择会员</strong>
          <span>选择会员后再读取其可用权益项目。</span>
        </div>
      </main>
    </div>

    <footer class="writeoff-bottom-bar entitlement-selector__footer">
      <div class="writeoff-bottom-bar__summary">
        <span>已选来源<strong>{{ selectedSources.length }}</strong></span>
        <span>项目<strong>{{ selectedProjectCount }}</strong></span>
        <span>本次次数<strong>{{ selectedTimes }}次</strong></span>
      </div>
      <div class="writeoff-bottom-bar__actions">
        <slot name="actions" :has-selected-project="hasSelectedProject" :selected-project-count="selectedProjectCount" :selected-times="selectedTimes" />
      </div>
    </footer>
  </div>
</template>
