<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  createServiceName,
  createRoomSetting,
  queryServiceNames,
  queryRoomSettings,
  setServiceNameEnabled,
  setServiceNameSwitch,
  setRoomSettingEnabled,
  sortServiceNames,
  sortRoomSettings,
  updateServiceName,
  updateRoomSetting
} from '@/services/roomManagementApi'

const router = useRouter()
const props = defineProps({ embedded: { type: Boolean, default: false } })
const activeTab = ref('rooms')
const keyword = ref('')
const status = ref('')
const records = ref([])
const total = ref(0)
const serviceKeyword = ref('')
const serviceStatus = ref('')
const serviceRecords = ref([])
const serviceTotal = ref(0)
const serviceSwitch = ref({ enabled: true, version: 0 })
const serviceLoaded = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editorOpen = ref(false)
const editorKind = ref('room')
const editorId = ref(0)
const editorVersion = ref(0)
const editorName = ref('')
const canSort = computed(() => !keyword.value.trim() && status.value === '')
const canSortServices = computed(() => !serviceKeyword.value.trim() && serviceStatus.value === '')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await queryRoomSettings({ keyword: keyword.value.trim(), status: status.value, page: 1, limit: 100 })
    records.value = Array.isArray(result.records) ? result.records : []
    total.value = Number(result.total) || records.value.length
  } catch (requestError) {
    error.value = requestError?.message || '房间列表加载失败。'
  } finally {
    loading.value = false
  }
}

async function loadServiceNames() {
  loading.value = true
  error.value = ''
  try {
    const result = await queryServiceNames({ keyword: serviceKeyword.value.trim(), status: serviceStatus.value, page: 1, limit: 100 })
    serviceRecords.value = Array.isArray(result.records) ? result.records : []
    serviceTotal.value = Number(result.total) || serviceRecords.value.length
    serviceSwitch.value = {
      enabled: result.switch?.enabled !== false,
      version: Number(result.switch?.version) || 0
    }
    serviceLoaded.value = true
  } catch (requestError) {
    error.value = requestError?.message || '服务名称列表加载失败。'
  } finally {
    loading.value = false
  }
}

// 页签切换只加载服务名称自己的数据源，避免把独立资料混入既有房间模型。
async function switchTab(tab) {
  activeTab.value = tab
  error.value = ''
  if (tab === 'services' && !serviceLoaded.value) await loadServiceNames()
}

function openCreate(kind = 'room') {
  editorKind.value = kind
  editorId.value = 0
  editorVersion.value = 0
  editorName.value = ''
  error.value = ''
  editorOpen.value = true
}

function openEdit(room) {
  editorKind.value = 'room'
  editorId.value = Number(room.id) || 0
  editorVersion.value = 0
  editorName.value = room.name || ''
  error.value = ''
  editorOpen.value = true
}

function openServiceEdit(service) {
  editorKind.value = 'service'
  editorId.value = Number(service.id) || 0
  editorVersion.value = Number(service.version) || 0
  editorName.value = service.name || ''
  error.value = ''
  editorOpen.value = true
}

async function saveEditor() {
  const name = editorName.value.trim()
  if (!name) {
    error.value = editorKind.value === 'service' ? '请输入服务名称。' : '请输入房间名称。'
    return
  }
  saving.value = true
  error.value = ''
  try {
    if (editorKind.value === 'service') {
      if (editorId.value > 0) await updateServiceName(editorId.value, name, editorVersion.value)
      else await createServiceName(name)
    } else if (editorId.value > 0) await updateRoomSetting(editorId.value, name)
    else await createRoomSetting(name)
    editorOpen.value = false
    if (editorKind.value === 'service') await loadServiceNames()
    else await load()
  } catch (requestError) {
    error.value = requestError?.message || (editorKind.value === 'service' ? '服务名称未能保存。' : '房间资料未能保存。')
  } finally {
    saving.value = false
  }
}

async function toggle(room) {
  if (room.enabled && !room.canDisable) {
    error.value = room.disableReason || '该房间当前不能停用。'
    return
  }
  saving.value = true
  error.value = ''
  try {
    await setRoomSettingEnabled(room.id, !room.enabled)
    await load()
  } catch (requestError) {
    error.value = requestError?.message || '房间状态未能更新。'
  } finally {
    saving.value = false
  }
}

