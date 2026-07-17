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
import Setting from "@/setting";

export default {
    path: `${Setting.routePre}/stock/manage`,
    name: 'stockManage',
    header: 'stockManage',
    // 父菜单 path 无独立页面；落到空壳会白屏，默认进入库列表
    redirect: `${Setting.routePre}/inbound/manage`,
    component: BasicLayout,
    children: [
        {
            path: `${Setting.routePre}/inbound/manage`,
            name: `inboundManage`,
            meta: {
                auth: ['store-inbound-manage'],
                title: '入库管理'
            },
            component: () => import('@/pages/stockManage/inboundManage/list')
        },
		{
		    path: `${Setting.routePre}/inbound/manage/add/:id?`,
		    redirect: `${Setting.routePre}/inbound/manage`
		},
		{
		    path: `${Setting.routePre}/outbound/manage`,
		    name: `outboundManage`,
		    meta: {
		        auth: ['store-outbound-manage'],
		        title: '出库管理'
		    },
		    component: () => import('@/pages/stockManage/outboundManage/list')
		},
		{
		    path: `${Setting.routePre}/outbound/manage/add/:id?`,
		    redirect: `${Setting.routePre}/outbound/manage`
		},
		{
		    path: `${Setting.routePre}/inventory/details`,
		    name: `inventoryDetails`,
		    meta: {
		        auth: ['store-inventory-details'],
		        title: '出入库明细列表'
		    },
		    component: () => import('@/pages/stockManage/inventoryDetails/list')
		},
		{
		    path: `${Setting.routePre}/inventory/details/info`,
		    name: `inventoryInfo`,
		    meta: {
		        auth: ['store-inventory-details-info'],
		        title: '出入库明细'
		    },
		    component: () => import('@/pages/stockManage/inventoryDetails/details')
		},
		{
		    path: `${Setting.routePre}/inventory/count`,
		    name: `inventoryCount`,
		    meta: {
		        auth: ['store-inventory-count'],
		        title: '库存盘点'
		    },
		    component: () => import('@/pages/stockManage/inventoryCount/list')
		},
		{
		    path: `${Setting.routePre}/inventory/count/add/:id?`,
		    redirect: `${Setting.routePre}/inventory/count`
		},
		{
		    path: `${Setting.routePre}/inventory/statistics`,
		    name: `inventoryStatistics`,
		    meta: {
		        auth: ['store-inventory-statistics'],
		        title: '库存统计'
		    },
		    component: () => import('@/pages/stockManage/inventoryStatistics/index')
		},
		{
		    path: `${Setting.routePre}/stock/import/record`,
		    name: `stockImportRecord`,
		    meta: {
		        auth: ['store-inbound-manage'],
		        title: '导入记录'
		    },
		    component: () => import('@/pages/stockManage/importRecord')
		},
		{
		    path: `${Setting.routePre}/stock/request`,
		    name: `stockRequestManage`,
		    meta: {
		        auth: ['store-stock-request', 'store-stock-manage'],
		        title: '请货管理'
		    },
		    component: () => import('@/pages/stockManage/stockRequestManage/list')
		},
		{
		    // 编辑页已改为列表内弹窗；菜单残留 /add 时回到列表
		    path: `${Setting.routePre}/stock/request/add/:id?`,
		    redirect: `${Setting.routePre}/stock/request`
		},
		{
		    path: `${Setting.routePre}/stock/transfer`,
		    name: `stockTransferManage`,
		    meta: {
		        auth: ['store-stock-transfer', 'store-stock-manage'],
		        title: '调拨管理'
		    },
		    component: () => import('@/pages/stockManage/stockTransferManage/list')
		},
		{
		    path: `${Setting.routePre}/stock/transfer/add/:id?`,
		    redirect: `${Setting.routePre}/stock/transfer`
		},
		// 项目配方仅总部维护；门店端路由下线（存量菜单权限亦由升级 SQL 隐藏）
		{
		    path: `${Setting.routePre}/stock/salon/usage`,
		    name: `salonUsageReport`,
		    meta: {
		        auth: ['store-salon-usage', 'store-stock-manage'],
		        title: '院装管理'
		    },
		    component: () => import('@/pages/stockManage/salonUsage/index')
		}
    ]
};
