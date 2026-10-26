<template>
  <section class="group-management-dashboard-frame">
    <iframe
      :key="dashboardUrl"
      class="group-management-dashboard-frame__content"
      :src="dashboardUrl"
      title="集团管理看板"
    />
  </section>
</template>

<script>
export default {
  name: 'GroupManagementDashboardFrame',
  computed: {
    dashboardUrl () {
      const query = new URLSearchParams()
      Object.entries(this.$route.query || {}).forEach(([key, value]) => {
        if (value == null) return
        ;(Array.isArray(value) ? value : [value]).forEach(item => query.append(key, String(item)))
      })
      const suffix = query.toString() ? `?${query.toString()}` : ''
      const vue3Origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_REPORT_VERSION || 'group-dashboard-v3'))
      return `${vue3Origin}/view_cashier_v3/?release=${version}#/platform/group-management-dashboard${suffix}`
    }
  }
}
</script>

<style scoped>
.group-management-dashboard-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.group-management-dashboard-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
