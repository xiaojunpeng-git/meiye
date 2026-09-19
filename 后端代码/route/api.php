<?php


use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\api\AuthTokenMiddleware;
use app\http\middleware\api\MerchantAuthMiddleware;
use app\http\middleware\BlockerMiddleware;
use app\http\middleware\api\ClientMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\StationOpenMiddleware;
use app\http\middleware\api\CommunityOpenMiddleware;
use think\facade\Config;
use think\facade\Route;
use think\Response;

/**
 * 用户端路由配置
 */
Route::group('api', function () {

	Route::any('wechat/serve', 'v1.wechat.Wechat/serve');//公众号服务
	Route::any('wechat/miniServe', 'v1.wechat.Wechat/miniServe');//小程序服务
	Route::any('work/serve', 'v1.wechat.Wechat/work');//企业微信服务
	Route::any('pay/notify/:type', 'v1.Pay/notify');//支付回调
	Route::any('pay/fynotify/:channel/:type', 'v1.Pay/fyNotify');//富友支付回调
	Route::any('pay/fynotify/:type', 'v1.Pay/fyNotifyLegacy');//兼容修复前已生成的回调地址
	Route::any('pay/mchNotify/:type', 'v1.Pay/mchNotify');//商户转账回调
	Route::any('city_delivery/notify', 'v1.CityDelivery/notify');//UU、达达回调
	Route::get('get_script', 'v1.PublicController/getScript');//统计代码
	Route::get('get_copyright', 'v1.PublicController/getCopyright');//获取版权
	Route::get('ali_pay', 'v1.order.StoreOrder/aliPay')->name('aliPay');// 支付宝复制链接支付
    Route::get('community/config', 'v1.community.Community/getConfig')->name('getConfig');//获取社区配置
	Route::any('order_call_back', 'v1.order.StoreOrder/callBack');//商家寄件回调
	Route::any('version', 'v1.PublicController/getVersion');//获取系统版本号
	//系统更新日志（公开只读，小程序）
	Route::get('changelog/list', 'v1.publics.SystemChangelog/lst')->name('changelogList');
	Route::get('changelog/detail/:id', 'v1.publics.SystemChangelog/detail')->name('changelogDetail');
	Route::get('changelog/unread', 'v1.publics.SystemChangelog/unread')->name('changelogUnread');

	/**
	 * 登录类
	 */
	Route::group(function () {
		//apple快捷登录
		Route::post('apple_login', 'v1.Login/appleLogin')->name('appleLogin');//微信APP授权
		//账号密码登录
		Route::post('login', 'v1.Login/login')->name('login');
		// 获取发短信的key
		Route::get('verify_code', 'v1.Login/verifyCode')->name('verifyCode');
		//手机号登录
		Route::post('login/mobile', 'v1.Login/mobile')->name('loginMobile');
		//图片验证码
		Route::get('sms_captcha', 'v1.Login/captcha')->name('captcha');
		//验证码发送
		Route::post('register/verify', 'v1.Login/verify')->name('registerVerify');
		//手机号注册
		Route::post('register', 'v1.Login/register')->name('register');
		//手机号修改密码
		Route::post('register/reset', 'v1.Login/reset')->name('registerReset');
		// 绑定手机号(静默授权 还未有用户信息)
		Route::post('binding', 'v1.Login/binding_phone')->name('bindingPhone');
		//图形验证码
		Route::get('ajcaptcha', 'v1.Login/ajcaptcha')->name('ajcaptcha');
		//图形验证码
		Route::post('ajcheck', 'v1.Login/ajcheck')->name('ajcheck');


	});

	/**
	 * 无需授权接口
	 */
	Route::group(function () {

		Route::get('geoLbscoder', 'v1.PublicController/geoLbscoder')->name('geoLbscoder');//经纬度转位置信息

		Route::get('city', 'v2.PublicController/city')->name('city');//增加省市区

		Route::get('site_config', 'v1.PublicController/getSiteConfig')->name('getSiteConfig');//获取网站配置

		Route::get('navigation/[:template_name]', 'v1.PublicController/getNavigation')->name('getNavigation');//获取底部导航

		Route::get('search/hot_keyword', 'v1.PublicController/hotKeywords')->name('hotKeyword');//热门搜索关键字获取
		Route::get('search/keyword', 'v1.PublicController/searchWords')->name('searchKeyword');//搜索关键字关联

		Route::get('category', 'v1.product.StoreProductCategory/category')->name('category');//商品分类类
		Route::get('level_category', 'v1.product.StoreProductCategory/levelCategory')->name('levelCategory');//商品同级所以分类
		Route::get('category_version', 'v1.product.StoreProductCategory/getCategoryVersion')->name('getCategoryVersion');//商品分类类版本
		Route::get('reply/list/:id', 'v1.product.StoreProductReply/reply_list')->name('replyList');//商品评价列表
		Route::get('reply/config/:id', 'v1.product.StoreProductReply/reply_config')->name('replyConfig');//商品评价数量和好评度


		Route::get('user_agreement/[:type]', 'v1.PublicController/getUserAgreement')->name('getUserAgreement')->middleware(AuthTokenMiddleware::class, false);//获取用户协议
        Route::get('agreement/[:type]', 'v1.PublicController/getAgreement')->name('getUserAgreement')->middleware(AuthTokenMiddleware::class, false);//分销说明

        Route::get('get_open_adv', 'v1.PublicController/getOpenAdv')->name('getOpenAdv');//首页开屏广告
        //分销设置
        Route::get('agent/brokerage', 'v2.spread.Spread/brokerage')->name('brokerage');
	});

	/**
	 * 授权不通过,不会抛出异常继续执行
	 */
	Route::group(function () {
		//公共类
		Route::get('index', 'v1.PublicController/index')->name('index');//首页
		Route::get('menu/user', 'v1.PublicController/menu_user')->name('menuUser');//个人中心菜单
        Route::get('menu/date', 'v1.PublicController/menu_user_data')->name('menuUserData');//个人中心数据
		Route::get('get_qrcode/:type/:id', 'v1.other.Qrcode/getQrcode')->name('getQrcode');//获取分销二维码

		//商品类
		Route::get('presale/list', 'v1.product.StoreProduct/presaleList')->name('presaleList');//预售商品列表
		Route::get('search/recommend/:type', 'v1.product.StoreProduct/searchRecommendList')->name('searchRecommendList');//搜索页推荐商品列表
		Route::get('search/filter', 'v1.product.StoreProduct/searchFilter')->name('searchFilter');//商品分类活动、标签、品牌筛选参数
		Route::get('brand', 'v1.product.StoreProduct/brand')->name('brand');//品牌列表

		//商品榜单
		Route::get('product/rank/category', 'v1.product.StoreProductRank/rankCategory')->name('RankCategory');//绑定分类列表
		Route::get('product/rank/:type', 'v1.product.StoreProductRank/rankList')->name('RankList');//榜单商品列表

		Route::post('image_base64', 'v1.PublicController/get_image_base64')->name('getImageBase64');// 获取图片base64
		Route::get('product/detail/recommend/:id','v1.product.StoreProduct/recommend')->name('productRecommend');//商品详情推荐商品
		Route::get('product/detail/activity/:id','v1.product.StoreProduct/activity')->name('productActivity');//商品详情关联活动
		Route::get('product/detail/:id/[:type]', 'v1.product.StoreProduct/detail')->name('detail');//商品详情
		Route::get('product/store/detail/:id', 'v2.product.StoreProduct/getStoreProductInfo')->name('getStoreProductInfo');//用平台商品ID获取门店该商品详情
		Route::get('product/card/related/:id', 'v2.product.StoreProduct/getCardRelatedProduct')->name('getCardRelatedProduct');//卡项商品关联商品获取
		Route::get('product/detail_content/:id/', 'v1.product.StoreProduct/detailContent')->name('detailContent');//商品详情内容
		Route::get('groom/list/:type', 'v1.product.StoreProduct/groom_list')->name('groomList');//获取首页推荐不同类型商品的轮播图和商品
		Route::get('products', 'v1.product.StoreProduct/lst')->name('products');//商品列表
		Route::get('product/hot', 'v1.product.StoreProduct/product_hot')->name('productHot');//为你推荐
		Route::get('reply/comment/:id', 'v1.product.StoreProductReply/commentList')->name('commentList');//评价回复列表
		//项目
		Route::get('reservation/product/detail/:id', 'v1.product.StoreProductReservation/getReservationProductInfo')->name('getReservationProductInfo');//项目、sku详情
		Route::get('reservation/product/date/:id','v1.product.StoreProductReservation/getReservationProductDate')->name('getReservationProductDate');//获取项目可预约日期时间
		Route::get('reservation/product/times_stock/:id','v1.product.StoreProductReservation/getReservationProductTimeStock')->name('getReservationProductTimeStock');//项目时段划分库存
		Route::post('reservation/product/compute', 'v1.product.StoreProductReservation/reservationCompute')->name('reservationCompute'); //购物车列表重新计算
		Route::get('reservation/staff/list', 'v1.reservation.ReservationStaff/list')->name('reservationStaffList');//预约服务人员列表（按门店，无距离筛选）
		Route::get('reservation/staff/available_time', 'v1.reservation.ReservationStaff/availableTime')->name('reservationStaffAvailableTime');//员工已被占用时段
		Route::get('reservation/staff/service_time_slots', 'v1.reservation.ReservationStaff/serviceTimeSlots')->name('reservationStaffServiceTimeSlots');//门店可预约时段
		Route::get('reservation/staff/busy', 'v1.reservation.ReservationStaff/busyStaff')->name('reservationStaffBusy');//指定时段已占用员工
		Route::get('reservation/staff/conflicts', 'v1.reservation.ReservationStaff/staffConflicts')->name('reservationStaffConflicts');//手艺人预约冲突

		//文章分类类
		Route::get('article/category/list', 'v1.publics.ArticleCategory/lst')->name('articleCategoryList');//文章分类列表
		//文章类
		Route::get('article/list/:cid', 'v1.publics.Article/lst')->name('articleList');//文章列表
		Route::get('article/like/:id', 'v1.publics.Article/userArticleLikes')->middleware(BlockerMiddleware::class)->name('userArticleLikes');//文章点赞
		Route::get('article/details/:id', 'v1.publics.Article/details')->name('articleDetails');//文章详情
		Route::get('article/hot/list', 'v1.publics.Article/hot')->name('articleHotList');//文章 热门
		Route::get('article/new/list', 'v1.publics.Article/new')->name('articleNewList');//文章 最新
		Route::get('article/banner/list', 'v1.publics.Article/banner')->name('articleBannerList');//文章 banner
		//活动---秒杀
		Route::get('seckill/index', 'v1.activity.StoreSeckill/index')->name('seckillIndex');//秒杀商品时间区间
		Route::get('seckill/list/:time', 'v1.activity.StoreSeckill/lst')->name('seckillList');//秒杀商品列表
		Route::get('seckill/detail/:id/[:time]', 'v1.activity.StoreSeckill/detail')->name('seckillDetail');//秒杀商品详情
		Route::get('seckill/detail_code/:id', 'v1.activity.StoreSeckill/detailCode')->name('seckilldetailCode');//秒杀商品二维码
		//活动---砍价
		Route::get('bargain/config', 'v1.activity.StoreBargain/config')->name('bargainConfig');//砍价商品列表配置
		Route::get('bargain/list', 'v1.activity.StoreBargain/lst')->name('bargainList');//砍价商品列表
		Route::get('bargain/detail/:id', 'v1.activity.StoreBargain/detail')->name('bargainDetail');//砍价商品详情
		//活动---拼团
		Route::get('combination/list', 'v1.activity.StoreCombination/lst')->name('combinationList');//拼团商品列表
		Route::get('combination/detail/:id', 'v1.activity.StoreCombination/detail')->name('combinationDetail');//拼团商品详情
		Route::get('combination/detail_code/:id', 'v1.activity.StoreCombination/detailCode')->name('detailCode');//拼团商品详情二维码
		//用户类
		Route::get('user/activity', 'v1.user.User/activity')->name('userActivity');//活动状态

		//微信
		Route::get('wechat/config', 'v1.wechat.Wechat/config')->name('wechatConfig');//微信 sdk 配置
		Route::get('wechat/auth', 'v1.wechat.Wechat/auth')->name('wechatAuth');//微信授权
		Route::post('wechat/app_auth', 'v1.wechat.Wechat/appAuth')->name('appAuth');//微信APP授权

		//小程序登录
		Route::post('wechat/mp_auth', 'v1.wechat.Routine/mp_auth')->name('mpAuth');//小程序登录
		Route::get('wechat/get_logo', 'v1.PublicController/getLogo')->name('getLogo');//登录页面logo
		Route::get('wechat/teml_ids', 'v1.wechat.Routine/teml_ids')->name('wechatTemlIds');//小程序订阅消息
		Route::get('wechat/live', 'v1.wechat.Routine/live')->name('wechatLive');//小程序直播列表
		Route::get('wechat/livePlaybacks/:id', 'v1.wechat.Routine/livePlaybacks')->name('livePlaybacks');//小程序直播回放

		//物流公司
		Route::get('logistics', 'v1.PublicController/logistics')->name('logistics');//物流公司列表

		//分享配置
		Route::get('share', 'v1.PublicController/share')->name('share');//分享配置

		//优惠券
		Route::get('coupons', 'v1.activity.StoreCoupons/lst')->name('couponsList'); //可领取优惠券列表

		//短信购买异步通知
		Route::post('sms/pay/notify', 'v1.PublicController/sms_pay_notify')->name('smsPayNotify'); //短信购买异步通知

		//获取关注微信公众号海报
		Route::get('wechat/follow', 'v1.wechat.Wechat/follow')->name('Follow');
		//用户是否关注
		Route::get('subscribe', 'v1.user.User/subscribe')->name('Subscribe');
		//首页获取用户进入门店（根据进店规则）
		Route::get('get_entry_store', 'v1.store.Store/getUserEntryStore')->name('getUserEntryStore');
		//进店绑定店员
		Route::get('belong/staff', 'v1.store.Store/setUserBelongStaff')->name('setUserBelongStaff');
		//首页展示门店列表
		Route::get('home/store_list', 'v1.store.Store/getHomeStoreList')->name('homeStoreList');
		//首页 DIY 员工展示（公开安全字段）
		Route::get('home/staff_list', 'v1.store.Store/getHomeStaffList')->name('homeStaffList');
		//下单选择门店列表
		Route::get('store_list', 'v1.PublicController/store_list')->name('storeList');
		//获取城市列表
		Route::get('city_list', 'v1.PublicController/city_list')->name('cityList');
		//获取附近最近门店
		Route::get('nearby_store', 'v1.store.Store/nearbyStore')->name('nearbyStore');

		Route::get('pink', 'v1.PublicController/pink')->name('pinkData');
		Route::get('combination/banner_list', 'v1.activity.StoreCombination/banner_list')->name('combinationBannerList');//拼团列表轮播图

		Route::post('user/set_visit', 'v1.user.User/set_visit')->name('setVisit');// 添加用户访问记录
		Route::get('copy_words', 'v1.PublicController/copy_words')->name('copyWords');// 复制口令接口

		//活动---积分商城
		Route::get('store_integral/index', 'v1.activity.StoreIntegral/index')->name('storeIntegralIndex');//积分商城首页数据
        Route::get('store_integral/category', 'v1.activity.StoreIntegral/category')->name('storeIntegralCategory');//积分商城分类列表
        Route::get('store_integral/list', 'v1.activity.StoreIntegral/lst')->name('storeIntegralList');//积分商品列表
		Route::get('store_integral/detail/:id', 'v1.activity.StoreIntegral/detail')->name('storeIntegralDetail');//积分商品详情
        Route::get('integral/config', 'v1.activity.StoreIntegral/getConfig')->name('getConfig');//获取积分配置
		//优惠套餐列表
		Route::get('store_discounts/list/:product_id', 'v1.activity.StoreDiscounts/index');

		//获取客服类型
		Route::get('get_customer_type', 'v2.PublicController/getCustomerType')->name('getCustomerType');//获取客服类型
		Route::get('user/service/get_adv', 'v1.user.StoreService/getKfAdv')->name('userServiceGetKfAdv');//获取客服页面广告

        Route::post('user/spread', 'v1.user.User/spread')->name('userSpread');//静默绑定授权

		//购物车
		Route::get('cart/list', 'v1.order.StoreCart/lst')->name('cartList'); //购物车列表
		Route::post('cart/store/list', 'v1.order.StoreCart/getCartallLst')->name('cartList'); //购物车门店列表
		Route::get('cart/count', 'v1.order.StoreCart/count')->name('cartCount'); //购物车 获取数量

		//付费会员
		Route::get('user/member/card/index', 'v1.user.MemberCard/index')->name('userMemberCardIndex');// 主页会员权益介绍页
		Route::get('user/member/overdue/time', 'v1.user.MemberCard/getOverdueTime')->name('userMemberOverdueTime');//会员时间

		//用户类 签到
		Route::get('sign/status', 'v1.user.UserSign/get_sign_status')->name('getSignStatus');//签到配置
		Route::get('sign/config', 'v1.user.UserSign/sign_config')->name('signConfig');//签到配置
		Route::get('sign/list', 'v1.user.UserSign/sign_list')->name('signList');//签到列表
		Route::get('sign/month', 'v1.user.UserSign/sign_month')->name('signIntegral');//签到列表（年月）
		Route::post('sign/user', 'v1.user.UserSign/sign_user')->name('signUser');//签到用户信息
		Route::get('sign/calendar', 'v1.user.UserSign/sign_calendar')->name('signCalendar');//日历数据

		//储值
		Route::get('recharge/index', 'v1.user.UserRecharge/index')->name('rechargeQuota');//储值余额选择

		//会员等级类
		Route::get('user/level/detection', 'v1.user.UserLevel/detection')->name('userLevelDetection');//检测用户是否可以成为会员
		Route::get('user/level/grade', 'v1.user.UserLevel/grade')->name('userLevelGrade');//会员等级列表
		Route::get('user/level/info', 'v1.user.UserLevel/userLevelInfo')->name('levelInfo');//获取等级详情
		Route::get('user/level/activate_info', 'v1.user.UserLevel/activateInfo')->name('userActivateInfo');//用户激活会员卡需要的信息

        //同城配送
        Route::get('delivery/status/config', 'v1.store.Store/deliveryStatus')->name('deliveryStatus');//同城配送配置

	})->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

	/**
	 * diy相关
	 */
	Route::group('diy', function () {

		//无需登录接口
		Route::group(function () {
			Route::get('diy_version/[:id]', 'v1.diy.Diy/getDiyVersion');//DIY版本接口
		});

		//未授权接口---不会抛异常
		Route::group(function () {
			Route::get('get_diy/[:id]', 'v1.diy.Diy/getDiy');//DIY接口
			Route::get('user_info', 'v1.diy.Diy/userInfo')->name('diyUserInfo');//diy用户信息
			Route::get('video_list', 'v1.diy.Diy/videoList')->name('diyVideoList');//diy短视频列表
			Route::get('newcomer_list', 'v1.diy.Diy/newcomerList')->name('diyNewcomerList');//diy新人专享商品列表
            Route::get('product_rank', 'v1.diy.Diy/productRank')->name('diyNewcomerList');//diy新人专享商品列表
            Route::get('sign', 'v1.diy.Diy/diySign')->name('diySign');//diy签到数据
            Route::get('get_suspended', 'v1.diy.Diy/getSuspendedDiy')->name('getSuspendedDiy');//diy悬浮窗数据
		})->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

		//需要授权接口
		Route::group(function () {

		})->middleware(AuthTokenMiddleware::class, true);

	});
    /**
     * 社区
     */
    Route::group('community', function () {
        //无需登录
        Route::group(function () {
            Route::get('topic', 'v1.community.Community/getTopic')->name('getTopic');//获取话题
        });
        //不登录不会报错
        Route::group(function () {
            Route::get('list', 'v1.community.Community/list')->name('list');//列表
            Route::get('detail/:id', 'v1.community.Community/detail')->name('detail');//详情
            Route::put('browse/:id', 'v1.community.Community/setBrowse')->name('setBrowse');//浏览
            Route::get('product_list', 'v1.community.Community/getProductList')->name('getProductList');//获取商品
            Route::get('product/list', 'v1.community.Community/getAssociationProductLst')->name('getAssociationProductLst');//社区关联商品列表
            //评论
            Route::get('comment/list', 'v1.community.CommunityComment/list')->name('getCommunityCommentList');//获取评论
            //个人中心
            Route::get('user_info/:authorUid', 'v1.community.CommunityUser/getInfo')->name('getInfo');//获取个人中心信息
            Route::get('topic_count/:id', 'v1.community.Community/topicCount')->name('topicCount');//话题发帖数量

        })->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');
        //需要授权接口
        Route::group(function () {
            Route::post('community_save', 'v1.community.Community/communitySave')->name('communitySave');//新增
            Route::post('community_update/:id', 'v1.community.Community/update')->name('update');//编辑
            Route::post('community_like/:id', 'v1.community.Community/setCommunityLike')->name('setCommunityLike');//点赞
            Route::get('like_list', 'v1.community.Community/communityLikeList')->name('communityLikeList');//点赞列表
            Route::get('elegant_list', 'v1.community.Community/communityElegantList')->name('communityElegantList');//种草秀
            Route::get('share/:id', 'v1.community.Community/communityShare')->name('communityShare');//分享
            Route::delete('community_delete/:id', 'v1.community.Community/communityDelete')->name('communityDelete');//删除
            Route::get('recommend_list', 'v1.community.CommunityUser/recommendList')->name('recommendList');//推荐用户
            //评论
            Route::post('comment/save', 'v1.community.CommunityComment/save')->name('saveCommunityComment');//新增评论
            Route::post('comment_like/:id', 'v1.community.CommunityComment/setCommentLike')->name('setCommentLike');//评论点赞
            Route::delete('comment_delete/:id', 'v1.community.CommunityComment/commentDelete')->name('commentDelete');//删除

            //主页
            Route::post('update_desc', 'v1.community.CommunityUser/updateDesc')->name('updateDesc');//修改个人简介
            Route::post('set_interest/:authorUid', 'v1.community.CommunityUser/setInterest')->name('setInterest');//关注/取消
            Route::get('follow_list/:type', 'v1.community.CommunityUser/followList')->name('followList');//关注/粉丝列表
            Route::get('user_friend', 'v1.community.CommunityUser/userFriend')->name('userFriend');//好友列表

            Route::get('follow', 'v1.community.CommunityUser/follow')->name('follow');//关注发新作品

            //消息列表
            Route::get('message', 'v1.community.Community/message')->name('MessageCommunityList'); //消息列表

        })->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');
    })->middleware(CommunityOpenMiddleware::class);

	/**
	 * 会员授权接口
	 */
    Route::group(function () {
		//用户修改手机号
		Route::post('user/updatePhone', 'v1.Login/update_binding_phone')->name('updateBindingPhone');
		//设置登录code
		Route::post('user/code', 'v1.user.StoreService/setLoginCode')->name('setLoginCode');
		//查看code是否可用
		Route::get('user/code', 'v1.Login/setLoginKey')->name('getLoginKey');
		//用户绑定手机号
		Route::post('user/binding', 'v1.Login/user_binding_phone')->name('userBindingPhone');
		Route::get('logout', 'v1.Login/logout')->name('logout');// 退出登录
		Route::post('switch_h5', 'v1.Login/switch_h5')->name('switch_h5');// 切换账号

		//商品类
		Route::get('product/code/:id', 'v1.product.StoreProduct/code')->name('productCode');//商品分享二维码 推广员

        //保存商品评价回复
        Route::post('reply/comment/:id', 'v1.product.StoreProductReply/replyComment')->middleware(BlockerMiddleware::class)->name('replyComment');
        //获取评论详情
        Route::get('reply/info/:id', 'v1.product.StoreProductReply/replyInfo')->name('replyInfo');
        //评论回复点赞
        Route::post('reply/praise/:id', 'v1.product.StoreProductReply/commentPraise')->middleware(BlockerMiddleware::class)->name('commentPraise');
        //取消评论回复点赞
        Route::post('reply/un_praise/:id', 'v1.product.StoreProductReply/unCommentPraise')->middleware(BlockerMiddleware::class)->name('unCommentPraise');
        //评论点赞
        Route::post('reply/reply_praise/:id', 'v1.product.StoreProductReply/replyPraise')->middleware(BlockerMiddleware::class)->name('replyPraise');
        //取消评论点赞
        Route::post('reply/un_reply_praise/:id', 'v1.product.StoreProductReply/unReplyPraise')->middleware(BlockerMiddleware::class)->name('unReplyPraise');

        //公共类
        Route::post('upload/image', 'v1.PublicController/upload_image')->name('uploadImage');//图片上传
        Route::post('upload/video', 'v1.PublicController/uploadVideo')->middleware(BlockerMiddleware::class)->name('uploadVideo');//视频上传
        //用户类 客服聊天记录
        Route::get('user/service/list', 'v1.user.StoreService/lst')->name('userServiceList');//客服列表
        Route::get('user/service/record', 'v1.user.StoreService/record')->name('userServiceRecord');//客服聊天记录
        Route::post('user/service/feedback', 'v1.user.StoreService/saveFeedback')->name('saveFeedback');//保存客服反馈信息
        Route::get('user/service/feedback', 'v1.user.StoreService/getFeedbackInfo')->name('getFeedbackInfo');//获得客服反馈头部信息
		Route::get('user/record', 'v1.user.StoreService/recordList')->name('recordList');//获取用户和客服的消息列表

        //用户类  用户
        Route::get('user', 'v1.user.User/user')->name('user');//个人中心

        Route::post('user/edit', 'v1.user.User/edit')->name('userEdit');//用户修改信息
        Route::get('user/balance', 'v1.user.User/balance')->name('userBalance');//用户资金统计
        Route::get('userinfo', 'v1.user.User/userinfo')->name('userinfo');// 用户信息
        Route::get('user/rand_code', 'v1.user.User/randCode')->name('randCode');//查看用户code
        Route::get('user/visit_list', 'v1.user.User/visitList')->name('visitList');//商品浏览列表
        Route::delete('user/visit', 'v1.user.User/visitDelete')->name('visitDelete');//商品浏览记录删除
		Route::get('cancel/user', 'v1.PublicController/cancelUser')->name('cancelUser');// 用户注销


        //用户类  地址
        Route::get('address/detail/:id', 'v1.user.UserAddress/address')->name('address');//获取单个地址
        Route::get('address/list', 'v1.user.UserAddress/address_list')->name('addressList');//地址列表
        Route::post('address/default/set', 'v1.user.UserAddress/address_default_set')->name('addressDefaultSet');//设置默认地址
        Route::get('address/default', 'v1.user.UserAddress/address_default')->name('addressDefault');//获取默认地址
        Route::post('address/edit', 'v1.user.UserAddress/address_edit')->name('addressEdit');//修改 添加 地址
        Route::post('address/del', 'v1.user.UserAddress/address_del')->name('addressDel');//删除地址
        //用户类 收藏
        Route::get('collect/user', 'v1.user.UserCollect/collect_user')->name('collectUser');//收藏商品列表
        Route::post('collect/add', 'v1.user.UserCollect/collect_add')->middleware(BlockerMiddleware::class)->name('collectAdd');//添加收藏
        Route::post('collect/del', 'v1.user.UserCollect/collect_del')->name('collectDel');//取消收藏
        Route::post('collect/all', 'v1.user.UserCollect/collect_all')->name('collectAll');//批量添加收藏

        Route::get('brokerage_rank', 'v1.user.UserBrokerage/brokerage_rank')->name('brokerageRank');//佣金排行
        Route::get('rank', 'v1.user.User/rank')->name('rank');//推广人排行
        //用戶类 分享
        Route::post('user/share', 'v1.PublicController/user_share')->name('user_share');//记录用户分享
        Route::get('user/share/words', 'v1.PublicController/copy_share_words')->name('user_share_words');//关键字分享
        //用户类 点赞
//    Route::post('like/add', 'user.User/like_add')->name('likeAdd');//添加点赞
//    Route::post('like/del', 'user.User/like_del')->name('likeDel');//取消点赞
        //用户类 签到
        Route::post('sign/integral', 'v1.user.UserSign/sign_integral')->middleware(BlockerMiddleware::class)->name('signIntegral');//签到
		Route::get('sign/remind/:status', 'v1.user.UserSign/sign_remind')->name('signRemind');//用户设置签到提醒

        //优惠券类
        Route::post('coupon/receive', 'v1.activity.StoreCoupons/receive')->middleware(BlockerMiddleware::class)->name('couponReceive'); //领取优惠券
        Route::post('coupon/receive/batch', 'v1.activity.StoreCoupons/receive_batch')->middleware(BlockerMiddleware::class)->name('couponReceiveBatch'); //批量领取优惠券
        Route::get('coupons/user/num', 'v1.activity.StoreCoupons/userCount')->name('userCount');//我的优惠券数量
        Route::get('coupons/user/:types', 'v1.activity.StoreCoupons/user')->name('couponsUser');//用户已领取优惠券
        Route::post('coupons/transfer/target', 'v1.activity.StoreCoupons/transferTarget')->middleware(BlockerMiddleware::class)->name('couponTransferTarget');//精确查询转赠接收会员
        Route::post('coupons/transfer', 'v1.activity.StoreCoupons/transfer')->middleware(BlockerMiddleware::class)->name('couponTransfer');//会员优惠券转赠
        Route::get('coupons/order/:price', 'v1.activity.StoreCoupons/order')->name('couponsOrder');//优惠券 订单列表


        //购物车类
        Route::post('cart/compute', 'v1.order.StoreCart/computeCart')->name('computeCart'); //购物车列表重新计算
        Route::post('cart/add', 'v1.order.StoreCart/add')->middleware(BlockerMiddleware::class)->name('cartAdd'); //购物车添加
        Route::post('cart/del', 'v1.order.StoreCart/del')->name('cartDel'); //购物车删除
        Route::post('order/cancel', 'v1.order.StoreOrder/cancel')->name('orderCancel'); //订单删除
        Route::post('order/cancel/del', 'v1.order.StoreOrder/cancel_del')->name('cancelTwoDel'); //交易取消
        Route::post('cart/num', 'v1.order.StoreCart/num')->name('cartNum'); //购物车 修改商品数量

		//预约单
		Route::get('reservation/order/list', 'v1.order.StoreReservationOrder/reservationList')->name('reservationOrderList'); //预约单列表
		Route::get('reservation/order/detail/:id', 'v1.order.StoreReservationOrder/detail')->name('reservationOrderDetail'); //预约单详情
		Route::get('reservation/orderInfo/:id', 'v1.order.StoreReservationOrder/getOrderInfo')->name('orderInfo'); //预约订单信息（售后预约）
		Route::post('reservation/switch', 'v1.order.StoreReservationOrder/switchGoodsInfo')->name('switchGoodsInfo'); //切换门店获取该商品信息
		Route::post('reservation/order/create/:id', 'v1.order.StoreReservationOrder/createReservationOrder')->name('createReservationOrder'); //预约单创建
		Route::post('reservation/order/cancel/:id', 'v1.order.StoreReservationOrder/cancelReservationOrder')->name('cancelReservationOrder'); //预约单取消
		Route::delete('reservation/order/del/:id', 'v1.order.StoreReservationOrder/delReservationOrder')->name('delReservationOrder'); //已取消预约单删除
		Route::get('reservation/order/purchased_items', 'v1.order.StoreReservationOrder/getUserPurchasedRemainItems')->name('reservationPurchasedItems'); //用户可预约已购项目
		// 新生命周期会员预约：只读写 V3 权威表，不读取或迁移历史预约。
		Route::get('reservation/v3/order/list', 'v1.order.V3ReservationOrder/listing')->name('memberV3ReservationList');
		Route::get('reservation/v3/order/detail/:id', 'v1.order.V3ReservationOrder/detail')->name('memberV3ReservationDetail');
		Route::post('reservation/v3/order/create/:orderId', 'v1.order.V3ReservationOrder/create')->name('memberV3ReservationCreate');
		Route::post('reservation/v3/order/cancel/:id', 'v1.order.V3ReservationOrder/cancel')->name('memberV3ReservationCancel');
		Route::delete('reservation/v3/order/del/:id', 'v1.order.V3ReservationOrder/delete')->name('memberV3ReservationDelete');
		Route::post('reservation/v3/order/del/:id', 'v1.order.V3ReservationOrder/delete')->name('memberV3ReservationDeletePost');
		Route::get('reservation/staff/list', 'v1.reservation.ReservationStaff/list')->name('reservationStaffListAuth'); //预约服务人员列表（按门店）
		Route::get('reservation/staff/available_time', 'v1.reservation.ReservationStaff/availableTime')->name('reservationStaffAvailableTimeAuth'); //员工已被占用时段
		Route::get('reservation/staff/service_time_slots', 'v1.reservation.ReservationStaff/serviceTimeSlots')->name('reservationStaffServiceTimeSlotsAuth'); //门店可预约时段
		Route::get('reservation/staff/busy', 'v1.reservation.ReservationStaff/busyStaff')->name('reservationStaffBusyAuth'); //指定时段已占用员工
		Route::get('reservation/staff/conflicts', 'v1.reservation.ReservationStaff/staffConflicts')->name('reservationStaffConflictsAuth'); //手艺人预约冲突

        //订单类
        Route::post('order/check_shipping', 'v1.order.StoreOrder/checkShipping')->name('checkShipping'); //检测是否显示快递和核销标签
        Route::post('order/confirm', 'v1.order.StoreOrder/confirm')->middleware(BlockerMiddleware::class)->name('orderConfirm'); //订单确认
        Route::post('order/computed/:key', 'v1.order.StoreOrder/computedOrder')->middleware(BlockerMiddleware::class)->name('computedOrder'); //计算订单金额
        Route::post('order/create/:key', 'v1.order.StoreOrder/create')->middleware(BlockerMiddleware::class)->name('orderCreate'); //订单创建
        Route::get('order/group/product', 'v1.order.StoreOrder/groupPurchaseProduct')->name('groupPurchaseProduct'); //提交订单凑单处理
        Route::get('order/cashier/:orderId/[:type]', 'v1.order.StoreOrder/cashier')->name('orderCashier'); //订单收银台
        Route::get('order/data', 'v1.order.StoreOrder/data')->name('orderData'); //订单统计数据
        Route::get('order/list', 'v1.order.StoreOrder/lst')->name('orderList'); //订单列表
        Route::get('order/detail/:uni', 'v1.order.StoreOrder/detail')->name('orderDetail'); //订单详情
        Route::get('debt/summary', 'v1.order.StoreDebt/summary')->name('debtSummary'); //欠款汇总
        Route::get('debt/list', 'v1.order.StoreDebt/lst')->name('debtList'); //会员端欠款列表
        Route::get('debt/cashier/:orderId', 'v1.order.StoreDebt/cashier')->name('debtCashier'); //欠款收银台
        Route::post('debt/repay/pay', 'v1.order.StoreDebt/repayPay')->middleware(BlockerMiddleware::class)->name('debtRepayPay'); //欠款还款
        Route::get('card/order/benefits/:id', 'v1.order.StoreOrder/getCardBenefits')->name('getCardBenefits'); //获取卡项权益
		Route::post('order/prize/:orderId', 'v1.order.StoreOrder/getOrderPrize');//获取订单下单奖励
        Route::get('order/write/records/:id', 'v1.order.StoreOrder/writeOffRecords')->name('writeOffRecords'); //订单核销记录
        Route::get('delivery_order/detail/:id', 'v1.order.StoreOrder/deliveryOrderDetail')->name('deliveryOrderDetail'); //配送订单详情

		Route::post('order/take', 'v1.order.StoreOrder/take')->middleware(BlockerMiddleware::class)->name('orderTake'); //订单收货
		Route::get('order/express/:uni/[:type]', 'v1.order.StoreOrder/express')->name('orderExpress'); //订单查看物流
		Route::post('order/del', 'v1.order.StoreOrder/del')->middleware(BlockerMiddleware::class)->name('orderDel'); //订单删除
		Route::post('order/again', 'v1.order.StoreOrder/again')->name('orderAgain'); //订单 再次下单
		Route::post('order/pay', 'v1.order.StoreOrder/pay')->middleware(BlockerMiddleware::class)->name('orderPay'); //订单支付
		Route::post('order/product', 'v1.order.StoreOrder/product')->name('orderProduct'); //订单商品信息
		Route::post('order/comment', 'v1.order.StoreOrder/comment')->middleware(BlockerMiddleware::class)->name('orderComment'); //订单评价
		Route::get('order/pay_cashier', 'v1.order.StoreOrder/payCashierOrder')->name('payCashierOrder'); //用户门店下单付款

        //订单售后
        Route::get('order/refund/reason', 'v1.order.StoreOrder/refund_reason')->name('orderRefundReason'); //订单退款理由
        Route::get('order/refund/cart_info/:id', 'v1.order.StoreOrder/refundCartInfo')->name('StoreOrderRefundCartInfo');//获取退款商品列表
        Route::post('order/refund/cart_info', 'v1.order.StoreOrder/refundCartInfoList')->name('StoreOrderRefundCartInfoList');//获取退款商品列表
        Route::post('order/refund/apply/:id', 'v1.order.StoreOrder/applyRefund')->middleware(BlockerMiddleware::class)->name('StoreOrderApplRefund');//订单申请退款V2
        Route::post('order/refund/express', 'v1.order.StoreOrder/refund_express')->name('orderRefundExpress'); //退货退款填写订单号
        Route::get('order/refund/list', 'v1.order.StoreOrderRefund/lst')->name('orderRefundList'); //售后订单列表
        Route::get('order/refund/detail/:uni', 'v1.order.StoreOrderRefund/detail')->name('orderRefundDetail'); //售后订单详情
        Route::post('order/refund/cancel/:uni', 'v1.order.StoreOrderRefund/cancelApply')->name('orderRefundCancel'); //取消售后申请
		Route::post('order/refund/again/:id', 'v1.order.StoreOrderRefund/againRefundOrder')->middleware(BlockerMiddleware::class)->name('againRefundOrder'); //再次提交售后申请
        Route::get('order/refund/del/:uni', 'v1.order.StoreOrderRefund/delRefundOrder')->middleware(BlockerMiddleware::class)->name('delRefundOrder'); //删除已退款和拒绝退款的订单

        //活动---砍价
        Route::post('bargain/start', 'v1.activity.StoreBargain/start')->middleware(BlockerMiddleware::class)->name('bargainStart');//砍价开启
        Route::post('bargain/start/user', 'v1.activity.StoreBargain/start_user')->name('bargainStartUser');//砍价 开启砍价用户信息
        Route::post('bargain/share', 'v1.activity.StoreBargain/share')->name('bargainShare');//砍价 观看/分享/参与次数
        Route::post('bargain/help', 'v1.activity.StoreBargain/help')->middleware(BlockerMiddleware::class)->name('bargainHelp');//砍价 帮助好友砍价
        Route::post('bargain/help/price', 'v1.activity.StoreBargain/help_price')->name('bargainHelpPrice');//砍价 砍掉金额
        Route::post('bargain/help/count', 'v1.activity.StoreBargain/help_count')->name('bargainHelpCount');//砍价 砍价帮总人数、剩余金额、进度条、已经砍掉的价格
        Route::post('bargain/help/list', 'v1.activity.StoreBargain/help_list')->name('bargainHelpList');//砍价 砍价帮
        Route::get('bargain/user/list', 'v1.activity.StoreBargain/user_list')->name('bargainUserList');//砍价列表(已参与)
        Route::post('bargain/user/cancel', 'v1.activity.StoreBargain/user_cancel')->name('bargainUserCancel');//砍价取消
        Route::get('bargain/poster_info/:bargainId', 'v1.activity.StoreBargain/posterInfo')->name('posterInfo');//砍价海报详细信息
        //活动---拼团
        Route::get('combination/pink/:id', 'v1.activity.StoreCombination/pink')->name('combinationPink');//拼团开团
        Route::post('combination/remove', 'v1.activity.StoreCombination/remove')->name('combinationRemove');//拼团 取消开团
        Route::get('combination/poster_info/:id', 'v1.activity.StoreCombination/posterInfo')->name('pinkPosterInfo');//拼团海报详细获取
        //账单类
        Route::get('commission', 'v1.user.UserBrokerage/commission')->name('commission');//推广数据 昨天的佣金 累计提现金额 当前佣金
        Route::post('spread/people', 'v1.user.User/spread_people')->name('spreadPeople');//推荐用户
        Route::post('spread/order', 'v1.user.UserBrokerage/spread_order')->name('spreadOrder');//推广订单
        Route::get('spread/commission/:type', 'v1.user.UserBill/spread_commission')->name('spreadCommission');//推广佣金明细
        Route::get('spread/count/:type', 'v1.user.UserBrokerage/spread_count')->name('spreadCount');//推广 佣金 3/提现 4 总和
        Route::get('integral/list', 'v1.user.UserBill/integral_list')->name('integralList');//积分记录
        Route::get('user/routine_code', 'v1.user.UserBill/getRoutineCode')->name('getRoutineCode');//小程序二维码
        Route::get('user/spread_info', 'v1.user.UserBill/getSpreadInfo')->name('getSpreadInfo');//获取分销背景等信息
        //提现类
        Route::get('extract/bank', 'v1.user.UserExtract/bank')->name('extractBank');//提现银行/提现最低金额
		Route::get('extract/list', 'v1.user.UserExtract/extractList')->name('extractDetail');//提现列表
		Route::get('extract/detail', 'v1.user.UserExtract/detail')->name('extractDetail');//提现详情
        Route::post('extract/cash', 'v1.user.UserExtract/cash')->middleware(BlockerMiddleware::class)->name('extractCash');//提现申请
        //储值类
        Route::post('recharge/recharge', 'v1.user.UserRecharge/recharge')->middleware(BlockerMiddleware::class)->name('rechargeRecharge');//统一储值
		Route::post('recharge/pay', 'v1.user.UserRecharge/pay')->middleware(BlockerMiddleware::class)->name('rechargePay');//统一储值 支付

        //会员等级类
        Route::get('user/level/task/:id', 'v1.user.UserLevel/task')->name('userLevelTask');//获取等级任务
        Route::get('user/level/expList', 'v1.user.UserLevel/expList')->name('expList');//获取经验列表
        Route::post('user/level/activate', 'v1.user.UserLevel/activateLevel')->name('userActivateLevel');//用户激活会员卡

        //首页获取未支付订单
        Route::get('order/nopay', 'v1.order.StoreOrder/get_noPay')->name('getNoPay');//获取未支付订单

        Route::get('seckill/code/:id', 'v1.activity.StoreSeckill/code')->name('seckillCode');//秒杀商品海报
        Route::get('combination/code/:id', 'v1.activity.StoreCombination/code')->name('combinationCode');//拼团商品海报

        //会员卡
        Route::post('user/member/card/draw', 'v1.user.MemberCard/draw_member_card')->middleware(BlockerMiddleware::class)->name('userMemberCardDraw');//卡密领取会员卡
        Route::post('user/member/card/create', 'v1.order.OtherOrder/create')->middleware(BlockerMiddleware::class)->name('userMemberCardCreate');//购买卡创建订单
		Route::post('user/member/card/pay', 'v1.order.OtherOrder/pay')->name('userMemberCardCreatePay');//会员订单支付
        Route::get('user/member/coupons/list', 'v1.user.MemberCard/memberCouponList')->name('userMemberCouponsList');//会员券列表

        //线下付款
        Route::post('order/offline/check/price', 'v1.order.OtherOrder/computed_offline_pay_price')->name('orderOfflineCheckPrice'); //检测线下付款金额
        Route::post('order/offline/create', 'v1.order.OtherOrder/create')->name('orderOfflineCreate'); //检测线下付款金额
        Route::get('order/offline/pay/type', 'v1.order.OtherOrder/pay_type')->name('orderOfflineCreate'); //线下付款支付方式
        //消息站内信
		Route::get('user/message', 'v1.user.SystemMessage/message')->name('MessageSystemList'); //用户信息
        Route::get('user/message_system/list', 'v1.user.SystemMessage/message_list')->name('SystemMessageList'); //站内信列表
        Route::get('user/message_system/detail/:id', 'v1.user.SystemMessage/detail')->name('SystemMessageDetail'); //详情
		//供应商申请
		Route::get('user/apply/record', 'v1.system.SupplierApply/userApplyRecord')->name('userApplyRecord'); //供应商申请记录
		Route::post('user/apply/supplier/:id', 'v1.system.SupplierApply/userApply')->middleware(BlockerMiddleware::class)->name('userApplySupplier'); //供应商申请
		Route::get('user/apply/:id', 'v1.system.SupplierApply/getInfo')->name('userApplyInfo'); //单个申请记录数据
		//分销员申请
		Route::get('user/promoter/apply/info', 'v1.system.PromoterApply/applyInfo')->name('申请信息');//申请信息
		Route::post('user/promoter/apply/:id', 'v1.system.PromoterApply/applyPromoter')->name('申请分销员');//申请分销员
		//加盟店申请
		Route::post('user/apply/store/:id', 'v1.system.StoreApply/userApply')->middleware(BlockerMiddleware::class)->name('userApplySupplier'); //加盟店申请
		Route::get('user/apply/store/record', 'v1.system.StoreApply/userApplyRecord')->name('userApplyRecord'); //加盟店申请记录
		Route::get('user/apply/store/:id', 'v1.system.StoreApply/getInfo')->name('userApplyInfo'); //单个申请记录数据

    })->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

    /**
     * v2 版本路由
     */
    Route::group('v2', function () {
        //无需授权接口
        Route::group(function () {
			//小程序登录页面自动加载，返回用户信息的缓存key，返回是否强制绑定手机号
			Route::get('routine/auth_type', 'v2.wechat.Routine/authType')->option(['real_name' => '小程序页面登录类型']);
			//小程序授权登录，返回token
			Route::get('routine/auth_login', 'v2.wechat.Routine/authLogin')->option(['real_name' => '小程序授权登录']);
			//小程序授权绑定手机号
			Route::post('routine/auth_binding_phone', 'v2.wechat.Routine/authBindingPhone')->option(['real_name' => '小程序授权绑定手机号']);
			//小程序手机号直接登录
			Route::post('routine/phone_login', 'v2.wechat.Routine/phoneLogin')->option(['real_name' => '手机号直接登录']);
			//小程序授权后绑定手机号
			Route::post('routine/binding_phone', 'v2.wechat.Routine/BindingPhone')->option(['real_name' => '小程序授权后绑定手机号']);
			//公众号授权登录，返回token
			Route::get('wechat/auth_login', 'v2.wechat.Wechat/authLogin')->option(['real_name' => '公众号授权登录']);
			//公众号授权绑定手机号
			Route::post('wechat/auth_binding_phone', 'v2.wechat.Wechat/silenceAuthBindingPhone')->option(['real_name' => '公众号授权绑定手机号']);


            //小程序授权
            Route::get('wechat/routine_auth', 'v2.wechat.Routine/auth');
            //小程序静默授权
            Route::get('wechat/silence_auth', 'v2.wechat.Routine/silenceAuthNoLogin');
            //小程序静默授权登录
            Route::get('wechat/silence_auth_login', 'v2.wechat.Routine/silenceAuth');
			//小程序授权绑定手机号
			Route::post('auth_bindind_phone', 'v2.wechat.Routine/authBindingPhone');
			//小程序手机号登录直接绑定
			Route::post('phone_silence_auth', 'v2.wechat.Routine/silenceAuthBindingPhone');
			//公众号授权登录
			Route::get('wechat/auth', 'v2.wechat.Wechat/auth');
            //公众号静默授权
            Route::get('wechat/wx_silence_auth', 'v2.wechat.Wechat/silenceAuthNoLogin');
            //公众号静默授权登录
            Route::get('wechat/wx_silence_auth_login', 'v2.wechat.Wechat/silenceAuth');
			//微信手机号登录直接绑定
			Route::post('phone_wx_silence_auth', 'v2.wechat.Wechat/silenceAuthBindingPhone');

            //DIY接口
            Route::get('diy/get_diy/[:name]', 'v2.PublicController/getDiy');
            //是否强制绑定手机号
            Route::get('bind_status', 'v2.PublicController/bindPhoneStatus');
            //获取门店核销开启状态
            Route::get('diy/get_store_status', 'v2.PublicController/getStoreStatus');
            //一键换色
            Route::get('diy/color_change/:name', 'v2.PublicController/colorChange');
			//商品详情diy
            Route::get('diy/product_detail', 'v2.PublicController/productDetailDiy');
            //获取地址列表
            Route::get('cityList', 'v2.PublicController/cityList');
            //活动优惠活动商品列表
            Route::get('promotions/productList/:type', 'v2.activity.StorePromotions/productList');
			//优惠活动赠品信息
            Route::get('promotions/give_info/:id', 'v2.activity.StorePromotions/getPromotionsGive');
        });
        //需要授权
        Route::group(function () {
            Route::post('reset_cart', 'v2.order.StoreCart/resetCart')->name('resetCart');
            Route::get('new_coupon', 'v2.activity.StoreCoupons/getNewCoupon')->name('getNewCoupon');//获取新人券
            Route::post('user/user_update', 'v2.wechat.Routine/updateInfo');
            Route::post('order/product_coupon/:orderId', 'v2.activity.StoreCoupons/getOrderProductCoupon');//获取订单商品关联优惠券
            Route::get('user/service/record', 'v2.user.StoreService/record')->name('userServiceRecord');//客服聊天记录
            Route::get('cart_list', 'v2.order.StoreCart/getCartList');//门店首页购物车列表
			Route::get('cart/count', 'v2.order.StoreCart/count');//门店首页购物车数量
            Route::get('get_attr/:id/:type', 'v2.product.StoreProduct/getProductAttr');
            Route::post('set_cart_num', 'v2.order.StoreCart/setCartNum');
            //订单申请发票
            Route::post('order/make_up_invoice', 'v2.order.StoreOrderInvoice/makeUp')->name('orderMakeUpInvoice');
            //订单再次开票
            Route::post('order/make_once_invoice', 'v2.order.StoreOrderInvoice/onceMoreMakeUp')->name('orderonceMoreMakeUpInvoice');
            //用户发票列表
            Route::get('invoice', 'v2.user.UserInvoice/invoiceList')->name('userInvoiceLIst');
            //单个发票详情
            Route::get('invoice/detail/:id', 'v2.user.UserInvoice/invoice')->name('userInvoiceDetail');
            //修改|添加发票
            Route::post('invoice/save', 'v2.user.UserInvoice/saveInvoice')->name('userInvoiceSave');
            //设置默认发票
            Route::post('invoice/set_default/:id', 'v2.user.UserInvoice/setDefaultInvoice')->name('userInvoiceSetDefault');
            //获取默认发票
            Route::get('invoice/get_default/:type', 'v2.user.UserInvoice/getDefaultInvoice')->name('userInvoiceGetDefault');
            //删除发票
            Route::get('invoice/del/:id', 'v2.user.UserInvoice/delInvoice')->name('userInvoiceDel');
            //订单申请开票记录
            Route::get('order/invoice_list', 'v2.order.StoreOrderInvoice/list')->name('orderInvoiceList');
            //订单开票详情
            Route::get('order/invoice_detail/:uni', 'v2.order.StoreOrderInvoice/detail')->name('orderInvoiceList');

            //清除搜索记录
            Route::get('user/clean_search', 'v2.user.UserSearch/cleanUserSearch')->name('cleanUserSearch');
            //更新公众号用户信息
            Route::get('user/wechat', 'v2.user.User/updateUserInfo')->name('updateUserInfo');

            //参与抽奖
            Route::post('lottery', 'v2.activity.LuckLottery/luckLottery')->middleware(BlockerMiddleware::class)->name('luckLottery');
            //领取奖品
            Route::post('lottery/receive', 'v2.activity.LuckLottery/lotteryReceive')->middleware(BlockerMiddleware::class)->name('lotteryReceive');
            //抽奖记录
            Route::get('lottery/record', 'v2.activity.LuckLottery/lotteryRecord')->name('lotteryRecord');
            //活动使用id
            Route::get('lottery/use', 'v2.activity.LuckLottery/getfactorUse')->name('getfactorUse');
            //获取分销等级列表
            Route::get('agent/level_list', 'v2.spread.AgentLevel/levelList')->name('agentLevelList');
            //获取分销等级任务列表
            Route::get('agent/level_task_list', 'v2.spread.AgentLevel/levelTaskList')->name('agentLevelTaskList');

            //获取用户余额、佣金、提现明细列表
            Route::get('user/money_list/:type', 'v2.user.User/userMoneyList')->name('userMoneyList');
            //获取用户推广用户列表
            Route::get('agent/agent_user_list/:type', 'v2.spread.Spread/agentUserList')->name('agentUserList');
            //获取用户推广获得收益，佣金轮播，分销规则
            Route::get('agent/agent_info', 'v2.spread.Spread/agentInfo')->name('agentInfo');

            //优惠活动凑单商品列表
            Route::get('promotions/collect_order/product', 'v2.activity.StorePromotions/collectOrderProduct');

            //拼单接口
            //验证是否在配送范围
            Route::get('is/within', 'v2.activity.UserCollage/isWithinScopeDistribution')->name('isWithinScopeDistribution');
            //发起拼单
            Route::get('user/initiate/collage', 'v2.activity.UserCollage/userInitiateCollage')->middleware(BlockerMiddleware::class)->name('userInitiateCollage');
            //检查用户是否发起拼单
            Route::get('is/user/initiate/collage', 'v2.activity.UserCollage/isUserInitiateCollage')->name('isUserInitiateCollage');
            //检查拼单
            Route::get('is/initiate/collage', 'v2.activity.UserCollage/isInitiateCollage')->name('isInitiateCollage');
            //购物车 统计 数量
            Route::get('user/initiate/collage/count', 'v2.activity.UserCollage/count')->name('count');
            //拼单购物车列表
            Route::get('user/initiate/collage/cart_list', 'v2.activity.UserCollage/getCartList')->name('getCartList');
            //拼单购物车删除商品
            Route::post('del/collage/cart', 'v2.activity.UserCollage/delUserCollagePartake')->name('delUserCollagePartake');
            //获取核销门店信息
            Route::get('collage/store/data', 'v2.activity.UserCollage/getStoredata')->name('getStoredata');
            //用户添加拼单商品
            Route::post('add/collage/partake', 'v2.activity.UserCollage/addCollagePartake')->name('addCollagePartake');
            //用户清空拼单数据
            Route::get('empty/collage/partake', 'v2.activity.UserCollage/emptyCollagePartake')->name('emptyCollagePartake');
            //复制他人拼单商品
            Route::get('duplicate/collage/partake', 'v2.activity.UserCollage/duplicateCollagePartake')->name('duplicateCollagePartake');
            //获取用户拼单数据
            Route::get('user/collage/partake', 'v2.activity.UserCollage/getUserCollagePartake')->name('getUserCollagePartake');
            //取消拼单
            Route::get('user/cancel', 'v2.activity.UserCollage/cancelInitiateCollage')->name('cancelInitiateCollage');
            //结算拼单
            Route::get('user/settle/collage', 'v2.activity.UserCollage/userSettleAccountsCollage')->name('userSettleAccountsCollage');

            //桌码
            //门店桌码配置
            Route::get('table/data', 'v2.activity.UserCode/getData')->name('getData');
            //记录桌码
            Route::get('add/table/code', 'v2.activity.UserCode/setTableCode')->name('setTableCode');
            //检查是否开启桌码
            Route::get('is/table/code', 'v2.activity.UserCode/isUserTableCode')->name('isUserTableCode');
            //处理换桌商品
            Route::get('changing/table', 'v2.activity.UserCode/userChangingTables')->name('userChangingTables');
            //获取桌码记录
            Route::get('get/table/code', 'v2.activity.UserCode/getTableCode')->name('getTableCode');
            //获取门店信息
            Route::get('get/store/data', 'v2.activity.UserCode/getStoredata')->name('getStoredata');
            //获取二维码信息
            Route::get('get/code/data', 'v2.activity.UserCode/getTableCodeData')->name('getTableCodeData');
            //桌码购物车 统计 数量
            Route::get('table/cart/count', 'v2.activity.UserCode/count')->name('count');
            //获取购物车
            Route::get('get/cate/list', 'v2.activity.UserCode/getCartList')->name('getCartList');
            //桌码购物车删除商品
            Route::post('del/table/cart', 'v2.activity.UserCode/delUserTableCodePartake')->name('delUserTableCodePartake');
            //确认下单
            Route::get('user/place/order', 'v2.activity.UserCode/userPlaceOrder')->name('userPlaceOrder');
            //用户添加桌码商品
            Route::get('add/table/cate', 'v2.activity.UserCode/addTableCodePartake')->name('addTableCodePartake');
            //用户清空购物车
            Route::get('user/empty/data', 'v2.activity.UserCode/emptyTablePartake')->name('emptyTablePartake');
            //获取桌码数据
            Route::get('get/table/partake', 'v2.activity.UserCode/getUserTableCodePartake')->name('getUserTableCodePartake');
            //结算拼单
            Route::get('user/settle/table', 'v2.activity.UserCode/userSettleAccountsCollage')->name('userSettleAccountsCollage');

            //卡项卡包
            //获取卡包列表
            Route::get('user/card/list', 'v2.user.UserCardHolder/getCardHolder')->name('getCardHolder');
            //卡项信息
            Route::get('card/holder/:id', 'v2.user.UserCardHolder/cardHolder')->name('cardHolder');
            //删除卡包
            Route::get('del/holder/:id', 'v2.user.UserCardHolder/delCardHolder')->name('delCardHolder');

        })->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

        //授权不通过,不会抛出异常继续执行
        Route::group(function () {
            //用户搜索记录
            Route::get('user/search_list', 'v2.user.UserSearch/getUserSeachList')->name('userSearchList');
            Route::get('get_today_coupon', 'v2.activity.StoreCoupons/getTodayCoupon');//新优惠券弹窗接口
            Route::get('subscribe', 'v2.PublicController/subscribe')->name('WechatSubscribe');// 微信公众号用户是否关注
            //公共类
            Route::get('index', 'v2.PublicController/index')->name('index');//首页
            //优惠券
            Route::get('coupons', 'v2.activity.StoreCoupons/lst')->name('couponsList'); //可领取优惠券列表
            Route::get('coupons/detail/:id', 'v2.activity.StoreCoupons/detail')->name('detail');//优惠券详情
            Route::post('coupons/applicable', 'v2.activity.StoreCoupons/applicableCoupon')->name('applicableCoupon');//获取该优惠券的适用门店
            Route::post('store/coupons', 'v2.activity.StoreCoupons/storeCoupon')->name('storeCoupon');//门店首页优惠券

            //商品评价列表
            Route::get('reply/list/:id', 'v2.product.StoreProduct/reply_list')->name('v2replyList');//商品评价列表

			//抽奖活动详情
			Route::get('lottery/info/:factor/:id', 'v2.activity.LuckLottery/lotteryInfo')->name('lotteryInfo');

        })->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

    });

	/**
	 * 营销路由
	 */
	Route::group('marketing', function () {

		//无需登录接口
		Route::group(function () {

		});

		//未授权接口---不会抛异常
		Route::group(function () {
			Route::get('short_video', 'v1.activity.Video/list')->name('shortVideoList');//短视频列表
            Route::get('short_video/info/:id', 'v1.activity.Video/info')->name('shortVideoProductInfo');//短视频详情
			Route::get('short_video/comment/:id', 'v1.activity.Video/commentList')->name('shortVideoCommentList');//短视频评论列表
			Route::get('short_video/product/:id', 'v1.activity.Video/productList')->name('shortVideoProductList');//短视频关联商品列表

			//新人礼
			Route::get('newcomer/info', 'v1.activity.StoreNewcomer/getInfo')->name('newcomerInfo');//新人礼信息
			Route::get('newcomer/product_list', 'v1.activity.StoreNewcomer/lst')->name('newcomerProductList');//新人专享商品
			Route::get('newcomer/product_detail/:id', 'v1.activity.StoreNewcomer/detail')->name('newcomerProductInfo');//新人商品详情

		})->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

		//需要授权接口
		Route::group(function () {
			Route::post('short_video/comment/:id/:pid', 'v1.activity.Video/saveComment')->middleware(BlockerMiddleware::class)->name('shortVideoComment');//短视频评论
			Route::get('short_video/comment_reply/:pid', 'v1.activity.Video/commentReplyList')->name('shortVideoCommentReplyList');//短视频评论回复列表
			Route::delete('short_video/comment/:id', 'v1.activity.Video/commentDelete')->name('shortVideoCommentDelete');//删除短视频评论

			Route::get('short_video/comment/:type/:id', 'v1.activity.Video/commentRelation')->middleware(BlockerMiddleware::class)->name('shortVideoCommentRelation');//短视频评论点赞
			Route::get('short_video/:type/:id', 'v1.activity.Video/relation')->middleware(BlockerMiddleware::class)->name('shortVideoRelation');//短视频点赞、收藏、分享

			//新人礼
			Route::get('newcomer/gift', 'v1.activity.StoreNewcomer/getGift')->name('newcomerInfo');//新人大礼包弹窗信息

		})->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

	});

	/**
	* pc 路由
	 */
    Route::group('pc', function () {
        //登录接口
        Route::group(function () {
            Route::get('key', 'pc.Login/getLoginKey')->name('getLoginKey');//获取扫码登录key
            Route::get('scan/:key', 'pc.Login/scanLogin')->name('scanLogin');//检测扫码情况
            Route::get('get_appid', 'pc.Login/getAppid')->name('getAppid');//检测扫码情况
            Route::get('wechat_auth', 'pc.Login/wechatAuth')->name('wechatAuth');//检测扫码情况
        });

        //未授权接口
        Route::group(function () {
            Route::get('get_pay_vip_code', 'pc.Home/getPayVipCode')->name('getPayVipCode');//获取付费会员购买页面二维码
            Route::get('get_product_phone_buy', 'pc.Home/getProductPhoneBuy')->name('getProductPhoneBuy');//手机购买跳转url配置
            Route::get('get_banner', 'pc.Home/getBanner')->name('getBanner');//PC首页轮播图
            Route::get('get_category_product', 'pc.Home/getCategoryProduct')->name('getCategoryProduct');//首页分类尚品
            Route::get('get_products', 'pc.Product/getProductList')->name('getProductList');//商品列表
            Route::get('get_product_code/:product_id', 'pc.Product/getProductRoutineCode')->name('getProductRoutineCode');//商品详情小程序二维码
            Route::get('get_city/:pid', 'pc.PublicController/getCity')->name('getCity');//获取城市数据
            Route::get('check_order_status/:order_id/:end_time', 'pc.Order/checkOrderStatus')->name('checkOrderStatus');//轮询订单状态接口
            Route::get('get_company_info', 'pc.PublicController/getCompanyInfo')->name('getCompanyInfo');//获取公司信息
            Route::get('get_recommend/:type', 'pc.Product/getRecommendList')->name('getRecommendList');//获取推荐商品
            Route::get('get_wechat_qrcode', 'pc.PublicController/getWechatQrcode')->name('getWechatQrcode');//获取关注二维码
            Route::get('get_good_product', 'pc.Product/getGoodProduct')->name('getGoodProduct');//获取优品推荐
        })->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

        //会员授权接口
        Route::group(function () {
            Route::get('get_cart_list', 'pc.Cart/getCartList')->name('getCartList');//购物车列表
            Route::get('get_balance_record/:type', 'pc.User/getBalanceRecord')->name('getBalanceRecord');//余额记录
            Route::get('get_order_list', 'pc.Order/getOrderList')->name('getOrderList');//订单列表
            Route::get('get_collect_list', 'pc.User/getCollectList')->name('getCollectList');//收藏列表
            Route::post('order/refund/cart_info', 'pc.Order/refundCartInfoList')->name('StoreOrderRefundCartInfoList');//获取退款商品列表
            Route::get('order/refund/list', 'pc.Order/refundList')->name('orderRefundList'); //售后订单列表
        })->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

    });

	/**
	 * 移动端商家管理
	 */
	Route::group('admin', function () {
		//控制台
		Route::get('erp/config', 'admin.order.StoreOrder/getErpConfig')->name('getErpConfig');//获取erp配置
		Route::get('refund_order/list', 'admin.order.StoreOrder/refundOrderList')->name('RefundOrderList');//退款订单列表
		Route::get('refund_order/detail/:uni', 'admin.order.StoreOrder/refundOrderDetail')->name('RefundOrderDetail');//退款订单详情
		Route::post('refund_order/remark', 'admin.order.StoreOrder/refundRemark')->name('refundRemark');//退款订单备注

		//移动端管理客服信息
		Route::get('service/info', 'admin.StoreService/info')->name('serviceInfo');//移动端管理客服信息

		//商品
		Route::group('product', function () {
			//代客下单商品
			Route::get('category', 'admin.product.StoreProductCategory/category')->name('category');//商品分类
			Route::get('list', 'admin.product.StoreProduct/lst')->name('products');//商品列表

			//商品管理
			Route::get('admin_list', 'admin.product.StoreProduct/adminList')->name('products');//管理商品列表
			Route::post('set_show', 'admin.product.StoreProduct/setShow')->name('setShow');//修改商品状态
			Route::get('product_label', 'admin.product.StoreProduct/labelTreeList')->name('labelTreeList');//商品标签树形列表
			Route::get('get_attr/:id', 'admin.product.StoreProduct/getAttr')->name('updateAttrs');//获取商品规格
			Route::post('update_attrs/:id', 'admin.product.StoreProduct/updateAttrs')->name('updateAttrs');//修改库存价格
			Route::post('batch_process', 'admin.product.StoreProduct/batchProcess')->name('batchProcess');//修改分类标签
		});


		//用户
		Route::group('user', function () {
			Route::get('list', 'admin.user.User/list')->name('list');//用户列表
			Route::get('label/:uid', 'admin.user.User/userLabel')->name('userLabel');//用户标签
			Route::get('coupon/grant', 'admin.user.User/couponGrant')->name('couponGrant');//优惠券列表
			Route::get('group/list', 'admin.user.User/userGroup')->name('userGroup');//用户分组
			Route::get('level/list', 'admin.user.User/userLevel')->name('userLevel');//用户等级
			Route::get('info/:uid', 'admin.user.User/info')->name('info');//用户详情
			Route::post('update_other/:uid', 'admin.user.User/updateOther')->middleware(BlockerMiddleware::class)->name('updateOther');//修改余额/积分
			Route::post('update', 'admin.user.User/update')->middleware(BlockerMiddleware::class)->name('update');//用户编辑

			Route::get('address/list/:uid', 'admin.user.UserAddress/address_list')->name('UserAddressList');//用户地址列表
			Route::get('address/default/:uid', 'admin.user.UserAddress/address_default')->name('addressDefault');//获取用户默认地址
		})->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

		//订单
		Route::group('order', function () {
			Route::get('statistics', 'admin.order.StoreOrder/statistics')->name('adminOrderStatistics');//订单数据统计
			Route::get('staging', 'admin.order.StoreOrder/stagingData')->name('adminOrderstagingData');//工作台数据统计
			Route::get('data', 'admin.order.StoreOrder/data')->name('adminOrderData');//订单每月统计数据
			Route::get('list', 'admin.order.StoreOrder/lst')->name('adminOrderList');//订单列表
			Route::get('detail/:orderId', 'admin.order.StoreOrder/detail')->name('adminOrderDetail');//订单详情
			Route::post('confirm/delivery', 'admin.order.StoreOrder/confirm_delivery')->name('confirmDelivery');//配送员确认送达
			Route::post('reassign/delivery/:id', 'admin.order.StoreOrder/reassign_delivery')->name('reassignDelivery');//订单改派
			Route::get('delivery/gain/:orderId', 'admin.order.StoreOrder/delivery_gain')->name('adminOrderDeliveryGain');//订单发货获取订单信息
			Route::post('delivery/keep/:id', 'admin.order.StoreOrder/delivery_keep')->middleware(BlockerMiddleware::class)->name('adminOrderDeliveryKeep');//订单发货
			Route::post('price', 'admin.order.StoreOrder/price')->name('adminOrderPrice');//订单改价
			Route::post('remark', 'admin.order.StoreOrder/remark')->name('adminOrderRemark');//订单备注
			Route::get('time', 'admin.order.StoreOrder/time')->name('adminOrderTime');//订单交易额
			Route::get('time/chart', 'admin.order.StoreOrder/timeChart')->name('timeChart');//订单交易额时间统计
			Route::post('offline', 'admin.order.StoreOrder/offline')->name('adminOrderOffline');//订单支付
			Route::post('refund', 'admin.order.StoreOrder/refund')->middleware(BlockerMiddleware::class)->name('adminOrderRefund');//订单退款
			Route::post('refund_agree/:id', 'admin.order.StoreOrder/agreeRefund')->name('adminOrderAgreeRefund');//商家同意退货退款

			Route::get('delivery/remind/:id', 'admin.order.StoreOrder/deliverRemind')->name('getDeliveryAll');//提醒发货
			Route::get('delivery', 'admin.order.StoreOrder/getDeliveryAll')->name('getDeliveryAll');//获取配送员
			Route::get('delivery_info', 'admin.order.StoreOrder/getDeliveryInfo')->name('getDeliveryInfo');//获取电子面单默认信息
			Route::get('export_temp', 'admin.order.StoreOrder/getExportTemp')->name('getExportTemp');//获取电子面单模板获取
			Route::get('export_all', 'admin.order.StoreOrder/getExportAll')->name('getExportAll');//获取物流公司
			Route::get('split_cart_info/:id', 'admin.order.StoreOrder/split_cart_info')->name('StoreOrderSplitCartInfo')->option(['real_name' => '获取订单可拆分商品列表']);//获取订单可拆分商品列表
			Route::put('split_delivery/:id', 'admin.order.StoreOrder/split_delivery')->middleware(BlockerMiddleware::class)->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);//拆单发送货
			Route::post('open/refund/:id', 'admin.order.StoreOrder/open_order_refund')->middleware(BlockerMiddleware::class)->name('openOrderRefund')->option(['real_name' => '拆单退款']);//拆单退款（兼容：后端收口整单）
			Route::post(':id/refund', 'admin.order.StoreOrder/terminal_order_refund')->middleware(BlockerMiddleware::class)->option(['real_name' => '手机管理端整单退款']);
			//订单核销
			Route::post('order_verific', 'admin.order.StoreOrder/order_verific')->middleware(BlockerMiddleware::class)->name('order');//订单核销
			Route::post('writeoff/records/:id', 'admin.order.StoreOrder/writeOffRecords')->middleware(BlockerMiddleware::class)->name('writeOffRecords')->option(['real_name' => '订单核销记录']);//订单核销记录

			//代客下单
			Route::get('cart/:uid', 'admin.order.StoreCart/getCartList')->name('orderCartList'); //购物车列表
			Route::get('all/cart/:uid', 'admin.order.StoreCart/getCartallLst')->name('getCartallLst'); //全部门店购物车
			Route::post('cart/add/:uid', 'admin.order.StoreCart/addCart')->middleware(BlockerMiddleware::class)->name('cartAdd'); //购物车添加
			Route::delete('cart/del/:uid', 'admin.order.StoreCart/delCart')->name('cartDel'); //购物车删除
			Route::post('cart/num/:uid', 'admin.order.StoreCart/numCart')->name('cartNum'); //购物车 修改商品数量

			Route::get('place/list', 'admin.order.CreateOrder/lst')->name('orderPlaceList'); //代客下单记录
			Route::post('confirm/:uid', 'admin.order.CreateOrder/confirm')->name('orderConfirm'); //订单确认
			Route::post('computed/:key/:uid', 'admin.order.CreateOrder/computedOrder')->name('computedOrder'); //计算订单金额
			Route::get('coupons/:uid', 'admin.activity.StoreCoupons/order')->name('couponsOrder');//下单可使用优惠券
			Route::post('create/:key/:uid', 'admin.order.CreateOrder/createOrder')->middleware(BlockerMiddleware::class)->name('createOrder'); //代客下单：创建订单
			Route::post('pay/:uid', 'admin.order.CreateOrder/pay')->name('payOrder'); //代客下单支付信息
			Route::get('pay/status', 'admin.order.CreateOrder/checkOrderStatus')->option(['real_name' => '获取订单状态']);
            Route::post('check_shipping', 'admin.order.StoreOrder/checkShipping')->name('checkShipping'); //检测是否显示快递和核销标签
            Route::get('reissue_order/:id', 'admin.order.StoreOrder/adminReissueOrder')->option(['real_name' => '管理员操作重新发单']);
		});

	})->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\api\CustomerMiddleware::class)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');


	/**
	 * 移动端区域管理中心 路由
	 */
	Route::group('agent', function () {
		//区域代理商所有门店
		Route::get('store/list', 'agent.SystemRegionAgent/storeList')->option(['real_name' => '区域代理商所有门店']);

		//区域代理商概览统计数据
		Route::get('home/header', 'agent.SystemRegionAgent/homeStatics')->option(['real_name' => '区域代理商概览统计数据']);
		//区域代理商销售额趋势统计
		Route::get('home/order', 'agent.SystemRegionAgent/orderChart')->option(['real_name' => '区域代理商销售额趋势统计']);
		//区域代理商门店交易排行、占比统计
		Route::get('home/store', 'agent.SystemRegionAgent/storeChart')->option(['real_name' => '区域代理商门店交易排行、占比统计']);
		Route::get('home/report', 'agent.Report/orderData')->option(['real_name' => '报表明细']);

		Route::get('home/yejiRanking', 'agent.SystemRegionAgent/yejiRanking')->option(['real_name' => '业绩']);
		Route::get('home/projectRanking', 'agent.SystemRegionAgent/projectRanking')->option(['real_name' => '项目数排行']);
		Route::get('home/dianke', 'agent.SystemRegionAgent/dianke')->option(['real_name' => '点客']);

	})->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

	/**
	 * 商家端身份与入口
	 */
	Route::post('merchant/login', 'v1.merchant.MerchantLogin/login')->option(['real_name' => '手机商家端员工账号登录']);
	Route::group('merchant', function () {
		Route::get('engineering-ledger/catalog', 'v1.merchant.EngineeringLedger/catalog')->option(['real_name' => '商家工程管理台账目录']);
		Route::get('engineering-ledger/list', 'v1.merchant.EngineeringLedger/list')->option(['real_name' => '商家工程管理台账列表']);
		Route::get('engineering-ledger/read/:id', 'v1.merchant.EngineeringLedger/read')->option(['real_name' => '商家工程管理台账详情']);
		Route::post('engineering-ledger/save', 'v1.merchant.EngineeringLedger/save')->option(['real_name' => '商家工程管理台账保存']);
		Route::get('engineering-ledger/export', 'v1.merchant.EngineeringLedger/export')->option(['real_name' => '商家工程管理台账导出']);
		Route::get('access', 'v1.merchant.MerchantAccess/access')->option(['real_name' => '商家入口权限']);
		Route::post('context/switch', 'v1.merchant.MerchantAccess/switchContext')->option(['real_name' => '切换商家身份上下文']);
		Route::get('home', 'v1.merchant.MerchantBiz/home')->option(['real_name' => '商家首页聚合']);
		Route::get('training/document/list', 'v1.merchant.MerchantBiz/trainingDocuments')->option(['real_name' => '商家培训资料列表']);
		Route::get('training/document/download/:id', 'v1.merchant.MerchantBiz/trainingDocumentDownload')->option(['real_name' => '商家培训资料下载']);
		Route::get('customer/segments', 'v1.merchant.MerchantBiz/customerSegments')->option(['real_name' => '客户客群']);
		Route::get('customer/mine/summary', 'v1.merchant.MerchantBiz/customerMineSummary')->option(['real_name' => '我的客户汇总']);
		Route::post('customer/create', 'v1.merchant.MerchantBiz/customerCreate')->option(['real_name' => '商家新增客户']);
		Route::get('customer/list', 'v1.merchant.MerchantBiz/customerList')->option(['real_name' => '商家客户列表']);
		Route::get('customer/detail/:uid', 'v1.merchant.MerchantBiz/customerDetail')->option(['real_name' => '商家客户详情']);
		Route::get('customer/orders', 'v1.merchant.MerchantBiz/customerOrders')->option(['real_name' => '商家客户订单记录']);
		Route::post('customer/update', 'v1.merchant.MerchantBiz/customerUpdate')->option(['real_name' => '商家客户档案保存']);
		Route::get('customer-care/workbench', 'v1.merchant.MerchantBiz/customerCareWorkbench')->option(['real_name' => '商家客情任务工作台']);
		Route::get('data/business', 'v1.merchant.MerchantBiz/dataBusiness')->option(['real_name' => '数仓经营概览']);
		Route::get('data/customer', 'v1.merchant.MerchantBiz/dataCustomer')->option(['real_name' => '数仓客户分析']);
		Route::get('data/staff/statistics', 'v1.merchant.MerchantBiz/dataStaffStats')->option(['real_name' => '数仓员工统计']);
		Route::get('yeji/self', 'v1.merchant.MerchantBiz/yejiSelf')->option(['real_name' => '本人业绩概览']);
		Route::get('yeji/self/detail', 'v1.merchant.MerchantBiz/yejiSelfDetail')->option(['real_name' => '本人业绩明细']);
		Route::get('metric/cash/detail', 'v1.merchant.MerchantBiz/metricCashDetail')->option(['real_name' => '店级现金业绩明细']);
		Route::get('metric/actual/detail', 'v1.merchant.MerchantBiz/metricActualDetail')->option(['real_name' => '店级实收业绩明细']);
		Route::get('metric/consume/detail', 'v1.merchant.MerchantBiz/metricConsumeDetail')->option(['real_name' => '店级消耗金额明细']);
		Route::get('reservation/list', 'v1.merchant.MerchantBiz/reservationList')->option(['real_name' => '商家预约列表']);
		Route::get('reservation/statistics', 'v1.merchant.MerchantBiz/reservationStatistics')->option(['real_name' => '商家预约状态统计']);
		Route::get('reservation/detail/:id', 'v1.merchant.MerchantBiz/reservationDetail')->option(['real_name' => '商家预约详情']);
		Route::get('reservation/tables', 'v1.merchant.MerchantBiz/reservationTables')->option(['real_name' => '商家预约房间列表']);
		Route::post('reservation/confirm/:id', 'v1.merchant.MerchantBiz/reservationConfirm')->option(['real_name' => '商家预约接单']);
		Route::post('reservation/refuse/:id', 'v1.merchant.MerchantBiz/reservationRefuse')->option(['real_name' => '商家预约拒绝']);
		Route::post('reservation/update/:id', 'v1.merchant.MerchantBiz/reservationUpdate')->option(['real_name' => '商家预约修改']);
		Route::post('reservation/service/set/:id', 'v1.merchant.MerchantBiz/reservationServiceSet')->option(['real_name' => '商家预约服务状态']);
		Route::get('debt/list', 'v1.merchant.MerchantBiz/debtList')->option(['real_name' => '商家欠款列表']);
	})->middleware(MerchantAuthMiddleware::class, true);

	/**
	 * 指标字典
	 */
	Route::group('metric', function () {
		Route::get('dictionary', 'v1.metric.MetricDictionary/index')->option(['real_name' => '指标字典']);
		Route::get('dictionary/:code', 'v1.metric.MetricDictionary/tooltip')->option(['real_name' => '指标口径说明']);
	})->middleware(AuthTokenMiddleware::class, true);

	/**
	 * 组织范围解析（手机端实时筛选，单次请求）
	 */
	Route::group('organization', function () {
		Route::get('scope/tree', 'v1.organization.OrganizationScope/tree')->option(['real_name' => '组织门店选择树']);
		Route::get('scope/resolve', 'v1.organization.OrganizationScope/resolve')->option(['real_name' => '解析门店范围']);
		Route::post('scope/resolve', 'v1.organization.OrganizationScope/resolve')->option(['real_name' => '解析门店范围(POST)']);
	})->middleware(AuthTokenMiddleware::class, true);


	/**
	* 移动端门店中心 路由
	 */
	 Route::group('store', function () {
		//无需登录接口
		Route::group(function () {
			Route::get('category', 'v1.store.StoreProductCategory/category')->name('category');//商品分类
		});
		//未授权接口---不会抛异常
        Route::group(function () {
			Route::get('list', 'v1.store.Store/getStoreList')->name('storeList');//门店列表
			Route::get('customer/list/:store_id', 'v1.store.Store/getCustomerList')->name('customerList');//客服列表
			Route::get('customer/info/:id', 'v1.store.Store/getCustomerInfo')->name('customerInfo');//客服详情
			Route::get('products', 'v1.store.StoreProduct/lst')->name('storeProducts');//商品列表
        	Route::get('brand', 'v1.store.StoreProduct/brand')->name('brand');//品牌列表
        })->middleware(AuthTokenMiddleware::class, false)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

		//需要授权
		Route::group(function () {
			Route::get('refund_order/list', 'store.order.RefundOrder/refundOrderList')->name('RefundOrderList');//退款订单列表
			Route::get('refund_order/detail/:uni', 'store.order.RefundOrder/refundOrderDetail')->name('RefundOrderDetail');//退款订单详情
			Route::post('refund_order/remark', 'store.order.RefundOrder/refundRemark')->name('refundRemark');//退款订单备注
			//门店店员
			Route::group('staff', function () {
				Route::get('info', 'store.StoreStaff/info')->name('storeStaffInfo');//门店店员信息
				Route::get('staging', 'store.StoreStaff/stagingData')->name('storeStagingData');//工作台数据统计
				Route::get('statistics', 'store.StoreStaff/statistics')->name('storeStatistics');//门店|店员统计信息
				Route::get('chart/:type', 'store.StoreStaff/timeChart')->name('timeChart');//统计曲线图
				Route::get('data/:type', 'store.StoreStaff/data');//店员统计信息列表
				Route::get('card/code', 'store.StoreStaff/code');//详细信息列表

				Route::get('yejiRanking', 'store.StoreStaff/yejiRanking');//业绩排名
				Route::get('dianke', 'store.StoreStaff/dianke');//点客业绩
				Route::get('detailYeji', 'store.StoreStaff/detailYeji');//提成明细
				Route::get('yejiInfo', 'store.StoreStaff/yejiInfo');//业绩统计
				Route::get('salary', 'store.StoreStaff/salary');//业绩统计
				Route::get('orderData', 'store.StoreStaff/orderData');//门店店长统计信息
				Route::get('homeStatics', 'store.StoreStaff/homeStatics');//门店店长统计信息
				// 老师中心
				Route::get('teacher/center', 'store.StoreStaff/teacherCenter');
				Route::post('teacher/update', 'store.StoreStaff/updateTeacherCenter');
				Route::get('teacher/rest/list', 'store.StoreStaff/teacherRestList');
				Route::get('teacher/rest/shift_options', 'store.StoreStaff/teacherRestShiftOptions');
				Route::post('teacher/rest/save', 'store.StoreStaff/teacherRestSave');
				Route::delete('teacher/rest/:id', 'store.StoreStaff/teacherRestDelete');
			});
			//配送员
			Route::group('delivery', function () {
				Route::get('info', 'store.StoreDelivery/info')->name('storeStaffInfo');//配送员信息
				Route::get('statistics', 'store.StoreDelivery/statistics')->name('deliveryStatistics');//门店配送员统计信息
				Route::get('data', 'store.StoreDelivery/data')->name('deliveryData');//每月配送统计列表数据
				Route::get('order', 'store.StoreDelivery/orderList')->name('deliveryOrder');//配送员订单列表
				Route::get('list', 'store.StoreDelivery/getDeliveryAll')->name('getDeliveryAll');//获取配送员列表
				Route::get('search/list', 'store.StoreDelivery/getDeliveryList')->name('getDeliveryList');//获取配送员列表
			});
			//商品
			Route::group('product', function () {
				//代客下单商品
				Route::get('category', 'store.product.StoreProductCategory/category')->name('category');//商品分类
				Route::get('list', 'store.product.StoreProduct/lst')->name('products');//商品列表
				//商品管理
				Route::get('admin_list', 'store.product.StoreProduct/adminList')->name('products');//管理商品列表
				Route::post('set_show', 'store.product.StoreProduct/setShow')->name('setShow');//修改商品状态
				Route::get('product_label', 'store.product.StoreProduct/labelTreeList')->name('labelTreeList');//商品标签树形列表
				Route::get('get_attr/:id', 'store.product.StoreProduct/getAttr')->name('updateAttrs');//获取商品规格
				Route::post('update_attrs/:id', 'store.product.StoreProduct/updateAttrs')->name('updateAttrs');//修改库存价格
				Route::post('batch_process', 'store.product.StoreProduct/batchProcess')->name('batchProcess');//修改分类标签
			});
			//用户
			Route::group('user', function () {
				Route::get('list', 'store.user.User/list')->name('list');//用户列表
				Route::get('orderList', 'store.user.User/orderList')->name('orderList');//订单列表
				Route::post('saveUser', 'store.user.User/saveUser')->name('saveUser');//保存用户
				Route::get('label/:uid', 'store.user.User/userLabel')->name('userLabel');//用户标签
				Route::get('coupon/grant', 'store.user.User/couponGrant')->name('couponGrant');//优惠券列表
				Route::get('group/list', 'store.user.User/userGroup')->name('userGroup');//用户分组
				Route::get('level/list', 'store.user.User/userLevel')->name('userLevel');//用户等级
				Route::get('info/:uid', 'store.user.User/info')->name('info');//用户详情
				Route::get('address/list/:uid', 'store.user.UserAddress/address_list')->name('UserAddressList');//用户地址列表
				Route::get('address/default/:uid', 'store.user.UserAddress/address_default')->name('addressDefault');//获取用户默认地址
			});
			//订单
			Route::group('order', function () {
				Route::get('list', 'store.order.StoreOrder/lst')->name('adminOrderList');//订单列表
				Route::get('detail/:orderId', 'store.order.StoreOrder/detail')->name('adminOrderDetail');//订单详情
				Route::get('delivery/gain/:orderId', 'store.order.StoreOrder/delivery_gain')->name('adminOrderDeliveryGain');//订单发货获取订单信息
				Route::post('delivery/keep/:id', 'store.order.StoreOrder/delivery_keep')->name('adminOrderDeliveryKeep');//订单发货
				Route::post('price', 'store.order.StoreOrder/price')->name('adminOrderPrice');//订单改价
				Route::post('remark', 'store.order.StoreOrder/remark')->name('adminOrderRemark');//订单备注
				Route::get('time', 'store.order.StoreOrder/time')->name('adminOrderTime');//订单交易额
				Route::get('time/chart', 'store.order.StoreOrder/timeChart')->name('timeChart');//订单交易额时间统计
				Route::post('offline', 'store.order.StoreOrder/offline')->name('adminOrderOffline');//订单支付
				Route::post('refund', 'store.order.StoreOrder/refund')->middleware(BlockerMiddleware::class)->name('adminOrderRefund');//订单退款
				Route::post('refund_agree/:id', 'store.order.StoreOrder/agreeRefund')->name('adminOrderAgreeRefund');//商家同意退货退款

				Route::get('delivery/remind/:id', 'store.order.StoreOrder/deliverRemind')->name('getDeliveryAll');//获取配送员
				Route::get('delivery', 'store.StoreDelivery/getDeliveryAll')->name('getDeliveryAll');//获取配送员
				Route::get('delivery_info', 'store.order.StoreOrder/getDeliveryInfo')->name('getDeliveryInfo');//获取电子面单默认信息
				Route::get('export_temp', 'store.order.StoreOrder/getExportTemp')->name('getExportTemp');//获取电子面单模板获取
				Route::get('export_all', 'store.order.StoreOrder/getExportAll')->name('getExportAll');//获取物流公司
				Route::get('split_cart_info/:id', 'store.order.StoreOrder/split_cart_info')->name('StoreOrderSplitCartInfo')->option(['real_name' => '获取订单可拆分商品列表']);//获取订单可拆分商品列表
				Route::put('split_delivery/:id', 'store.order.StoreOrder/split_delivery')->middleware(BlockerMiddleware::class)->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);//拆单发送货
				Route::post('open/refund/:id', 'store.order.StoreOrder/open_order_refund')->middleware(BlockerMiddleware::class)->name('openOrderRefund')->option(['real_name' => '拆单退款']);//拆单退款

				//订单核销
				Route::get('writeoff_info/:type', 'store.order.StoreOrder/writeoffOrderinfo')->name('writeoffOrderinfo');//扫码核销获取订单信息
				Route::post('cart_info', 'store.order.StoreOrder/orderCartInfo')->name('writeoffOrderCartInfo');//核销获取商品信息
				Route::post('writeoff', 'store.order.StoreOrder/wirteoff')->middleware(BlockerMiddleware::class)->name('storeOrderWriteoff');//订单核销
				Route::post('order_verific', 'store.order.StoreOrder/order_verific')->middleware(BlockerMiddleware::class)->name('order');//订单核销
				Route::post('writeoff/records/:id', 'store.order.StoreOrder/writeOffRecords')->middleware(BlockerMiddleware::class)->name('writeOffRecords')->option(['real_name' => '订单核销记录']);//订单核销记录
			});
			//预约订单
			Route::group('reservation', function () {
				Route::get('list', 'store.order.StoreReservationOrder/reservationList')->name('adminReservationOrderList');//预约单列表
				Route::get('statistics', 'store.order.StoreReservationOrder/statistics')->name('adminReservationOrderStatistics');//管家中心统计
				Route::get('table/list', 'store.order.StoreReservationOrder/tableList')->name('adminReservationTableList');//门店房间
				Route::get('detail/:id', 'store.order.StoreReservationOrder/detail')->name('adminReservationOrderDetail');//预约单详情
				Route::post('confirm/:id', 'store.order.StoreReservationOrder/confirmReservationOrder')->name('adminReservationOrderConfirm');//接单确认
				Route::post('refuse/:id', 'store.order.StoreReservationOrder/refuseReservationOrder')->name('adminReservationOrderRefuse');//拒绝预约
				Route::post('update/:id', 'store.order.StoreReservationOrder/updateReservationOrder')->name('adminReservationOrderUpdate');//修改预约
				Route::post('service/set/:id', 'store.order.StoreReservationOrder/setServiceStatus')->name('setServiceStatus');//预约单服务状态
				Route::get('service/tag/list', 'store.order.StoreReservationOrder/serviceTagList')->name('adminReservationServiceTagList');//服务标签选项
				Route::post('service/tag/set/:id', 'store.order.StoreReservationOrder/setServiceTag')->name('adminReservationServiceTagSet');//设置服务标签
			});

		})->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

	 });

	/**
	 * 门店目标管理
	 */
	Route::group('target', function () {
		Route::get('list', 'target.StoreTarget/list');
		Route::get('detail/:id', 'target.StoreTarget/detail');
		Route::post('save', 'target.StoreTarget/save');
		Route::post('copy/:id', 'target.StoreTarget/copy');
		Route::delete('delete/:id', 'target.StoreTarget/delete');
		Route::get('analysis', 'target.StoreTarget/analysis');
		Route::get('metric_options', 'target.StoreTarget/metricOptions');
        Route::get('store_options', 'target.StoreTarget/storeOptions');
        Route::get('store_options_tree', 'target.StoreTarget/storeOptionsTree');
		Route::get('product_search', 'target.StoreTarget/productSearch');
		Route::get('product_select', 'target.StoreTarget/productSelect');
		Route::get('ranking', 'target.StoreTarget/ranking');
		Route::get('allocate_info', 'target.StoreTarget/allocateInfo');
		Route::post('allocate_save', 'target.StoreTarget/allocateSave');
	})->middleware(AuthTokenMiddleware::class, true)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'user');

	/**
	 * 企业微信
	 */
	Route::group('work', function () {
		//获取企业微信jsSDK配置
		Route::get('config', 'v1.work.WorkController/config')->name('WorkConfig');
		//获取企业微信应用jsSDK配置
		Route::get('agentConfig', 'v1.work.WorkController/agentConfig')->name('agentConfig');
		//获取客户群详情
		Route::get('groupInfo', 'v1.work.GroupChat/getGroupInfo')->name('getGroupInfo');
		//获取群成员列表
		Route::get('groupMember/:id', 'v1.work.GroupChat/getChatMemberList')->name('getChatMemberList');

		Route::group(function () {
			//获取客户信息详情
			Route::get('client/info', 'v1.work.Client/getClientInfo')->name('getClientInfo');
			//获取客户订单列表
			Route::get('order/list', 'v1.work.Order/getUserOrderList')->name('getWorkOrderList');
			//获取客户订单详情
			Route::get('order/info/:id', 'v1.work.Order/orderInfo')->name('getWorkOrderInfo');
			//购买商品记录
			Route::get('product/cart_list', 'v1.work.Product/getCartProductList')->name('getCartProductList');
			//浏览记录商品记录
			Route::get('product/visit_list', 'v1.work.Product/getVisitProductList')->name('getVisitProductList');

		})->middleware(ClientMiddleware::class);
	});

    /**
     * miss 路由
     */
    Route::miss(function () {
        if (app()->request->isOptions()) {
            $header = Config::get('cookie.header');
            $header['Access-Control-Allow-Origin'] = app()->request->header('origin');
            return Response::create('ok')->code(200)->header($header);
        } else
            return Response::create()->code(404);
    });

})->prefix('api.')->middleware([
	InstallMiddleware::class,
	AllowOriginMiddleware::class,
	StationOpenMiddleware::class
]);
