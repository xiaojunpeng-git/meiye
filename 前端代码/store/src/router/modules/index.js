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

const meta = {
    auth: true
};

const pre = 'home_';

export default {
    path: `${Setting.routePre}/home`,
    name: 'home',
    header: 'home',
    redirect: {
        name: `${pre}index`
    },
    meta,
    component: BasicLayout,
    children: [
        {
            path: 'index',
            name: `${pre}index`,
            meta: {
                auth: ['store-statistics-index'],
                title: '运营概况'
            },
            component: () => import('@/pages/index/index')
        },
        {
            path: 'reservation-detail',
            name: `${pre}reservationDetail`,
            meta: {
                auth: ['store-statistics-index'],
                title: '经营明细-预约客'
            },
            component: () => import('@/pages/index/reservation-detail')
        },
        {
            path: 'new-profile-detail',
            name: `${pre}newProfileDetail`,
            meta: {
                auth: ['store-statistics-index'],
                title: '经营明细-新建档'
            },
            component: () => import('@/pages/index/new-profile-detail')
        },
        {
            path: 'source-customer-detail',
            name: `${pre}sourceCustomerDetail`,
            meta: {
                auth: ['store-statistics-index'],
                title: '经营明细-散客新客'
            },
            component: () => import('@/pages/index/source-customer-detail')
        },
        {
            path: 'money-detail',
            name: `${pre}moneyDetail`,
            meta: {
                auth: ['store-statistics-index'],
                title: '经营明细-金额'
            },
            component: () => import('@/pages/index/money-detail')
        }
    ]
};
