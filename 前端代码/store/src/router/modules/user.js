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

const pre = 'user_';

export default {
    path: `${Setting.routePre}/user/`,
    name: 'user',
    header: 'user',
    meta: {
        // 授权标识
        auth: ['store-user']
    },
    redirect: {
        name: `${pre}user`
    },
    component: BasicLayout,
    children: [
        {
            path: 'index',
            name: `${pre}user`,
            meta: {
                title: '用户列表',
                auth: ['store-user-index']
            },
            component: () => import('@/pages/user')
        },
        {
            path: 'label/index',
            name: `${pre}userlabel`,
            meta: {
                title: '用户标签',
                auth: ['store-user-label-index']
            },
            component: () => import('@/pages/user/label')
        },
    ]
};
