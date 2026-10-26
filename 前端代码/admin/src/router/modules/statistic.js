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
import Setting from '@/setting';
const meta = {
  auth: true
};

const pre = 'statistic_';

export default {
  path: `${Setting.roterPre}/statistic`,
  name: 'statistic',
  header: 'statistic',
  // redirect: {
  //     name: `${pre}product`
  // },
  component: BasicLayout,
  children: [
    {
      path: 'product',
      name: `${pre}product`,
      meta: {
        title: '商品统计'
      },
      component: () => import('@/pages/statistic/product/index')
    },
    {
      path: 'user',
      name: `${pre}user`,
      meta: {
        title: '用户统计'
      },
      component: () => import('@/pages/statistic/user/index')
    },
    {
      path: 'transaction',
      name: `${pre}transaction`,
      meta: {
        title: '交易统计'
      },
      component: () => import('@/pages/statistic/transaction/index')
    },
    {
      path: 'capital',
      name: `${pre}capital`,
      meta: {
        auth: ['admin-statistic-capital'],
        title: '资金流水'
      },
      component: () => import('@/pages/statistic/capital/index')
    },
    {
      path: 'order',
      name: `${pre}order`,
      meta: {
        title: '订单统计'
      },
      component: () => import('@/pages/statistic/order/index')
    },
    {
      path: 'balance',
      name: `${pre}balance`,
      meta: {
        title: '余额统计'
      },
      component: () => import('@/pages/statistic/balance/index')
    }
  ]
};
