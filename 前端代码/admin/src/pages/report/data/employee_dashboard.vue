<template>
  <section class="employee-dashboard-frame">
    <iframe
      :key="dashboardUrl"
      class="employee-dashboard-frame__content"
      :src="dashboardUrl"
      title="员工看板"
    />
  </section>
</template>

<script>
export default {
  name: 'EmployeeDashboardFrame',
  computed: {
    dashboardUrl () {
      const query = new URLSearchParams()
      Object.entries(this.$route.query || {}).forEach(([key, value]) => {
        if (value == null) return
        ;(Array.isArray(value) ? value : [value]).forEach(item => query.append(key, String(item)))
      })
      const suffix = query.toString() ? `?${query.toString()}` : ''
      const section = this.$route.params.section || 'overview'
      const devEmployeeOrigin = process.env.NODE_ENV === 'development' && window.location.port === '18081'
        ? 'http://127.0.0.1:18091'
        : ''
      const vue3Origin = String(devEmployeeOrigin || process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_REPORT_VERSION || 'employee-dashboard-v1'))
      return `${vue3Origin}/view_cashier_v3/?release=${version}#/platform/employee-dashboard/${encodeURIComponent(section)}${suffix}`
    }
  }
}
</script>

<style scoped>
.employee-dashboard-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.employee-dashboard-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
