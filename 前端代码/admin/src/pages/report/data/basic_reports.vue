<template>
  <section class="basic-report-frame"><iframe :src="reportUrl" title="基础报表" /></section>
</template>
<script>
/** 平台只提供认证壳；目录、查询栏和结果与门店共用同一Vue3页面。 */
export default {
  name: 'BasicReportFrame',
  computed: {
    reportUrl () {
      const origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')
      const report = String(this.$route.params.report || '')
      return `${origin}/view_cashier_v3/#/platform/basic-reports${report ? '/' + encodeURIComponent(report) : ''}`
    }
  }
}
</script>
<style scoped>
.basic-report-frame{height:calc(100vh - 118px);min-height:420px;background:#fff}.basic-report-frame iframe{display:block;border:0;width:100%;height:100%}
</style>
