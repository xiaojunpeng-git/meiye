const REPORT_CODES = Object.freeze([
  'partner_item_summary',
  'partner_item_detail',
  'member_consumption_detail',
  'store_item_analysis',
  'store_craftsman_consumption',
  'store_salesperson_performance'
])

function resolveReportCode(route) {
  const requested = String(route?.params?.report || route?.query?.report || '').trim()
  return REPORT_CODES.includes(requested) ? requested : REPORT_CODES[0]
}

/**
 * Vue 2 只保留旧菜单和书签的兼容入口；报表页面实现统一由 Vue 3 承担。
 */
export default {
  name: 'StoreBusinessReportCompatibilityRedirect',
  created () {
    this.redirectToVue3()
  },
  watch: {
    '$route.fullPath' () {
      this.redirectToVue3()
    }
  },
  methods: {
    redirectToVue3 () {
      const code = resolveReportCode(this.$route)
      const target = `${window.location.origin}/view_cashier_v3/#/platform/reports/${encodeURIComponent(code)}`
      if (window.location.href !== target) window.location.replace(target)
    }
  },
  render (createElement) {
    return createElement('div')
  }
}
