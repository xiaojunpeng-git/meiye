import BasicLayout from '@/layouts/basic-layout';
import Setting from '@/setting';

/** 平台成长管理沿用Vue2主框架，只按业务菜单授权。 */
export default {
  path: `${Setting.roterPre}/growth`,
  name: 'growth',
  header: 'growth',
  // 根入口按已有菜单授权选落点；后端仍逐请求校验菜单和数据范围。
  redirect: () => {
    const granted = String(localStorage.getItem('unique_auth') || '').split(',');
    const first = [
      'content',
      'tasks',
      'plans',
      'exams',
      'practice',
      'inspection',
      'profiles',
      'reports',
      'settings'
    ].find((name) => granted.includes('admin-growth-' + name));
    return `${Setting.roterPre}/growth/${first || 'content'}`;
  },
  component: BasicLayout,
  meta: { auth: ['admin-growth'], title: '成长管理' },
  children: [
    {
      path: 'capabilities',
      name: 'growth_capabilities',
      meta: { auth: ['admin-growth-practice'], title: '岗位能力与带教师傅' },
      component: () => import('@/pages/growth/Capabilities.vue')
    },
    {
      path: 'overview',
      name: 'growth_overview',
      meta: { auth: ['admin-growth-reports'], title: '成长工作台' },
      component: () => import('@/pages/growth/Overview.vue')
    },
    {
      path: 'business',
      name: 'growth_business',
      meta: { auth: ['admin-growth-reports'], title: '经营观察与复盘' },
      component: () => import('@/pages/growth/Business.vue')
    },
    {
      path: 'reports',
      name: 'growth_reports',
      meta: { auth: ['admin-growth-reports'], title: '成长统计' },
      component: () => import('@/pages/growth/Reports.vue')
    },
    {
      path: 'onboarding',
      name: 'growth_onboarding',
      meta: { auth: ['admin-growth-plans'], title: '入职自动培养' },
      component: () => import('@/pages/growth/Onboarding.vue')
    },
    {
      path: 'help',
      name: 'growth_help',
      meta: { auth: ['admin-growth-practice'], title: '成长答疑' },
      component: () => import('@/pages/growth/Help.vue')
    },
    {
      path: 'profiles',
      name: 'growth_profiles',
      meta: { auth: ['admin-growth-profiles'], title: '成长档案与考评' },
      component: () => import('@/pages/growth/Profiles.vue')
    },
    {
      path: 'inspection',
      name: 'growth_inspection',
      meta: { auth: ['admin-growth-inspection'], title: '巡检整改' },
      component: () => import('@/pages/growth/Inspection.vue')
    },
    {
      path: 'practice',
      name: 'growth_practice',
      meta: { auth: ['admin-growth-practice'], title: '带教与实操' },
      component: () => import('@/pages/growth/Practice.vue')
    },
    {
      path: 'plans',
      name: 'growth_plans',
      meta: { auth: ['admin-growth-plans'], title: '培养计划' },
      component: () => import('@/pages/growth/Plans.vue')
    },
    {
      path: 'exams',
      name: 'growth_exams',
      meta: { auth: ['admin-growth-exams'], title: '练习与考试' },
      component: () => import('@/pages/growth/Exams.vue')
    },
    {
      path: 'content',
      name: 'growth_content',
      meta: { auth: ['admin-growth-content'], title: '课程与知识' },
      component: () => import('@/pages/growth/Content.vue')
    },
    {
      path: 'tasks',
      name: 'growth_tasks',
      meta: { auth: ['admin-growth-tasks'], title: '学习任务' },
      component: () => import('@/pages/growth/Tasks.vue')
    },
    {
      path: 'settings',
      name: 'growth_settings',
      meta: { auth: ['admin-growth-settings'], title: '成长设置' },
      component: () => import('@/pages/growth/Settings.vue')
    }
  ]
};
