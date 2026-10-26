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

const pre = 'cms_';

export default {
  path: `${Setting.roterPre}/cms`,
  name: 'cms',
  header: 'cms',
  // redirect: {
  //     name: `${pre}article`
  // },
  component: BasicLayout,
  children: [
    {
      path: 'article/index/:id?',
      name: `${pre}article`,
      meta: {
        auth: ['cms-article-index'],
        title: '文章管理'
      },
      component: () => import('@/pages/cms/article/index')
    },
    {
      path: 'article_category/index',
      name: `${pre}articleCategory`,
      meta: {
        auth: ['cms-article-category'],
        title: '文章分类'
      },
      component: () => import('@/pages/cms/articleCategory/index')
    },
    {
      path: 'article/add_article/:id?',
      name: `${pre}addArticle`,
      meta: {
        auth: ['cms-article-creat'],
        title: '文章添加'
      },
      component: () => import('@/pages/cms/addArticle/index')
    }
  ]
};
