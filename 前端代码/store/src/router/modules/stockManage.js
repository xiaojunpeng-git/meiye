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
		    name: `inboundAdd`,
		    meta: {
		        auth: ['store-inbound-manage-add'],
		        title: '添加入库单'
		    },
		    component: () => import('@/pages/stockManage/inboundManage/add')
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
		    name: `outboundAdd`,
		    meta: {
		        auth: ['store-outbound-manage-add'],
		        title: '添加出库单'
		    },
		    component: () => import('@/pages/stockManage/outboundManage/add')
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
		    name: `inventoryAdd`,
		    meta: {
		        auth: ['store-inventory-count-add'],
		        title: '添加盘点单'
		    },
		    component: () => import('@/pages/stockManage/inventoryCount/add')
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
		        auth: ['store-stock-request'],
		        title: '请货管理'
		    },
		    component: () => import('@/pages/stockManage/stockRequestManage/list')
		},
		{
		    path: `${Setting.routePre}/stock/request/add/:id?`,
		    name: `stockRequestAdd`,
		    meta: {
		        auth: ['store-stock-request-add'],
		        title: '请货单'
		    },
		    component: () => import('@/pages/stockManage/stockRequestManage/add')
		},
		{
		    path: `${Setting.routePre}/stock/transfer`,
		    name: `stockTransferManage`,
		    meta: {
		        auth: ['store-stock-transfer'],
		        title: '调拨管理'
		    },
		    component: () => import('@/pages/stockManage/stockTransferManage/list')
		},
		{
		    path: `${Setting.routePre}/stock/transfer/add/:id?`,
		    name: `stockTransferAdd`,
		    meta: {
		        auth: ['store-stock-transfer-add'],
		        title: '调拨单'
		    },
		    component: () => import('@/pages/stockManage/stockTransferManage/add')
		}
    ]
};
