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

export default {

  path: `${Setting.roterPre}/stock/manage`,
  name: 'stockManage',
  header: 'stockManage',
  component: BasicLayout,
  children: [
    {
      path: `${Setting.roterPre}/inbound/manage`,
      name: `inboundManage`,
      meta: {
        auth: ['admin-inbound-manage'],
        title: '入库管理'
      },
      component: () => import('@/pages/stockManage/inboundManage/list')
    },
    {
		    path: `${Setting.roterPre}/inbound/manage/add/:id?`,
		    name: `inboundAdd`,
		    meta: {
		        auth: ['admin-inbound-manage-add'],
		        title: '添加入库单'
		    },
		    component: () => import('@/pages/stockManage/inboundManage/add')
    },
    {
		    path: `${Setting.roterPre}/outbound/manage`,
		    name: `outboundManage`,
		    meta: {
		        auth: ['admin-outbound-manage'],
		        title: '出库管理'
		    },
		    component: () => import('@/pages/stockManage/outboundManage/list')
    },
    {
		    path: `${Setting.roterPre}/outbound/manage/add/:id?`,
		    name: `outboundAdd`,
		    meta: {
		        auth: ['admin-outbound-manage-add'],
		        title: '添加出库单'
		    },
		    component: () => import('@/pages/stockManage/outboundManage/add')
    },
    {
		    path: `${Setting.roterPre}/inventory/details`,
		    name: `inventoryDetails`,
		    meta: {
		        auth: ['admin-inventory-details'],
		        title: '出入库明细列表'
		    },
		    component: () => import('@/pages/stockManage/inventoryDetails/list')
    },
    {
		    path: `${Setting.roterPre}/inventory/details/info`,
		    name: `inventoryInfo`,
		    meta: {
		        auth: ['admin-inventory-details-info'],
		        title: '出入库明细'
		    },
		    component: () => import('@/pages/stockManage/inventoryDetails/details')
    },
    {
		    path: `${Setting.roterPre}/inventory/count`,
		    name: `inventoryCount`,
		    meta: {
		        auth: ['admin-inventory-count'],
		        title: '库存盘点'
		    },
		    component: () => import('@/pages/stockManage/inventoryCount/list')
    },
    {
		    path: `${Setting.roterPre}/inventory/count/add/:id?`,
		    name: `inventoryAdd`,
		    meta: {
		        auth: ['admin-inventory-count-add'],
		        title: '添加盘点单'
		    },
		    component: () => import('@/pages/stockManage/inventoryCount/add')
    },
    {
		    path: `${Setting.roterPre}/inventory/statistics`,
		    name: `inventoryStatistics`,
		    meta: {
		        auth: ['admin-inventory-statistics'],
		        title: '库存统计'
		    },
		    component: () => import('@/pages/stockManage/inventoryStatistics/index')
    }
  ]
};
