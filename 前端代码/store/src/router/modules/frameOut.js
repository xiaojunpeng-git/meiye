// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import Setting from "@/setting";
const pre = 'kefu_';

export default [
    // 登录
    {
        path: `${Setting.routePre}/login`,
        name: 'login',
        meta: {
            title: '$t:page.login.title'
        },
        component: () => import('@/pages/account/login')
    }
]