async function move(index, direction) {
  if (!canSort.value || saving.value) return
  const target = index + direction
  if (target < 0 || target >= records.value.length) return
  const next = [...records.value]
  ;[next[index], next[target]] = [next[target], next[index]]
  saving.value = true
  error.value = ''
  try {
    await sortRoomSettings(next.map((item) => item.id))
    await load()
  } catch (requestError) {
    error.value = requestError?.message || '房间排序未能保存。'
  } finally {
    saving.value = false
  }
}

async function toggleService(service) {
  saving.value = true
  error.value = ''
  try {
    await setServiceNameEnabled(service.id, !service.enabled, service.version)
    await loadServiceNames()
  } catch (requestError) {
    error.value = requestError?.message || '服务名称状态未能更新。'
  } finally {
    saving.value = false
  }
}

// 总开关只保存当前门店的显示状态，任何业务模块都不会读取或执行该值。
async function toggleServiceSwitch() {
  saving.value = true
  error.value = ''
  try {
    const result = await setServiceNameSwitch(!serviceSwitch.value.enabled, serviceSwitch.value.version)
    serviceSwitch.value = {
      enabled: result.enabled !== false,
      version: Number(result.version) || 0
    }
  } catch (requestError) {
    error.value = requestError?.message || '服务开关未能保存。'
  } finally {
    saving.value = false
  }
}

