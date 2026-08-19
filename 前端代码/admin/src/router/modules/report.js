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
import { SIX_DIMENSION_REPORTS } from '@/libs/sixDimensionReports';
import { PHASE_FOUR_OPERATION_REPORTS, PHASE_FOUR_CROSS_END_REPORTS } from '@/libs/phaseFourReports';
import { PHASE_SIX_REPORTS } from '@/libs/phaseSixReports';

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
    ...PHASE_SIX_REPORTS.map(report => ({
      path: `other-reports/${report.code}`,
      name: `${pre}${report.code}`,
      meta: {
        auth: [`admin-report-phase-six-${report.code}`],
        title: report.title,
        reportCode: report.code,
        phaseSixReport: true
      },
      component: () => import('@/pages/report/data/store_business')
    })),
    {
      path: 'group-management-dashboard',
      name: `${pre}group_management_dashboard`,
      meta: {
        auth: ['admin-report-group-management-dashboard'],
        title: '集团管理看板'
      },
      component: () => import('@/pages/report/data/group_management_dashboard')
    },
    {
      path: 'member-management-dashboard',
      name: `${pre}member_management_dashboard`,
      meta: {
        auth: ['admin-report-member-management-dashboard'],
        title: '会员看板'
      },
      component: () => import('@/pages/report/data/member_management_dashboard')
    },
    {
      // Compatibility route for a second menu entry under another parent.
      // The page, API and permission remain shared with the canonical route.
      path: 'member-management-dashboard-customer',
      name: `${pre}member_management_dashboard_customer`,
      meta: {
        auth: ['admin-report-member-management-dashboard'],
        title: '会员看板'
      },
      component: () => import('@/pages/report/data/member_management_dashboard')
    },
    {
      // 每个稳定报表 code 都形成独立 URL；页面实现统一由 Vue 3 报表运行时承载。
      path: 'store-operations/:report',
      name: `${pre}store_operations_report`,
      meta: {
        // The report code is the authorization boundary. The page and export
        // APIs perform the concrete report permission check after resolving
        // :report; inheriting the legacy sales permission here would allow an
        // unrelated sales role to enter every store-operations report.
        title: '门店运营报表'
      },
      component: () => import('@/pages/report/data/store_business')
    },
    ...SIX_DIMENSION_REPORTS.map(report => ({
      path: `six-dimension-center/${report.code}`,
      name: `${pre}${report.code}`,
      meta: {
        auth: [`admin-report-six-dimension-${report.code}`],
        title: report.title,
        reportCode: report.code
      },
      component: () => import('@/pages/report/data/six_dimension')
    })),
    ...PHASE_FOUR_OPERATION_REPORTS.map(report => ({
      path: `operations-center/${report.code}`,
      name: `${pre}${report.code}`,
      meta: {
        auth: [`admin-report-phase-four-${report.code}`],
        title: report.title,
        reportCode: report.code,
        phaseFourReport: true
      },
      component: () => import('@/pages/report/data/six_dimension')
    })),
    ...PHASE_FOUR_CROSS_END_REPORTS.map(report => ({
      path: `finance-center/front-desk/${report.code}`,
      name: `${pre}${report.code}`,
      meta: {
        auth: [`admin-report-phase-four-${report.code}`],
        title: report.title,
        reportCode: report.code,
        phaseFourReport: true
      },
      component: () => import('@/pages/report/data/six_dimension')
    })),
    {
      // Legacy URL: keep bookmarked links safe while the six reports move
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
