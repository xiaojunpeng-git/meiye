// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import Setting from '@/setting';

export default [
    // 登录
    {
        path: `${Setting.roterPre}/login`,
        name: 'login',
        meta: {
            title: '$t:page.login.title'
        },
        component: () => import('@/pages/account/login')
    },
    //副屏登录
    {
        path: `${Setting.roterPre}/auxScreen/login`,
        name: 'login',
        meta: {
            title: '登录'
        },
        component: () => import('@/pages/auxScreen/login')
    },
    //副屏首页
    {
        path: `${Setting.roterPre}/auxScreen/index`,
        name: 'auxScreen',
        meta: {
            title: '副屏'
        },
        component: () => import('@/pages/auxScreen/index')
    }
]
