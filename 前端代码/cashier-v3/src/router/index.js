import { createRouter, createWebHashHistory } from 'vue-router'
import CashierShell from '@/layouts/CashierShell.vue'
import CashierWorkbenchView from '@/views/CashierWorkbenchView.vue'
import WriteoffWorkbenchView from '@/views/WriteoffWorkbenchView.vue'
import ProjectReplacementView from '@/views/ProjectReplacementView.vue'
import RoomStatusView from '@/views/RoomStatusView.vue'
import ReservationListView from '@/views/ReservationListView.vue'
import MemberListView from '@/views/MemberListView.vue'
import HangOrderListView from '@/views/HangOrderListView.vue'
import OrderCenterView from '@/views/OrderCenterView.vue'
import ManagementCenterView from '@/views/ManagementCenterView.vue'
import BusinessDashboardView from '@/views/BusinessDashboardView.vue'
import StoreBusinessReportView from '@/views/StoreBusinessReportView.vue'
import MemberDashboardView from '@/views/MemberDashboardView.vue'
import GroupManagementDashboardView from '@/views/GroupManagementDashboardView.vue'
import ProductDashboardView from '@/views/ProductDashboardView.vue'
import EngineeringManagementView from '@/views/EngineeringManagementView.vue'
import ConsumptionTierConfigView from '@/views/ConsumptionTierConfigView.vue'
import StaffListView from '@/views/StaffListView.vue'
import RoomSettingsView from '@/views/RoomSettingsView.vue'
import RoutePlaceholderView from '@/views/RoutePlaceholderView.vue'
import StoreLoginView from '@/views/StoreLoginView.vue'
import FundManagementView from '@/views/FundManagementView.vue'
import PresaleClaimView from '@/views/PresaleClaimView.vue'
import { bootstrapCashierV3Workbench, hasCashierV3Session } from '@/services/cashierV3SessionLifecycle'
import { canUseCashierV3Feature } from '@/services/cashierV3Bridge'

const routeFeatureCodes = Object.freeze({
  'cashier-v3-cashier': 'cashier.v3.cashier',
  'cashier-v3-writeoff': 'cashier.v3.writeoff',
  'cashier-v3-replacement': 'cashier.v3.writeoff',
  'cashier-v3-room': 'cashier.v3.room',
  'cashier-v3-reservation': 'cashier.v3.reservation',
  'cashier-v3-member': 'cashier.v3.member',
  'cashier-v3-care': 'cashier.v3.member',
  'cashier-v3-fund': 'cashier.v3.member',
  'cashier-v3-presale-claim': 'cashier.v3.inventory.outbound',
  'cashier-v3-hang': 'cashier.v3.hang',
  'cashier-v3-order-center': 'cashier.v3.order_center',
  'cashier-v3-management-center': 'cashier.v3.management_center',
  'cashier-v3-staff-list': 'cashier.v3.management_center',
  'cashier-v3-room-settings': 'cashier.v3.management_center',
  'cashier-v3-business-dashboard': 'cashier.v3.management_center',
  'cashier-v3-store-business-reports': 'cashier.v3.management_center'
})

const defaultRouteNames = Object.freeze([
  'cashier-v3-cashier',
  'cashier-v3-hang',
  'cashier-v3-room',
  'cashier-v3-reservation',
  'cashier-v3-member',
  'cashier-v3-order-center',
  'cashier-v3-management-center'
])

function firstGrantedRouteName() {
  return defaultRouteNames.find((routeName) => canUseCashierV3Feature(routeFeatureCodes[routeName])) || ''
}

