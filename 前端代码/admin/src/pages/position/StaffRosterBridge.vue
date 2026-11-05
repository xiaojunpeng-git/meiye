<template><section class="staff-roster-frame"><iframe :src="src" title="员工排班" /></section></template>
<script>
// 平台壳只挂载同一Vue3页面；管理员令牌由同源Cookie传递，不生成门店员工会话。
export default {
  name: 'StaffRosterBridge',
  computed: {
    src () { return `${String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || window.location.origin).replace(/\/$/, '')}/view_cashier_v3/#/platform/staff-roster` }
  }
}
</script>
<style scoped>.staff-roster-frame{height:calc(100vh - 118px);min-height:420px}.staff-roster-frame iframe{width:100%;height:100%;border:0;display:block}</style>
