// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import index from './modules/index';
import product from './modules/product';
import order from './modules/order';
import report from './modules/report';
import target from './modules/target';
import position from './modules/position';
import user from './modules/user';
import supplier from './modules/supplier';
import echarts from './modules/echarts';
import setting from './modules/setting';
import settingPage from './modules/settingPage';
import store from './modules/store';
import agent from './modules/agent';
import finance from './modules/finance';
import cms from './modules/cms';
import marketing from './modules/marketing';
import app from './modules/app';
import system from './modules/system';
import statistic from './modules/statistic';
import BasicLayout from '@/layouts/basic-layout';
import frameOut from './modules/frameOut';
import work from './modules/work';
import community from './modules/community';
import stockManage from './modules/stockManage';
import fundManage from './modules/fundManage';
import Setting from '@/setting';
/**
 * 在主框架内显示
 */

const frameIn = [
  {
    path: '/',
    meta: {
      title: 'MOHE'
    },
    redirect: {
      name: 'home_index'
    },
    component: BasicLayout,
    children: [
      {
        path: `${Setting.roterPre}/system/log`,
        name: 'log',
        meta: {
          title: '前端日志',
          auth: true
        },
        component: () => import('@/pages/system/log')
      },
      {
        path: `${Setting.roterPre}/system/user`,
        name: `systemUser`,
        meta: {
          auth: true,
          title: '个人中心'
        },
        component: () => import('@/pages/setting/user/index')
      },
      // 刷新页面 必须保留
      {
        path: 'refresh',
        name: 'refresh',
        hidden: true,
        component: {
          beforeRouteEnter(to, from, next) {
            next((instance) => instance.$router.replace(from.fullPath));
          },
          render: (h) => h()
        }
      },
      // 页面重定向 必须保留
      {
        path: 'redirect/:route*',
        name: 'redirect',
        hidden: true,
        component: {
          beforeRouteEnter(to, from, next) {
            next((instance) =>
              instance.$router.replace(JSON.parse(from.params.route))
            );
          },
          render: (h) => h()
        }
      }
    ]
  },
  {
    path: `/${Setting.roterPre}`,
    meta: {
      title: 'MOHE'
    },
    redirect: {
      name: 'home_index'
    },
    component: BasicLayout,
    children: [
      {
        path: `system/log`,
        name: 'log',
        meta: {
          title: '前端日志',
          auth: true
        },
        component: () => import('@/pages/system/log')
      },
      {
        path: `system/user`,
        name: `systemUser`,
        meta: {
          auth: true,
          title: '个人中心'
        },
        component: () => import('@/pages/setting/user/index')
      },
      // 刷新页面 必须保留
      {
        path: 'refresh',
        name: 'refresh',
        hidden: true,
        component: {
          beforeRouteEnter(to, from, next) {
            next((instance) => instance.$router.replace(from.fullPath));
          },
          render: (h) => h()
        }
      },
      // 页面重定向 必须保留
      {
        path: 'redirect/:route*',
        name: 'redirect',
        hidden: true,
        component: {
          beforeRouteEnter(to, from, next) {
            next((instance) =>
              instance.$router.replace(JSON.parse(from.params.route))
            );
          },
          render: (h) => h()
        }
      }
    ]
  },
  {
    path: Setting.roterPre,
    redirect: {
      name: 'home_index'
    }
  },
  {
    path: `${Setting.roterPre}/setting/system/create`,
    name: `system_create`,
    meta: {
      auth: ['setting-system_create'],
      title: '系统表单'
    },
    component: () => import('@/pages/setting/systemForm/create')
  },
  {
    path: `${Setting.roterPre}/pages/diy`,
    name: `setting_diy`,
    meta: {
      auth: ['admin-setting-pages-diy'],
      title: '首页装修'
    },
    component: () => import('@/pages/setting/devise/index')
  },
  {
    path: `${Setting.roterPre}/pages/special/diy`,
    name: `setting_special_diy`,
    meta: {
      auth: ['setting-diy-special-diy'],
      title: '专题页设计'
    },
    component: () => import('@/pages/setting/special/index')
  },
  {
    path: `${Setting.roterPre}/widget.images/index.html`,
    name: `images`,
    meta: {
      auth: true,
      title: '上传图片'
    },
    component: () => import('@/components/uploadPictures/widgetImg')
  },
  // 配合单打印
  {
    path: `${Setting.roterPre}/supplier/order/distribution`,
    name: `distribution`,
    meta: {
      auth: true,
      title: '配货单'
    },
    component: () => import('@/components/distribution')
  },
  {
    path: `${Setting.roterPre}/widget.widgets/icon.html`,
    name: `imagesIcon`,
    meta: {
      auth: true,
      title: '上传图标'
    },
    component: () => import('@/components/iconFrom/index')
  },
  {
    path: `${Setting.roterPre}/store.StoreProduct/index.html`,
    name: `storeProduct`,
    meta: {
      title: '选择商品'
    },
    component: () => import('@/components/goodsList/index')
  },
  {
    path: `${Setting.roterPre}/video.shortVideo/index.html`,
    name: `shortVideo`,
    meta: {
      title: '选择视频'
    },
    component: () => import('@/components/shortVideoList/index')
  },
  {
    path: `${Setting.roterPre}/system.User/list.html`,
    name: `changeUser`,
    meta: {
      title: '选择用户'
    },
    component: () => import('@/components/customerInfo/index')
  },
  {
    path: `${Setting.roterPre}/widget.video/index.html`,
    name: `video`,
    meta: {
      title: '上传视频'
    },
    component: () => import('@/components/uploadVideos/index')
  },
  {
    path: `/app/upload`,
    name: `mobile_upload`,
    meta: {
      title: '手机端扫码上传'
    },
    component: () => import('@/pages/app/upload')
  },
  index,
  product,
  order,
  report,
  target,
  position,
  user,
  supplier,
  echarts,
  setting,
  settingPage,
  store,
  agent,
  finance,
  cms,
  marketing,
  app,
  system,
  statistic,
  work,
  community,
  stockManage,
  fundManage
];

/**
 * 在主框架之外显示
 */
const pre = 'kefu_';

const frameOuts = frameOut;

/**
 * 错误页面
 */

const errorPage = [
  {
    path: `${Setting.roterPre}/other`,
    name: 'other',
    meta: {
      title: 'other'
    },
    component: () => import('@/pages/system/error/other')
  },
  {
    path: `${Setting.roterPre}/403`,
    name: '403',
    meta: {
      title: '403'
    },
    component: () => import('@/pages/system/error/403')
  },
  {
    path: `${Setting.roterPre}/500`,
    name: '500',
    meta: {
      title: '500'
    },
    component: () => import('@/pages/system/error/500')
  },
  {
    path: `${Setting.roterPre}/*`,
    name: '404',
    meta: {
      title: '404'
    },
    component: () => import('@/pages/system/error/404')
  }
];

// 导出需要显示菜单的
export const frameInRoutes = frameIn;

// 重新组织后导出
export default [...frameIn, ...frameOuts, ...errorPage];
