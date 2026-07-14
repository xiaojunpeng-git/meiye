import BasicLayout from '@/layouts/basic-layout';
import Setting from '@/setting';
import TargetDataAnalysis from '@/pages/target/data_analysis/index.vue';
import TargetMonthlyDetail from '@/pages/target/monthly_detail/index.vue';
import TargetStoreDetail from '@/pages/target/store_detail/index.vue';

const pre = 'target_';
const targetBase = `${Setting.roterPre}/target`;
const targetAnalysisPath = `${targetBase}/data_analysis`;

export default {
  path: targetBase,
  name: 'target',
  header: 'target',
  redirect: {
    name: `${pre}data_analysis`
  },
  meta: {
    auth: ['admin-target']
  },
  component: BasicLayout,
  beforeEnter(to, from, next) {
    const legacyPath = `${targetBase}/data-analysis`;
    if (to.path === targetBase || to.path === `${targetBase}/` || to.path === legacyPath) {
      return next({ path: targetAnalysisPath, replace: true });
    }
    next();
  },
  children: [
    {
      path: 'data_analysis',
      alias: 'data-analysis',
      name: `${pre}data_analysis`,
      meta: {
        auth: ['admin-target', 'admin-target-data-analysis'],
        title: '目标数据分析'
      },
      component: TargetDataAnalysis
    },
    {
      path: 'monthly_detail',
      alias: 'monthly-detail',
      name: `${pre}monthly_detail`,
      meta: {
        auth: ['admin-target', 'admin-target-data-analysis'],
        title: '月度目标完成明细'
      },
      component: TargetMonthlyDetail
    },
    {
      path: 'store_detail',
      alias: 'store-detail',
      name: `${pre}store_detail`,
      meta: {
        auth: ['admin-target', 'admin-target-data-analysis'],
        title: '门店目标完成明细'
      },
      component: TargetStoreDetail
    }
  ]
};
