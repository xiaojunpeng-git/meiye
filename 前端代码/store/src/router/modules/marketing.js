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
import Setting from "@/setting";

const pre = 'marketing_';

export default {
  path: `${Setting.routePre}/marketing/`,
  name: 'marketing',
  header: 'marketing',
  meta: {
    // 授权标识
    auth: ['store-marketing']
  },
  redirect: {
    name: `${pre}shortVideo`
  },
  component: BasicLayout,
  children: [
    {
      path: 'short_video/index',
      name: `${pre}shortVideo`,
      meta: {
        auth: ['store-marketing-short_video-index'],
        title: '短视频'
      },
      component: () => import('@/pages/marketing/shortVideo/index')
    },
    {
      path: 'short_video/create/:id?',
      name: `${pre}shortVideoCreate`,
      meta: {
        auth: ['store-marketing-short_video-create'],
        title: '短视频添加'
      },
      component: () => import('@/pages/marketing/shortVideo/create')
    },
    {
      path: 'short_video/comment',
      name: `${pre}comment`,
      meta: {
        auth: ['store-marketing-short_video-comment'],
        title: '短视频评论'
      },
      component: () => import('@/pages/marketing/shortVideo/comment')
    },
    {
      path: 'community/content',
      name: `${pre}content`,
      meta: {
        title: '社区内容',
        auth: ['store-marketing-community-content']
      },
      component: () => import('@/pages/marketing/community/content')
    },
    {
      path: 'community/addContent/:id?',
      name: `${pre}addContent`,
      meta: {
        title: '添加内容',
        auth: ['store-marketing-community-addcontent']
      },
      component: () => import('@/pages/marketing/community/addContent')
    },
    {
      path: 'community/comment',
      name: `${pre}comment`,
      meta: {
        title: '社区评论',
        auth: ['store-marketing-community-comment']
      },
      component: () => import('@/pages/marketing/community/comment')
    },
    {
      path: 'coupon/index',
      name: `${pre}coupon_index`,
      meta: {
        title: '优惠券列表',
        auth: ['store-marketing-coupon-index']
      },
      component: () => import('@/pages/marketing/coupon/index')
    },
    {
      path: 'coupon/user',
      name: `${pre}coupon_user`,
      meta: {
        title: '领取记录',
        auth: ['store-marketing-coupon-user']
      },
      component: () => import('@/pages/marketing/coupon/user')
    },
    {
      path: 'coupon/create/:id?/:type?',
      name: `${pre}coupon_create`,
      meta: {
        title: '领取记录',
        auth: ['store-marketing-coupon-create']
      },
      component: () => import('@/pages/marketing/coupon/create')
    },
  ]
};
