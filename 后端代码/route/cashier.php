<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\BlockerMiddleware;
use app\http\middleware\cashier\AuthTokenMiddleware;
use app\http\middleware\cashier\CashierCheckRoleMiddleware;
use app\http\middleware\StationOpenMiddleware;
use think\facade\Config;
use think\facade\Route;
use think\Response;

/**
 * 收银台路由配置
 */
Route::group('cashierapi', function () {

    /**
     * 不需要登录不验证权限
     */
    Route::group(function () {
		//图形验证码
        Route::get('ajcaptcha', 'Login/ajcaptcha')->name('ajcaptcha');
        //图形验证码
        Route::post('ajcheck', 'Login/ajcheck')->name('ajcheck');
        //是否需要滑块验证接口
        Route::post('is_captcha', 'Login/getAjCaptcha')->name('getAjCaptcha');
        //账号密码登录
        Route::post('login', 'Login/login')->name('login')->option(['real_name' => '账号密码登录']);
        //微信扫码登录
        Route::get('wechat_scan_login', 'Login/wechatScanLogin')->name('wechatScanLogin')->option(['real_name' => '微信扫码登录']);
        //企业微信扫码登录
        Route::get('work_scan_login', 'Login/workScanLogin')->name('workScanLogin')->option(['real_name' => '企业微信扫码登录']);
        //企业微信配置
        Route::get('work/config', 'Login/getWechatConfig')->name('getWechatConfig')->option(['real_name' => '企业微信配置']);
        //扫码登录状态信息检测获取
        Route::post('check_scan_login', 'Login/checkScanLogin')->name('checkScanLogin')->option(['real_name' => '扫码登录状态信息检测获取']);
        //登录信息
        Route::get('login/info', 'Login/info')->name('loginInfo')->option(['real_name' => '登录信息']);
        //图片验证码
        Route::get('captcha_store', 'Login/captcha')->name('captcha')->option(['real_name' => '图片验证码']);
		//获取版权
        Route::get('copyright', 'Common/getCopyright')->option(['real_name' => '获取版权']);
    })->middleware(\app\http\middleware\SystemLogMiddleware::class, 'cashier');

    /**
     * 只需登录不验证权限
     */
    Route::group(function () {
        //获取logo
        Route::get('logo', 'Common/getLogo')->option(['real_name' => '获取logo']);
        //获取配置
        Route::get('config', 'Common/getConfig')->option(['real_name' => '获取配置']);
        //erp配置
        Route::get('erp/config', 'Common/getConfig')->option(['real_name' => '获取配置']);
//        //获取未读消息
//        Route::get('jnotice', 'Common/jnotice')->option(['real_name' => '获取未读消息']);
        //获取省市区街道
        Route::get('city', 'Common/city')->option(['real_name' => '获取省市区街道']);
        //获取搜索菜单列表
        Route::get('menusList', 'Common/menusList')->option(['real_name' => '搜索菜单列表']);
        //修改当前管理员信息
        Route::put('update_store', 'Login/updateStore')->name('updateStore')->option(['real_name' => '修改当前登录店员信息']);
        //退出登录
        Route::get('logout', 'Login/logOut')->option(['real_name' => '退出登录']);
        //修改收银员信息
        Route::put('updatePwd', 'User/updatePwd')->option(['real_name' => '修改收银员信息']);

        //公共类
        Route::post('upload/image', 'Common/upload_image')->name('uploadImage');//图片上传

    })->middleware(AuthTokenMiddleware::class)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'cashier');

    /**
     * 需登录验证权限
     */
    Route::group(function () {
        //首页头部统计数据
        Route::get('home/header', 'Common/homeStatics')->option(['real_name' => '首页头部统计数据']);
        //首页营业趋势图表
        Route::get('home/operate', 'Common/operateChart')->option(['real_name' => '首页营业趋势图表']);
        //首页交易图表
        Route::get('home/orderChart', 'Common/orderChart')->option(['real_name' => '首页交易图表']);
        //首页店员统计
        Route::get('home/staff', 'Common/staffChart')->option(['real_name' => '首页店员统计']);
        //轮询查询扫码订单支付状态
        Route::post('check_order_status/:type', 'Common/checkOrderStatus')->option(['real_name' => '轮询订单状态接口'])->name('checkOrderStatus');//轮询订单状态接口

        //获取储值套餐
        Route::get('store/recharge_info', 'Recharge/rechargeInfo')->option(['real_name' => '获取储值套餐']);
        //收银台用户储值
        Route::post('store/recharge', 'Recharge/recharge')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '获取储值套餐']);

        //系统更新日志（只读轻量）
        Route::get('changelog/unread', 'SystemChangelog/unread')->option(['real_name' => '更新日志未读']);
        Route::get('changelog', 'SystemChangelog/index')->option(['real_name' => '更新日志列表']);
        Route::get('changelog/:id', 'SystemChangelog/read')->option(['real_name' => '更新日志详情']);
        //获取登录店员详情
        Route::get('user/cashier_info', 'User/getCashierInfo')->option(['real_name' => '获取登录店员详情']);
		//获取当前门店店员列表和店员信息
		Route::get('user/all_staff_list', 'User/getALlStaffList')->option(['real_name' => '获取当前门店所有店员信息']);
        Route::post('user/allList', 'User/getStaffAll')->option(['real_name' => '获取门店所有店员']);
        //获取当前门店店员列表和店员信息
        Route::get('user/cashier_list', 'User/getCashierList')->option(['real_name' => '获取当前门店店员列表和店员信息']);
        //收银台选择用户列表
        Route::get('user/get_list', 'User/getUserList')->option(['real_name' => '收银台选择用户列表']);
        //修改余额和本金
        Route::post('user/changeMoney', 'User/changeMoney')->option(['real_name' => '修改余额和本金']);
        //收银台切换购物车用户
        Route::post('user/switch/:cashierId', 'User/switchCartUser')->option(['real_name' => '收银台切换购物车用户']);
		//搜索、获取用户信息
		Route::post('user/search_user_info', 'User/searchUserInfo')->option(['real_name' => '搜索、获取用户信息']);
		//收银台注册用户
		Route::post('user/register_user', 'User/saveUser')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '收银台注册用户']);
        //获取收银台用户信息
        Route::post('user/user_Info', 'User/getUserInfo')->option(['real_name' => '获取收银台用户信息']);
		//收银台修改用户信息
		Route::post('user/update/:uid', 'User/updateUser')->option(['real_name' => '收银台修改用户信息']);
        //收银台获取当前用户信息
        Route::get('user/info/:uid', 'User/getUidInfo')->option(['real_name' => '获取当前用户信息']);
        //收银台获取当前用户记录
        Route::get('user/record/:uid', 'User/userRecord')->option(['real_name' => '收银台获取当前用户记录']);
        //显示指定的资源
        Route::get('user/read/:id', 'User/read')->option(['real_name' => '显示指定的资源']);
        //获取指定用户的信息
        Route::get('user/one_info/:id', 'User/oneUserInfo')->option(['real_name' => '获取指定用户的信息']);
        //获取单个卡项信息
        Route::get('user/card_holder/:id', 'User/cardHolder')->option(['real_name' => '卡项信息']);
        //收银台获取副屏信息
        Route::get('user/aux_screen', 'User/getAuxScreenInfo')->option(['real_name' => '收银台获取副屏信息']);
        //收银台切换用户切换店员
        Route::post('user/swith_user', 'User/switchUser')->option(['real_name' => '收银台切换用户切换店员']);

        //获取会员类型
        Route::get('user/member_card', 'User/getMemberCard')->option(['real_name' => '获取会员类型']);
        //获取会员类型
        Route::post('user/mer_recharge', 'User/payMember')->option(['real_name' => '会员储值']);

        //店员交接班时业绩
        Route::get('staff/shift/handover/:staff_id', 'User/shiftHandover')->option(['real_name' => '店员交接班时业绩']);

        //获取收银订单用户
        Route::get('order/get_user_list/:cashierId', 'Order/getUserList')->option(['real_name' => '获取收银订单用户']);
        //业绩
        Route::post('order/get_yeji', 'Order/getYeji')->option(['real_name' => '获得业绩']);
        Route::post('order/save_yeji', 'Order/saveYeji')->option(['real_name' => '保存业绩']);
        Route::post('order/staff_yeji', 'Order/getStaffYeji')->option(['real_name' => '生成业绩']);
        Route::post('order/cashType', 'Order/cashType')->option(['real_name' => '现金类型']);
        Route::post('order/cashSource', 'Order/cashSource')->option(['real_name' => '来源']);
        Route::delete('order/delCoupon/:id', 'Order/delCoupon')->option(['real_name' => '删除会员优惠劵记录']);
        //收银台挂单列表
        Route::get('order/get_hang_list/:cashierId', 'Order/getHangList')->option(['real_name' => '收银台挂单列表']);
        //收银台删除挂单
        Route::delete('order/del_hang', 'Order/deleteHangOrder')->option(['real_name' => '收银台删除挂单']);
        //收银台订单列表
        Route::post('order/get_order_list/[:orderType]', 'Order/getOrderList')->option(['real_name' => '收银台订单列表']);
        //收银台核销订单列表
        Route::post('order/get_verify_list', 'Order/getVerifyList')->option(['real_name' => '收银台核销订单列表']);
        Route::post('order/getPrice', 'Order/getPrice')->option(['real_name' => '获取金额']);
        Route::post('order/getService', 'Order/getService')->option(['real_name' => '获取劳动业绩']);
        //收银台核销订单数据
        Route::get('order/verify_cart_info', 'Order/verifyCartInfo')->option(['real_name' => '收银台核销订单数据']);
		//订单核销表单弹窗
		Route::get('order/write/form/:id', 'Order/writeOrderFrom')->name('writeOrderForm')->option(['real_name' => '订单核销表单']);
		//订单核销表单提交
		Route::post('order/write/form/:id', 'Order/writeoffFrom')->middleware(BlockerMiddleware::class, 'cashier')->name('writeOrderForm')->option(['real_name' => '订单核销表单']);
        //收银台订单核销
        Route::put('order/write_off/:id', 'Order/writeOff')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '订单号核销']);
        Route::post('order/card_transfer', 'Order/cardTransfer')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '卡项订单转让']);
        Route::post('order/card_extend', 'Order/cardExtend')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '卡项订单延期']);
        //收银台订单详情
        Route::get('order/get_order_Info/:id', 'Order/order_info')->option(['real_name' => '收银台订单详情']);
        //收银台获取订单状态
        Route::get('order/get_order_status/:id', 'Order/status')->option(['real_name' => '获取订单状态']);
        //收银台计算订单金额
        Route::post('order/compute/:uid', 'Order/orderCompute')->option(['real_name' => '收银台计算订单金额']);
        //收银台创建订单
        Route::post('order/create/:uid', 'Order/createOrder')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '收银台创建订单']);
        //收银台再次支付订单
        Route::post('order/pay/:orderId', 'Order/payOrder')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '收银台再次支付订单']);
        //收银台订单小票打印
        Route::get('order/print/:id', 'Order/order_print')->option(['real_name' => '收银台订单小票打印']);
        //收银台订单备注
        Route::put('order/remark/:id', 'Order/remark')->option(['real_name' => '收银台订单备注']);
        //用户优惠券列表
        Route::post('order/coupon_list/:uid', 'Order/couponList')->option(['real_name' => '用户优惠券列表']);
        //卡升级：获取会员旧的有效卡项
        Route::get('order/valid_card_upgrade/:uid', 'Order/validCardUpgradeList')->option(['real_name' => '卡升级：获取会员旧的有效卡项']);

        // 欠款
        Route::get('debt/summary', 'Debt/summary')->option(['real_name' => '会员欠款汇总']);
        Route::get('debt/reminder', 'Debt/reminder')->option(['real_name' => '欠款提醒列表']);
        Route::get('debt/stores/:uid', 'Debt/filterStores')->option(['real_name' => '用户欠款门店筛选']);
        Route::get('debt/user/:uid', 'Debt/userList')->option(['real_name' => '用户欠款记录']);
        Route::get('debt/repay/list', 'Debt/repayList')->option(['real_name' => '还款记录']);
        Route::get('debt/order/:order_id', 'Debt/orderItems')->option(['real_name' => '订单欠款明细']);
        Route::post('debt/repay/pay', 'Debt/repayPay')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '欠款补交支付']);
        Route::post('debt/repay/check', 'Debt/repayCheck')->option(['real_name' => '欠款还款状态查询']);

        //用户领取优惠券
        Route::post('coupon/receive/:uid', 'Order/couponReceive')->option(['real_name' => '用户领取优惠券']);
        Route::get('coupon/released', 'StoreCouponIssue/index')->option(['real_name' => '已发布优惠券列表']);
        //收银台获取物流公司
        Route::get('order/express_list', 'Order/express')->option(['real_name' => '收银台获取物流公司']);
        //收银台获取配送员
        Route::get('order/delivery_list', 'Order/getDeliveryList')->option(['real_name' => '收银台获取配送员']);
        //面单默认配置信息
        Route::get('order/sheet_info', 'Order/getSheetInfo')->option(['real_name' => '面单默认配置信息']);
        //获取订单可拆分商品列表
        Route::get('order/split_cart_info/:id', 'Order/split_cart_info')->option(['real_name' => '获取订单可拆分商品列表']);
        //收银台订单发送货
        Route::put('order/delivery/:id', 'Order/updateDelivery')->option(['real_name' => '收银台订单发送货']);
        //收银台订单改派
        Route::post('order/reassign/:id', 'Order/reassign_delivery')->option(['real_name' => '订单改派']);
        //收银台订单确认送达
        Route::post('order/confirm', 'Order/confirm_delivery')->option(['real_name' => '配送员确认送达']);
        //管理员操作重新发单
        Route::get('order/reissue_order/:id', 'Order/adminReissueOrder')->option(['real_name' => '管理员操作重新发单']);

        //订单退款表单
        Route::get('refund/refund/:id', 'Order/refund')->name('StoreOrderRefund')->option(['real_name' => '订单退款表单']);
        //订单退款
        Route::put('order/refund/:id', 'Order/update_refund')->middleware(BlockerMiddleware::class, 'cashier')->name('StoreOrderUpdateRefund')->option(['real_name' => '订单退款']);
        //收银台拆单退款
        Route::post('open/refund/:id', 'Order/open_order_refund')->middleware(BlockerMiddleware::class, 'cashier')->name('StoreOrderUpdateRefund')->option(['real_name' => '后台拆单退款']);
        Route::post('order/:id/refund', 'Order/terminal_order_refund')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '整单退款']);
        Route::post('order/:id/void', 'Order/terminal_order_void')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '整单作废']);
        Route::post('order/:id/reopen', 'Order/terminal_order_reopen')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '创建或获取重开草稿']);
        Route::get('order/reopen/:token', 'Order/terminal_order_reopen_load')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '加载重开草稿']);
        Route::put('order/writeoff/:subOrderId/cancel', 'Order/cancel_writeoff')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '撤销本次核销']);
        //收银台退款订单列表
        Route::get('order/get_refund_list', 'Refund/getRefundList')->option(['real_name' => '收银台退款订单列表']);
        //收银台退款订单详情
        Route::get('order/get_refund_Info/:id', 'Refund/detail')->option(['real_name' => '收银台退款订单详情']);
        //售后订单退款
        Route::put('order/order_refund/:id', 'Refund/update_refund')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '售后订单退款']);
        //商家同意退款，等待用户退货
        Route::get('order/refund/agree/:id', 'Refund/agreeRefund')->option(['real_name' => '商家同意退款，等待用户退货']);
        //售后订单备注
        Route::put('order/refund/remark/:id', 'Refund/remark')->option(['real_name' => '售后订单备注']);


		//看板设置
        Route::get('config/:type', 'Common/getStoreConfig')->option(['real_name' => '获取门店配置']);
        Route::post('config/:type', 'Common/save')->option(['real_name' => '保存看板配置']);
		//看板数据
		Route::get('reservation/notice/board', 'ReservationOrder/noticeBoardData')->option(['real_name' => '看板数据']);
		Route::get('reservation/staff/list', 'ReservationStaff/list')->option(['real_name' => '预约服务人员列表']);
		Route::get('reservation/staff/available_time', 'ReservationStaff/availableTime')->option(['real_name' => '员工已被占用时段']);
		Route::get('reservation/staff/conflicts', 'ReservationStaff/conflicts')->option(['real_name' => '员工预约时段冲突']);
		Route::get('reservation/staff/busy', 'ReservationStaff/busyStaff')->option(['real_name' => '指定时段已占用员工']);
		Route::get('reservation/table/list', 'ReservationOrder/tableList')->option(['real_name' => '门店房间列表']);
		//预约单列表
		Route::get('reservation/order/list', 'ReservationOrder/reservationList')->option(['real_name' => '预约单列表']);
		Route::get('reservation/order/purchased_items/:uid', 'ReservationOrder/getUserPurchasedRemainItems')->option(['real_name' => '用户可预约已购项目']);
		//获取订单预约信息
		Route::get('reservation/order/order_info/:id', 'ReservationOrder/getOrderReservationInfo')->option(['real_name' => '订单预约信息']);
		//获取商品预约时段
		Route::get('reservation/order/goods_time', 'ReservationOrder/getGoodsReservationTime')->option(['real_name' => '商品预约时段']);
		//创建预约单
		Route::post('reservation/order/create/:id', 'ReservationOrder/createReservationOrder')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '创建预约单']);
		Route::post('reservation/order/guest/create', 'ReservationOrder/createGuestReservationRecord')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '未购项目创建预约']);
		//确认预约
		Route::post('reservation/order/confirm/:id', 'ReservationOrder/confirmReservationOrder')->option(['real_name' => '确认预约']);
		Route::post('reservation/order/refuse/:id', 'ReservationOrder/refuseReservationOrder')->option(['real_name' => '拒绝预约']);
		//预约单详情
		Route::get('reservation/order/detail/:id', 'ReservationOrder/detail')->option(['real_name' => '预约单详情']);
		//预约单商品可选时段
		Route::get('reservation/order/product_time/:id', 'ReservationOrder/getReservationProductTime')->option(['real_name' => '预约单商品可选时段']);
		//预约单修改
		Route::post('reservation/order/update/:id', 'ReservationOrder/update')->option(['real_name' => '预约单修改']);
		//预约单状态
		Route::post('reservation/order/service/set/:id', 'ReservationOrder/setServiceStatus')->middleware(BlockerMiddleware::class, 'cashier')->option(['real_name' => '预约单状态']);
		//取消预约
		Route::post('reservation/order/cancel/:id', 'ReservationOrder/cancelReservationOrder')->option(['real_name' => '预约单取消']);
		//删除预约单
		Route::delete('reservation/order/del/:id', 'ReservationOrder/delReservationOrder')->option(['real_name' => '删除预约单']);

		//预约单导出
		Route::get('export/reservation/order', 'ExportExcel/reservationOrder')->option(['real_name' => '预约单导出']);

		// 排班管理
		Route::get('schedule/calendar', 'Schedule/calendar')->option(['real_name' => '排班月历']);
		Route::get('schedule/day', 'Schedule/dayDetail')->option(['real_name' => '排班日详情']);
		Route::post('schedule/day/save', 'Schedule/saveDay')->option(['real_name' => '保存日排班']);
		Route::get('schedule/staff/selectable', 'Schedule/selectableStaff')->option(['real_name' => '可选排班员工']);
		Route::get('schedule/export', 'Schedule/exportList')->option(['real_name' => '排班导出']);
		Route::get('shift/list', 'Shift/index')->option(['real_name' => '班次列表']);
		Route::post('shift/save', 'Shift/save')->option(['real_name' => '保存班次']);
		Route::delete('shift/del/:id', 'Shift/delete')->option(['real_name' => '删除班次']);
        //卡项商品关联商品获取
        Route::get('product/card/related/:id', 'Product/getCardRelatedProduct')->option(['real_name' => '卡项商品关联商品获取']);
        //获取卡项权益
        Route::get('card/benefits/:id', 'Order/getCardBenefits')->name('getCardBenefits')->option(['real_name' => '获取卡项权益']);
        //后台退款信息
        Route::get('order/benefits/:id', 'Order/cardBenefits')->name('cardBenefits')->option(['real_name' => '后台退款信息']);
        //订单核销记录
        Route::get('order/writeoff/records/:id', 'Order/writeOffRecords')->name('writeOffRecords')->option(['real_name' => '订单核销记录']);
		//获取全部商品分类
		Route::get('product/category', 'Product/category')->name('category');//商品分类类
		//获取商品一级分类
        Route::get('product/get_one_category', 'Product/getOneCategory')->option(['real_name' => '获取商品一级分类']);
        //获取收银台商品列表
        Route::get('product/get_list', 'Product/getProductList')->option(['real_name' => '获取收银台商品列表']);
        //获取收银台商品详情
        Route::get('product/get_info/:id/[:uid]', 'Product/getProductInfo')->option(['real_name' => '获取收银台商品详情']);
        //获取收银台商品规格
        Route::get('product/get_attr/:id/[:uid]', 'Product/getProductAttr')->option(['real_name' => '获取收银台商品详情']);

        Route::get('product/list', 'product/search_list')->option(['real_name' => '获取所有商品列表']);
        Route::get('product/cascader_list/[:type]', 'product/cascader_list')->option(['real_name' => '商品分类cascader行列表']);
        //获取收银台购物车信息
        Route::get('cart/get_cart/:uid/:cashierId', 'Cart/getCartList')->option(['real_name' => '获取收银台购物车信息']);
        //收银台选择商品进入购物车
        Route::post('cart/set_cart/:uid', 'Cart/addCart')->option(['real_name' => '收银台添加购物车']);
        //收银台更改购物车数量
        Route::put('cart/set_cart_num/:uid', 'Cart/numCart')->option(['real_name' => '收银台更改购物车数量']);
        //收银台删除购物车信息
        Route::delete('cart/del_cart/:uid', 'Cart/delCart')->option(['real_name' => '收银台删除购物车信息']);
        //收银台更改购物车规格
        Route::put('cart/change_cart', 'Cart/changeCart')->option(['real_name' => '收银台更改购物车规格']);

        //获取门店适用的活动
        Route::get('promotions/list/:type', 'Promotions/getPromotionInfo')->option(['real_name' => '获取门店适用的活动']);
        //获取活动商品数量信息
        Route::get('promotions/count/:uid', 'Promotions/promotionsCount')->option(['real_name' => '获取收银台购物车信息']);
        //收银台获取活动商品列表
        Route::get('promotions/activity_list/:uid/:type', 'Promotions/activityList')->option(['real_name' => '收银台获取活动商品列表']);


        //桌码管理
        Route::get('code/list', 'Table/getTableCode')->option(['real_name' => '桌码管理']);
        //桌码状态设置
        Route::get('qrcode/status/:id', 'Table/tableQrcodeStatus')->option(['real_name' => '桌码状态设置']);
        //桌码订单列表
        Route::get('get/table/list', 'Table/getTableCodeList')->option(['real_name' => '桌码订单列表']);
        //桌码订单购物车信息
        Route::get('get/order/info/:oid', 'Table/getOrderInfo')->option(['real_name' => '桌码订单购物车信息']);
        //获取全部点餐用户信息
        Route::get('table/uid/all', 'Table/getTableCodeUserAll')->option(['real_name' => '获取全部点餐用户信息']);
        //购物车
        Route::get('get/cart/list', 'Table/getCartList')->option(['real_name' => '购物车']);
        //桌码订单购物车数量操作
        Route::post('edit/table/cart', 'Table/editCart')->option(['real_name' => '桌码订单购物车数量操作']);
		//桌码订单购物车删除商品
		Route::delete('del/table/cart', 'Table/delCart')->option(['real_name' => '桌码订单购物车删除商品']);
        //取消桌码
        Route::get('cancel/table', 'Table/cancelInitiateTable')->option(['real_name' => '取消桌码']);
        //手动打单
        Route::get('staff/place', 'Table/staffPlaceOrder')->option(['real_name' => '手动打单']);
        //线下支付
        Route::post('pay_offline/:id', 'Order/pay_offline')->name('StoreOrderorPayOffline')->option(['real_name' => '线下支付']);
		//桌码订单改价获取商品信息
		Route::get('table/update/info', 'Table/getTableUpdateInfo')->name('StoreOrderUpdate')->option(['real_name' => '订单改价获取商品信息']);
		//桌码订单改价
		Route::post('table/update', 'Table/tableUpdate')->name('StoreOrderUpdate')->option(['real_name' => '订单改价']);

    })->middleware([AuthTokenMiddleware::class, CashierCheckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'cashier');


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

})->prefix('cashier.')->middleware([
	InstallMiddleware::class,
	AllowOriginMiddleware::class,
	StationOpenMiddleware::class,
]);


