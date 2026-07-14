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

const pre = 'product_';

export default {
  path: `${Setting.roterPre}/product`,
  name: 'product',
  header: 'product',
  meta: {
    // 授权标识
    auth: ['admin-store-index']
  },
  // redirect: {
  //     name: `${pre}productList`
  // },
  component: BasicLayout,
  children: [
    {
      path: 'product_list',
      name: `${pre}productList`,
      meta: {
        title: '商品管理',
        keepAlive: true,
        scollTopPosition: 0,
        auth: ['admin-store-storeProuduct-index']
      },
      component: () => import('@/pages/product/productList')
    },
    {
      path: 'product_classify',
      name: `${pre}productClassify`,
      meta: {
        title: '商品分类',
        auth: ['admin-store-storeCategory-index']
      },
      component: () => import('@/pages/product/productClassify')
    },
    {
      path: 'add_product/:id?',
      name: `${pre}productAdd`,
      meta: {
        auth: ['admin-store-storeProuduct-index'],
        title: '商品添加'
      },
      component: () => import('@/pages/product/productAdd')
    },
    {
      path: 'product_reply/:id?',
      name: `${pre}productEvaluate`,
      meta: {
        auth: ['admin-store-storeProuduct-index'],
        title: '商品评论'
      },
      component: () => import('@/pages/product/productReply')
    },
    {
      path: 'product_attr',
      name: `${pre}productAttr`,
      meta: {
        auth: ['admin-store-storeProuduct-index'],
        title: '商品规格'
      },
      component: () => import('@/pages/product/productAttr')
    },
    {
      path: 'product_brand',
      name: `${pre}productBrand`,
      meta: {
        auth: ['admin-store-storeBrand-index'],
        title: '品牌管理'
      },
      component: () => import('@/pages/product/productBrand')
    },
    {
		    path: 'unitList',
		    name: `${pre}unitList`,
		    meta: {
		        auth: ['admin-storeProduct-unit'],
		        title: '商品单位'
		    },
		    component: () => import('@/pages/product/unitList')
    },
    {
      path: 'label',
      name: `${pre}label`,
      meta: {
        auth: ['admin-storeProduct-label'],
        title: '商品标签'
      },
      component: () => import('@/pages/product/label')
    },
    {
      path: 'specs',
      name: `${pre}specs`,
      meta: {
        auth: ['admin-storeProduct-specs'],
        title: '商品参数'
      },
      component: () => import('@/pages/product/specs')
    },
    {
      path: 'ensure',
      name: `${pre}ensure`,
      meta: {
        auth: ['admin-storeProduct-ensure'],
        title: '保障服务'
      },
      component: () => import('@/pages/product/ensure')
    },
    {
		    path: 'ensure/create/:id?',
		    name: `${pre}ensureCreate`,
		    meta: {
		        auth: ['admin-storeProduct-ensure-create'],
		        title: '添加商品参数模板'
		    },
		    component: () => import('@/pages/product/specsAdd')
    },
    {
		    path: 'hotWords',
		    name: `${pre}hotWords`,
		    meta: {
		        auth: ['admin-storeProduct-hotWords'],
		        title: '搜索热词'
		    },
		    component: () => import('@/pages/product/hotWords')
    }
  ]
};
