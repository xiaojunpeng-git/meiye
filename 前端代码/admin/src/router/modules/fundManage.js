import BasicLayout from '@/layouts/basic-layout'
import Setting from '@/setting'
export default { path:`${Setting.roterPre}/fund`,name:'fundManage',header:'fundManage',redirect:`${Setting.roterPre}/fund/entry`,component:BasicLayout,children:[{path:`${Setting.roterPre}/fund/entry`,name:'fundEntry',meta:{auth:['admin-finance'],title:'费用'},component:()=>import('@/pages/fundManage/FundV3Bridge')}] }
