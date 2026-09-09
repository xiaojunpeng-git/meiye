// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import BasicLayout from '@/layouts/basic-layout';
import Setting from '@/setting';
const pre = 'store_';

export default {
  path: `${Setting.roterPre}/store`,
  name: 'store',
  header: 'store',
  // redirect: {
  // 	name: `${pre}statistics`
  // },
  component: BasicLayout,
  children: [
    {
      path: 'system/base',
      name: `${pre}systemBase`,
      meta: {
        auth: ['store-system-base'],
        title: '门店设置'
      },
      component: () => import('@/pages/store/base/index')
    },
    {
      path: 'category/index',
      name: `${pre}category`,
      meta: {
        auth: ['admin-store-store_category'],
        title: '门店分类'
      },
      component: () => import('@/pages/store/storeCategory/index')
    },
    {
      path: 'store_menus/index',
      name: `${pre}storeMenus`,
      meta: {
        auth: ['admin-store_menus-index'],
        title: '门店菜单'
      },
      component: () => import('@/pages/store/storeMenus/index')
    },
    {
      path: 'cashier_menus/index',
      name: `${pre}cashierMenus`,
      meta: {
        auth: ['admin-cashier_menus-index'],
        title: '收银台菜单'
      },
      component: () => import('@/pages/store/cashierMenus/index')
    },
    {
      path: 'statistics',
      name: `${pre}statistics`,
      meta: {
        auth: ['admin-store-store_statistics'],
        title: '运营概况'
      },
      component: () => import('@/pages/store/statistics/index')
    },
    {
      path: 'statistics/reservation-detail',
      name: `${pre}statisticsReservationDetail`,
      meta: {
        auth: ['admin-store-store_statistics'],
        title: '经营明细-预约客'
      },
      component: () => import('@/pages/store/statistics/reservation-detail')
    },
    {
      path: 'statistics/new-profile-detail',
      name: `${pre}statisticsNewProfileDetail`,
      meta: {
        auth: ['admin-store-store_statistics'],
        title: '经营明细-新建档'
      },
      component: () => import('@/pages/store/statistics/new-profile-detail')
    },
    {
      path: 'statistics/source-customer-detail',
      name: `${pre}statisticsSourceCustomerDetail`,
      meta: {
        auth: ['admin-store-store_statistics'],
        title: '经营明细-散客新客'
      },
      component: () => import('@/pages/store/statistics/source-customer-detail')
    },
    {
      path: 'statistics/money-detail',
      name: `${pre}statisticsMoneyDetail`,
      meta: {
        auth: ['admin-store-store_statistics'],
        title: '经营明细-金额'
      },
      component: () => import('@/pages/store/statistics/money-detail')
    },
    {
      path: 'store/index',
      name: `${pre}storeList`,
      meta: {
        auth: ['admin-store-store_list'],
        title: '门店列表'
      },
      component: () => import('@/pages/store/storeList/index')
    },
    {
      path: 'add_store/:id?',
      name: `${pre}addStore`,
      meta: {
        auth: ['admin-store-add_store'],
        title: '添加门店'
      },
      component: () => import('@/pages/store/addStore/index')
    },
    {
      path: 'store/apply',
      name: `${pre}storeApply`,
      meta: {
        auth: ['admin-store-store_apply'],
        title: '门店申请'
      },
      component: () => import('@/pages/store/storeApply/index')
    },
    {
      path: 'region/list',
      name: `${pre}regionList`,
      meta: {
        auth: ['admin-store-region_list'],
        title: '组织'
      },
      // O5：正式菜单入口切到已验收 workspace（旧 region/index 源码保留，便于回滚）
      component: () => import('@/pages/store/region/workspace/index')
    },
    {
      path: 'region/prototype',
      name: `${pre}regionPrototype`,
      meta: {
        auth: ['admin-store-region_list'],
        title: '组织架构（新）'
      },
      // 兼容旧书签/直链，与正式入口同一页面
      component: () => import('@/pages/store/region/workspace/index')
    },
    {
      path: 'region/job-positions',
      name: `${pre}regionJobPositions`,
      meta: {
        auth: ['admin-store-region-job_positions'],
        title: '岗位策略'
      },
      component: () => import('@/pages/store/region/job-positions/index')
    },
    {
      path: 'region/staffing',
      name: `${pre}regionStaffingQuota`,
      meta: {
        auth: ['admin-organization-staffing'],
        title: '岗位编制'
      },
      component: () => import('@/pages/report/data/staffing_quota')
    },
    {
      path: 'region/create/:id?',
      name: `${pre}addRegion`,
      meta: {
        auth: ['admin-store-region_create'],
        title: '添加管理员'
      },
      component: () => import('@/pages/store/region/create')
    },
    {
      path: 'order/center',
      name: `${pre}orderCenter`,
      meta: {
        auth: [
          'admin-store-order-center-sales',
          'admin-store-order-center-recharge',
          'admin-store-order-center-refund',
          'admin-store-order-center-debt',
          'admin-store-order-center-service',
          'admin-store-order-center-supplement',
          'admin-store-order-center-gift',
          'admin-store-order-center-card-operation'
        ],
        title: '门店订单'
      },
      component: () => import('@/pages/store/order/center')
    },
    {
      path: 'order/index',
      name: `${pre}order`,
      meta: {
        auth: ['admin-store-store_order'],
        title: '门店订单'
      },
      component: () => import('@/pages/store/order/index')
    },
    {
      path: 'debt/index',
      name: `${pre}debt`,
      meta: {
        auth: ['admin-store-store_order'],
        title: '欠款管理'
      },
      component: () => import('@/pages/store/debt/index')
    },
    {
      path: 'refund/order',
      name: `${pre}refundOrder`,
      meta: {
        auth: ['admin-store-store_order'],
        title: '售后订单'
      },
      component: () => import('@/pages/store/refund/order')
    },
    {
      path: 'capital/index',
      name: `${pre}capital`,
      meta: {
        auth: ['admin-store-capital-index'],
        title: '门店流水'
      },
      component: () => import('@/pages/store/capital/index')
    },
    {
      path: 'bill/index',
      name: `${pre}bill`,
      meta: {
        auth: ['admin-store-bill-index'],
        title: '账单记录'
      },
      component: () => import('@/pages/store/bill/index')
    },
    {
      path: 'cash/index',
      name: `${pre}cash`,
      meta: {
        auth: ['admin-store-cash-index'],
        title: '转账申请'
      },
      component: () => import('@/pages/store/cash/index')
    },
    {
      path: 'finance/set/:type?/:tab_id?',
      name: `${pre}setting`,
      meta: {
        auth: ['admin-store-finance-set'],
        title: '财务设置'
      },
      props: {
        typeMole: 'finance'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: 'writeoff/index',
      name: `${pre}writeoff`,
      meta: {
        auth: ['admin-store-writeoff'],
        title: '核销记录'
      },
      component: () => import('@/pages/store/writeoff/index')
    }
  ]
};
