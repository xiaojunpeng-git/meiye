<template><iframe class="fund-frame" title="费用" :src="src" /></template>
<script setup>
// The fund HTML is a separate app shell. Give each mount a fresh document URL so
// an open cashier session cannot reuse an older shell after a fund-only release.
const fundUrl = new URL('/view_fund_v3/', window.location.origin)
fundUrl.searchParams.set('source', 'store')
fundUrl.searchParams.set('entry_version', String(Date.now()))
const src = fundUrl.toString()
</script>
<style scoped>.fund-frame{display:block;width:100%;min-height:calc(100vh - 116px);border:0;background:#f5f6f8}</style>
