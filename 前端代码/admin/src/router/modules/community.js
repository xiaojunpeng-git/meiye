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

const pre = 'community_';

export default {
  path: `${Setting.roterPre}/community`,
  name: 'community',
  header: 'community',
  meta: {
    // 授权标识
    auth: ['admin-community']
  },
  component: BasicLayout,
  children: [
    {
      path: 'topic',
      name: `${pre}topic`,
      meta: {
        auth: ['admin-community-topic'],
        title: '社区话题'
      },
      component: () => import('@/pages/community/topic')
    },
    {
      path: 'content',
      name: `${pre}content`,
      meta: {
        auth: ['admin-community-content'],
        title: '社区内容'
      },
      component: () => import('@/pages/community/content')
    },
    {
      path: 'addContent/:id?',
      name: `${pre}addContent`,
      meta: {
        auth: ['admin-community-addcontent'],
        title: '添加内容'
      },
      component: () => import('@/pages/community/addContent')
    },
    {
      path: 'comment',
      name: `${pre}comment`,
      meta: {
        auth: ['admin-community-comment'],
        title: '社区评论'
      },
      component: () => import('@/pages/community/comment')
    },
    {
      path: 'setting',
      name: `${pre}setting`,
      meta: {
        auth: ['admin-community-setting'],
        title: '社区设置'
      },
      component: () => import('@/pages/community/setting')
    }
  ]
};