// 仅在完整未筛选列表中允许排序，并携带每行版本防止覆盖其他人的修改。
async function moveService(index, direction) {
  if (!canSortServices.value) return
  const target = index + direction
  if (target < 0 || target >= serviceRecords.value.length) return
  const next = serviceRecords.value.slice()
  ;[next[index], next[target]] = [next[target], next[index]]
  saving.value = true
  error.value = ''
  try {
    await sortServiceNames(next.map((item) => ({ id: item.id, expected_version: item.version })))
    serviceRecords.value = next
    await loadServiceNames()
  } catch (requestError) {
    error.value = requestError?.message || '服务名称排序未能保存。'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="room-settings-page" aria-label="房间设置">
    <header class="room-settings-page__header">
      <div>
        <nav class="room-settings-tabs" aria-label="设置类别">
          <button type="button" :class="{ 'room-settings-tabs__item--active': activeTab === 'rooms' }" @click="switchTab('rooms')">房间设置</button>
          <button type="button" :class="{ 'room-settings-tabs__item--active': activeTab === 'services' }" @click="switchTab('services')">服务名称</button>
        </nav>
        <span>{{ activeTab === 'rooms' ? '维护当前门店的房间基础资料' : '维护当前门店独立保存的定制服务名称' }}</span>
      </div>
      <div class="room-settings-page__actions">
        <button v-if="!props.embedded" type="button" class="button button--secondary" @click="router.push({ name: 'cashier-v3-management-center' })">返回管理</button>
      </div>
    </header>

    <div v-if="activeTab === 'rooms'" class="room-settings-filters">
      <label>
        <span>搜索</span>
        <input v-model="keyword" type="search" placeholder="房间名称或排序号" @keyup.enter="load">
      </label>
      <label>
        <span>状态</span>
        <select v-model="status" @change="load">
          <option value="">全部</option>
          <option value="1">已启用</option>
          <option value="0">已停用</option>
        </select>
      </label>
      <button type="button" class="button button--secondary" :disabled="loading" @click="load">查询</button>
      <button type="button" class="button button--primary" :disabled="loading" @click="openCreate('room')">新增房间</button>
    </div>

    <div v-else class="room-settings-filters">
      <label>
        <span>搜索</span>
        <input v-model="serviceKeyword" type="search" placeholder="请输入服务名称" @keyup.enter="loadServiceNames">
      </label>
      <label>
        <span>状态</span>
        <select v-model="serviceStatus" @change="loadServiceNames">
          <option value="">全部</option>
          <option value="1">已启用</option>
          <option value="0">已停用</option>
        </select>
      </label>
      <button type="button" class="button button--secondary" :disabled="loading" @click="loadServiceNames">查询</button>
      <button type="button" class="button button--primary" :disabled="loading" @click="openCreate('service')">新增服务</button>
      <button
        type="button"
        :class="['button', serviceSwitch.enabled ? 'button--secondary' : 'button--primary']"
        :disabled="loading || saving"
        :aria-pressed="serviceSwitch.enabled"
        @click="toggleServiceSwitch"
      >服务开关：{{ serviceSwitch.enabled ? '开' : '关' }}</button>
    </div>

    <p v-if="error" class="room-settings-error" role="alert">{{ error }}</p>

    <div v-if="activeTab === 'rooms'" class="room-settings-table-wrap">
      <table class="room-settings-table">
        <thead>
          <tr>
            <th>排序</th>
            <th>房间名称</th>
            <th>状态</th>
            <th>创建时间</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody v-if="records.length">
          <tr v-for="(room, index) in records" :key="room.id">
            <td>
              <div class="room-settings-sort">
                <span>{{ room.sort }}</span>
                <button type="button" aria-label="上移" :disabled="!canSort || saving || index === 0" @click="move(index, -1)">↑</button>
                <button type="button" aria-label="下移" :disabled="!canSort || saving || index === records.length - 1" @click="move(index, 1)">↓</button>
              </div>
            </td>
            <td><strong>{{ room.name }}</strong></td>
            <td><span class="room-settings-status" :class="{ 'room-settings-status--off': !room.enabled }">{{ room.enabled ? '已启用' : '已停用' }}</span></td>
            <td>{{ room.createdAt || '—' }}</td>
            <td>
              <div class="room-settings-row-actions">
                <button type="button" class="button button--text" :disabled="saving" @click="openEdit(room)">编辑</button>
                <button type="button" class="button button--text" :disabled="saving || (room.enabled && !room.canDisable)" :title="room.enabled && !room.canDisable ? room.disableReason : ''" @click="toggle(room)">{{ room.enabled ? '停用' : '启用' }}</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="loading" class="room-settings-empty">正在加载房间…</div>
      <div v-else-if="!records.length" class="room-settings-empty">暂无房间</div>
    </div>
    <div v-else class="room-settings-table-wrap">
      <table class="room-settings-table room-settings-table--service">
        <thead>
          <tr>
            <th>排序</th>
            <th>服务名称</th>
            <th>状态</th>
            <th>创建时间</th>
            <th>更新时间</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody v-if="serviceRecords.length">
          <tr v-for="service in serviceRecords" :key="service.id">
            <td class="room-settings-sort">
              <span>{{ service.sort }}</span>
              <button type="button" :disabled="saving || !canSortServices || service === serviceRecords[0]" @click="moveService(serviceRecords.indexOf(service), -1)">↑</button>
              <button type="button" :disabled="saving || !canSortServices || service === serviceRecords[serviceRecords.length - 1]" @click="moveService(serviceRecords.indexOf(service), 1)">↓</button>
            </td>
            <td><strong>{{ service.name }}</strong></td>
            <td><span :class="['room-settings-status', { 'room-settings-status--off': !service.enabled }]">{{ service.enabled ? '已启用' : '已停用' }}</span></td>
            <td>{{ service.createdAt || '—' }}</td>
            <td>{{ service.updatedAt || '—' }}</td>
            <td>
              <button type="button" class="button button--text" :disabled="saving" @click="openServiceEdit(service)">编辑</button>
              <button type="button" class="button button--text" :disabled="saving" @click="toggleService(service)">{{ service.enabled ? '停用' : '启用' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="loading" class="room-settings-empty">正在加载服务名称…</div>
      <div v-else-if="!serviceRecords.length" class="room-settings-empty">暂无服务名称</div>
    </div>
    <footer class="room-settings-page__footer">{{ activeTab === 'rooms' ? `共 ${total} 个房间` : `共 ${serviceTotal} 个服务名称` }}</footer>

    <div v-if="editorOpen" class="room-settings-modal" role="dialog" aria-modal="true" :aria-label="editorKind === 'service' ? '服务名称' : '房间资料'">
      <form class="room-settings-modal__content" @submit.prevent="saveEditor">
        <header><h3>{{ editorKind === 'service' ? (editorId ? '编辑服务名称' : '新增服务') : (editorId ? '编辑房间' : '新增房间') }}</h3></header>
        <label>
          <span>{{ editorKind === 'service' ? '服务名称' : '房间名称' }}</span>
          <input v-model="editorName" maxlength="64" autofocus :placeholder="editorKind === 'service' ? '请输入服务名称' : '请输入房间名称'">
        </label>
        <footer>
          <button type="button" class="button button--secondary" :disabled="saving" @click="editorOpen = false">取消</button>
          <button type="submit" class="button button--primary" :disabled="saving">保存</button>
        </footer>
      </form>
    </div>
  </section>
</template>

<style scoped>
.room-settings-page { display: grid; align-content: start; gap: 14px; min-width: 0; min-height: 0; padding: 20px; overflow: auto; background: #f5f7fa; }
.room-settings-page__header, .room-settings-filters, .room-settings-table-wrap { border: 1px solid #dde4ed; border-radius: 8px; background: #fff; }
.room-settings-page__header { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 18px 20px; }
.room-settings-page h2, .room-settings-page h3 { margin: 0; color: #1f2329; font-size: 20px; }
.room-settings-page__header span { display: block; margin-top: 6px; color: #697586; font-size: 13px; }
.room-settings-page__actions, .room-settings-row-actions, .room-settings-sort, .room-settings-modal footer { display: flex; align-items: center; gap: 8px; }
.room-settings-tabs { display: flex; align-items: center; gap: 24px; }
.room-settings-tabs__item { appearance: none; }
.room-settings-tabs button { position: relative; border: 0; padding: 0 0 8px; color: #697586; background: transparent; font-size: 20px; font-weight: 600; cursor: pointer; }
.room-settings-tabs button::after { position: absolute; right: 0; bottom: 0; left: 0; height: 2px; border-radius: 2px; background: transparent; content: ''; }
.room-settings-tabs button.room-settings-tabs__item--active { color: #1f2329; }
.room-settings-tabs button.room-settings-tabs__item--active::after { background: #1677ff; }
.room-settings-filters { display: flex; align-items: end; gap: 12px; padding: 14px 16px; }
.room-settings-filters label, .room-settings-modal label { display: grid; gap: 6px; color: #526071; font-size: 13px; }
.room-settings-filters input, .room-settings-filters select, .room-settings-modal input { min-height: 34px; border: 1px solid #cfd8e3; border-radius: 6px; padding: 0 10px; color: #1f2329; background: #fff; }
.room-settings-error { margin: 0; padding: 10px 12px; border: 1px solid #ffd6d2; border-radius: 6px; color: #c42b1c; background: #fff4f2; font-size: 13px; }
.room-settings-table-wrap { overflow: hidden; }
.room-settings-table { width: 100%; border-collapse: collapse; }
.room-settings-table th, .room-settings-table td { padding: 12px 14px; border-bottom: 1px solid #edf1f5; text-align: left; color: #465466; font-size: 13px; vertical-align: middle; }
.room-settings-table th { color: #697586; font-weight: 600; background: #fafbfd; }
.room-settings-table strong { color: #1f2329; }
.room-settings-table--service th:nth-child(2) { width: 28%; }
.room-settings-sort button { width: 26px; height: 26px; border: 1px solid #d6dfe9; border-radius: 4px; color: #526071; background: #fff; cursor: pointer; }
.room-settings-sort button:disabled { cursor: not-allowed; opacity: .45; }
.room-settings-status { display: inline-block; padding: 3px 8px; border-radius: 4px; color: #237804; background: #f6ffed; }
.room-settings-status--off { color: #8c8c8c; background: #f5f5f5; }
.room-settings-empty { padding: 50px 20px; color: #697586; text-align: center; font-size: 13px; }
.room-settings-page__footer { color: #697586; font-size: 13px; }
.room-settings-modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 18px; background: rgba(31, 35, 41, .42); }
.room-settings-modal__content { display: grid; width: min(100%, 420px); gap: 18px; padding: 20px; border-radius: 8px; background: #fff; box-shadow: 0 16px 40px rgba(0, 0, 0, .18); }
.room-settings-modal footer { justify-content: flex-end; }
@media (max-width: 680px) { .room-settings-page { padding: 12px; } .room-settings-page__header, .room-settings-filters { align-items: stretch; flex-direction: column; } .room-settings-page__actions { justify-content: flex-end; } .room-settings-table th:nth-child(4), .room-settings-table td:nth-child(4) { display: none; } }
</style>
