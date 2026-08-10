<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  createRoomSetting,
  queryRoomSettings,
  setRoomSettingEnabled,
  sortRoomSettings,
  updateRoomSetting
} from '@/services/roomManagementApi'

const router = useRouter()
const props = defineProps({ embedded: { type: Boolean, default: false } })
const keyword = ref('')
const status = ref('')
const records = ref([])
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editorOpen = ref(false)
const editorId = ref(0)
const editorName = ref('')
const canSort = computed(() => !keyword.value.trim() && status.value === '')

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

function openCreate() {
  editorId.value = 0
  editorName.value = ''
  error.value = ''
  editorOpen.value = true
}

function openEdit(room) {
  editorId.value = Number(room.id) || 0
  editorName.value = room.name || ''
  error.value = ''
  editorOpen.value = true
}

async function saveEditor() {
  const name = editorName.value.trim()
  if (!name) {
    error.value = '请输入房间名称。'
    return
  }
  saving.value = true
  error.value = ''
  try {
    if (editorId.value > 0) await updateRoomSetting(editorId.value, name)
    else await createRoomSetting(name)
    editorOpen.value = false
    await load()
  } catch (requestError) {
    error.value = requestError?.message || '房间资料未能保存。'
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

onMounted(load)
</script>

<template>
  <section class="room-settings-page" aria-label="房间设置">
    <header class="room-settings-page__header">
      <div>
        <h2>房间设置</h2>
        <span>维护当前门店的房间基础资料</span>
      </div>
      <div class="room-settings-page__actions">
        <button v-if="!props.embedded" type="button" class="button button--secondary" @click="router.push({ name: 'cashier-v3-management-center' })">返回管理</button>
        <button type="button" class="button button--primary" @click="openCreate">新增房间</button>
      </div>
    </header>

    <div class="room-settings-filters">
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
    </div>

    <p v-if="error" class="room-settings-error" role="alert">{{ error }}</p>

    <div class="room-settings-table-wrap">
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
    <footer class="room-settings-page__footer">共 {{ total }} 个房间</footer>

    <div v-if="editorOpen" class="room-settings-modal" role="dialog" aria-modal="true" aria-label="房间资料">
      <form class="room-settings-modal__content" @submit.prevent="saveEditor">
        <header><h3>{{ editorId ? '编辑房间' : '新增房间' }}</h3></header>
        <label>
          <span>房间名称</span>
          <input v-model="editorName" maxlength="64" autofocus placeholder="请输入房间名称">
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
.room-settings-filters { display: flex; align-items: end; gap: 12px; padding: 14px 16px; }
.room-settings-filters label, .room-settings-modal label { display: grid; gap: 6px; color: #526071; font-size: 13px; }
.room-settings-filters input, .room-settings-filters select, .room-settings-modal input { min-height: 34px; border: 1px solid #cfd8e3; border-radius: 6px; padding: 0 10px; color: #1f2329; background: #fff; }
.room-settings-error { margin: 0; padding: 10px 12px; border: 1px solid #ffd6d2; border-radius: 6px; color: #c42b1c; background: #fff4f2; font-size: 13px; }
.room-settings-table-wrap { overflow: hidden; }
.room-settings-table { width: 100%; border-collapse: collapse; }
.room-settings-table th, .room-settings-table td { padding: 12px 14px; border-bottom: 1px solid #edf1f5; text-align: left; color: #465466; font-size: 13px; vertical-align: middle; }
.room-settings-table th { color: #697586; font-weight: 600; background: #fafbfd; }
.room-settings-table strong { color: #1f2329; }
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
