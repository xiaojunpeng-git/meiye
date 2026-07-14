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

const pre = 'agent_';
const meta = {
  auth: true
};
export default {
  path: `${Setting.roterPre}/agent`,
  name: 'agent',
  header: 'agent',
  // redirect: {
  //     name: `${pre}agentManage`
  // },
  meta,
  component: BasicLayout,
  children: [
    {
      path: 'agent_manage/index',
      name: `${pre}agentManage`,
      meta: {
        auth: ['agent-agent-manage'],
        title: '分销员管理'
      },
      component: () => import('@/pages/agent/agentManage')
    },
    {
      path: 'agreement',
      name: `${pre}agreementt`,
      meta: {
        auth: ['agent-agreement'],
        title: '分销说明'
      },
      component: () => import('@/pages/agent/agreement')
    },
    {
		  path: 'promoter/apply',
		  name: `${pre}agent`,
		  meta: {
		    auth: ['admin-agent-promoter-apply'],
		    title: '分销员申请'
		  },
		  component: () => import('@/pages/agent/apply')
    }
  ]
};
