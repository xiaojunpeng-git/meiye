import BasicLayout from '@/layouts/basic-layout';
import Setting from "@/setting";

const pre = 'report_';

export default {
    path: `${Setting.routePre}/report/`,
    name: 'report',
    header: 'report',
    meta: {
        // 授权标识
        auth: ['store-report']
    },
    redirect: {
        name: `${pre}reportList`
    },
    component: BasicLayout,
    children: [
        {
            path: 'index',
            name: `${pre}reportList`,
            meta: {
                title: '订单报表',
                auth: ['store-report-index']
            },
            component: () => import('@/pages/report/orderData')
        },
        {
            path: `${Setting.routePre}/report/salary`,
            name: `${pre}salary_report`,
            meta: {
                title: '工资列表',
                auth: ['store-report-salary']
            },
            component: () => import('@/pages/report/salary_report')
        },
        {
            path: `${Setting.routePre}/report/salary_table`,
            name: `${pre}salary_report_table`,
            meta: {
                title: '其他报表',
                auth: ['store-report-table']
            },
            component: () => import('@/pages/report/salary_table')
        }
     ]
};
