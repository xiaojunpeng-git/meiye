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

const pre = 'report_';

export default {
  path: `${Setting.roterPre}/report`,
  name: 'report',
  header: 'report',
  meta: {
    // 授权标识
    auth: ['admin-report']
  },
  component: BasicLayout,
  children: [
    {
      path: 'business-center',
      name: `${pre}business_center`,
      meta: {
        auth: ['report-sale-info'],
        title: '门店运营'
      },
      component: () => import('@/pages/report/data/business_hub')
    },
    {
      path: 'store-operations',
      name: `${pre}store_operations`,
      meta: {
        auth: ['report-sale-info'],
        title: '门店运营报表'
      },
      component: () => import('@/pages/report/data/store_business')
    },
    {
      // Each second-level menu item opens the same report workspace with a
      // stable report code. The component is reused and reacts to param
      // changes, so switching reports does not rebuild or refresh the menu.
      path: 'store-operations/:report',
      name: `${pre}store_operations_report`,
      meta: {
        auth: ['report-sale-info'],
        title: '门店运营报表'
      },
      component: () => import('@/pages/report/data/store_business')
    },
    {
      // Legacy URL: keep bookmarked links safe while the seven reports move
      // into direct second-level menu entries.
      path: 'store-business',
      name: `${pre}store_business_legacy`,
      meta: {
        auth: ['report-sale-info'],
        title: '门店业务报表（已迁移）'
      },
      redirect: (to) => ({
        // Use the incoming path so generated agent routes keep their own prefix.
        path: to.path.replace(/\/store-business$/, '/business-center'),
        query: to.query
      })
    },
    {
      path: 'report_sale',
      name: `${pre}report_sale`,
      meta: {
        auth: ['report-sale-info'],
        title: '销售数据'
      },
      component: () => import('@/pages/report/data/sale_report')
    },
    {
      path: 'report_pk',
      name: `${pre}report_pk`,
      meta: {
        auth: ['report-pk-info'],
        title: '销售PK报表'
      },
      component: () => import('@/pages/report/data/pk_report')
    },
    {
      path: 'report_work',
      name: `${pre}report_work`,
      meta: {
        auth: ['report-work-info'],
        title: '销售分析'
      },
      component: () => import('@/pages/report/data/work_report')
    },
    {
      path: 'report_xn',
      name: `${pre}report_xn`,
      meta: {
        auth: ['report-xn-info'],
        title: '门店效能分析'
      },
      component: () => import('@/pages/report/data/xn_report')
    },
    {
      path: 'report_salary',
      name: `${pre}report_salary`,
      meta: {
        auth: ['report-salary-info'],
        title: '工资表'
      },
      component: () => import('@/pages/report/data/salary_report')
    },
    {
      path: 'report_table',
      name: `${pre}report_table`,
      meta: {
        auth: ['report-table-info'],
        title: '其他报表'
      },
      component: () => import('@/pages/report/data/salary_table')
    },
    {
      path: 'report_receive',
      name: `${pre}report_receive`,
      meta: {
        auth: ['report-receive-info'],
        title: '门店对账单'
      },
      component: () => import('@/pages/report/data/report_receive')
    }
  ]
};
