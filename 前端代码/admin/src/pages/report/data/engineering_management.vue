<template>
  <section class="engineering-management-frame">
    <iframe :key="dashboardUrl" class="engineering-management-frame__content" :src="dashboardUrl" title="工程管理" />
  </section>
</template>

<script>
export default {
  name: 'EngineeringManagementFrame',
  computed: {
    dashboardUrl () {
      const query = new URLSearchParams()
      Object.entries(this.$route.query || {}).forEach(([key, value]) => {
        if (value == null) return
        ;(Array.isArray(value) ? value : [value]).forEach(item => query.append(key, String(item)))
      })
      const suffix = query.toString() ? `?${query.toString()}` : ''
      const ledgerType = this.$route.meta && this.$route.meta.ledgerType
      const typeSuffix = ledgerType ? `${suffix ? '&' : '?'}type=${encodeURIComponent(ledgerType)}` : ''
      const origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_REPORT_VERSION || 'engineering-ledger-v1'))
      return `${origin}/view_cashier_v3/?release=${version}#/platform/engineering-management${suffix}${typeSuffix}`
    }
  }
}
</script>

<style scoped>
.engineering-management-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.engineering-management-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
