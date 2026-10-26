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

const pre = 'user_';

// 客户分析原型页面目录。每个页面保持独立路由和权限标识，页面内容由
// Vue 3 报表运行时承载；本阶段仅使用前端模拟数据。
const CUSTOMER_ANALYTICS_REPORTS = Object.freeze([
  ['overview', 'customer_overview', '客户概况'],
  ['source-analysis', 'customer_source_analysis', '客户来源分析'],
  ['visit-analysis', 'customer_visit_analysis', '到店数据分析'],
  ['store-health', 'customer_store_health', '门店健康数据分析'],
  ['consumption-tier', 'customer_consumption_tier', '消费分级分析'],
  ['cash-performance', 'customer_cash_performance', '现金业绩分析'],
  ['refund-performance', 'customer_refund_performance', '退货业绩分析'],
  ['item-analysis', 'customer_item_analysis', '客户品相分析'],
  ['unconsumed-analysis', 'customer_unconsumed_analysis', '客户未耗分析']
]);

export default {
  path: `${Setting.roterPre}/user`,
  name: 'user',
  header: 'user',
  // redirect: {
  //     name: `${pre}list`
  // },
  meta,
  component: BasicLayout,
  children: [
    // {
    //   path: `${Setting.roterPre}/user/list`,
    //   name: `${pre}list`,
    //   meta: {
    //     auth: ["admin-user-user-index"],
    //     title: "用户列表",
    //   },
    //   component: () => import("@/pages/user/list/index"),
    // },
    {
      path: 'customer-analytics',
      name: `${pre}customer_analytics`,
      meta: {
        auth: ['admin-customer-analytics'],
        title: '客户分析'
      },
      redirect: {
        name: `${pre}customer_overview`
      }
    },
    ...CUSTOMER_ANALYTICS_REPORTS.map(([path, code, title]) => ({
      path: `customer-analytics/${path}`,
      name: `${pre}${code}`,
      meta: {
        auth: [`admin-customer-analytics-${code}`],
        title,
        customerAnalytics: true,
        // The embedded Vue 3 runtime uses the stable URL slug. Keep the
        // permission code independent from the presentation route code.
        reportCode: path
      },
      component: () => import('@/pages/report/data/customer_analytics')
    })),
    {
      path: `list`,
      name: `${pre}list`,
      meta: {
        auth: ['admin-user-user-index'],
        title: '用户列表'
      },
      component: () => import('@/pages/user/list/index')
    },
    {
      path: `${Setting.roterPre}/kefu/setup`,
      name: `${pre}kefuSetUp`,
      meta: {
        auth: ['admin-kefu-setup'],
        title: '客服设置'
      },
      props: {
        typeMole: 'kefu'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: `${Setting.roterPre}/vipuser/level/list`,
      name: `${pre}level`,
      meta: {
        auth: ['user-user-level'],
        footer: true,
        title: '会员等级'
      },
      component: () => import('@/pages/user/level/index')
    },
    {
      path: `${Setting.roterPre}/vipuser/level/setup`,
      name: `${pre}levelSetup`,
      meta: {
        auth: ['vipuser-level-setup'],
        footer: true,
        title: '等级设置'
      },
      props: {
        typeMole: 'vip'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/setup`,
      name: `${pre}levelsetup`,
      meta: {
        auth: ['vipuser-grade-setup'],
        footer: true,
        title: '会员设置'
      },
      props: {
        typeMole: 'svip'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: `${Setting.roterPre}/user/group`,
      name: `${pre}group`,
      meta: {
        auth: ['user-user-group'],
        footer: true,
        title: '用户分组'
      },
      component: () => import('@/pages/user/group/index')
    },
    {
      path: `${Setting.roterPre}/user/label/`,
      name: `${pre}label`,
      meta: {
        auth: ['user-user-label'],
        footer: true,
        title: '用户标签'
      },
      component: () => import('@/pages/user/label/cate')
    },
    {
      path: `${Setting.roterPre}/user/recharge/:id`,
      name: `${pre}recharge`,
      meta: {
        auth: ['user-user-recharge'],
        footer: true,
        title: '充值配置'
      },
      component: () => import('@/pages/system/group/list')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/type`,
      name: `${pre}type`,
      meta: {
        auth: ['admin-user-member-type'],
        footer: true,
        title: '会员类型'
      },
      component: () => import('@/pages/user/grade/type/index')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/card`,
      name: `${pre}card`,
      meta: {
        auth: ['admin-user-grade-card'],
        footer: true,
        title: '卡密会员'
      },
      component: () => import('@/pages/user/grade/card/index')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/record`,
      name: `${pre}record`,
      meta: {
        auth: ['admin-user-grade-record'],
        footer: true,
        title: '会员记录'
      },
      component: () => import('@/pages/user/grade/record/index')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/right`,
      name: `${pre}right`,
      meta: {
        auth: ['admin-user-grade-right'],
        footer: true,
        title: '会员权益'
      },
      component: () => import('@/pages/user/grade/right/index')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/list/:id`,
      name: `${pre}gradelist`,
      meta: {
        auth: ['user-member_card-index'],
        footer: true,
        title: '会员卡列表'
      },
      component: () => import('@/pages/user/grade/card/list')
    },
    {
      path: `${Setting.roterPre}/vipuser/grade/agreement`,
      name: `${pre}agreement`,
      meta: {
        auth: ['admin-user-grade-agreement'],
        footer: true,
        title: '会员协议'
      },
      component: () => import('@/pages/user/grade/agreement/index')
    },
    {
      path: 'setup_user',
      name: `${pre}setup_user`,
      meta: {
        auth: ['user-user-setup_user'],
        title: '用户设置'
      },
      props: {
        typeMole: 'user'
      },
      component: () => import('@/pages/user/setupUser/index.vue')
    },
    {
      path: `${Setting.roterPre}/operation/newcomer`,
      name: `${pre}newcomer`,
      meta: {
        auth: ['user-user-newcomer'],
        title: '新人礼包'
      },
      props: {
        typeMole: 'user'
      },
      component: () => import('@/pages/user/operation/newcomer.vue')
    },
    {
      path: `${Setting.roterPre}/operation/card`,
      name: `${pre}card`,
      meta: {
        auth: ['admin-operation-card'],
        title: '开卡礼包'
      },
      props: {
        typeMole: 'user'
      },
      component: () => import('@/pages/user/operation/card.vue')
    }
    // {
    //     path: 'grade',
    //     name: `${pre}grade`,
    //     meta: {
    //         auth: ['user-user-grade'],
    //         footer: true,
    //         title: '卡密等级'
    //     },
    //     component: () => import('@/pages/user/grade/index')
    // },
    // {
    //     path: 'grade/card/:id',
    //     name: `${pre}card`,
    //     meta: {
    //         auth: ['user-member_card-index'],
    //         footer: true,
    //         title: '卡密列表'
    //     },
    //     component: () => import('@/pages/user/grade/card')
    // },
    // {
    //     path: 'member/type',
    //     name: `${pre}memberType`,
    //     meta: {
    //         auth: ['admin-user-member-type'],
    //         footer: true,
    //         title: '会员分类'
    //     },
    //     component: () => import('@/pages/user/grade/type')
    // }
  ]
};
