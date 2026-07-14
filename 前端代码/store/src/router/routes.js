// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import index from './modules/index';
import product from './modules/product';
import report from './modules/report';
import order from './modules/order';
import staff from './modules/staff';
import BasicLayout from '@/layouts/basic-layout';
import frameOut from './modules/frameOut'
import capital from './modules/capital';
import user from './modules/user';
import marketing from './modules/marketing';
import set from './modules/set';
import stockManage from "./modules/stockManage";
import Setting from "@/setting";

/**
 * 在主框架内显示
 */

const frameIn = [{
		path: `/`,
		meta: {
			title: 'MOHE'
		},
		redirect: {
			name: 'home_index'
		},
		component: BasicLayout,
		children: [

			// 刷新页面 必须保留
			{
				path: 'refresh',
				name: 'refresh',
				hidden: true,
				component: {
					beforeRouteEnter(to, from, next) {
						next(instance => instance.$router.replace(from.fullPath));
					},
					render: h => h()
				}
			},
			// 页面重定向 必须保留
			{
				path: 'redirect/:route*',
				name: 'redirect',
				hidden: true,
				component: {
					beforeRouteEnter(to, from, next) {
						next(instance => instance.$router.replace(JSON.parse(from.params.route)));
					},
					render: h => h()
				}
			}
		],
    alias: `${Setting.routePre}/`
	},
	{
	    path: `${Setting.routePre}/system/user`,
	    name: `systemUser`,
	    meta: {
	        auth: true,
	        title: '个人中心'
	    },
	    component: () => import('@/pages/setting/user/index')
	},
	{
		path: `${Setting.routePre}/order/create`,
		name: `${pre}offline`,
		meta: {
			auth: ['store-order-create'],
			title: '收银台'
		},
		component: () => import('@/pages/order/cashier/index')
	},
	{
		path: `${Setting.routePre}/order/distribution`,
		name: `distribution`,
		meta: {
			auth: true,
			title: '配货单'
		},
		component: () => import('@/pages/order/distribution')
	},
	{
		path: `${Setting.routePre}/video.shortVideo/index.html`,
		name: `shortVideo`,
		meta: {
			title: '选择视频'
		},
		component: () => import('@/components/shortVideoList/index')
	},
	{
		path: `${Setting.routePre}/system.User/list.html`,
		name: `changeUser`,
		meta: {
			title: '选择用户'
		},
		component: () => import('@/components/userList/index')
	},
	{
		path: `${Setting.routePre}/widget.images/index.html`,
		name: `images`,
		meta: {
			auth: true,
			title: '上传图片'
		},
		component: () => import('@/components/uploadPictures/widgetImg')
	},
	index,
	product,
	report,
	order,
	staff,
	capital,
	set,
	user,
	marketing,
	stockManage
];

/**
 * 在主框架之外显示
 */
const pre = 'kefu_';

const frameOuts = frameOut;




/**
 * 错误页面
 */

const errorPage = [
	{
        path: `${Setting.routePre}/other`,
        name: 'other',
        meta: {
            title: 'other'
        },
        component: () => import('@/pages/system/error/other')
    },{
		path: `${Setting.routePre}/403`,
		name: '403',
		meta: {
			title: '403'
		},
		component: () => import('@/pages/system/error/403')
	},
	{
		path: `${Setting.routePre}/500`,
		name: '500',
		meta: {
			title: '500'
		},
		component: () => import('@/pages/system/error/500')
	},
	{
		path: `${Setting.routePre}/*`,
		name: '404',
		meta: {
			title: '404'
		},
		component: () => import('@/pages/system/error/404')
	}
];

// 导出需要显示菜单的
export const frameInRoutes = frameIn;

// 重新组织后导出
export default [
	...frameIn,
	...frameOuts,
	...errorPage
];
