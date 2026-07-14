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
const pre = 'marketing_';

export default {
  path: `${Setting.roterPre}/marketing`,
  name: 'marketing',
  header: 'marketing',
  // redirect: {
  //     name: `${pre}storeSeckill`
  // },
  component: BasicLayout,
  children: [
    {
      path: 'home',
      name: `${pre}home`,
      meta: {
        auth: ['admin-marketing-home'],
        title: '营销中心'
      },
      component: () => import('@/pages/marketing/home/index')
    },
    {
      path: 'store_combination/index',
      name: `${pre}combinalist`,
      meta: {
        auth: ['marketing-store_combination'],
        title: '拼团商品'
      },
      component: () => import('@/pages/marketing/storeCombination/index')
    },
    {
      path: 'store_combination/combina_list',
      name: `${pre}combinaList`,
      meta: {
        auth: ['marketing-store_combination-combina_list'],
        title: '拼团列表'
      },
      component: () => import('@/pages/marketing/storeCombination/combinaList')
    },
    {
      path: 'store_combination/create/:id?/:copy?',
      name: `${pre}storeCombinationCreate`,
      meta: {
        auth: ['marketing-store_combination-create'],
        title: '添加拼团'
      },
      component: () => import('@/pages/marketing/storeCombination/create')
    },
    {
      path: 'store_combination/statistics/:id?',
      name: `${pre}storeCombinationStatistics`,
      meta: {
        title: '拼团统计'
      },
      component: () => import('@/pages/marketing/storeCombination/statistics')
    },
    {
      path: 'store_coupon/index',
      name: `${pre}storeCoupon`,
      meta: {
        auth: ['marketing-store_coupon'],
        title: '优惠券模板'
      },
      component: () => import('@/pages/marketing/storeCoupon/index')
    },
    {
      path: 'store_coupon_issue/index',
      name: `${pre}storeCouponIssue`,
      meta: {
        auth: ['marketing-store_coupon_issue'],
        title: '优惠券列表'
      },
      component: () => import('@/pages/marketing/storeCouponIssue/index')
    },
    {
      path: 'store_coupon_issue/create/:id?/:type?',
      name: `${pre}storeCouponCreate`,
      meta: {
        auth: ['admin-marketing-store_coupon-add'],
        title: '添加优惠券'
      },
      component: () => import('@/pages/marketing/storeCouponIssue/create')
    },
    {
				    path: 'discount/list',
				    name: `${pre}discountList`,
				    meta: {
				        auth: ['marketing-discount-list'],
				        title: '限时折扣'
				    },
				    component: () => import('@/pages/marketing/discount/index')
    },
    {
				    path: 'discount/give',
				    name: `${pre}discountGive`,
				    meta: {
				        auth: ['marketing-discount-give'],
				        title: '满送活动'
				    },
				    component: () => import('@/pages/marketing/fullDelivery/index')
    },
    {
				    path: 'discount/add_give/:id?',
				    name: `${pre}addDelivery`,
				    meta: {
				        auth: ['marketing-discount-add_give'],
				        title: '添加满送活动'
				    },
				    component: () => import('@/pages/marketing/addDelivery/index')
    },
    {
				    path: 'discount/full_discount',
				    name: `${pre}fullDiscount`,
				    meta: {
				        auth: ['marketing-discount-full_discount'],
				        title: '满减满折'
				    },
				    component: () => import('@/pages/marketing/fullDiscount/index')
    },
    {
				    path: 'discount/add_discount/:id?',
				    name: `${pre}addFullDiscount`,
				    meta: {
				        auth: ['marketing-discount-add_discount'],
				        title: '添加满减满折'
				    },
				    component: () => import('@/pages/marketing/addFullDiscount/index')
    },
    {
				    path: 'discount/add/:id?',
				    name: `${pre}fullDiscount_add`,
				    meta: {
				        auth: ['marketing-discount-add'],
				        title: '添加限时折扣'
				    },
				    component: () => import('@/pages/marketing/addDiscount/index')
    },
    {
				    path: 'discount/pieces_discount',
				    name: `${pre}piecesDiscount`,
				    meta: {
				        auth: ['marketing-discount-pieces_discount'],
				        title: '第N件N折'
				    },
				    component: () => import('@/pages/marketing/piecesDiscount/index')
    },
    {
				    path: 'discount/add_pieces/:id?',
				    name: `${pre}addPiecesDiscount`,
				    meta: {
				        auth: ['marketing-discount-add_pieces'],
				        title: '添加第N件N折'
				    },
				    component: () => import('@/pages/marketing/addPiecesDiscount/index')
    },
    {
      path: 'store_coupon_user/index',
      name: `${pre}storeCouponUser`,
      meta: {
        auth: ['marketing-store_coupon_user'],
        title: '用户领取记录'
      },
      component: () => import('@/pages/marketing/storeCouponUser/index')
    },
    {
      path: 'coupon/system_config/:type?/:tab_id?',
      name: `${pre}coupon`,
      meta: {
        auth: ['admin-order-storeOrder-index'],
        title: '优惠券配置'
      },
      component: () => import('@/pages/setting/setSystem/index')
    },
    {
      path: 'store_bargain/index',
      name: `${pre}storeBargain`,
      meta: {
        auth: ['marketing-store_bargain'],
        title: '砍价商品'
      },
      component: () => import('@/pages/marketing/storeBargain/index')
    },
    {
      path: 'store_bargain/setting',
      name: `${pre}storeBargainSetting`,
      meta: {
        auth: ['marketing-store_bargain-setting'],
        title: '砍价设置'
      },
      props: {
        typeMole: 'bargain'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: 'store_bargain/bargain_list',
      name: `${pre}bargainList`,
      meta: {
        auth: ['marketing-store_bargain-bargain_list'],
        title: '砍价列表'
      },
      component: () => import('@/pages/marketing/storeBargain/bargainList')
    },
    {
      path: 'store_bargain/create/:id?/:copy?',
      name: `${pre}storeBargainCreate`,
      meta: {
        auth: ['marketing-store_bargain-create'],
        title: '添加砍价'
      },
      component: () => import('@/pages/marketing/storeBargain/create')
    },
    {
      path: 'store_bargain/statistics/:id?',
      name: `${pre}storeBargainStatistics`,
      meta: {
        title: '砍价统计'
      },
      component: () => import('@/pages/marketing/storeBargain/statistics')
    },
    {
      path: 'store_seckill/index',
      name: `${pre}storeSeckill`,
      meta: {
        auth: ['marketing-store_seckill'],
        title: '秒杀商品'
      },
      component: () => import('@/pages/marketing/storeSeckill/index')
    },
    {
      path: 'store_seckill/list',
      name: `${pre}storeSeckill`,
      meta: {
        auth: ['marketing-seckill_list'],
        title: '秒杀列表'
      },
      component: () => import('@/pages/marketing/storeSeckill/list')
    },
    {
      path: 'store_seckill_data/index',
      name: `${pre}storeSeckillData`,
      meta: {
        auth: ['marketing-store_seckill-data'],
        title: '秒杀配置'
      },
      component: () => import('@/pages/marketing/storeSeckill/config')
    },
    {
      path: 'store_seckill/create/:id?/:copy?',
      name: `${pre}storeSeckillCreate`,
      meta: {
        auth: ['marketing-store_seckill-create'],
        title: '添加秒杀'
      },
      component: () => import('@/pages/marketing/storeSeckill/create')
    },
    {
      path: 'store_seckill/statistics/:id?',
      name: `${pre}storeSeckillStatistics`,
      meta: {
        title: '秒杀统计'
      },
      component: () => import('@/pages/marketing/storeSeckill/statistics')
    },
    {
      path: `integral/system_config/:type?/:tab_id?`,
      name: `${pre}integral`,
      meta: {
        auth: ['marketing-integral-system_config'],
        title: '积分配置'
      },
      props: {
        typeMole: 'integral'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
      // component: () => import('@/pages/setting/setSystem/index')
    },
    {
      path: 'user_point/index',
      name: `${pre}userPoint`,
      meta: {
        auth: ['marketing-user_point'],
        title: '积分日志'
      },
      component: () => import('@/pages/marketing/userPoint/index')
    },
    {
      path: 'user_point/rule',
      name: `${pre}userPointRule`,
      meta: {
        auth: ['marketing-user_point_rule'],
        title: '积分规则'
      },
      component: () => import('@/pages/marketing/userPoint/rule')
    },
    {
		    path: 'integral/signIn',
		    name: `${pre}signIn`,
		    meta: {
		        auth: ['marketing-integral-sign'],
		        title: '积分签到'
		    },
		    component: () => import('@/pages/marketing/signIn/index')
    },
    {
      path: 'point_statistic',
      name: `${pre}point_statistic`,
      meta: {
        auth: ['marketing-point_statistic-index'],
        title: '积分统计'
      },
      component: () => import('@/pages/marketing/pointStatistic/index')
    },
    {
      path: 'integral/classify',
      name: `${pre}classify`,
      meta: {
        auth: ['marketing-integral-classify'],
        title: '积分分类'
      },
      component: () => import('@/pages/marketing/integralClassify/index')
    },
    {
		    path: 'balance_recharge',
		    name: `${pre}recharge`,
		    meta: {
		        auth: ['marketing-balance_recharge'],
		        title: '充值金额'
		    },
		    component: () => import('@/pages/marketing/recharge/index')
    },
    {
      path: 'setup_recharge',
      name: `${pre}setup_recharge`,
      meta: {
        auth: ['marketing-setup-recharge'],
        title: '充值金额'
      },
      props: {
        typeMole: 'recharge'
      },
      component: () => import('@/components/fromSubmit/commonForm.vue')
    },
    {
      path: 'live/live_room',
      name: `${pre}live_room`,
      meta: {
        auth: true,
        title: '直播间管理'
      },
      component: () => import('@/pages/marketing/live/index')
    },
    {
      path: 'live/add_live_room',
      name: `${pre}add_live_room`,
      meta: {
        auth: true,
        title: '直播间管理'
      },
      component: () => import('@/pages/marketing/live/creat_live')
    },
    {
      path: 'live/live_goods',
      name: `${pre}live_goods`,
      meta: {
        auth: true,
        title: '直播间商品管理'
      },
      component: () => import('@/pages/marketing/live/live_goods')
    },
    {
      path: 'live/add_live_goods',
      name: `${pre}add_live_goods`,
      meta: {
        auth: true,
        title: '直播间商品管理'
      },
      component: () => import('@/pages/marketing/live/add_goods')
    },
    {
      path: 'live/anchor',
      name: `${pre}anchor`,
      meta: {
        auth: true,
        title: '主播管理'
      },
      component: () => import('@/pages/marketing/live/anchor')
    },
    {
      path: 'lottery/index',
      name: `${pre}lottery`,
      meta: {
        auth: true,
        title: '抽奖列表'
      },
      component: () => import('@/pages/marketing/lottery/index')
    },
    {
      path: 'lottery/create/:id?',
      name: `${pre}create`,
      meta: {
        auth: true,
        title: '创建抽奖'
      },
      component: () => import('@/pages/marketing/lottery/create')
    },
    {
      path: 'lottery/recording_list',
      name: `${pre}recording_list`,
      meta: {
        auth: ['admin-marketing-lottery-recording_list'],
        title: '中奖记录'
      },
      component: () => import('@/pages/marketing/lottery/recordingList')
    },
    {
      path: 'lottery/setting',
      name: `${pre}lottery_setting`,
      meta: {
        auth: ['admin-marketing-lottery-setting'],
        title: '抽奖设置'
      },
      props: {
        typeMole: 'luck_lottery'
      },
      component: () => import('@/components/fromSubmit/commonForm')
    },
    {
      path: 'store_discounts/index',
      name: `${pre}store_discounts`,
      meta: {
        auth: true,
        title: '套餐列表'
      },
      component: () => import('@/pages/marketing/storeDiscounts/index')
    },
    {
      path: 'store_discounts/create',
      name: `${pre}store_discounts_create`,
      meta: {
        auth: true,
        title: '创建套餐'
      },
      component: () => import('@/pages/marketing/storeDiscounts/create')
    },
    {
      path: 'store_integral/index',
      name: `${pre}storeIntegral`,
      meta: {
        auth: ['marketing-store_integral'],
        title: '积分商品'
      },
      component: () => import('@/pages/marketing/storeIntegral/index')
    },
    {
      path: 'store_integral/create/:id?/:copy?',
      name: `${pre}storeIntegralCreate`,
      meta: {
        auth: ['marketing-store_integral-create'],
        title: '添加积分商品'
      },
      component: () => import('@/pages/marketing/storeIntegral/create')
    },
    {
      path: 'store_integral/add_store_integral',
      name: `${pre}addStoreIntegral`,
      meta: {
        auth: ['marketing-store_integral-create'],
        title: '批量添加积分商品'
      },
      component: () => import('@/pages/marketing/storeIntegral/addStoreIntegral')
    },
    {
      path: 'store_integral/order_list',
      name: `${pre}storeIntegralOrder`,
      meta: {
        auth: ['marketing-store_integral-order'],
        title: '兑换订单'
      },
      component: () => import('@/pages/marketing/storeIntegralOrder/index')
    },
    {
      path: 'short_video/index',
      name: `${pre}shortVideoIndex`,
      meta: {
        auth: ['marketing-short_video-index'],
        title: '短视频'
      },
      component: () => import('@/pages/marketing/shortVideo/index')
    },
    {
      path: 'short_video/create/:id?',
      name: `${pre}shortVideoCreate`,
      meta: {
        auth: ['marketing-short_video-create'],
        title: '添加短视频'
      },
      component: () => import('@/pages/marketing/shortVideo/create')
    },
    {
      path: 'short_video/comment',
      name: `${pre}shortVideo`,
      meta: {
        auth: ['marketing-short_video-comment'],
        title: '短视频评论'
      },
      component: () => import('@/pages/marketing/shortVideo/comment')
    },
    {
      path: 'activity_frame',
      name: `${pre}activityFrame`,
      meta: {
        auth: ['admin-marketing-activity_frame'],
        title: '活动边框'
      },
      component: () => import('@/pages/marketing/activityFrame')
    },
    {
      path: 'activity_frame/create/:id?',
      name: `${pre}frameCreate`,
      meta: {
        auth: ['marketing-activity_frame-create'],
        title: '添加活动边框'
      },
      component: () => import('@/pages/marketing/activityFrame/create')
    },
    {
      path: 'activity_background',
      name: `${pre}activityBackground`,
      meta: {
        auth: ['admin-marketing-activity_background'],
        title: '活动背景'
      },
      component: () => import('@/pages/marketing/activityBackground')
    },
    {
      path: 'activity_background/create/:id?',
      name: `${pre}backgroundCreate`,
      meta: {
        auth: ['marketing-activity_background-create'],
        title: '添加活动背景'
      },
      component: () => import('@/pages/marketing/activityBackground/create')
    },
    {
      path: 'channel_code',
      name: `${pre}channelCode`,
      meta: {
        keepAlive: true,
        auth: ['marketing-channel_code'],
        title: '渠道码'
      },
      component: () => import('@/pages/marketing/channelCode/index')
    },
    {
      path: 'channel_code/create/:id?',
      name: `${pre}channelCreate`,
      meta: {
        auth: ['marketing-channel_code-create'],
        title: '添加渠道码'
      },
      component: () => import('@/pages/marketing/channelCode/create')
    },
    {
      path: 'channel_code/statistic/:id?',
      name: `${pre}channelStatistic`,
      meta: {
        auth: ['marketing-channel_code-statistic'],
        title: '二维码统计'
      },
      component: () => import('@/pages/marketing/channelCode/statistic')
    },
    {
      path: 'sign_config',
      name: `${pre}signConfig`,
      meta: {
        auth: ['admin-marketing-sign_config'],
        title: '签到配置'
      },
      props: {
        typeMole: 'sign'
      },
      component: () => import('@/components/fromSubmit/commonForm')
    },
    {
      path: 'sign_rewards',
      name: `${pre}signRewards`,
      meta: {
        auth: ['admin-marketing-sign_rewards'],
        title: '签到奖励'
      },
      component: () => import('@/pages/marketing/signRewards/index.vue')
    }
  ]
};
