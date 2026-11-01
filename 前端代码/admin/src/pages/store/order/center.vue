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
  'all', 'sales', 'recharge', 'refund', 'debt',
  'service', 'supplement', 'gift', 'card_operation'
])

export default {
  name: 'PlatformOrderCenterFrame',
  computed: {
    activeTab () {
      const tab = String((this.$route && this.$route.query && this.$route.query.tab) || 'all').trim()
      return VALID_TABS.has(tab) ? tab : 'all'
    },
    orderCenterUrl () {
      // 平台与门店复用同一 Vue3 订单中心静态入口；平台只增加
      // 组织范围上下文，不允许固定另一个旧资源版本。
      const devOrigin = process.env.NODE_ENV === 'development' ? 'http://127.0.0.1:18091' : window.location.origin
      const vue3Origin = String(process.env.VUE_APP_CASHIER_V3_DEV_ORIGIN || devOrigin).replace(/\/$/, '')
      // 平台嵌入页必须与本次已部署的门店端共用同一静态入口标识；禁止固定旧日期，
      // 否则平台会继续加载旧查询栏，即使门店端已经升级。
      const version = encodeURIComponent(String(process.env.VUE_APP_CASHIER_V3_ORDER_CENTER_VERSION || '20261031-final-v4'))
      return `${vue3Origin}/view_cashier_v3/?release=${version}&v=${version}#/platform/order-center?embedded=1&tab=${encodeURIComponent(this.activeTab)}`
    }
  }
}
</script>

<style scoped>
.platform-order-center-frame { height: calc(100vh - 118px); min-height: 640px; background: #f5f7f9; }
.platform-order-center-frame__content { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
</style>
