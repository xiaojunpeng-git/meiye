<template><span aria-hidden="true"></span></template>
<script setup>
import { onMounted, onBeforeUnmount } from 'vue'
import { mountMoheAi } from '../../../shared/mohe-ai/browser-entry.mjs'
import { browserTransport } from '../../../shared/mohe-ai/browser-transport.mjs'
import { readStoreV3SessionToken } from '../services/storeV3SessionToken.js'
let dispose
onMounted(() => { dispose = mountMoheAi({ request: browserTransport('/cashierapi/v3/ai', readStoreV3SessionToken) }) })
onBeforeUnmount(() => { if (dispose) dispose() })
</script>
