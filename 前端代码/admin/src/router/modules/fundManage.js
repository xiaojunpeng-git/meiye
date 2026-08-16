import BasicLayout from '@/layouts/basic-layout'
import Setting from '@/setting'
// 平台费用入口由 system_menus 的 admin-fund-manage 权限规则提供，
// 归属“财务 / 门店财务”，不能依赖顶层写死菜单。
export default {
  path: `${Setting.roterPre}/finance/store_fund`,
  name: 'fundManage',
  header: 'finance',
  redirect: `${Setting.roterPre}/finance/store_fund`,
  component: BasicLayout,
  children: [
    {
      path: '',
      name: 'fundEntry',
      meta: { auth: ['admin-fund-manage'], title: '费用' },
      component: () => import('@/pages/fundManage/FundV3Bridge')
    }
  ]
}
