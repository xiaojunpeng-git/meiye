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

const pre = 'order_';

export default {
	path: `${Setting.routePre}/order/`,
	name: 'order',
	header: 'order',
	meta: {
	    // 授权标识
	    auth: ['store-order']
	},
	redirect: {
		name: `${pre}list`
	},
	component: BasicLayout,
	children: [
		{
			path: 'index',
			name: `${pre}list`,
			meta: {
				auth: ['store-order-index'],
				title: '订单列表'
			},
			component: () => import('@/pages/order/orderList/index')
		},
		{
			path: 'split_list',
			name: `${pre}split_list`,
			meta: {
				auth: ['order_split_order'],
				title: '子订单列表'
			},
			component: () => import('@/pages/order/orderList/splitList.vue')
		},
		{
			path: 'refund',
			name: `${pre}refund`,
			meta: {
				auth: ['store-order-refund'],
				title: '售后退款'
			},
			component: () => import('@/pages/order/refund/index')
		},
		{
			path: `${Setting.routePre}/recharge/index`,
			name: `${pre}recharge`,
			meta: {
				auth: ['store-recharge-index'],
				title: '充值订单'
			},
			component: () => import('@/pages/order/recharge/index')
		},
		{
			path: `${Setting.routePre}/vip/index`,
			name: `${pre}vip`,
			meta: {
				auth: ['store-vip-index'],
				title: '付费会员订单'
			},
			component: () => import('@/pages/order/vip/index')
		},
    {
			path: `writeoff/index`,
			name: `${pre}writeoff`,
			meta: {
				auth: ['store-order-writeoff'],
				title: '核销记录'
			},
			component: () => import('@/pages/order/writeoff/index')
		},
		{
			path: 'debt/index',
			name: `${pre}debt`,
			meta: {
				auth: ['store-order-debt'],
				title: '欠款管理'
			},
			component: () => import('@/pages/order/debt/index')
		},
	]
};
