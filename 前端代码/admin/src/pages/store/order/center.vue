<template>
  <section class="platform-order-center-frame">
    <iframe
      :key="orderCenterUrl"
      class="platform-order-center-frame__content"
      :src="orderCenterUrl"
      title="门店订单"
    />
  </section>
</template>

<script>
const VALID_TABS = new Set([
  'sales', 'recharge', 'refund', 'debt',
  'service', 'supplement', 'gift', 'card_operation'
])

export default {
  name: 'PlatformOrderCenterFrame',
  computed: {
    activeTab () {
      const tab = String((this.$route && this.$route.query && this.$route.query.tab) || 'sales').trim()
      return VALID_TABS.has(tab) ? tab : 'sales'
    },
    orderCenterUrl () {
      // 平台嵌入模式复用 Vue3 订单中心，只调用管理员只读接口；所有操作
      // （退款、还款、核销、赠送、卡操作等）仍必须到门店端办理。
      const devOrigin = process.env.NODE_ENV === 'development' ? 'http://127.0.0.1:18091' : window.location.origin
      const vue3Origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || devOrigin).replace(/\/$/, '')
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_ORDER_CENTER_VERSION || 'platform-order-center-v1'))
      const assetVersion = '20260909-order-center-v1'
      return `${vue3Origin}/view_cashier_v3/?release=${version}&v=${assetVersion}#/platform/order-center?embedded=1&tab=${encodeURIComponent(this.activeTab)}`
    }
  }
}
</script>

<style scoped>
.platform-order-center-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.platform-order-center-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
