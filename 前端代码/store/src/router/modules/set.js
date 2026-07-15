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

const pre = 'set_';

export default {
	path: `${Setting.routePre}/set/`,
	name: 'set',
	header: 'set',
	meta: {
		// 授权标识
		auth: ['store-set']
	},
	redirect: {
		name: `${pre}set`
	},
	component: BasicLayout,
	children: [{
			path: 'store',
			name: `${pre}set`,
			meta: {
				title: '门店设置',
				auth: ['store-set-store']
			},
			component: () => import('@/pages/setting/index')
		},
		{
			path: 'index',
			name: `${pre}setting`,
			meta: {
				title: '电子面单打印',
				auth: ['store-set-index']
			},
			props: {
			    typeMole: 'third'
			},
			component: () => import('@/components/fromSubmit/commonForm.vue')
		},
		{
			path: 'hardware/ticket',
			name: `${pre}ticket`,
			meta: {
				title: '小票打印',
				auth: ['store-set-hardware-ticket']
			},
			component: () => import('@/pages/setting/ticket/index')
		},
		{
			path: 'delivery/record',
			name: `${pre}deliveryRecord`,
			meta: {
				title: '配送记录',
				auth: ['store-set-delivery-record']
			},
			component: () => import('@/pages/setting/deliveryRecord/index')
		},
		{
			path: `${Setting.routePre}/admin/index`,
			name: `${pre}admin`,
			meta: {
				title: '管理员列表',
				auth: ['store-admin-index']
			},
			component: () => import('@/pages/setting/admin/index')
		},
		{
			path: `${Setting.routePre}/admin/system_role`,
			name: `${pre}role`,
			meta: {
				title: '角色管理',
				auth: ['store-admin-system_role']
			},
			component: () => import('@/pages/setting/admin/role')
		},
		{
		    path: `${Setting.routePre}/admin/system_role/add/:id?`,
		    name: `${pre}roleAdd`,
		    meta: {
		        title: '添加身份',
		        auth: ['admin-system_role-add'],
		    },
		    component: () => import('@/pages/setting/admin/role/add')
		},
    {
			path: `${Setting.routePre}/set/table_code/index`,
			name: `${pre}table_code`,
			meta: {
				title: '房间设置',
				auth: ['store-set-table_code-index']
			},
			component: () => import('@/pages/setting/tableCode/index')
		},
    {
			path: `${Setting.routePre}/set/table_code/classify`,
			name: `${pre}tableCodeClassify`,
			meta: {
				title: '房间分类',
				auth: ['store-set-table_code-classify']
			},
			component: () => import('@/pages/setting/tableCode/classify')
		},
    {
			path: `${Setting.routePre}/set/table_code/list`,
			name: `${pre}tableCodeList`,
			meta: {
				title: '房间列表',
				auth: ['store-set-table_code-list']
			},
			component: () => import('@/pages/setting/tableCode/list')
		},
    {
			path: 'hardware/content/:id',
			name: `${pre}content`,
			meta: {
				title: '小票配置',
				auth: ['store-set-hardware-content']
			},
			component: () => import('@/pages/setting/ticket/content')
		},
    {
			path: 'city_delivery/index',
			name: `${pre}city_delivery`,
			meta: {
				title: '配送设置',
				auth: ['store-set-city_delivery']
			},
			component: () => import('@/pages/setting/cityDelivery/index')
		},
		{
			path: 'changelog',
			name: `${pre}changelog`,
			meta: {
				title: '更新日志',
				auth: ['store-set-changelog']
			},
			component: () => import('@/pages/setting/changelog/index')
		},
		{
			path: 'training/document',
			name: `${pre}trainingDocument`,
			meta: {
				title: '培训资料',
				auth: ['store-set-training-document']
			},
			component: () => import('@/pages/setting/trainingDocument/index')
		},
	]
};
