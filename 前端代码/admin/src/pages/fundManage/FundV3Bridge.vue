<template><iframe class="fund-v3-frame" title="费用" :src="src" /></template>
<script>
export default {
  name:'FundV3Bridge',
  computed:{
    src(){ return `${window.location.origin}/view_fund_v3/?source=platform` }
  }
}
</script>
<style scoped>.fund-v3-frame{display:block;width:100%;min-height:calc(100vh - 112px);border:0;background:#f5f6f8}</style>