const routes = [
  { path: '/login', name: 'cashier-v3-login', component: StoreLoginView, meta: { title: '门店端登录' } },
  {
    path: '/platform/reports/:report?',
    name: 'cashier-v3-platform-store-business-reports',
    component: StoreBusinessReportView,
    meta: { platformReport: true, title: '门店运营报表' }
  },
  {
    path: '/platform/six-dimension/consumption-tiers',
    name: 'cashier-v3-platform-six-dimension-consumption-tiers',
    component: ConsumptionTierConfigView,
    meta: { platformReport: true, title: '消费分级设置' }
  },
  {
    path: '/platform/group-management-dashboard',
    name: 'cashier-v3-platform-group-management-dashboard',
    component: GroupManagementDashboardView,
    meta: { platformReport: true, title: '集团管理看板' }
  },
  {
    path: '/platform/product-dashboard',
    name: 'cashier-v3-platform-product-dashboard',
    component: ProductDashboardView,
    meta: { platformReport: true, title: '商品看板' }
  },
  {
    path: '/platform/member-management-dashboard',
    name: 'cashier-v3-platform-member-management-dashboard',
    component: MemberDashboardView,
    meta: { platformReport: true, title: '会员看板' }
  },
  {
    path: '/platform/engineering-management',
    name: 'cashier-v3-platform-engineering-management',
    component: EngineeringManagementView,
    props: { platform: true },
    meta: { platformReport: true, title: '工程管理' }
  },
  {
    path: '/',
    component: CashierShell,
    redirect: '/cashier',
    children: [
      {
        path: 'cashier',
        name: 'cashier-v3-cashier',
        component: CashierWorkbenchView,
        meta: { title: '收银' }
      },
      {
        path: 'writeoff',
        name: 'cashier-v3-writeoff',
        component: WriteoffWorkbenchView,
        meta: { title: '核销' }
      },
      {
        path: 'replacement',
        name: 'cashier-v3-replacement',
        component: ProjectReplacementView,
        meta: { title: '项目替换' }
      },
      {
        path: 'room',
        name: 'cashier-v3-room',
        component: RoomStatusView,
        meta: { title: '房间' }
      },
      {
        path: 'reservation',
        name: 'cashier-v3-reservation',
        component: ReservationListView,
        meta: { title: '预约', description: '预约、主项目与明细项目、人员、时间和可选房间在同一条预约中呈现。' }
      },
      {
        path: 'member',
        name: 'cashier-v3-member',
        component: MemberListView,
        meta: { title: '会员列表', description: '会员查询、资料、资产与记录入口。' }
      },
      {
        path: 'care',
        name: 'cashier-v3-care',
        component: RoutePlaceholderView,
        meta: {
          title: '客情',
          emptyTitle: '暂无待处理客情任务',
          emptyDescription: '会员客情记录会显示在这里。'
        }
      },
      {
        path: 'fund',
        name: 'cashier-v3-fund',
        component: FundManagementView,
        meta: { title: '费用', description: '录入、审核、冲销、台账与专项统计。' }
      },
      {
        path: 'presale-claim',
        name: 'cashier-v3-presale-claim',
        component: PresaleClaimView,
        meta: { title: '客户领用', description: '客户权益商品可分次领用，领用后自动生成出库单。' }
      },
      {
        path: 'hang',
        name: 'cashier-v3-hang',
        component: HangOrderListView,
        meta: { title: '挂单', description: '查看未结挂单；可继续编辑原单、开始服务或作废。' }
      },
      {
        path: 'order-center',
        name: 'cashier-v3-order-center',
        component: OrderCenterView,
        meta: { title: '订单', description: '统一查看销售、服务、退款与作废；销售订单是其中一种业务订单。' }
      },
      {
        path: 'management-center',
        name: 'cashier-v3-management-center',
        component: ManagementCenterView,
        meta: { title: '管理', description: '原门店后台功能的统一目录与逐步迁入入口。' }
      },
      {
        path: 'management-center/staff',
        name: 'cashier-v3-staff-list',
        component: StaffListView,
        meta: { title: '员工列表', description: '查询当前门店员工并维护收银角色资格。' }
      },
      {
        path: 'management-center/room-settings',
        name: 'cashier-v3-room-settings',
        component: RoomSettingsView,
        meta: { title: '房间设置', description: '维护当前门店房间基础资料。' }
      },
      {
        path: 'management-center/business-dashboard',
        name: 'cashier-v3-business-dashboard',
        component: BusinessDashboardView,
        meta: { title: '经营看板', description: 'V3 事实层经营指标、趋势、排行、明细与数据追平状态。' }
      },
      {
        path: 'data/reports/:report?',
        name: 'cashier-v3-store-business-reports',
        component: StoreBusinessReportView,
        meta: { title: '门店运营', description: '门店运营报表统一读取 V3 事实、指标与数据权限服务。' }
      }
    ]
  }
]

// 工作台路由生命周期：同一时刻只允许一个 bootstrap；失败后下一次进入可重试。
let workbenchBootstrapCompleted = false
let workbenchBootstrapPromise = null
export function ensureCashierV3RouterBootstrap(options = {}) {
  if (workbenchBootstrapCompleted) return Promise.resolve(null)
  if (workbenchBootstrapPromise) return workbenchBootstrapPromise

  const attempt = bootstrapCashierV3Workbench({
    reason: options.reason || 'router',
    silent: options.silent !== false
  })
    .then((result) => {
      const response = result?.data?.result && typeof result.data.result === 'object' ? result.data : result
      const status = String(response?.result?.status || '').toLowerCase()
      workbenchBootstrapCompleted = ['success', 'succeeded'].includes(status)
      return result
    })
    .catch((error) => {
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[cashier-v3] bootstrap failed', error?.message || error)
      }
      return null
    })
    .finally(() => {
      if (workbenchBootstrapPromise === attempt) workbenchBootstrapPromise = null
    })
  workbenchBootstrapPromise = attempt
  return attempt
}

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

router.beforeEach(async (to) => {
  if (to.path === '/login' || to.name === 'cashier-v3-login') return true
  // 平台报表使用后台管理员登录态，不能初始化或依赖收银工作台会话。
  if (to.meta.platformReport === true) return true
  if (to.path !== '/' && !String(to.name || '').startsWith('cashier-v3-')) return true

  // 空会话不能渲染游客收银壳。否则目录和当前门店均为空，容易被误判为商品
  // 未加载；门店端业务路由必须先回到登录页建立令牌和首份工作台投影。
  if (!hasCashierV3Session()) return { name: 'cashier-v3-login' }

  const featureCode = routeFeatureCodes[String(to.name || '')]
  // 登录响应已经由服务端按任职、入口和岗位规则签发功能码。先使用这份
  // 会话快照决定首屏，避免轻量 bootstrap 尚未返回完整根投影时把新登录
  // 用户错误送回登录页；没有该快照仍须走下方的 fail-closed 初始化路径。
  if (featureCode && canUseCashierV3Feature(featureCode)) return true

  await ensureCashierV3RouterBootstrap({ reason: 'route-permission', silent: true })
  if (featureCode && canUseCashierV3Feature(featureCode)) return true

  const fallbackRoute = firstGrantedRouteName()
  if (fallbackRoute && fallbackRoute !== to.name) return { name: fallbackRoute }
  return { name: 'cashier-v3-login' }
})

export default router
