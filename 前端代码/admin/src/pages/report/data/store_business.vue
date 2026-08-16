<template>
  <section class="store-business-report-frame">
    <iframe
      :key="reportUrl"
      class="store-business-report-frame__content"
      :src="reportUrl"
      :title="reportTitle"
    />
  </section>
</template>

<script>
import { STORE_OPERATION_REPORT_CODES } from '@/libs/storeOperationReports'

function resolveReportCode(route) {
  const params = route && route.params ? route.params : {}
  const query = route && route.query ? route.query : {}
  const requested = String(params.report || query.report || '').trim()
  return STORE_OPERATION_REPORT_CODES.includes(requested) ? requested : STORE_OPERATION_REPORT_CODES[0]
}

export default {
  name: 'StoreBusinessReportFrame',
  computed: {
    reportCode () {
      return resolveReportCode(this.$route)
    },
    reportTitle () {
      return this.$route.meta.title || '门店运营报表'
    },
    reportUrl () {
      const query = new URLSearchParams()
      Object.entries(this.$route.query || {}).forEach(([key, value]) => {
        if (key === 'report' || value == null) return
        const values = Array.isArray(value) ? value : [value]
        values.forEach(item => query.append(key, String(item)))
      })
      const suffix = query.toString() ? `?${query.toString()}` : ''
      const vue3Origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')
      const reportVersion = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_REPORT_VERSION || '44aa777b'))
      return `${vue3Origin}/view_cashier_v3/?release=${reportVersion}#/platform/reports/${encodeURIComponent(this.reportCode)}${suffix}`
    }
  },
}
</script>

<style scoped>
.store-business-report-frame {
  height: calc(100vh - 118px);
  min-height: 640px;
  background: #f5f7f9;
}

.store-business-report-frame__content {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
  background: #fff;
}
</style>
