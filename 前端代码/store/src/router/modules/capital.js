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

const pre = 'capital_';

export default {
    path: `${Setting.routePre}/finance/`,
    name: 'capital',
    header: 'capital',
    meta: {
        // 授权标识
        auth: ['store-finance']
    },
    redirect: {
        name: `${pre}capital`
    },
    component: BasicLayout,
    children: [
        {
            path: `${Setting.routePre}/capital/index`,
            name: `${pre}capital`,
            meta: {
                title: '门店流水',
                auth: ['store-capital-index']
            },
            component: () => import('@/pages/capital/index')
        },
		{
		    path: `${Setting.routePre}/bill/index`,
		    name: `${pre}bill`,
		    meta: {
		        title: '账单记录',
		        auth: ['store-bill-index']
		    },
		    component: () => import('@/pages/capital/bill')
		},
		{
		    path: `${Setting.routePre}/cash/index`,
		    name: `${pre}cash`,
		    meta: {
		        title: '转账申请',
		        auth: ['store-cash-index']
		    },
		    component: () => import('@/pages/capital/cash')
		},
		{
		    path: `${Setting.routePre}/finance/set`,
		    name: `${pre}setting`,
		    meta: {
		        title: '财务设置',
		        auth: ['store-finance-set']
		    },
		    component: () => import('@/pages/capital/setting')
		}
    ]
};
