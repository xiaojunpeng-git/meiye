import BasicLayout from '@/layouts/basic-layout';
import Setting from '@/setting';
import TargetScheme from '@/pages/target/scheme/index.vue';
import TargetSchemeEdit from '@/pages/target/scheme/Edit.vue';
import TargetSchemeReport from '@/pages/target/scheme/Report.vue';

const pre = 'target_';
const targetBase = `${Setting.roterPre}/target`;
const targetAnalysisPath = `${targetBase}/data_analysis`;

export default {
  path: targetBase,
  name: 'target',
  header: 'report',
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
      path: 'scheme',
      name: `${pre}scheme`,
      meta: { auth: ['admin-target', 'admin-target-scheme'], title: '目标方案' },
      component: TargetScheme
    },
    {
      path: 'scheme/create',
      name: `${pre}scheme_create`,
      meta: { auth: ['admin-target', 'admin-target-scheme'], title: '新增目标方案' },
      component: TargetSchemeEdit
    },
    {
      path: 'scheme/:id',
      name: `${pre}scheme_edit`,
      meta: { auth: ['admin-target', 'admin-target-scheme'], title: '编辑目标方案' },
      component: TargetSchemeEdit
    },
    {
      path: 'data_analysis',
      alias: 'data-analysis',
      name: `${pre}data_analysis`,
      meta: {
        auth: ['admin-target', 'admin-target-data-analysis'],
        title: '目标数据分析'
      },
      component: TargetSchemeReport,
      props: { reportCode: 'target_analysis', title: '目标数据分析' }
    },
    {
      path: 'monthly_detail',
      alias: 'monthly-detail',
      name: `${pre}monthly_detail`,
      meta: {
        auth: ['admin-target', 'admin-target-monthly-detail'],
        title: '月度目标完成明细'
      },
      component: TargetSchemeReport,
      props: { reportCode: 'target_monthly', title: '月度目标完成明细' }
    },
    {
      path: 'store_detail',
      alias: 'store-detail',
      name: `${pre}store_detail`,
      meta: {
        auth: ['admin-target', 'admin-target-store-detail'],
        title: '门店目标完成明细'
      },
      component: TargetSchemeReport,
      props: { reportCode: 'target_store', title: '门店目标完成明细' }
    }
  ]
};
