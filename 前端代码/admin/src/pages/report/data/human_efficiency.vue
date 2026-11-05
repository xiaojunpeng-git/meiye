<template><section class="human-frame"><iframe :src="dashboardUrl" title="人效看板" /></section></template>
<script>
export default {
  name: 'HumanEfficiencyFrame',
  computed: {
    // 与员工看板使用同一 Vue 3 开发源；生产仍走本站标准集成目录。
    dashboardUrl () {
      const origin = process.env.NODE_ENV === 'development' && window.location.port === '18081'
        ? 'http://127.0.0.1:18091' : (process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin)
      return `${String(origin).replace(/\/$/, '')}/view_cashier_v3/#/platform/human-efficiency`
    }
  }
}
</script>
<style scoped>.human-frame{height:calc(100vh - 118px);min-height:500px}.human-frame iframe{display:block;width:100%;height:100%;border:0}</style>
