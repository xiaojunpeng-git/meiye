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
  // 新库存 Vue3 工作台承接平台库存菜单；旧 Vue2 页面保留冻结，不再承载新功能。
  redirect: `${Setting.roterPre}/stock/manage/home`,
  component: BasicLayout,
  children: [
    {
      path: `${Setting.roterPre}/stock/manage/home`, name: 'stockManageV3Home',
      meta: { auth: ['admin-stock-manage'], title: '库存首页' },
      component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
      path: `${Setting.roterPre}/inbound/manage`,
      name: `inboundManage`,
      meta: {
        auth: ['admin-inbound-manage'],
        title: '入库管理'
      },
      component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/inbound/manage/add/:id?`,
		    redirect: `${Setting.roterPre}/inbound/manage`
    },
    {
		    path: `${Setting.roterPre}/outbound/manage`,
		    name: `outboundManage`,
		    meta: {
		        auth: ['admin-outbound-manage'],
		        title: '出库管理'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/outbound/manage/add/:id?`,
		    redirect: `${Setting.roterPre}/outbound/manage`
    },
    {
		    path: `${Setting.roterPre}/inventory/details`,
		    name: `inventoryDetails`,
		    meta: {
		        auth: ['admin-inventory-details'],
		        title: '出入库明细列表'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/inventory/details/info`,
		    name: `inventoryInfo`,
		    meta: {
		        auth: ['admin-inventory-details-info'],
		        title: '出入库明细'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/inventory/count`,
		    name: `inventoryCount`,
		    meta: {
		        auth: ['admin-inventory-count'],
		        title: '库存盘点'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/inventory/count/add/:id?`,
		    redirect: `${Setting.roterPre}/inventory/count`
    },
    {
		    path: `${Setting.roterPre}/inventory/statistics`,
		    name: `inventoryStatistics`,
		    redirect: `${Setting.roterPre}/inventory/details`
    },
    {
		    path: `${Setting.roterPre}/stock/request`,
		    name: `stockRequestManage`,
		    meta: {
		        auth: ['admin-stock-request', 'admin-stock-manage'],
		        title: '请货管理'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/stock/request/add/:id?`,
		    redirect: `${Setting.roterPre}/stock/request`
    },
    {
		    path: `${Setting.roterPre}/stock/transfer`,
		    name: `stockTransferManage`,
		    meta: {
		        auth: ['admin-stock-transfer', 'admin-stock-manage'],
		        title: '调拨管理'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/stock/transfer/add/:id?`,
		    redirect: `${Setting.roterPre}/stock/transfer`
    },
    {
		    path: `${Setting.roterPre}/stock/recipe`,
		    name: `salonRecipeManage`,
		    meta: {
		        auth: ['admin-salon-recipe', 'admin-stock-manage'],
		        title: '项目配方'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    },
    {
		    path: `${Setting.roterPre}/stock/recipe/add/:id?`,
		    redirect: `${Setting.roterPre}/stock/recipe`
    },
    {
		    path: `${Setting.roterPre}/stock/salon/usage`,
		    name: `salonUsageReport`,
		    meta: {
		        auth: ['admin-salon-usage', 'admin-stock-manage'],
		        title: '院装管理'
		    },
		    component: () => import('@/pages/stockManage/InventoryV3Bridge')
    }
  ]
};
