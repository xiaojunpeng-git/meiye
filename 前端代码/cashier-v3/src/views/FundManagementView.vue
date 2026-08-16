<template><iframe class="fund-frame" title="费用" :src="src" /></template>
<script setup>
const src = `${window.location.origin}/view_fund_v3/?source=store`
</script>
<style scoped>.fund-frame{display:block;width:100%;min-height:calc(100vh - 116px);border:0;background:#f5f6f8}</style>
