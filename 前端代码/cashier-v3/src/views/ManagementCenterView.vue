<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { requestCashierV3Action, useCashierV3State } from '@/services/cashierV3Bridge'

const state = useCashierV3State()
const router = useRouter()
const managementCenter = computed(() => state.managementCenter || {})
const entries = computed(() => {
  const source = Array.isArray(managementCenter.value.entries) ? managementCenter.value.entries : []
  const local = {
    id: 'staff-list-v3',
    name: '员工列表',
    description: '查询当前门店员工并设置销售人、手艺人资格。',
    category: '人员管理',
    mode: 'v3',
    status: 'available',
    allowed: true
  }
  const roomSettings = {
    id: 'room-settings-v3',
    name: '房间设置',
    description: '维护当前门店的房间基础资料、启停和排序。',
    category: '门店设置',
    mode: 'v3',
    status: 'available',
    allowed: true
  }
  const businessDashboard = {
    id: 'business-dashboard-v3',
    name: '运营概况',
    description: '查看当前门店的经营指标、趋势、排行和明细。',
    category: '经营管理',
    mode: 'v3',
    status: 'available',
    allowed: true
  }
  const additions = [local, roomSettings, businessDashboard].filter((entry) => !source.some((current) => current.id === entry.id))
  return [...source, ...additions]
})
const groupedEntries = computed(() => {
  const groups = new Map()
  entries.value.forEach((entry) => {
    const groupName = entry.categoryName || entry.category || '管理功能'
    if (!groups.has(groupName)) groups.set(groupName, [])
    groups.get(groupName).push(entry)
  })
  return Array.from(groups, ([name, items]) => ({ name, items }))
})

function entryModeLabel(entry) {
  if (entry.mode === 'legacy_bridge') return '原功能'
  return entry.modeLabel || ''
}

function entryStatusLabel(entry) {
  if (entry.status === 'coming_soon') return '暂未开放'
  if (entry.status === 'disabled') return '当前不可用'
  return ''
}

function canOpenEntry(entry) {
  return entry.allowed !== false && !['coming_soon', 'disabled'].includes(entry.status)
}

async function openEntry(entry) {
  if (!canOpenEntry(entry)) return
  if (entry.id === 'staff-list-v3') {
    await router.push({ name: 'cashier-v3-staff-list' })
    return
  }
  if (entry.id === 'room-settings-v3') {
    await router.push({ name: 'cashier-v3-room-settings' })
    return
  }
  if (entry.id === 'business-dashboard-v3') {
    await router.push({ name: 'cashier-v3-business-dashboard' })
    return
  }
  await requestCashierV3Action('open-management-entry', {
    entryId: entry.id
  })
}
</script>

<template>
  <section class="management-center-page" aria-label="管理中心">
    <header class="management-center-page__header">
      <h2>管理中心</h2>
      <span class="management-center-page__scope">当前门店：{{ state.storeName }}</span>
    </header>

    <div v-if="groupedEntries.length" class="management-center-groups">
      <section v-for="group in groupedEntries" :key="group.name" class="management-center-group">
        <h3>{{ group.name }}</h3>
        <div class="management-center-entry-grid">
          <button
            v-for="entry in group.items"
            :key="entry.id"
            type="button"
            class="management-center-entry"
            :class="{
              'management-center-entry--legacy': entry.mode === 'legacy_bridge',
              'management-center-entry--disabled': !canOpenEntry(entry)
            }"
            :disabled="!canOpenEntry(entry)"
            @click="openEntry(entry)"
          >
            <span v-if="entryModeLabel(entry)" class="management-center-entry__badge">{{ entryModeLabel(entry) }}</span>
            <strong>{{ entry.name }}</strong>
            <span v-if="entry.description">{{ entry.description }}</span>
            <em v-if="entryStatusLabel(entry)">{{ entryStatusLabel(entry) }}</em>
          </button>
        </div>
      </section>
    </div>

    <section v-else class="management-center-empty">
      <strong>暂无可用管理功能</strong>
    </section>
  </section>
</template>

<style scoped>
.management-center-page {
  display: grid;
  min-width: 0;
  min-height: 0;
  align-content: start;
  gap: 14px;
  padding: 20px;
  overflow: auto;
  background: #f5f7fa;
}

.management-center-page__header,
.management-center-rule-card,
.management-center-group,
.management-center-empty {
  border: 1px solid #dde4ed;
  border-radius: 12px;
  background: #fff;
}

.management-center-page__header {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 18px;
  padding: 20px;
}

.management-center-page__header h2,
.management-center-group h3,
.management-center-empty strong {
  margin: 0;
  color: #1f2329;
}

.management-center-page__header h2 {
  font-size: 20px;
}

.management-center-page__header p {
  max-width: 720px;
  margin: 7px 0 0;
  color: #697586;
  font-size: 13px;
  line-height: 1.65;
}

.management-center-page__scope {
  flex: none;
  padding: 7px 10px;
  border-radius: 8px;
  background: #f5f8fc;
  color: #526071;
  font-size: 13px;
}

.management-center-rule-card {
  display: grid;
  gap: 6px;
  padding: 15px 18px;
  border-color: #b9d4ff;
  background: #f4f9ff;
}

.management-center-rule-card strong {
  color: #1677cc;
  font-size: 14px;
}

.management-center-rule-card span {
  color: #526071;
  font-size: 13px;
  line-height: 1.65;
}

.management-center-groups {
  display: grid;
  gap: 14px;
}

.management-center-group {
  padding: 18px;
}

.management-center-group h3 {
  font-size: 16px;
}

.management-center-entry-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
  margin-top: 14px;
}

.management-center-entry {
  display: grid;
  min-height: 132px;
  align-content: start;
  gap: 7px;
  padding: 16px;
  border: 1px solid #dbe4ef;
  border-radius: 10px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}

.management-center-entry:hover:not(:disabled) {
  border-color: #91caff;
  box-shadow: 0 7px 18px rgba(24, 119, 204, .09);
  transform: translateY(-1px);
}

.management-center-entry__badge {
  justify-self: start;
  padding: 3px 7px;
  border-radius: 999px;
  background: #eaf4ff;
  color: #1677cc;
  font-size: 11px;
  font-weight: 600;
}

.management-center-entry--legacy .management-center-entry__badge {
  background: #f7f3eb;
  color: #9a6a19;
}

.management-center-entry strong {
  color: #303133;
  font-size: 15px;
}

.management-center-entry > span:not(.management-center-entry__badge),
.management-center-entry em {
  color: #697586;
  font-size: 12px;
  font-style: normal;
  line-height: 1.55;
}

.management-center-entry em {
  color: #d48806;
}

.management-center-entry--disabled {
  cursor: not-allowed;
  opacity: .56;
}

.management-center-empty {
  display: grid;
  min-height: 260px;
  align-content: center;
  justify-items: center;
  gap: 9px;
  padding: 22px;
  text-align: center;
}

.management-center-empty strong {
  font-size: 17px;
}

.management-center-empty span {
  max-width: 480px;
  color: #697586;
  font-size: 13px;
  line-height: 1.65;
}

@media (max-width: 780px) {
  .management-center-page {
    padding: 12px;
  }

  .management-center-page__header {
    display: grid;
  }
}
</style>
