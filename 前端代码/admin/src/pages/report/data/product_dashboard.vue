<template>
  <section class="product-dashboard-frame">
    <iframe :key="dashboardUrl" class="product-dashboard-frame__content" :src="dashboardUrl" title="商品看板" />
  </section>
</template>

<script>
export default {
  name: 'ProductDashboardFrame',
  computed: {
    dashboardUrl () {
      const query = new URLSearchParams()
      Object.entries(this.$route.query || {}).forEach(([key, value]) => {
        if (value == null) return
        ;(Array.isArray(value) ? value : [value]).forEach(item => query.append(key, String(item)))
      })
      const suffix = query.toString() ? `?${query.toString()}` : ''
      const vue3Origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_REPORT_VERSION || 'product-dashboard-v1'))
      return `${vue3Origin}/view_cashier_v3/?release=${version}#/platform/product-dashboard${suffix}`
    }
  }
}
</script>

<style scoped>
.product-dashboard-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.product-dashboard-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
