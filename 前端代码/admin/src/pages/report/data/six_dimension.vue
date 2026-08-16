<template>
  <section class="six-dimension-report-frame">
    <iframe
      :key="reportUrl"
      class="six-dimension-report-frame__content"
      :src="reportUrl"
      :title="reportTitle"
    />
  </section>
</template>

<script>
import { SIX_DIMENSION_REPORTS, SIX_DIMENSION_REPORT_CODES } from '@/libs/sixDimensionReports'

function resolveReportCode (route) {
  const requested = String(route.meta.reportCode || route.params.report || route.query.report || '').trim()
  return SIX_DIMENSION_REPORT_CODES.includes(requested) ? requested : SIX_DIMENSION_REPORT_CODES[0]
}

export default {
  name: 'SixDimensionReportFrame',
  computed: {
    reportCode () {
      return resolveReportCode(this.$route)
    },
    reportTitle () {
      if (this.isTierConfig) return '消费分级设置'
      const report = SIX_DIMENSION_REPORTS.find(item => item.code === this.reportCode)
      return report ? report.title : '六维数据中心'
    },
    isTierConfig () {
      return this.$route.meta.settingsPage === 'consumption-tiers'
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
      const reportVersion = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_REPORT_VERSION || 'six-dimension-v1'))
      const runtimePath = this.isTierConfig
        ? '/platform/six-dimension/consumption-tiers'
        : `/platform/reports/${encodeURIComponent(this.reportCode)}`
      return `${vue3Origin}/view_cashier_v3/?release=${reportVersion}#${runtimePath}${suffix}`
    }
  }
}
</script>

<style scoped>
.six-dimension-report-frame {
  height: calc(100vh - 118px);
  min-height: 640px;
  background: #f5f7f9;
}

.six-dimension-report-frame__content {
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
  background: #fff;
}
</style>
