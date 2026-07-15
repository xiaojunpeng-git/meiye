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

const pre = 'staff_';

export default {
    path: `${Setting.routePre}/staff/`,
    name: 'staff',
    header: 'staff',
    meta: {
        // 授权标识
        auth: ['store-staff']
    },
    redirect: {
        name: `${pre}clerkList`
    },
    component: BasicLayout,
    children: [
        {
            path: 'index',
            name: `${pre}clerkList`,
            meta: {
                auth: ['store-staff-index'],
                title: '店员列表'
            },
            component: () => import('@/pages/staff/clerkList')
        },
		{
		    path: `clerkList/add/:id?`,
		    redirect: 'index'
		},
        {
            path: 'statistics',
            name: `${pre}achievement`,
            meta: {
                auth: ['store-staff-statistics'],
                title: '业绩统计'
            },
            component: () => import('@/pages/staff/achievement')
        },
        {
            path: 'handover',
            name: `${pre}handover`,
            meta: {
                auth: ['store-staff-handover'],
                title: '交班记录'
            },
            component: () => import('@/pages/staff/handover/index')
        },
        {
            path: 'schedule',
            name: `${pre}schedule`,
            meta: {
                auth: ['store-staff-index'],
                title: '排班管理'
            },
            component: () => import('@/pages/staff/schedule/index')
        },
		{
		    path: `${Setting.routePre}/delivery/index`,
		    name: `${pre}deliveryClerk`,
		    meta: {
		        auth: ['store-delivery-index'],
		        title: '配送员列表'
		    },
		    component: () => import('@/pages/staff/deliveryClerk')
		},
		{
		    path: `${Setting.routePre}/delivery/statistics`,
		    name: `${pre}statistics`,
		    meta: {
		        auth: ['store-delivery-statistics'],
		        title: '配送统计'
		    },
		    component: () => import('@/pages/staff/bill')
		},
    ]
};
