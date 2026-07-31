<template>
  <div class="inventory-v3-bridge">
    <iframe title="新版库存管理" :src="inventoryUrl" frameborder="0" />
  </div>
</template>

<script>
const pageByRoute = Object.freeze({
  inboundManage: 'inbound', outboundManage: 'outbound', inventoryDetails: 'movement',
  inventoryInfo: 'movement', inventoryCount: 'count', inventoryStatistics: 'statistics',
  stockRequestManage: 'request', stockTransferManage: 'transfer', salonRecipeManage: 'recipe',
  salonUsageReport: 'usage', stockManageV3Home: 'overview'
})

export default {
  name: 'InventoryV3Bridge',
  computed: {
    inventoryUrl() {
      const page = pageByRoute[this.$route.name] || 'overview'
      return `/view_inventory_v3/?source=platform&page=${encodeURIComponent(page)}`
    }
  }
}
</script>

<style scoped>
.inventory-v3-bridge { width: 100%; min-height: calc(100vh - 112px); background: #f4f7fb; }
.inventory-v3-bridge iframe { display: block; width: 100%; min-height: calc(100vh - 112px); border: 0; background: #fff; }
</style>
