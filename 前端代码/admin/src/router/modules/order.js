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

const pre = 'order_';

export default {
  path: `${Setting.roterPre}/order`,
  name: 'order',
  header: 'order',
  // redirect: {
  //     name: `${pre}list`
  // },
  component: BasicLayout,
  children: [
    {
      path: 'list',
      name: `${pre}list`,
      meta: {
        auth: ['admin-order-storeOrder-index'],
        title: '订单管理'
      },
      component: () => import('@/pages/order/orderList/index')
    },
    {
      path: 'split_list',
      name: `${pre}split_list`,
      meta: {
        auth: ['admin-order-storeOrder-index'],
        title: '子订单列表'
      },
      component: () => import('@/pages/order/orderList/splitList.vue')
    },
    {
      path: 'offline',
      name: `${pre}offline`,
      meta: {
        auth: ['admin-order-offline'],
        title: '收银订单'
      },
      component: () => import('@/pages/order/offline/index')
    },
    {
      path: 'refund',
      name: `${pre}refund`,
      meta: {
        auth: ['admin-order-refund'],
        title: '售后订单'
      },
      component: () => import('@/pages/order/refund/index')
    },
    {
      path: 'invoice/list',
      name: `${pre}invoice`,
      meta: {
        auth: ['admin-order-startOrderInvoice-index'],
        title: '发票管理'
      },
      component: () => import('@/pages/order/invoice/index')
    },
    {
      path: 'invoice/setup',
      name: `${pre}invoicesetup`,
      meta: {
        auth: ['admin-order-invoice-setup'],
        title: '发票设置'
      },
      props: {
        typeMole: 'invoice'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: 'queue/list',
      name: `${pre}queue`,
      meta: {
        // auth: ['admin-order-startOrderInvoice-index'],
        title: '任务列表'
      },
      component: () => import('@/pages/order/orderList/handle/queueList')
    },
    {
      path: 'yeji',
      name: `${pre}yeji`,
      meta: {
        auth: ['admin-order-yeji-index'],
        title: '员工业绩'
      },
      component: () => import('@/pages/yeji/index')
    },
    {
      path: 'yeji_range',
      name: `${pre}yeji_range`,
      meta: {
        auth: ['admin-order-yeji_range-index'],
        title: '业绩区间'
      },
      component: () => import('@/pages/yeji/yeji_range')
    },
    {
      path: 'yeji_commission',
      name: `${pre}yeji_commission`,
      meta: {
        auth: ['admin-order-yeji_commission-index'],
        title: '业绩提成'
      },
      component: () => import('@/pages/yeji/yeji_commission')
    }
  ]
};
