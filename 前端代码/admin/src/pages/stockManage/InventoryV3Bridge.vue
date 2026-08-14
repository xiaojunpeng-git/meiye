<template>
  <div class="inventory-v3-bridge">
    <iframe ref="inventoryFrame" title="新版库存管理" :src="inventoryUrl" frameborder="0" />
  </div>
</template>

<script>
import util from '@/libs/util'

const pageByRoute = Object.freeze({
  inboundManage: 'inbound', outboundManage: 'outbound', inventoryDetails: 'stock',
  inventoryInfo: 'movement', inventoryCount: 'count', inventoryStatistics: 'movement',
  stockRequestManage: 'request', stockTransferManage: 'transfer', salonRecipeManage: 'recipe',
  salonUsageReport: 'usage', stockManageV3Home: 'overview'
})

export default {
  name: 'InventoryV3Bridge',
  mounted() {
    window.addEventListener('message', this.handleInventorySessionRequest)
  },
  beforeDestroy() {
    window.removeEventListener('message', this.handleInventorySessionRequest)
  },
  computed: {
    inventoryUrl() {
      const page = pageByRoute[this.$route.name] || 'overview'
      const isLocalDevelopment = process.env.NODE_ENV === 'development'
        && typeof window !== 'undefined'
        && ['127.0.0.1', 'localhost'].includes(window.location.hostname)
      // The inventory Vue 3 source has its own dev server. In the platform's
      // local hot-update mode, point the iframe there; otherwise use the
      // same-origin published integration bundle (8080/production).
      const configuredDevOrigin = String(process.env.VUE_APP_INVENTORY_V3_DEV_ORIGIN || '')
        .trim()
        .replace(/\/+$/, '')
      const inventoryOrigin = isLocalDevelopment && configuredDevOrigin
        ? configuredDevOrigin
        : window.location.origin
      const query = new URLSearchParams({
        source: 'platform',
        page,
        parent_origin: window.location.origin
      })
      return `${inventoryOrigin}/view_inventory_v3/?${query.toString()}`
    }
  },
  methods: {
    handleInventorySessionRequest(event) {
      if (!event || !event.data || event.data.type !== 'cashier-v3:inventory-session-request') return
      const frame = this.$refs.inventoryFrame
      const frameWindow = frame && frame.contentWindow
      if (!frameWindow || event.source !== frameWindow) return
      let inventoryOrigin = ''
      try {
        inventoryOrigin = new URL(this.inventoryUrl).origin
      } catch (_) {
        return
      }
      if (event.origin !== inventoryOrigin) return
      const token = util.cookies.get('token')
      if (!token) return
      frameWindow.postMessage({ type: 'cashier-v3:inventory-session', token }, inventoryOrigin)
    }
  }
}
</script>

<style scoped>
.inventory-v3-bridge { width: 100%; min-height: calc(100vh - 112px); background: #f4f7fb; }
.inventory-v3-bridge iframe { display: block; width: 100%; min-height: calc(100vh - 112px); border: 0; background: #fff; }
</style>
