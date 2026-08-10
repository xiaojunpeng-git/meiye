<script setup>
import { ref } from 'vue'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import StaffListView from '@/views/StaffListView.vue'
import RoomSettingsView from '@/views/RoomSettingsView.vue'

const state = useCashierV3State()
const activeTab = ref('staff')

const tabs = [
  { id: 'staff', label: '人员管理' },
  { id: 'room', label: '房间设置' }
]
</script>

<template>
  <section class="management-center-page" aria-label="管理">
    <header class="management-center-page__header">
      <div>
        <h2>管理</h2>
        <span>当前门店：{{ state.storeName }}</span>
      </div>
      <nav class="management-center-tabs" aria-label="管理功能">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          :class="{ 'management-center-tabs__button--active': activeTab === tab.id }"
          @click="activeTab = tab.id"
        >{{ tab.label }}</button>
      </nav>
    </header>

    <KeepAlive>
      <StaffListView v-if="activeTab === 'staff'" />
      <RoomSettingsView v-else embedded />
    </KeepAlive>
  </section>
</template>

<style scoped>
.management-center-page {
  display: grid;
  min-width: 0;
  min-height: 0;
  grid-template-rows: auto minmax(0, 1fr);
  overflow: hidden;
  background: #f5f7fa;
}

.management-center-page__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  min-width: 0;
  padding: 14px 20px 0;
  background: #fff;
  border-bottom: 1px solid #e6ecf2;
}

.management-center-page__header > div { display: flex; align-items: baseline; gap: 12px; min-width: 0; }
.management-center-page__header h2 { margin: 0; color: #1f2329; font-size: 20px; }
.management-center-page__header span { overflow: hidden; color: #697586; font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }

.management-center-tabs { display: flex; align-self: stretch; align-items: end; gap: 22px; }
.management-center-tabs button {
  min-height: 40px;
  padding: 0 2px 11px;
  border: 0;
  border-bottom: 2px solid transparent;
  color: #697586;
  background: transparent;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.management-center-tabs__button--active { border-bottom-color: #1677cc !important; color: #1677cc !important; }

@media (max-width: 780px) {
  .management-center-page__header { display: grid; gap: 10px; padding: 14px 14px 0; }
  .management-center-page__header > div { display: grid; gap: 4px; }
  .management-center-tabs { gap: 18px; }
}
</style>
