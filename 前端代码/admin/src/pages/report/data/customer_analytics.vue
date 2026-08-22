<template>
  <section class="customer-analytics-frame">
    <iframe
      :key="analyticsUrl"
      class="customer-analytics-frame__content"
      :src="analyticsUrl"
      :title="pageTitle"
    />
  </section>
</template>

<script>
const DEFAULT_REPORT = 'customer-overview'

export default {
  name: 'CustomerAnalyticsFrame',
  computed: {
    reportCode () {
      return String(
        (this.$route && this.$route.params && this.$route.params.report) ||
          (this.$route && this.$route.meta && this.$route.meta.reportCode) ||
          DEFAULT_REPORT
      ).trim() || DEFAULT_REPORT
    },
    pageTitle () {
      return (this.$route && this.$route.meta && this.$route.meta.title) || '客户分析'
    },
    analyticsUrl () {
      const query = new URLSearchParams()
      Object.entries((this.$route && this.$route.query) || {}).forEach(([key, value]) => {
        if (value == null) return
        ;(Array.isArray(value) ? value : [value]).forEach(item => query.append(key, String(item)))
      })
      // 明确标记为平台嵌入，Vue 3 页面据此隐藏自己的重复导航；
      // 直接打开 18091 预览不带该标记，仍可保留页面内导航。
      query.set('embedded', '1')
      const suffix = query.toString() ? `?${query.toString()}` : ''
      // 本地平台 18081 的客户分析内容由 Vue 3 热更新端 18091 提供；
      // 正式构建仍可通过环境变量或同源部署覆盖，避免把调试端口带入生产。
      const devOrigin = process.env.NODE_ENV === 'development' ? 'http://127.0.0.1:18091' : window.location.origin
      const vue3Origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || devOrigin).replace(/\/$/, '')
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_CUSTOMER_ANALYTICS_VERSION || 'customer-analytics-v1'))
      return `${vue3Origin}/view_cashier_v3/?release=${version}#/platform/customer-analytics/${encodeURIComponent(this.reportCode)}${suffix}`
    }
  }
}
</script>

<style scoped>
.customer-analytics-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.customer-analytics-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
