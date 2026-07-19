<?php


use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\store\AuthTokenMiddleware;
use app\http\middleware\store\StoreCkeckRoleMiddleware;
use app\http\middleware\StationOpenMiddleware;
use think\facade\Config;
use think\facade\Route;
use think\Response;

/**
 * 门店路由配置
 */
Route::group('storeapi', function () {


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
        Route::get('code', 'Test/code')->name('code')->option(['real_name' => '测试验证码']);
        Route::get('index', 'Test/index')->name('index')->option(['real_name' => '测试主页']);

        //账号密码登录
        Route::post('login', 'Login/login')->name('login')->option(['real_name' => '账号密码登录']);
        //登录信息
        Route::get('login/info', 'Login/info')->name('loginInfo')->option(['real_name' => '登录信息']);
        //图片验证码
        Route::get('captcha_store', 'Login/captcha')->name('captcha')->option(['real_name' => '图片验证码']);
		//获取版权
        Route::get('copyright', 'Common/getCopyright')->option(['real_name' => '获取版权']);
    });

    /**
     * 只需登录不验证权限
     */
    Route::group(function () {
        //获取logo
        Route::get('logo', 'Common/getLogo')->option(['real_name' => '获取logo']);
        //获取配置
        Route::get('config', 'Common/getConfig')->option(['real_name' => '获取配置']);
        //获取未读消息
        Route::get('jnotice', 'Common/jnotice')->option(['real_name' => '获取未读消息']);
        //获取省市区街道
        Route::get('city', 'Common/city')->option(['real_name' => '获取省市区街道']);
        //获取搜索菜单列表
        Route::get('menusList', 'Common/menusList')->option(['real_name' => '搜索菜单列表']);
        //修改当前管理员信息
        Route::put('update_store', 'Login/updateStore')->name('updateStore')->option(['real_name' => '修改当前登录店员信息']);
        //退出登录
        Route::get('logout', 'Login/logOut')->option(['real_name' => '退出登录']);
        //修改密码
        Route::put('updatePwd', 'staff.StoreStaff/updateStaffPwd')->option(['real_name' => '修改密码']);
		//解析（导入地图城市地址）
		Route::get('resolve/city', 'Common/resolveCityList')->option(['real_name' => '解析导入地图城市地址']);

    })->middleware(AuthTokenMiddleware::class)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

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

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');


    /**
     * 基础管理
     */
    Route::group('system', function () {
        //获取门店菜单列表
        Route::get('menusList', 'system.SystemMenus/index')->option(['real_name' => '获取角色菜单列表']);
		//店员身份列表所有
		Route::get('roleList', 'system.SystemRole/roleList')->option(['real_name' => '店员身份列表所有']);
		Route::get('position', 'system.SystemRole/position')->option(['real_name' => '']);
		Route::get('positionLevel', 'system.SystemRole/positionLevel')->option(['real_name' => '']);
//		Route::get('Agent', 'system.SystemRole/Agent')->option(['real_name' => '店员身份列表所有']);
        //店员身份列表带分页
        Route::get('role', 'system.SystemRole/index')->option(['real_name' => '管理员身份列表']);
        //管理员身份权限列表
        Route::get('role/create', 'system.SystemRole/create')->option(['real_name' => '管理员身份权限列表']);
        //编辑角色详情
        Route::get('role/:id/edit', 'system.SystemRole/edit')->option(['real_name' => '编辑角色详情']);
        //新建或编辑管角色
        Route::post('role/:id', 'system.SystemRole/save')->option(['real_name' => '新建或编辑管角色']);
        //修改管理员身份状态
        Route::put('role/set_status/:id/:status', 'system.SystemRole/set_status')->option(['real_name' => '修改管理员身份状态']);
        //删除管理员身份
        Route::delete('role/:id', 'system.SystemRole/delete')->option(['real_name' => '删除管理员身份']);
        //获取当前登录门店信息
        Route::get('store/info', 'system.Store/info')->option(['real_name' => '获取当前登录门店信息']);
        //修改当前登录门店信息
        Route::put('store/update', 'system.Store/update')->option(['real_name' => '修改当前登录门店信息']);
		//获取同城配送uu、达达配送商品类型
        Route::get('store/getBusiness/:type', 'system.Store/getBusiness')->option(['real_name' => '获取同城配送uu、达达配送商品类型']);
        //门店管理员资源路由
        Route::resource('admin', 'system.StoreAdmin')->option(['real_name' => [
            'index' => '获取管理员列表',
            'read' => '获取管理员详情',
            'create' => '获取创建管理员表单',
            'save' => '保存管理员',
            'edit' => '获取修改管理员表单',
            'update' => '修改管理员',
            'delete' => '删除管理员'
        ]]);
        //修改管理员状态
        Route::put('admin/set_status/:id/:status', 'system.StoreAdmin/set_status')->option(['real_name' => '修改管理员状态']);

        Route::get('config/edit_new_build/:type', 'system.Config/getFormBuild')->option(['real_name' => '门店配置表单']);
		Route::get('config/:type', 'system.Config/getConfig')->option(['real_name' => '门店配置表单']);
		Route::post('config/:type', 'system.Config/save')->option(['real_name' => '保存门店配置']);
        Route::post('config', 'system.Config/save')->option(['real_name' => '保存门店配置']);

		//小票打印
		Route::get('printer/list', 'system.SystemPrinter/index')->option(['real_name' => '门店配置表单']);
		Route::get('printer/info/:id', 'system.SystemPrinter/info')->option(['real_name' => '门店配置表单']);
		Route::post('printer/:id', 'system.SystemPrinter/save')->option(['real_name' => '保存门店配置']);
		Route::post('printer/status/:id/:status', 'system.SystemPrinter/set_status')->option(['real_name' => '保存门店配置']);
		Route::delete('printer/:id', 'system.SystemPrinter/delete')->option(['real_name' => '保存门店配置']);

        //系统日志
        Route::get('log', 'system.Log/index')->name('SystemLog')->option(['real_name' => '系统日志']);
        //系统日志管理员搜索条件
        Route::get('log/search_admin', 'system.Log/search_admin')->option(['real_name' => '系统日志管理员搜索条件']);

		//获取系统表单信息
		Route::get('form/info/:id', 'system.form.SystemForm/getInfo')->option(['real_name' => '获取系统表单信息']);
		//获取所有系统表单
		Route::get('form/all_system_form', 'system.form.SystemForm/allSystemForm')->option(['real_name' => '获取所有系统表单']);

        //获取门店二维码
        Route::get('store/qrcode', 'system.Store/store_qrcode')->option(['real_name' => '获取门店二维码']);
        //获取门店列表
        Route::get('store/list', 'system.Store/store_list')->option(['real_name' => '获取门店列表']);
        //系统更新日志（只读）
        Route::get('changelog', 'system.SystemChangelog/index')->option(['real_name' => '更新日志列表']);
        Route::get('changelog/:id', 'system.SystemChangelog/read')->option(['real_name' => '更新日志详情']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class]);

    /**
     * 同城配送
     */
    Route::group('delivery', function () {
        //同城配送配置
        Route::get('status/config', 'system.Store/deliveryStatus')->option(['real_name' => '同城配送配置']);
        Route::post('config/update', 'system.DeliveryConfig/update')->option(['real_name' => '修改门店配送设置']);
        Route::get('config/detail', 'system.DeliveryConfig/detail')->option(['real_name' => '获取配送配置']);
        Route::post('reassign/:id', 'order.Order/reassign_delivery')->option(['real_name' => '订单改派']);
        Route::post('confirm', 'order.Order/confirm_delivery')->option(['real_name' => '配送员确认送达']);
        Route::get('export/statistics', 'export.ExportExcel/statistics')->option(['real_name' => '配送数据统计导出']);
        Route::get('data', 'order.StoreDeliveryOrder/getData')->option(['real_name' => '统计数据']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class]);

    /**
     * 桌码管理
     */
    Route::group('table', function () {
        //获取餐桌座位数列表
        Route::get('seats/list', 'table.TableCode/getTableSeats')->option(['real_name' => '获取餐桌座位数列表']);
        //获取单个餐桌座位数
        Route::get('seats/:id', 'table.TableCode/getSeats')->option(['real_name' => '获取单个餐桌座位数']);
        //添加、编辑餐桌座位数
        Route::get('add/seats/:id', 'table.TableCode/setTableSeats')->option(['real_name' => '添加、编辑餐桌座位数']);
        //删除餐桌座位数
        Route::delete('del/seats/:id', 'table.TableCode/delTableSeats')->option(['real_name' => '删除餐桌座位数']);

        //获取桌码分类列表
        Route::get('cate/list', 'table.TableCode/getTableCodeClassify')->option(['real_name' => '获取桌码分类列表']);
        //获取单个桌码分类
        Route::get('cate/:id', 'table.TableCode/getOneClassify')->option(['real_name' => '获取单个桌码分类']);
        //添加、编辑桌码分类
        Route::get('add/cate/:id', 'table.TableCode/setTableCodeClassify')->option(['real_name' => '添加、编辑桌码分类']);
        //删除桌码分类
        Route::delete('del/cate/:id', 'table.TableCode/delTableCodeClassify')->option(['real_name' => '删除桌码分类']);

        //桌码添加、编辑
        Route::post('add/qrcode/:id', 'table.TableCode/addTableQrcode')->option(['real_name' => '桌码添加、编辑']);
        //获取单个桌码
        Route::get('qrcode/:id', 'table.TableCode/getOneTableQrcodey')->option(['real_name' => '获取单个桌码']);
        //删除桌码
        Route::delete('del/qrcode/:id', 'table.TableCode/delTableQrcodey')->option(['real_name' => '删除桌码']);
        //获取桌码列表
        Route::get('qrcodes/list', 'table.TableCode/getTableQrcodeyList')->option(['real_name' => '获取桌码列表']);
        //桌码操作启用
        Route::get('update/using/:id', 'table.TableCode/updateUsing')->option(['real_name' => '桌码操作启用']);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /**
     * 社区相关路由
     */
    Route::group('community', function () {
        //获取所有社区话题
        Route::get('all_topic', 'community.CommunityTopic/allTopic')->option(['real_name' => '获取所有社区话题']);

        //社区内容顶部header
        Route::get('community/header', 'community.Community/type_header')->option(['real_name' => '社区内容顶部header']);
        //社区内容列表
        Route::get('community/list', 'community.Community/index')->option(['real_name' => '社区内容列表']);
        //社区内容详情
        Route::get('community/info/:id', 'community.Community/info')->option(['real_name' => '社区内容添加、编辑表单']);
        //社区内容添加、编辑
        Route::post('community/save/:id', 'community.Community/save')->option(['real_name' => '社区内容添加、编辑']);
        //社区内容是否显示
        Route::post('community/set_status/:id/:status', 'community.Community/setStatus')->option(['real_name' => '设置社区内容是否显示']);
        //删除社区内容
        Route::delete('community/del/:id', 'community.Community/delete')->option(['real_name' => '删除社区内容']);

        //社区评论
        Route::get('comment/list', 'community.CommunityComment/index')->option(['real_name' => '社区评论列表']);
        //社区评论回复列表
        Route::get('comment/reply/:id', 'community.CommunityComment/getCommentReply')->option(['real_name' => '社区评论回复列表']);
        //社区评论回复表单
        Route::get('comment/reply/form/:id', 'community.CommunityComment/replyForm')->option(['real_name' => '社区评论回复']);
        //社区评论回复
        Route::post('comment/reply/:id', 'community.CommunityComment/setReply')->option(['real_name' => '社区评论回复']);
        //社区评论删除
        Route::delete('comment/del/:id', 'community.CommunityComment/delete')->option(['real_name' => '社区评论列表']);
        //社区评论审核表单
        Route::get('comment/verify/form/:id', 'community.CommunityComment/verifyForm')->option(['real_name' => '社区内容审核']);
        //显示隐藏
        Route::put('comment/set_status/:id/:status', 'community.CommunityComment/setStatus')->option(['real_name' => '显示隐藏']);
        //社区虚拟评论表单
        Route::get('comment/fictitious/:id', 'community.CommunityComment/fictitiousComment')->option(['real_name' => '社区虚拟评论表单']);
        //社区保存虚拟评论
        Route::post('comment/save_fictitious', 'community.CommunityComment/saveFictitiousComment')->option(['real_name' => '社区保存虚拟评论']);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');
    /**
     * 用户
     */
    Route::group('user', function () {
        //门店搜索用户
        Route::get('search', 'user.User/search')->option(['real_name' => '门店搜索用户']);
        //获取指定用户的信息
        Route::get('one_info/:id', 'user.User/oneUserInfo')->option(['real_name' => '获取指定用户的信息']);
        //获取单个卡项信息
        Route::get('card_holder/:id', 'user.User/cardHolder')->option(['real_name' => '卡项信息']);
        //用户管理资源路由
        Route::resource('user', 'user.User')->except(['create', 'save'])->option(['real_name' => [
            'index' => '获取门店用户列表',
            'read' => '获取门店用户详情',
            'edit' => '获取修改用户表单',
            'update' => '修改用户',
            'delete' => '删除用户'
        ]]);
        //用户标签分类
        Route::resource('user_label_cate', 'user.UserLabelCate')->option(['real_name' => [
            'index' => '获取标签分类列表',
            'read' => '获取标签分类详情',
            'create' => '获取创建标签分类表单',
            'save' => '保存标签分类',
            'edit' => '获取修改标签分类表单',
            'update' => '修改标签分类',
            'delete' => '删除标签分类'
        ]]);
        //添加或修改用户标签
        Route::post('user_label/save', 'user.UserLabel/save')->option(['real_name' => '添加或修改用户标签']);
        //用户标签
        Route::resource('user_label', 'user.UserLabel')->except(['read', 'save', 'update'])->option(['real_name' => [
            'index' => '获取标签列表',
            'read' => '获取标签详情',
            'create' => '获取创建标签表单',
            'save' => '保存分类',
            'edit' => '获取修改标签表单',
            'update' => '修改标签',
            'delete' => '删除标签'
        ]]);
        //获取用户标签
        Route::get('label/:uid', 'user.UserLabel/getUserLabel')->option(['real_name' => '获取用户标签']);
        //设置和取消用户标签
        Route::post('label/:uid', 'user.UserLabel/setUserLabel')->option(['real_name' => '设置和取消用户标签']);
        //设置用户标签
        Route::post('set_label', 'user.User/set_label')->option(['real_name' => '设置用户标签']);
        //保存用户标签
        Route::put('save_set_label', 'user.User/save_set_label')->option(['real_name' => '保存用户标签']);

        //获取储值套餐
        Route::get('recharge/meal', 'user.UserRecharge/index')->option(['real_name' => '获取储值套餐']);
        //给用户储值
        Route::post('recharge', 'user.UserRecharge/recharge')->option(['real_name' => '获取储值套餐']);
        //获取svip列表
        Route::get('member/ship', 'user.UserMember/index')->option(['real_name' => '获取svip列表']);
        //给用户购买付费会员
        Route::post('member', 'user.UserMember/payMember')->option(['real_name' => '给用户购买付费会员']);
        //获取卡卷颜色
        Route::get('wechat/card', 'user.User/getWechatCard')->option(['real_name' => '获取卡卷颜色']);
        //用户分组列表
        Route::get('group/list', 'user.User/userGroup')->option(['real_name' => '用户分组列表']);
        //用户等级列表
        Route::get('level/list', 'user.User/userLevel')->option(['real_name' => '用户等级列表']);
        //用户标签树
        Route::get('user_label/tree', 'user.User/userLabelTree')->option(['real_name' => '用户标签树形列表']);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /**
     * 员工
     */
    Route::group('staff', function () {
        //登录收银端
        Route::get('login_cashier/:id', 'staff.StoreStaff/loginCashier')->option(['real_name' => '登录收银端']);
        //获取店员统计详情
        Route::get('info/:id', 'staff.StoreStaff/staffDetail')->option(['real_name' => '获取店员统计详情']);
        //修改订单关联店员
        Route::get('order/staff', 'order.Order/updateOrderStaff')->option(['real_name' => '修改订单关联店员']);
        //获取店员交易统计
        Route::get('statistics', 'staff.StoreStaff/getStaffStatistics')->option(['real_name' => '获取店员交易统计']);
        //店员交易统计导出
        Route::get('statistics/export', 'export.ExportExcel/statisticsExport')->option(['real_name' => '店员交易统计导出']);
        //获取店员交易头部数据
        Route::get('statisticsHeader', 'staff.StoreStaff/getStaffStatisticsHeader')->option(['real_name' => '获取店员交易头部数据']);
        //获取门店所有店员
        Route::get('staff/all', 'staff.StoreStaff/getStaffSelect')->option(['real_name' => '获取门店所有店员']);
		//获取门店店员列表
		Route::get('workMember/list', 'staff.StoreStaff/getWorkMemberList')->option(['real_name' => '获取门店店员列表']);
		//获取门店店员列表
		Route::get('staff', 'staff.StoreStaff/index')->option(['real_name' => '获取门店店员列表']);
        //获取门店店员详情
        Route::get('read/:id', 'staff.StoreStaff/read')->option(['real_name' => '获取门店店员详情']);
        //店员专属客户（须在 staff/:id 之前，避免被 :id 吞掉）
        Route::get('staff/customer/:id', 'staff.StoreStaff/getStaffCustomer')->option(['real_name' => '获取店员专属客户']);
        //店员业绩订单
        Route::get('staff/performance/:id', 'staff.StoreStaff/getStaffPerformance')->option(['real_name' => '获取店员业绩列表']);
        //获取门店店员详情
        Route::get('staff/:id', 'staff.StoreStaff/read')->option(['real_name' => '获取门店店员详情']);
		//获取店员详情
		Route::get('staff_info', 'staff.StoreStaff/info')->option(['real_name' => '获取店员详情']);
		//添加、编辑店员
		Route::post('staff/:id', 'staff.StoreStaff/save')->option(['real_name' => '添加、编辑店员']);
        //列表列配置
        Route::get('column_setting', 'staff.StoreStaff/getColumnSetting')->option(['real_name' => '获取店员列表列配置']);
        Route::post('column_setting', 'staff.StoreStaff/saveColumnSetting')->option(['real_name' => '保存店员列表列配置']);
		//删除门店店员
		Route::delete('staff/:id', 'staff.StoreStaff/delete')->option(['real_name' => '删除门店店员']);
        //店员绑定uid
        Route::post('binding/user', 'staff.StoreStaff/bandingUser')->option(['real_name' => '店员绑定uid']);
        //修改店员状态
        Route::put('staff/set_show/:id/:is_show', 'staff.StoreStaff/set_show')->option(['real_name' => '修改店员状态']);
        //获取配送员统计详情
        Route::get('delivery/info/:id', 'staff.StoreDelivery/deliveryDetail')->option(['real_name' => '获取配送员统计详情']);
        //配送员账单统计
        Route::get('delivery/statistics', 'staff.StoreDelivery/statistics')->option(['real_name' => '配送员账单统计']);//配送员账单统计
        //获取配送员select
        Route::get('delivery/get_delivery_select', 'staff.StoreDelivery/getDeliverySelect')->option(['real_name' => '获取配送员select']);
        //配送员账单统计头部
        Route::get('delivery/statisticsHeader', 'staff.StoreDelivery/statisticsHeader')->option(['real_name' => '配送员账单头部']);
        //配送员资源路由
        Route::resource('delivery', 'staff.StoreDelivery')->option(['real_name' => [
            'index' => '获取配送员列表',
            'read' => '获取配送员详情',
            'create' => '添加配送员表单',
            'save' => '保存配送员',
            'edit' => '获取修改配送员表单',
            'update' => '修改配送员',
            'delete' => '删除配送员'
        ]]);
        //修改配送员状态
        Route::put('delivery/set_show/:id/:status', 'staff.StoreDelivery/set_status')->option(['real_name' => '修改配送员状态']);

        //店员收银台交接班记录
        Route::get('shift/list', 'staff.StoreStaff/getShiftHandover')->option(['real_name' => '店员收银台交接班记录']);
        //店员交接班时业绩
        Route::get('shift/handover/:id', 'staff.StoreStaff/shiftHandover')->option(['real_name' => '店员交接班时业绩']);
        // 员工排班/休息
        Route::get('schedule/list', 'staff.StaffSchedule/index')->option(['real_name' => '员工排班列表']);
        Route::get('schedule/calendar', 'staff.StaffSchedule/calendar')->option(['real_name' => '排班月历看板']);
        Route::get('schedule/day', 'staff.StaffSchedule/dayDetail')->option(['real_name' => '某日排班详情']);
        Route::post('schedule/day/save', 'staff.StaffSchedule/saveDay')->option(['real_name' => '按班次保存排班']);
        Route::get('schedule/staff/selectable', 'staff.StaffSchedule/selectableStaff')->option(['real_name' => '可选排班员工']);
        Route::post('schedule/save', 'staff.StaffSchedule/save')->option(['real_name' => '保存员工排班']);
        Route::post('schedule/batch', 'staff.StaffSchedule/batchSave')->option(['real_name' => '批量保存排班']);
        Route::delete('schedule/del/:id', 'staff.StaffSchedule/delete')->option(['real_name' => '删除排班记录']);
        Route::get('shift/template/list', 'staff.StaffShift/index')->option(['real_name' => '班次模板列表']);
        Route::post('shift/template/save', 'staff.StaffShift/save')->option(['real_name' => '保存班次模板']);
        Route::delete('shift/template/del/:id', 'staff.StaffShift/delete')->option(['real_name' => '删除班次模板']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /**
     * 财务
     */
    Route::group('finance', function () {
        //获取门店财务信息
        Route::get('info', 'system.Store/getFinanceInfo')->option(['real_name' => '获取门店财务信息']);
        //设置门店财务信息
        Route::post('info', 'system.Store/setFinanceInfo')->option(['real_name' => '设置门店财务信息']);
        //门店转账列表
        Route::get('storeExtract/list', 'finance.StoreExtract/index')->option(['real_name' => '门店转账列表']);
        //门店转账记录备注
        Route::post('storeExtract/mark/:id', 'finance.StoreExtract/mark')->option(['real_name' => '门店转账记录备注']);
        //门店申请转账
        Route::post('storeExtract/cash', 'finance.StoreExtract/cash')->option(['real_name' => '门店申请转账']);
        //门店流水列表
        Route::get('store_finance_flow/list', 'finance.StoreFinanceFlow/index')->option(['real_name' => '门店流水列表']);
        //获取店员select
        Route::get('store_finance_flow/staff', 'finance.StoreFinanceFlow/getStaffSelect')->option(['real_name' => '获取店员select']);
        //门店流水备注
        Route::post('store_finance_flow/mark/:id', 'finance.StoreFinanceFlow/mark')->option(['real_name' => '门店流水备注']);
        //门店账单记录
        Route::get('store_finance_flow/fund_record', 'finance.StoreFinanceFlow/fundRecord')->option(['real_name' => '门店账单记录']);
        //门店账单详情
        Route::get('store_finance_flow/fund_record_info', 'finance.StoreFinanceFlow/fundRecordInfo')->option(['real_name' => '门店账单详情']);

		//储值退款
		Route::put('recharge/:id', 'order.Recharge/refund_update')->option(['real_name' => '储值退款']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');


	/**
     * 系统设置维护 系统权限管理、系统菜单管理 系统配置 相关路由
     */
    Route::group('setting', function () {
		//运费模板列表
        Route::get('shipping_templates/list', 'product.shipping.ShippingTemplates/temp_list')->option(['real_name' => '运费模板列表']);
        //修改运费模板数据
        Route::get('shipping_templates/:id/edit', 'product.shipping.ShippingTemplates/edit')->option(['real_name' => '修改运费模板数据']);
        //新增或修改运费模版
        Route::post('shipping_templates/save/:id', 'product.shipping.ShippingTemplates/save')->option(['real_name' => '新增或修改运费模版']);
        //删除运费模板
        Route::delete('shipping_templates/del/:id', 'product.shipping.ShippingTemplates/delete')->option(['real_name' => '删除运费模板']);
        //城市数据接口
        Route::get('shipping_templates/city_list', 'product.shipping.ShippingTemplates/city_list')->option(['real_name' => '城市数据接口']);

	})->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /**
     * 商品
     */
    Route::group('product', function () {
		//商品批量操作
		Route::post('batch_process', 'product.StoreProduct/batchProcess')->option(['real_name' => '商品批量操作']);
        //商品导入
        Route::post('product_import', 'product.StoreProduct/productImport')->option(['real_name' => '商品导入']);
        //商品标签（分类）树形列表
        Route::get('product_label', 'product.label.StoreProductLabel/tree_list')->option(['real_name' => '商品标签（分类）树形列表']);
        Route::get('all_label', 'product.label.StoreProductLabel/allLabel')->option(['real_name' => '所有的用户标签']);
        Route::get('all_ensure', 'product.ensure.StoreProductEnsure/allEnsure')->option(['real_name' => '所有的保障服务']);
        Route::get('all_specs', 'product.specs.StoreProductSpecs/allSpecs')->option(['real_name' => '所有的参数模版']);
        Route::get('get_all_unit', 'product.StoreProductUnit/getAllUnit')->option(['real_name' => '获取所有商品单位']);

        //商品分类列表
        Route::get('category', 'product.StoreProductCategory/index')->option(['real_name' => '商品分类列表']);
		//商品分类树形列表
        Route::get('category/tree/:type', 'product.StoreProductCategory/tree_list')->option(['real_name' => '商品分类树形列表']);
        //商品分类cascader行列表
        Route::get('category/cascader_list/[:type]', 'product.StoreProductCategory/cascader_list')->option(['real_name' => '商品分类cascader行列表']);
        //商品分类新增表单
        Route::get('category/create', 'product.StoreProductCategory/create')->option(['real_name' => '商品分类新增表单']);
        //商品分类新增
        Route::post('category', 'product.StoreProductCategory/save')->option(['real_name' => '商品分类新增']);
        //商品分类编辑表单
        Route::get('category/:id', 'product.StoreProductCategory/edit')->option(['real_name' => '商品分类编辑表单']);
        //商品分类编辑
        Route::put('category/:id', 'product.StoreProductCategory/update')->option(['real_name' => '商品分类编辑']);
        //删除商品分类
        Route::delete('category/:id', 'product.StoreProductCategory/delete')->option(['real_name' => '删除商品分类']);
        //商品分类修改状态
        Route::put('category/set_show/:id/:is_show', 'product.StoreProductCategory/set_show')->option(['real_name' => '商品分类修改状态']);

		//获取运费模板
        Route::get('product/get_template', 'product.StoreProduct/get_template')->option(['real_name' => '获取运费模板']);
		//上传视频密钥接口
        Route::get('product/get_temp_keys', 'product.StoreProduct/getTempKeys')->option(['real_name' => '上传视频密钥接口']);
		//获取商品规则属性模板
        Route::get('product/get_rule', 'product.StoreProduct/get_rule')->option(['real_name' => '获取商品规则属性模板']);
		//获取所有商品列表
        Route::get('product/list', 'product.StoreProduct/search_list')->option(['real_name' => '获取所有商品列表']);
        //获取商品规格
        Route::get('product/attrs/:id', 'product.StoreProduct/getAttrs')->option(['real_name' => '获取商品规格']);
        //快速批量修改库存
        Route::put('product/saveStocks/:id', 'product.StoreProduct/saveProductAttrsStock')->option(['real_name' => '快速批量修改库存']);
		//快速批量修改sku售价
		Route::put('product/savePrice/:id', 'product.StoreProduct/saveProductAttrsPrice')->option(['real_name' => '快速批量修改sku售价']);
        //门店同步商品库存
        Route::post('product/synchStocks', 'product.StoreProduct/synchStocks')->option(['real_name' => '门店同步商品库存']);

		//商品列表
		Route::get('product', 'product.StoreProduct/index')->option(['real_name' => '商品列表']);
		//新建或修改商品
		Route::post('product/:id', 'product.StoreProduct/save')->option(['real_name' => '新建或修改商品']);
        //修改门店分类
		Route::post('product/cate/:id', 'product.StoreProduct/updateStoreCate')->option(['real_name' => '修改门店分类']);
		//商品放入回收站
        Route::delete('product/:id', 'product.StoreProduct/delete')->option(['real_name' => '商品放入回收站']);
		//回收站商品删除
		Route::delete('product/del/:id', 'product.StoreProduct/delProduct')->option(['real_name' => '回收站商品删除']);
        //修改商品状态
        Route::put('product/set_show/:id/:is_show', 'product.StoreProduct/set_show')->option(['real_name' => '修改商品状态']);
		//设置批量商品上架
		Route::put('product/product_show', 'product.StoreProduct/product_show')->option(['real_name' => '设置批量商品上架']);
		//设置批量商品下架
		Route::put('product/product_unshow', 'product.StoreProduct/product_unshow')->option(['real_name' => '设置批量商品下架']);


        //获取关联用户标签
        Route::get('getUserLabel', 'product.StoreProduct/getUserLabel')->option(['real_name' => '获取关联用户标签']);
		//商品规则列表
        Route::get('product/rule', 'product.StoreProductRule/index')->option(['real_name' => '商品规则列表']);
        //新建或编辑商品规则
        Route::post('product/rule/:id', 'product.StoreProductRule/save')->option(['real_name' => '新建或编辑商品规则']);
        //商品规则详情
        Route::get('product/rule/:id', 'product.StoreProductRule/read')->option(['real_name' => '商品规则详情']);
        //删除商品规则
        Route::delete('product/rule/delete/:id', 'product.StoreProductRule/delete')->option(['real_name' => '删除商品规则']);
		//商品详情
		Route::get('product/:id', 'product.StoreProduct/get_product_info')->option(['real_name' => '商品详情']);

        //商品列表头部数据
        Route::get('type_header', 'product.StoreProduct/type_header')->option(['real_name' => '商品列表头部数据']);
        //修改商品状态
        Route::put('product/set_show/:id/:is_show', 'product.StoreProduct/set_show')->option(['real_name' => '修改商品状态']);
		//生成商品规格列表
        Route::post('generate_attr/:id/:type', 'product.StoreProduct/is_format_attr')->option(['real_name' => '生成商品规格列表']);
        //商品评价
        //商品评论列表
        Route::get('reply', 'product.StoreProductReply/index')->option(['real_name' => '商品评论列表']);
        //商品回复评论
        Route::put('reply/set_reply/:id', 'product.StoreProductReply/set_reply')->option(['real_name' => '商品回复评论']);
        //删除商品评论
        Route::delete('reply/:id', 'product.StoreProductReply/delete')->option(['real_name' => '删除商品评论']);

		//商品品牌cascader行列表
		Route::get('brand/cascader_list/[:type]', 'product.StoreBrand/cascader_list')->option(['real_name' => '商品品牌cascader行列表']);

		//商品标签
		Route::post('label/:id', 'product.label.StoreProductLabel/save')->option(['real_name' => '保存商品标签']);
		Route::delete('label/:id', 'product.label.StoreProductLabel/delete')->option(['real_name' => '删除商品标签']);
		Route::get('label/form', 'product.label.StoreProductLabel/getLabelForm')->option(['real_name' => '获取商品标签表单']);

        //获取数据
        Route::get('obtain/data/:id', 'product.StoreProduct/productData')->option(['real_name' => '获取数据']);
        //修改数据
        Route::post('modify/data/:id', 'product.StoreProduct/updataData')->option(['real_name' => '修改数据']);

		//库存路由
		Route::group('inventory', function () {
			//入库
			Route::get('in/order', 'product.inventory.StoreProductStockInOrder/index')->option(['real_name' => '获取所有入库单列表']);
			Route::get('in/order/refundInfo', 'product.inventory.StoreProductStockInOrder/getRefundOrderInfo')->option(['real_name' => '退货入库查询退款单信息']);
			Route::post('in/order/add', 'product.inventory.StoreProductStockInOrder/save')->option(['real_name' => '保存入库单']);
			Route::get('in/order/info/:id', 'product.inventory.StoreProductStockInOrder/info')->option(['real_name' => '获取入库单详情']);
			Route::get('in/order/remark/form/:id', 'product.inventory.StoreProductStockInOrder/remarkForm')->option(['real_name' => '获取入库单备注表单']);
			Route::post('in/order/remark/:id', 'product.inventory.StoreProductStockInOrder/remark')->option(['real_name' => '保存入库单备注']);
			Route::get('in/order/template', 'product.inventory.StoreProductStockInOrder/downloadTemplate')->option(['real_name' => '下载入库导入模板']);
			Route::post('in/order/template', 'product.inventory.StoreProductStockInOrder/downloadTemplate')->option(['real_name' => '下载入库导入模板']);
			Route::get('in/order/template/file', 'product.inventory.StoreProductStockInOrder/downloadTemplateFile')->option(['real_name' => '流式下载入库导入模板']);
			Route::post('in/order/import', 'product.inventory.StoreProductStockInOrder/import')->option(['real_name' => '导入入库Excel']);

			//出库
			Route::get('out/order', 'product.inventory.StoreProductStockOutOrder/index')->option(['real_name' => '获取所有出库单列表']);
			Route::post('out/order/add', 'product.inventory.StoreProductStockOutOrder/save')->option(['real_name' => '保存出库单']);
			Route::get('out/order/info/:id', 'product.inventory.StoreProductStockOutOrder/info')->option(['real_name' => '获取出库单详情']);
			Route::get('out/order/remark/form/:id', 'product.inventory.StoreProductStockOutOrder/remarkForm')->option(['real_name' => '获取出库单备注表单']);
			Route::post('out/order/remark/:id', 'product.inventory.StoreProductStockOutOrder/remark')->option(['real_name' => '保存出库单备注']);
			Route::get('out/order/template', 'product.inventory.StoreProductStockOutOrder/downloadTemplate')->option(['real_name' => '下载出库导入模板']);
			Route::post('out/order/template', 'product.inventory.StoreProductStockOutOrder/downloadTemplate')->option(['real_name' => '下载出库导入模板']);
			Route::get('out/order/template/file', 'product.inventory.StoreProductStockOutOrder/downloadTemplateFile')->option(['real_name' => '流式下载出库导入模板']);
			Route::post('out/order/import', 'product.inventory.StoreProductStockOutOrder/import')->option(['real_name' => '导入出库Excel']);

			//库存盘点
			Route::get('count/list', 'product.inventory.StoreProductStockCount/index')->option(['real_name' => '获取所有库存盘点列表']);
			Route::post('count/save/:id', 'product.inventory.StoreProductStockCount/save')->option(['real_name' => '新增库存盘点']);
			Route::get('count/info/:id', 'product.inventory.StoreProductStockCount/info')->option(['real_name' => '新增库存盘点']);
			Route::get('count/remark/form/:id', 'product.inventory.StoreProductStockCount/remarkForm')->option(['real_name' => '获取出库单备注表单']);
			Route::post('count/remark/:id', 'product.inventory.StoreProductStockCount/remark')->option(['real_name' => '保存出库单备注']);

			//出入库商品sku
			Route::get('productAttr/statistics', 'product.inventory.StoreProductStockDetail/productAttrStatistics')->option(['real_name' => '出入库明细顶部统计']);
			Route::get('productAttr/list', 'product.inventory.StoreProductStockDetail/getProductAttrList')->option(['real_name' => '出入库明细列表']);
			Route::get('productAttr/info', 'product.inventory.StoreProductStockDetail/getProductAttrInfo')->option(['real_name' => '商品sku信息']);
			Route::get('productAttr/order/list', 'product.inventory.StoreProductStockDetail/getProductAttrStockOrderList')->option(['real_name' => 'sku出入库明细']);

			//库存明细
			Route::get('detail/list', 'product.inventory.StoreProductStockDetail/index')->option(['real_name' => '库存明细列表']);
			//出入库统计
			Route::get('order/overall_statistics', 'product.inventory.StoreProductStockDetail/stockOrderOverallStatistics')->option(['real_name' => '出入库顶部统计']);
			Route::get('order/statistics', 'product.inventory.StoreProductStockDetail/stockOrderStatistics')->option(['real_name' => '出入库统计']);

			//请货
			Route::get('request/list', 'product.inventory.StoreStockRequest/index')->option(['real_name' => '请货单列表']);
			Route::get('request/info/:id', 'product.inventory.StoreStockRequest/info')->option(['real_name' => '请货单详情']);
			Route::post('request/save/:id', 'product.inventory.StoreStockRequest/save')->option(['real_name' => '保存请货草稿']);
			Route::delete('request/:id', 'product.inventory.StoreStockRequest/delete')->option(['real_name' => '删除请货草稿']);
			Route::post('request/confirm/:id', 'product.inventory.StoreStockRequest/confirmApply')->option(['real_name' => '确认请货申请']);
			Route::post('request/reject/:id', 'product.inventory.StoreStockRequest/reject')->option(['real_name' => '驳回请货']);
			Route::post('request/cancel/:id', 'product.inventory.StoreStockRequest/cancel')->option(['real_name' => '取消请货']);
			Route::get('request/shared_skus', 'product.inventory.StoreStockRequest/sharedSkus')->option(['real_name' => '双店同源SKU']);
			Route::get('request/pending_badge', 'product.inventory.StoreStockRequest/pendingBadge')->option(['real_name' => '请货待办角标']);

			//调拨
			Route::get('transfer/list', 'product.inventory.StoreStockTransfer/index')->option(['real_name' => '调拨单列表']);
			Route::get('transfer/info/:id', 'product.inventory.StoreStockTransfer/info')->option(['real_name' => '调拨单详情']);
			Route::post('transfer/save/:id', 'product.inventory.StoreStockTransfer/save')->option(['real_name' => '保存调拨草稿']);
			Route::delete('transfer/:id', 'product.inventory.StoreStockTransfer/delete')->option(['real_name' => '删除调拨草稿']);
			Route::post('transfer/cancel/:id', 'product.inventory.StoreStockTransfer/cancel')->option(['real_name' => '取消调拨草稿']);
			Route::post('transfer/confirm/:id', 'product.inventory.StoreStockTransfer/confirm')->option(['real_name' => '确认调拨']);
			Route::post('transfer/reverse/:id', 'product.inventory.StoreStockTransfer/reverse')->option(['real_name' => '调拨冲销']);

			//院装·项目配方
			Route::get('recipe/list', 'product.inventory.StoreProjectConsumableRecipe/index')->option(['real_name' => '项目配方列表']);
			Route::get('recipe/info/:id', 'product.inventory.StoreProjectConsumableRecipe/info')->option(['real_name' => '项目配方详情']);
			Route::post('recipe/save/:id', 'product.inventory.StoreProjectConsumableRecipe/save')->option(['real_name' => '保存项目配方']);
			Route::post('recipe/status/:id', 'product.inventory.StoreProjectConsumableRecipe/setStatus')->option(['real_name' => '启用停用项目配方']);
			Route::delete('recipe/:id', 'product.inventory.StoreProjectConsumableRecipe/delete')->option(['real_name' => '删除项目配方']);

			//院装·院装管理（领用/退回明细与统计）
			Route::get('salon/usage/list', 'product.inventory.SalonStockReport/usageList')->option(['real_name' => '院装领用退回明细']);
			Route::get('salon/usage/statistics', 'product.inventory.SalonStockReport/statistics')->option(['real_name' => '院装耗材统计']);
		});

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

	/**
     * 优惠卷相关路由
     */
    Route::group('marketing', function () {
        //显示资源列表头部
        Route::get('coupon/header', 'marketing.coupon.StoreCouponIssue/type_header')->option(['real_name' => '显示资源列表头部']);
        //已发布优惠券列表
        Route::get('coupon/released', 'marketing.coupon.StoreCouponIssue/index')->option(['real_name' => '已发布优惠券列表']);
        //添加优惠券
        Route::post('coupon/save_coupon/:id', 'marketing.coupon.StoreCouponIssue/saveCoupon')->option(['real_name' => '添加优惠券']);
        //修改优惠券状态
        Route::get('coupon/status/:id/:status', 'marketing.coupon.StoreCouponIssue/status')->option(['real_name' => '修改优惠券状态']);
        //一键复制优惠券
        Route::get('coupon/copy/:id', 'marketing.coupon.StoreCouponIssue/copy')->option(['real_name' => '一键复制优惠券']);
        //发送优惠券列表
        Route::get('coupon/grant', 'marketing.coupon.StoreCouponIssue/index')->option(['real_name' => '发送优惠券列表']);
        //已发布优惠券删除
        Route::delete('coupon/released/:id', 'marketing.coupon.StoreCouponIssue/delete')->option(['real_name' => '已发布优惠券删除']);
        //已发布优惠券修改状态表单
        Route::get('coupon/released/:id/status', 'marketing.coupon.StoreCouponIssue/edit')->option(['real_name' => '已发布优惠券修改状态表单']);
        //已发布优惠券修改状态
        Route::put('coupon/released/status/:id', 'marketing.coupon.StoreCouponIssue/status')->option(['real_name' => '已发布优惠券修改状态']);
        //已发布优惠券领取记录
        Route::get('coupon/released/issue_log/:id', 'marketing.coupon.StoreCouponIssue/issue_log')->option(['real_name' => '已发布优惠券领取记录']);
        //会员领取记录
        Route::get('coupon/user', 'marketing.coupon.StoreCouponUser/index')->option(['real_name' => '会员领取记录']);
        //发送优惠券
        Route::post('coupon/user/grant', 'marketing.coupon.StoreCouponUser/grant')->option(['real_name' => '发送优惠券']);

		//短视频
        Route::get('video/index', 'marketing.video.video/index')->option(['real_name' => '短视频列表']);
		//短视频信息
		Route::get('video/info/:id', 'marketing.video.video/info')->option(['real_name' => '短视频信息']);
		//短视频保存
		Route::post('video/save/:id', 'marketing.video.video/save')->option(['real_name' => '短视频保存']);
		//短视频上下架
        Route::get('video/set_status/:id/:status', 'marketing.video.video/set_show')->option(['real_name' => '短视频上下架']);
		//短视频删除
        Route::delete('video/del/:id', 'marketing.video.video/delete')->option(['real_name' => '短视频删除']);

		//短视频评论
		Route::get('video/comment', 'marketing.video.VideoComment/index')->option(['real_name' => '短视频评论列表']);
		//短视频评论回复列表
		Route::get('video/comment/reply/:id', 'marketing.video.VideoComment/getCommentReply')->option(['real_name' => '短视频评论回复列表']);
		//短视频评论回复
		Route::post('video/comment/reply/:id', 'marketing.video.VideoComment/setReply')->option(['real_name' => '短视频评论回复']);
		//短视频评论获取管理员回复
		Route::get('video/comment/get_reply/:id', 'marketing.video.VideoComment/getReply')->option(['real_name' => '短视频评论获取管理员回复']);
		//短视频评论删除
		Route::delete('video/comment/:id', 'marketing.video.VideoComment/delete')->option(['real_name' => '短视频评论列表']);
		//短视频自评表单
        Route::get('video/comment/fictitious/:video_id', 'marketing.video.VideoComment/fictitiousComment')->option(['real_name' => '短视频自评表单']);
        //短视频保存自评
        Route::post('video/comment/save_fictitious', 'marketing.video.VideoComment/saveFictitiousComment')->option(['real_name' => '短视频保存自评']);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /**
     * 附件相关路由
     */

    Route::group('file', function () {
        //图片附件列表
        Route::get('file', 'file.SystemAttachment/index')->option(['real_name' => '图片附件列表']);
        //删除图片
        Route::post('file/delete', 'file.SystemAttachment/delete')->option(['real_name' => '删除图片']);
        //移动图片分类表单
        Route::get('file/move', 'file.SystemAttachment/move')->option(['real_name' => '移动图片分类表单']);
        //移动图片分类
        Route::put('file/do_move', 'file.SystemAttachment/moveImageCate')->option(['real_name' => '移动图片分类']);
        //修改图片名称
        Route::put('file/update/:id', 'file.SystemAttachment/update')->option(['real_name' => '修改图片名称']);
        //上传图片
        Route::post('upload/[:upload_type]', 'file.SystemAttachment/upload')->option(['real_name' => '上传图片']);
		//获取上传类型
        Route::get('upload_type', 'file.SystemAttachment/uploadType')->option(['real_name' => '上传类型']);
		//分片上传本地视频
        Route::post('video_upload', 'file.SystemAttachment/videoUpload')->option(['real_name' => '分片上传本地视频']);
		//oss视频素材保存
        Route::post('video_attachment', 'file.SystemAttachment/saveVideoAttachment')->option(['real_name' => '视频素材保存']);

        //获取扫码上传页面链接以及参数
        Route::get('scan/qrcode', 'file.SystemAttachment/scanUploadQrcode')->option(['real_name' => '获取扫码上传页面链接以及参数']);
        //删除二维码
        Route::get('remove/qrcode', 'file.SystemAttachment/removeUploadQrcode')->option(['real_name' => '删除二维码']);
        //获取扫码上传的图片数据
        Route::get('scan/image/list/:scan_token', 'file.SystemAttachment/scanUploadImage')->option(['real_name' => '获取扫码上传的图片数据']);
        //网络图片上传
        Route::post('online/upload', 'file.SystemAttachment/onlineUpload')->option(['real_name' => '网络图片上传']);

        //获取上传信息
        Route::get('get/way_data', 'file.SystemAttachment/getAdminsData')->option(['real_name' => '获取上传信息']);
        //保存上传信息
        Route::get('set/way_data/:is_way', 'file.SystemAttachment/setAdminsData')->option(['real_name' => '保存上传信息']);

        //附件分类管理资源路由
        Route::resource('category', 'file.SystemAttachmentCategory')->option(['real_name' => [
            'index' => '获取附件分类列表',
            'read' => '获取附件分类详情',
            'create' => '获取创建附件分类表单',
            'save' => '保存附件分类',
            'edit' => '获取修改附件分类表单',
            'update' => '修改附件分类',
            'delete' => '删除附件分类'
        ]]);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');
    Route::group('report', function () {
        Route::get('order_data', 'report.Report/orderData')->name('reportOrder')->option(['real_name' => '订单报表']);
        Route::get('reportSale', 'report.ReportData/reportSale')->name('reportColumn')->option(['real_name' => '报表列信息']);
        Route::get('reportList', 'report.ReportData/reportList')->name('reportList')->option(['real_name' => '报表列表']);
        Route::get('receiveColumn', 'report.Report/receiveColumn')->name('receiveColumn')->option(['real_name' => 'receiveColumn']);
        Route::get('fenxiList', 'report.ReportData/fenxiList')->name('fenxiList')->option(['real_name' => '项目分析列表']);
        Route::get('xnList', 'report.ReportData/xnList')->name('xnList')->option(['real_name' => '门店效能分析']);
        Route::get('pkInfo', 'report.ReportPk/pkInfo')->name('pkInfo')->option(['real_name' => '报表PK']);
        Route::post('save_pk', 'report.ReportPk/savePk')->name('savePk')->option(['real_name' => '修改PK']);
        Route::get('salaryColumn', 'report.ReportSalary/salaryColumn')->name('salaryColumn')->option(['real_name' => '工资列']);
        Route::get('salaryList', 'report.ReportSalary/salaryList')->name('salaryList')->option(['real_name' => '工资表']);
        Route::post('setSalary', 'report.ReportSalary/setSalary')->name('setSalary')->option(['real_name' => '保存工资']);
        Route::get('agentList', 'report.ReportSalary/agentList')->name('agentList')->option(['real_name' => '代班列表']);
        Route::get('editAgent', 'report.ReportSalary/editAgent')->name('editAgent')->option(['real_name' => '编辑代班']);
        Route::post('saveAgent/:id', 'report.ReportSalary/saveAgent')->name('saveAgent')->option(['real_name' => '保存代班']);
        Route::put('delAgent/:id', 'report.ReportSalary/delAgent')->name('delAgent')->option(['real_name' => '删除代班']);
        Route::get('getFreeze', 'report.ReportSalary/getFreeze')->name('getFreeze')->option(['real_name' => '保存工资']);
        Route::get('selfCount', 'report.ReportTable/selfCount')->name('selfCount')->option(['real_name' => '报表合计']);
        Route::get('selfColumn', 'report.ReportTable/selfColumn')->name('selfColumn')->option(['real_name' => '报表列名']);
        Route::get('selfSearch', 'report.ReportTable/selfSearch')->name('selfSearch')->option(['real_name' => '报表搜索栏']);
        Route::get('selfList', 'report.ReportTable/selfList')->name('selfList')->option(['real_name' => '报表列表']);
        Route::get('getTable', 'report.ReportTable/getTable')->name('getTable')->option(['real_name' => '获取报表']);
        Route::delete('reportTable/del/:id', 'report.ReportTable/delTable')->name('delTable')->option(['real_name' => '删除行']);
        Route::post('setTableSalary', 'report.ReportTable/setSalary')->name('setSalary')->option(['real_name' => '编辑表单']);
        Route::post('addTableSalary', 'report.ReportTable/addSalary')->name('addSalary')->option(['real_name' => '新增表单']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    Route::group('order', function () {
        Route::post('get_remark_info', 'yeji.Yeji/getRemark')->option(['real_name' => '获得订单备注']);
        Route::post('saveRemark', 'yeji.Yeji/saveRemark')->option(['real_name' => '更新付款方式']);
        Route::post('saveSource', 'yeji.Yeji/saveSource')->option(['real_name' => '更新付款方式']);
        Route::post('saveGendan', 'yeji.Yeji/saveGendan')->option(['real_name' => '更新跟单状态']);
        Route::post('allList', 'yeji.Yeji/allList')->name('allList')->option(['real_name' => '']);
        Route::post('save_yeji', 'yeji.Yeji/save_yeji')->name('save_yeji')->option(['real_name' => '']);
        Route::post('get_yeji', 'yeji.Yeji/getYeji')->option(['real_name' => '获得业绩']);
        Route::post('get_cart_yeji', 'yeji.Yeji/getCartYeji')->option(['real_name' => '获得业绩']);
        Route::delete('delCoupon/:id', 'yeji.yeji/delCoupon')->option(['real_name' => '删除会员优惠劵记录']);
        Route::group('cashier', function () {
            Route::post('user', 'order.Cashier/getUserInfo')->name('cashierUserInfo')->option(['real_name' => '获取收银台用户信息']);
            Route::get('cate', 'order.Cashier/getCateGoryList')->name('cashierCateGoryList')->option(['real_name' => '获取收银台一级分类列表']);
            Route::get('product', 'order.Cashier/getProductList')->name('cashierProductList')->option(['real_name' => '获取收银台商品信息']);
            Route::get('cart/:uid/:staff_id', 'order.Cashier/getCartList')->name('cashierCartList')->option(['real_name' => '获取收银台购物车信息']);
            Route::post('cart/:uid', 'order.Cashier/addCart')->name('cashierAddCart')->option(['real_name' => '收银台添加购物车']);
            Route::post('hang', 'order.Cashier/saveHangOrder')->name('saveHangOrder')->option(['real_name' => '收银台保存挂单']);
            Route::delete('hang', 'order.Cashier/deleteHangOrder')->name('deleteHangOrder')->option(['real_name' => '收银台删除挂单']);
            Route::post('switch/:staffId', 'order.Cashier/switchCartUser')->name('switchCartUser')->option(['real_name' => '收银台切换购物车用户']);
            Route::get('hang/:staffId', 'order.Cashier/getHangOrder')->name('getHangOrder')->option(['real_name' => '收银台获取挂单']);
            Route::get('hang/list/:staffId', 'order.Cashier/getHangOrderList')->name('getHangOrderList')->option(['real_name' => '收银台获取挂单列表分页']);
            Route::delete('cart/:uid', 'order.Cashier/delCart')->name('cashierDelCart')->option(['real_name' => '收银台删除购物车信息']);
            Route::put('cart/:uid', 'order.Cashier/numCart')->name('cashierNumCart')->option(['real_name' => '收银台更改购物车数量']);
            Route::put('changeCart', 'order.Cashier/changeCart')->name('cashierChangeCart')->option(['real_name' => '收银台更改购物车规格']);
            Route::post('compute/:uid', 'order.Cashier/computeOrder')->name('cashierComputeOrder')->option(['real_name' => '收银台计算订单金额']);
            Route::post('create/:uid', 'order.Cashier/createOrder')->name('cashierCreateOrder')->option(['real_name' => '收银台创建订单']);
            Route::get('staff', 'order.Cashier/getStaffList')->name('getStaffList')->option(['real_name' => '获取当前门店店员列表和店员信息']);
            Route::post('code', 'order.Cashier/getAnalysisCode')->name('getAnalysisCode')->option(['real_name' => '扫码自动解析']);
            Route::get('detail/:id/[:uid]', 'order.Cashier/getProductDetail')->name('getProductDetail')->option(['real_name' => '收银台获取商品详情']);
            Route::post('pay/:orderId', 'order.Cashier/payOrder')->name('payOrder')->option(['real_name' => '收银台订单支付']);
            Route::get('cashier_scan', 'order.Cashier/cashier_scan')->name('cashierScan')->option(['real_name' => '门店收银台二维码']);
            Route::post('coupon_list/:uid', 'order.Cashier/couponList')->name('cashierScan')->option(['real_name' => '用户优惠券列表']);
        });
        //储值订单列表
        Route::get('recharge', 'order.Recharge/index')->name('RechargeOrderList')->option(['real_name' => '储值订单列表']);
        //删除储值记录
        Route::delete('recharge/:id', 'order.Recharge/delete')->option(['real_name' => '删除储值记录']);
        //获取用户储值数据
        Route::get('recharge/user_recharge', 'order.Recharge/user_recharge')->option(['real_name' => '获取用户储值数据']);
        //储值退款表单
        Route::get('recharge/:id/refund_edit', 'order.Recharge/refund_edit')->option(['real_name' => '储值退款表单']);
        //保存储值订单备注
        Route::put('recharge/remark/:id', 'order.Recharge/remarks')->option(['real_name' => '保存储值订单备注']);
        //获取储值订单备注
        Route::get('recharge/remark/:id', 'order.Recharge/getRemark')->option(['real_name' => '获取储值订单备注']);
        //储值退款表单
        Route::get('recharge/:id/refund_edit', 'order.Recharge/refund_edit')->option(['real_name' => '储值退款表单']);
        //储值退款
        Route::put('recharge/:id', 'order.Recharge/refund_update')->option(['real_name' => '储值退款']);

        //付费会员订单列表
        Route::get('vip_order', 'order.PayVipOrder/index')->name('PayVipOrderList')->option(['real_name' => '付费会员订单列表']);
        //获取会员备注
        Route::get('vip/remark/:id', 'order.PayVipOrder/getRemark')->name('getRemark')->option(['real_name' => '获取会员备注']);
        //获取会员状态
        Route::get('vip/status/:id', 'order.PayVipOrder/status')->name('getStatusList')->option(['real_name' => '获取会员状态']);
        //保存会员备注
        Route::put('vip/remark/:id', 'order.PayVipOrder/remark')->name('remarkSave')->option(['real_name' => '保存会员备注']);
        Route::put('postChexiao/:id', 'order.order/postChexiao')->name('postChexiao')->option(['real_name' => '撤销本次核销（兼容旧入口）']);
        Route::put('getCash', 'order.order/getCash')->name('getCash')->option(['real_name' => '保存会员备注']);
        //打印订单
        Route::get('print/:id', 'order.Order/order_print')->name('StoreOrderPrint')->option(['real_name' => '打印订单']);
        //获取头部数据
        Route::get('header', 'order.Order/header')->name('StoreOrderHeader')->option(['real_name' => '获取门店订单头部统计']);
        //订单列表
        Route::get('list', 'order.Order/index')->name('StoreOrderList')->option(['real_name' => '订单列表']);
        //订单头部数据
        Route::get('chart', 'order.Order/chart')->name('StoreOrderChart')->option(['real_name' => '订单头部数据']);
		//订单核销表单弹窗
		Route::get('write/form/:id', 'order.Order/writeOrderFrom')->name('writeOrderForm')->option(['real_name' => '订单核销表单']);
		//订单核销表单提交
		Route::post('write/form/:id', 'order.Order/writeoffFrom')->name('writeOrderForm')->option(['real_name' => '订单核销表单']);
        //订单核销
        Route::post('write', 'order.Order/write_order')->name('writeOrder')->option(['real_name' => '订单核销']);
        //获取核销订单商品信息
        Route::get('writeOff/cartInfo', 'order.Order/orderCartInfo')->name('writeOrderCartInfo')->option(['real_name' => '获取核销订单商品信息']);
        //订单号核销
        Route::put('write_update/:order_id', 'order.Order/wirteoff')->name('writeOrderUpdate')->option(['real_name' => '订单号核销']);
        Route::get('debt/list', 'order.Debt/index')->option(['real_name' => '欠款列表']);
        Route::get('debt/user/:uid', 'order.Debt/userList')->option(['real_name' => '用户欠款记录']);
        Route::get('debt/repay/list', 'order.Debt/repayList')->option(['real_name' => '用户还款记录']);
        Route::get('debt/detail/:order_id', 'order.Debt/orderDetail')->option(['real_name' => '订单欠款详情']);
        Route::put('debt/close/:id', 'order.Debt/close')->option(['real_name' => '关闭欠款']);
        Route::get('debt/order/:order_id', 'order.Debt/orderItems')->option(['real_name' => '订单欠款明细']);
        Route::post('debt/repay/pay', 'order.Debt/repayPay')->option(['real_name' => '欠款补交支付']);
        Route::post('debt/repay/check', 'order.Debt/repayCheck')->option(['real_name' => '欠款还款状态查询']);
        Route::post('cashType', 'order.Cashier/cashType')->option(['real_name' => '现金类型']);
        Route::post('cashSource', 'order.Cashier/cashSource')->option(['real_name' => '来源']);
        //获取订单编辑表单
        Route::get('edit/:id', 'order.Order/edit')->name('StoreOrderEdit')->option(['real_name' => '获取订单编辑表单']);
        //订单改价获取商品信息
        Route::get('update/info/:id', 'order.Order/getUpdateInfo')->name('StoreOrderUpdate')->option(['real_name' => '订单改价获取商品信息']);
        //管理员操作重新发单
        Route::get('reissue_order/:id', 'order.Order/adminReissueOrder')->name('adminReissueOrder')->option(['real_name' => '管理员操作重新发单']);
       //修改订单
        Route::put('update/:id', 'order.Order/update')->name('StoreOrderUpdate')->option(['real_name' => '修改订单']);
        //确认收货
        Route::put('take/:id', 'order.Order/take_delivery')->name('StoreOrderTakeDelivery')->option(['real_name' => '确认收货']);
		//未发货订单修改地址
		Route::post('edit_address/:id', 'order.Order/editAddress')->option(['real_name' => '修改收货地址']);
		//订单发送货
        Route::put('delivery/:id', 'order.Order/update_delivery')->name('StoreOrderUpdateDelivery')->option(['real_name' => '订单发送货']);
        //获取订单可拆分商品列表
        Route::get('split_cart_info/:id', 'order.Order/split_cart_info')->name('StoreOrderSplitCartInfo')->option(['real_name' => '获取订单可拆分商品列表']);
        //拆单发送货
        Route::put('split_delivery/:id', 'order.Order/split_delivery')->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);
        //获取订单拆分子订单列表
        Route::get('split_order/:id', 'order.Order/split_order')->name('StoreOrderSplitOrder')->option(['real_name' => '获取订单拆分子订单列表']);
        //订单退款表单
        Route::get('refund/:id', 'order.Order/refund')->name('StoreOrderRefund')->option(['real_name' => '订单退款表单']);
        //订单退款
        Route::put('refund/:id', 'order.Order/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '订单退款']);
        //后台拆单退款（兼容：已收口为整单退款+终态）
        Route::put('open/refund/:id', 'order.Order/open_order_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '后台拆单退款']);
        //整单退款（阶段2）：本分组前缀已是 order，勿再写 order/ 以免变成 order/order/:id/...
        Route::post(':id/refund', 'order.Order/terminal_order_refund')->option(['real_name' => '整单退款']);
        //整单作废（阶段3）
        Route::post(':id/void', 'order.Order/terminal_order_void')->option(['real_name' => '整单作废']);
        //重新开单草稿（阶段4）
        Route::post(':id/reopen', 'order.Order/terminal_order_reopen')->option(['real_name' => '创建或获取重开草稿']);
        Route::get('reopen/:token', 'order.Order/terminal_order_reopen_load')->option(['real_name' => '加载重开草稿']);
        //撤销本次核销（阶段3明确入口；旧 postChexiao 保留）
        Route::put('writeoff/:subOrderId/cancel', 'order.Order/cancel_writeoff')->option(['real_name' => '撤销本次核销']);
        //快递公司电子面单模版
        Route::get('express/temp', 'order.Order/express_temp')->option(['real_name' => '快递公司电子面单模版']);
        //获取物流信息
        Route::get('express/:id', 'order.Order/get_express')->name('StoreOrderUpdateExpress')->option(['real_name' => '获取物流信息']);
        //获取物流公司
        Route::get('express_list', 'order.Order/express')->name('StoreOrdeRexpressList')->option(['real_name' => '获取物流公司']);
        //订单详情
        Route::get('info/:id', 'order.Order/order_info')->name('StoreOrderorInfo')->option(['real_name' => '订单详情']);
        //获取配送信息表单
        Route::get('distribution/:id', 'order.Order/distribution')->name('StoreOrderorDistribution')->option(['real_name' => '获取配送信息表单']);
        //修改配送信息
        Route::put('distribution/:id', 'order.Order/update_distribution')->name('StoreOrderorUpdateDistribution')->option(['real_name' => '修改配送信息']);
        //获取不退款表单
        Route::get('no_refund/:id', 'order.Order/no_refund')->name('StoreOrderorNoRefund')->option(['real_name' => '获取不退款表单']);
        //修改不退款理由
        Route::put('no_refund/:id', 'order.Order/update_un_refund')->name('StoreOrderorUpdateNoRefund')->option(['real_name' => '修改不退款理由']);
        //线下支付
        Route::post('pay_offline/:id', 'order.Order/pay_offline')->name('StoreOrderorPayOffline')->option(['real_name' => '线下支付']);
        //获取退积分表单
        Route::get('refund_integral/:id', 'order.Order/refund_integral')->name('StoreOrderorRefundIntegral')->option(['real_name' => '获取退积分表单']);
        //修改退积分
        Route::put('refund_integral/:id', 'order.Order/update_refund_integral')->name('StoreOrderorUpdateRefundIntegral')->option(['real_name' => '修改退积分']);
        //修改备注信息
        Route::put('remark/:id', 'order.Order/remark')->name('StoreOrderorRemark')->option(['real_name' => '修改备注信息']);
        //获取订单状态
        Route::get('status/:id', 'order.Order/status')->name('StoreOrderorStatus')->option(['real_name' => '获取订单状态']);
        //删除订单单个
        Route::delete('del/:id', 'order.Order/del')->name('StoreOrderorDel')->option(['real_name' => '删除订单单个']);
        //批量删除订单
        Route::post('dels', 'order.Order/del_orders')->name('StoreOrderorDels')->option(['real_name' => '批量删除订单']);
        //面单默认配置信息
        Route::get('sheet_info', 'order.Order/getDeliveryInfo')->option(['real_name' => '面单默认配置信息']);
        //电子面单模板列表
        Route::get('expr/temp', 'order.Order/expr_temp')->option(['real_name' => '电子面单模板列表']);
        //更多操作打印电子面单
        Route::get('order_dump/:order_id', 'order.Order/order_dump')->option(['real_name' => '更多操作打印电子面单']);
        //批量发货
        Route::get('hand/batch_delivery', 'order.Order/hand_batch_delivery')->option(['real_name' => '批量发货']);
        //自动批量发货
        Route::post('other/batch_delivery', 'order.Order/other_batch_delivery')->option(['real_name' => '自动批量发货']);
        //订单批量删除
        Route::post('batch/del_orders', 'order.Order/del_orders')->option(['real_name' => '订单批量删除']);
        //订单导出
        Route::post('export/:type', 'order.Order/export')->option(['real_name' => '订单导出']);
        //订单列表获取配送员
        Route::get('delivery/list', 'order.Order/getDeliveryList')->option(['real_name' => '订单列表获取配送员']);

		//配送订单
		Route::get('delivery_order/list', 'order.StoreDeliveryOrder/index')->name('StoreDeliveryOrderList')->option(['real_name' => '配送订单列表']);
		Route::get('delivery_order/info/:id', 'order.StoreDeliveryOrder/detail')->name('StoreDeliveryOrderDetail')->option(['real_name' => '配送订单详情']);
		Route::get('delivery_order/cancelForm/:id', 'order.StoreDeliveryOrder/cancelForm')->name('StoreDeliveryOrderCancelForm')->option(['real_name' => '配送订单取消表单']);
		Route::post('delivery_order/cancel/:id', 'order.StoreDeliveryOrder/cancel')->name('StoreDeliveryOrderCancel')->option(['real_name' => '配送订单取消']);
		Route::delete('delivery_order/:id', 'order.StoreDeliveryOrder/delete')->name('StoreDeliveryOrderDelete')->option(['real_name' => '配送订单删除']);

		//打印配货单信息
		Route::get('distribution_info', 'order.Order/distributionInfo')->name('StoreOrderDistributionInfo')->option(['real_name' => '打印配货单信息']);

        //订单核销记录
        Route::get('writeoff/records/:id', 'order.Order/writeOffRecords')->name('writeOffRecords')->option(['real_name' => '订单核销记录']);
        //赠送详情
        Route::get('sendDetail/:id', 'order.Order/sendDetail')->name('sendDetail')->option(['real_name' => '赠送详情']);
        //核销记录 --门店
        Route::post('writeoff/records', 'order.Order/getWriteOffRecords')->name('getWriteOffRecords')->option(['real_name' => '核销记录']);
        //获取卡项权益
        Route::get('card/benefits/:id', 'order.Order/getCardBenefits')->name('getCardBenefits')->option(['real_name' => '获取卡项权益']);
        //后台退款信息
        Route::get('benefits/:id', 'order.Order/cardBenefits')->name('cardBenefits')->option(['real_name' => '后台退款信息']);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');


    /**
     * 售后 相关路由
     */
    Route::group('refund', function () {
        //售后列表
        Route::get('list', 'order.Refund/getRefundList')->option(['real_name' => '售后订单列表']);
        //商家同意退款，等待用户退货
        Route::get('agree/:order_id', 'order.Refund/agreeRefund')->option(['real_name' => '商家同意退款，等待用户退货']);
        //售后订单备注
        Route::put('remark/:id', 'order.Refund/remark')->option(['real_name' => '售后订单备注']);
        //售后订单退款表单
        Route::get('refund/:id', 'order.Refund/refund')->name('StoreOrderRefund')->option(['real_name' => '售后订单退款表单']);
        //售后订单退款
        Route::put('refund/:id', 'order.Refund/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '售后订单退款']);
        //售后订单详情
        Route::get('detail/:id', 'order.Refund/detail')->name('StoreOrderRefundDetail')->option(['real_name' => '售后订单详情']);
        //订单退款理由
        Route::get('reason', 'order.Refund/refund_reason')->name('orderRefundReason')->option(['real_name' => '订单退款理由']);
		//售后订单删除
		Route::delete('del/:id', 'order.Refund/del')->name('orderRefundDel')->option(['real_name' => '售后订单删除']);
		//售后退货物流
		Route::get('express/:id', 'order.Refund/getRefundExpress')->option(['real_name' => '售后退货物流']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /**
     * 小票打印
     */
    Route::group('print', function () {
        Route::get('list', 'system.SystemPrinter/index')->option(['real_name' => '小票打印列表']);
        Route::post('save/:id', 'system.SystemPrinter/save')->option(['real_name' => '添加修改小票打印']);
        Route::get('set_status/:id/:status', 'system.SystemPrinter/set_status')->option(['real_name' => '修改小票打印状态']);
        Route::delete('del/:id', 'system.SystemPrinter/delete')->option(['real_name' => '删除小票打印']);
        Route::get('content/:id', 'system.SystemPrinter/getPrintContent')->option(['real_name' => '获取小票打印详情']);
        Route::post('save_content/:id', 'system.SystemPrinter/savePrintContent')->option(['real_name' => '保存小票打印详情']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');
    /**
     * 导出excel相关路由
     */
    Route::group('export', function () {
        //门店账单导出
        Route::get('financeRecord', 'export.ExportExcel/financeRecord')->option(['real_name' => '门店账单导出']);

		//商品入库单导出
		Route::get('productStockInOrder', 'export.ExportExcel/productStockInOrder')->option(['real_name' => '商品入库单导出']);
		//商品出库单导出
		Route::get('productStockOutOrder', 'export.ExportExcel/productStockOutOrder')->option(['real_name' => '商品出库单导出']);
		//商品库存盘点导出
		Route::get('productStockCount', 'export.ExportExcel/productStockCount')->option(['real_name' => '商品库存盘点导出']);
		//商品库存明细导出
		Route::get('productStockDetail', 'export.ExportExcel/productStockDetail')->option(['real_name' => '商品库存明细导出']);
		//商品出入库存统计导出
		Route::get('productStockOrderStatistics', 'export.ExportExcel/productStockOrderStatistics')->option(['real_name' => '商品出入库存统计导出']);
        //商品导出
        Route::get('productImport', 'export.ExportExcel/storeProductImport')->option(['real_name' => '商品导出']);
        //门店用户导出
        Route::get('user', 'export.ExportExcel/user')->option(['real_name' => '门店用户导出']);
        //错误记录导出
        Route::get('import/error/down', 'export.ExportExcel/importUserExport')->option(['real_name' => '错误记录导出']);
        //门店导入记录列表（仅本店）
        Route::get('import/list', 'export.ExportExcel/importUserList')->option(['real_name' => '门店导入记录']);

    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

    /** 培训资料中心 */
    Route::group('training/document', function () {
        Route::get('list', 'system.TrainingDocument/index')->option(['real_name' => '培训资料列表']);
        Route::get('download/:id', 'system.TrainingDocument/download')->option(['real_name' => '下载培训资料']);
    })->middleware([AuthTokenMiddleware::class, StoreCkeckRoleMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'store');

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

})->prefix('store.')->middleware([
	InstallMiddleware::class,
	AllowOriginMiddleware::class,
	StationOpenMiddleware::class
]);

