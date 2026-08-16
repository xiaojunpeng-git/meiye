<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
use app\http\middleware\InstallMiddleware;
use think\facade\Route;
use think\facade\Config;
use think\Response;

/**
 * 后台管理路由配置
 */
Route::group('adminapi', function () {

    /**
     * 无需授权的接口
     */
    Route::group(function () {
        //图形验证码
        Route::get('ajcaptcha', 'Login/ajcaptcha')->name('ajcaptcha');
        //图形验证码
        Route::post('ajcheck', 'Login/ajcheck')->name('ajcheck');
        //是否需要滑块验证接口
        Route::post('is_captcha', 'Login/getAjCaptcha')->name('getAjCaptcha');
        //用户名密码登录
        Route::post('login', 'Login/login')->name('AdminLogin')->option(['real_name' => '用户名密码登录']);
        //短信登录
        Route::post('mobile_login', 'Login/mobileLogin')->name('mobileLogin')->option(['real_name' => '短信登录']);
        //短信重置密码
        Route::post('reset_pwd', 'Login/resetPwd')->name('resetPwd')->option(['real_name' => '短信重置密码']);
        //后台登录页面数据
        Route::get('login/info', 'Login/info')->option(['real_name' => '登录信息']);
        //下载文件
        Route::get('download', 'PublicController/download')->option(['real_name' => '下载文件']);
        //获取ERP开关配置
        Route::get('erp/config', 'PublicController/getErpConfig')->option(['real_name' => '获取ERP开关配置']);
        //验证码
        Route::get('captcha_pro', 'Login/captcha')->name('')->option(['real_name' => '获取验证码']);
        //获取版权
        Route::get('copyright', 'Common/getCopyright')->option(['real_name' => '获取版权']);

        //扫码上传
        Route::post('file/scan/upload', 'PublicController/scanUpload')->name('scanUpload');

        //服务器信息
        Route::get('system/info', 'PublicController/getSystemInfo')->option(['real_name' => '路由导入']);
//        Route::get('index', 'Test/index')->option(['real_name' => '测试地址']);
//        Route::get('sms/aliyun', 'Test/aliyun')->option(['real_name' => '测试阿里云短信']);

//        Route::get('r', 'Test/rule')->option(['real_name' => '查看路由地址']);

		//代理商用户名密码登录
		Route::post('agent/login', 'v1.agent.Login/login')->name('AgentLogin')->option(['real_name' => '代理商用户名密码登录']);
		//代理商短信登录
		Route::post('agent/mobile_login', 'v1.agent.Login/mobileLogin')->name('AgentMobileLogin')->option(['real_name' => '代理商短信登录']);
		//代理商忘记密码
		Route::post('agent/reset_pwd', 'v1.agent.Login/resetPwd')->name('resetPwd')->option(['real_name' => '代理商忘记密码']);
    });

	/**
	 * 需要登录，不验证访问权限
	 */
	Route::group(function () {
		//待办事项
		Route::get('jnotice', 'Common/jnotice')->option(['real_name' => '待办事项']);
		//验证授权
		Route::get('check_auth', 'Common/check_auth')->option(['real_name' => '验证授权']);
		//申请授权
		Route::post('auth_apply', 'Common/auth_apply')->option(['real_name' => '申请授权']);
		//查询版权
		Route::get('mohe_copyright', 'Common/mohe_copyright')->option(['real_name' => '申请版权']);
		//授权信息
		Route::get('auth', 'Common/auth')->option(['real_name' => '授权信息']);
		//授权验证码
		Route::get('mohe_verify', 'Common/mohe_verify')->option(['real_name' => '授权验证码']);
		//登录
		Route::post('mohe_login', 'Common/mohe_login')->option(['real_name' => '登录']);
		//授权订单
		Route::post('mohe_order', 'Common/mohe_order')->option(['real_name' => '授权订单']);
		//获取授权订单
		Route::get('mohe_order/:orderId', 'Common/mohe_order_info')->option(['real_name' => '获取授权订单']);
		//授权支付
		Route::post('mohe_pay', 'Common/mohe_pay')->option(['real_name' => '授权支付']);
		//获取授权产品
		Route::get('mohe_product', 'Common/mohe_product')->option(['real_name' => '获取授权产品']);
		//保存版权
		Route::post('copyright', 'Common/saveCopyright')->option(['real_name' => '保存版权']);
		//获取左侧菜单
		Route::get('menus', 'v1.system.SystemMenus/menus')->option(['real_name' => '左侧菜单']);
		//获取搜索菜单列表
		Route::get('menusList', 'Common/menusList')->option(['real_name' => '搜索菜单列表']);
		//获取logo
		Route::get('logo', 'Common/getLogo')->option(['real_name' => '获取logo']);
		//管理员退出登录
		Route::get('setting/admin/logout', 'v1.system.admin.SystemAdmin/logout')->name('SystemAdminLogout')->option(['real_name' => '退出登录']);
		//获取当前管理员信息
		Route::get('setting/info', 'v1.system.admin.SystemAdmin/info')->name('SystemAdminInfo')->option(['real_name' => '获取当前管理员信息']);
		//修改当前管理员信息
		Route::put('setting/update_admin', 'v1.system.admin.SystemAdmin/update_admin')->name('SystemAdminUpdateAdmin')->option(['real_name' => '修改当前管理员信息']);

	})->middleware(\app\http\middleware\admin\AdminAuthTokenMiddleware::class)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 文件下载、导出相关路由
     */
    Route::group(function () {
        //下载备份记录表
        Route::get('backup/download', 'v1.system.SystemDatabackup/downloadFile')->option(['real_name' => '下载表备份记录']);
        //首页统计数据
        Route::get('home/header', 'Common/homeStatics')->option(['real_name' => '首页统计数据']);
        //首页订单图表
        Route::get('home/order', 'Common/orderChart')->option(['real_name' => '首页订单图表']);
        //首页用户图表
        Route::get('home/user', 'Common/userChart')->option(['real_name' => '首页用户图表']);
        //首页交易额排行
        Route::get('home/rank', 'Common/purchaseRanking')->option(['real_name' => '首页交易额排行']);
		//获取后台菜单快捷入口
		Route::get('get_fast_menus', 'v1.system.SystemCustomMenus/getFastMenus')->option(['real_name' => '获取后台菜单快捷入口']);
		//设置后台菜单快捷入口
		Route::post('set_fast_menus', 'v1.system.SystemCustomMenus/setFastMenus')->option(['real_name' => '设置后台菜单快捷入口']);
        //获取城市数据
        Route::get('city', 'Common/city')->option(['real_name' => '获取城市数据']);
		//解析（导入地图城市地址）
		Route::get('resolve/city', 'Common/resolveCityList')->option(['real_name' => '解析导入地图城市地址']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 企业微信相关
     */
    Route::group('work', function () {

        //获取企业微信发送朋友圈任务列表
        Route::get('moment', 'Moment/index')->option(['real_name' => '获取企业微信发送朋友圈任务列表']);
        //保存企业微信发送朋友圈任务
        Route::post('moment', 'Moment/save')->option(['real_name' => '保存企业微信发送朋友圈任务']);
        //企业微信发送朋友圈详情
        Route::get('moment/:id', 'Moment/read')->option(['real_name' => '企业微信发送朋友圈详情']);
        //删除企业微信发送朋友圈
        Route::delete('moment/:id', 'Moment/delete')->option(['real_name' => '删除企业微信发送朋友圈']);
        //获取企业微信发送过朋友圈成员列表
        Route::get('moment_list', 'Moment/sendResultList')->option(['real_name' => '获取企业微信发送过朋友圈成员列表']);
        //企业微信客户列表
        Route::get('client', 'Client/index')->option(['real_name' => '企业微信客户列表']);
        //修改企业微信客户
        Route::put('client/:id', 'Client/update')->option(['real_name' => '修改企业微信客户']);
        //同步企业微信客户
        Route::get('client/synch', 'Client/synch')->option(['real_name' => '同步企业微信客户']);
        //批量设置企业微信客户标签
        Route::post('client/batchLabel', 'Client/batchLabel')->option(['real_name' => '批量设置企业微信客户标签']);
        //查找成员下的跟踪客户
        Route::post('client/count', 'Client/count')->option(['real_name' => '查找成员下的跟踪客户']);
        //企业微信组织架构
        Route::get('tree', 'Member/getMemberTree')->option(['real_name' => '企业微信组织架构']);
        //企业微信客户标签
        Route::get('label', 'Member/getUserLabel')->option(['real_name' => '企业微信客户标签']);
        //获取企业微信员工列表
        Route::get('member', 'Member/index')->option(['real_name' => '获取企业微信员工列表']);
        //获取企业微信部门
        Route::get('department', 'Department/index')->option(['real_name' => '获取企业微信部门']);
        //获取企业微信客户群聊
        Route::get('group_chat', 'GroupChat/index')->option(['real_name' => '获取企业微信客户群聊']);
        //获取企业微信客户群聊成员
        Route::get('group_chat/member', 'GroupChat/chatMember')->option(['real_name' => '获取企业微信客户群聊成员']);
        //客户群统计
        Route::get('group_chat/statistics', 'GroupChat/chatStatistics')->option(['real_name' => '客户群统计']);
        //客户群统计列表数据
        Route::get('group_chat/statisticsList', 'GroupChat/chatStatisticsList')->option(['real_name' => '客户群统计列表数据']);
        //同步客户群
        Route::post('group_chat/synch', 'GroupChat/synchGroupChat')->option(['real_name' => '同步客户群']);
        //同步企业微信成员
        Route::post('synchMember', 'Member/synchMember')->option(['real_name' => '同步企业微信成员']);
        //获取群发成员列表
        Route::get('group_template/memberList/:id', 'GroupTemplate/memberList')->option(['real_name' => '获取群发成员列表']);
        //获取群发客户列表
        Route::get('group_template/clientList/:id', 'GroupTemplate/clientList')->option(['real_name' => '获取群发客户列表']);
        //获取群发群列表
        Route::get('group_template_chat/groupChatList/:id', 'GroupTemplate/groupChatList')->option(['real_name' => '获取群发群列表']);
        //获取群发群主列表
        Route::get('group_template_chat/groupChatOwnerList/:id', 'GroupTemplate/groupChatOwnerList')->option(['real_name' => '获取群发群主列表']);
        //获取群发群主下的群列表
        Route::get('group_template_chat/getOwnerChatList', 'GroupTemplate/getOwnerChatList')->option(['real_name' => '获取群发群主下的群列表']);
        //提醒发送
        Route::post('group_template/sendMessage', 'GroupTemplate/sendMessage')->option(['real_name' => '提醒发送']);
        //自动拉群配置
        Route::resource('group_chat_auth', 'GroupChatAuth')->except(['create', 'edit'])->option([
            'real_name' => [
                'index' => '获取欢自动拉群配置列表',
                'read' => '获取自动拉群配置',
                'save' => '保存自动拉群配置',
                'update' => '修改自动拉群配置',
                'delete' => '删除自动拉群配置'
            ]
        ]);
        //欢迎语
        Route::resource('welcome', 'Welcome')->except(['create', 'edit'])->option([
            'real_name' => [
                'index' => '获取欢迎语列表',
                'read' => '获取创建欢迎语',
                'save' => '保存欢迎语',
                'update' => '修改欢迎语',
                'delete' => '删除欢迎语'
            ]
        ]);

        //客户群发
        Route::resource('group_template', 'GroupTemplate')->except(['create', 'edit', 'update'])->option([
            'real_name' => [
                'index' => '获取客户群发列表',
                'read' => '获取客户群发详情',
                'save' => '保存客户群发',
                'delete' => '删除客户群发'
            ]
        ]);
        //客户群群发
        Route::resource('group_template_chat', 'GroupTemplate')->except(['create', 'edit', 'update'])->option([
            'real_name' => [
                'index' => '获取客户群群发列表',
                'read' => '获取客户群群发详情',
                'save' => '保存客户群群发',
                'delete' => '删除客户群群发'
            ]
        ]);
        //获取扫描渠道码添加的客户列表
        Route::get('channel/code/client', 'ChannelCode/getClientList')->option(['real_name' => '获取扫描渠道码添加的客户列表']);
        //批量移动分类
        Route::post('channel/code/bactch/cate', 'ChannelCode/bactchMoveCate')->option(['real_name' => '批量移动分类']);
        //渠道二维码
        Route::resource('channel/code', 'ChannelCode')->except(['create', 'edit'])->option([
            'real_name' => [
                'index' => '获取渠道二维码列表',
                'read' => '获取创建渠道二维码',
                'save' => '保存渠道二维码',
                'update' => '修改渠道二维码',
                'delete' => '删除渠道二维码'
            ]
        ]);
        //渠道二维码分类
        Route::resource('channel/cate', 'ChannelCate')->except(['read'])->option([
            'real_name' => [
                'index' => '获取渠道二维码分类列表',
                'create' => '获取创建渠道二维码分类表单',
                'save' => '保存渠道二维码分类',
                'edit' => '获取修改渠道二维码分类表单',
                'update' => '修改渠道二维码分类',
                'delete' => '删除渠道二维码分类'
            ]
        ]);
    })->prefix('admin.v1.work.')->middleware([
            \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
            \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
	])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 分销管理 相关路由
     */
    Route::group('agent', function () {

        //分销员列表
        Route::get('index', 'v1.spread.AgentManage/index')->option(['real_name' => '分销员列表']);
        //修改上级推广人
        Route::put('spread', 'v1.spread.AgentManage/editSpread')->option(['real_name' => '修改上级推广人']);
        //头部统计
        Route::get('statistics', 'v1.spread.AgentManage/get_badge')->option(['real_name' => '分销员列表头部统计']);
        //推广人列表
        Route::get('stair', 'v1.spread.AgentManage/get_stair_list')->option(['real_name' => '推广人列表']);
        //推广人头部统计
        Route::get('stair/statistics', 'v1.spread.AgentManage/get_stair_badge')->option(['real_name' => '推广人头部统计']);
        //计推广订单列表
        Route::get('stair/order', 'v1.spread.AgentManage/get_stair_order_list')->option(['real_name' => '推广订单列表']);
        //推广订单列表头部
        Route::get('stair/order/statistics', 'v1.spread.AgentManage/get_stair_order_badge')->option(['real_name' => '推广订单列表头部']);
        //清除上级推广人
        Route::put('stair/delete_spread/:uid', 'v1.spread.AgentManage/delete_spread')->option(['real_name' => '清除上级推广人']);
        //取消推广资格
        Route::put('stair/delete_system_spread/:uid', 'v1.spread.AgentManage/delete_system_spread')->option(['real_name' => '取消推广资格']);
        //查看公众号推广二维码
        Route::get('look_code', 'v1.spread.AgentManage/look_code')->option(['real_name' => '查看公众号推广二维码']);
        //查看小程序推广二维码
        Route::get('look_xcx_code', 'v1.spread.AgentManage/look_xcx_code')->option(['real_name' => '查看小程序推广二维码']);
        //查看H5推广二维码
        Route::get('look_h5_code', 'v1.spread.AgentManage/look_h5_code')->option(['real_name' => '查看H5推广二维码']);
        //积分配置编辑表单
        Route::get('config/edit_basics', 'v1.system.config.SystemConfig/edit_basics')->option(['real_name' => '积分配置编辑表单']);
        //积分配置保存数据
        Route::post('config/save_basics', 'v1.system.config.SystemConfig/save_basics')->option(['real_name' => '积分配置保存数据']);
        //分销员等级资源路由
        Route::resource('level', 'v1.spread.AgentLevel')->name('AgentLevelResource')->option(['real_name' => [
            'index' => '获取分销等级列表',
            'read' => '获取分销等级详情',
            'create' => '获取创建分销等级表单',
            'save' => '保存分销等级',
            'edit' => '获取修改分销等级表单',
            'update' => '修改分销等级',
            'delete' => '删除分销等级'
        ]]);
        //修改分销等级状态
        Route::put('level/set_status/:id/:status', 'v1.spread.AgentLevel/set_status')->name('levelSetStatus')->option(['real_name' => '修改分销等级状态']);
        //分销员等级任务资源路由
        Route::resource('level_task', 'v1.spread.AgentLevelTask')->name('AgentLevelTaskResource')->option(['real_name' => [
            'index' => '获取分销等级任务列表',
            'read' => '获取分销等级任务详情',
            'create' => '获取创建分销等级任务表单',
            'save' => '保存分销等级任务',
            'edit' => '获取修改分销等级任务表单',
            'update' => '修改分销等级任务',
            'delete' => '删除分销等级任务'
        ]]);
        //修改分销等级任务状态
        Route::put('level_task/set_status/:id/:status', 'v1.spread.AgentLevelTask/set_status')->name('levelTaskSetStatus')->option(['real_name' => '修改分销等级任务状态']);
        //获取赠送分销等级表单
        Route::get('get_level_form', 'v1.spread.AgentManage/getLevelForm')->name('getLevelForm')->option(['real_name' => '获取赠送分销等级表单']);
        //赠送分销等级
        Route::post('give_level', 'v1.spread.AgentManage/giveAgentLevel')->name('giveAgentLevel')->option(['real_name' => '赠送分销等级']);
        //获取分销说明
        Route::get('get_agent_agreement', 'v1.spread.AgentManage/getAgentAgreement')->name('getAgentAgreement')->option(['real_name' => '获取分销说明']);
        //保存分销说明
        Route::post('set_agent_agreement/:id', 'v1.spread.AgentManage/setAgentAgreement')->name('setAgentAgreement')->option(['real_name' => '保存分销说明']);

		//申请分销员
		Route::get('promoter/apply/list', 'v1.spread.PromoterApply/applyList')->name('applyList')->option(['real_name' => '分销员申请列表']);
		Route::get('promoter/apply/examine/:id/:uid/:status', 'v1.spread.PromoterApply/applyVerify')->name('applyExamine')->option(['real_name' => '分销员审核']);
		Route::delete('promoter/apply/del/:id', 'v1.spread.PromoterApply/applyDel')->name('applyDelete')->option(['real_name' => '删除分销员申请']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 应用模块 相关路由
     */
    Route::group('app', function () {
        //一键同步订阅消息
        Route::get('routine/syncSubscribe', 'v1.wechat.RoutineTemplate/syncSubscribe')->name('syncSubscribe')->option(['real_name' => '一键同步订阅消息']);
        //一键同步微信模版消息
        Route::get('wechat/syncSubscribe', 'v1.wechat.WechatTemplate/syncSubscribe')->name('syncSubscribe')->option(['real_name' => '一键同步微信模版消息']);
        //修改订阅消息状态
        Route::put('routine/set_status/:id/:status', 'v1.wechat.RoutineTemplate/set_status')->name('RoutineSetStatus')->option(['real_name' => '修改订阅消息状态']);
        //微信公众号菜单列表
        Route::get('wechat/menu', 'v1.wechat.menus/index')->option(['real_name' => '微信公众号菜单列表']);
        //保存微信公众号菜单
        Route::post('wechat/menu', 'v1.wechat.menus/save')->option(['real_name' => '保存微信公众号菜单']);
        //修改模板消息状态
        Route::put('wechat/template/set_status/:id/:status', 'v1.wechat.WechatTemplate/set_status')->name('WechatTemplateSetStatus')->option(['real_name' => '修改模板消息状态']);

        /** 公众号渠道码 */
        Route::get('wechat_qrcode/cate/list', 'v1.wechat.WechatQrcode/getCateList')->option(['real_name' => '渠道码分类列表']);
        Route::get('wechat_qrcode/cate/create/:id', 'v1.wechat.WechatQrcode/createForm')->option(['real_name' => '渠道码分类添加编辑表单']);
        Route::post('wechat_qrcode/cate/save', 'v1.wechat.WechatQrcode/saveCate')->option(['real_name' => '渠道码分类保存']);
        Route::delete('wechat_qrcode/cate/del/:id', 'v1.wechat.WechatQrcode/delCate')->option(['real_name' => '渠道码分类删除']);
        Route::post('wechat_qrcode/save/:id', 'v1.wechat.WechatQrcode/saveQrcode')->option(['real_name' => '保存渠道码']);
        Route::get('wechat_qrcode/info/:id', 'v1.wechat.WechatQrcode/qrcodeInfo')->option(['real_name' => '渠道码详情']);
        Route::get('wechat_qrcode/list', 'v1.wechat.WechatQrcode/qrcodeList')->option(['real_name' => '渠道码列表']);
        Route::delete('wechat_qrcode/del/:id', 'v1.wechat.WechatQrcode/delQrcode')->option(['real_name' => '删除渠道码']);
        Route::put('wechat_qrcode/set_status/:id/:status', 'v1.wechat.WechatQrcode/setStatus')->option(['real_name' => '切换渠道码状态']);
        Route::get('wechat_qrcode/user_list/:qid', 'v1.wechat.WechatQrcode/userList')->option(['real_name' => '渠道码用户列表']);
        Route::get('wechat_qrcode/statistic/:qid', 'v1.wechat.WechatQrcode/qrcodeStatistic')->option(['real_name' => '渠道码统计']);

        //关注回复
        Route::get('wechat/reply', 'v1.wechat.Reply/reply')->option(['real_name' => '关注回复']);
        //获取关注回复二维码
        Route::get('wechat/code_reply/:id', 'v1.wechat.Reply/code_reply')->option(['real_name' => '获取关注回复二维码']);
        //关键字回复列表
        Route::get('wechat/keyword', 'v1.wechat.Reply/index')->option(['real_name' => '关键字回复列表']);
        //关键字回复详情
        Route::get('wechat/keyword/:id', 'v1.wechat.Reply/read')->option(['real_name' => '关键字回复详情']);
        //保存关键字回复
        Route::post('wechat/keyword/:id', 'v1.wechat.Reply/save')->option(['real_name' => '保存关键字回复']);
        //删除关键字回复
        Route::delete('wechat/keyword/:id', 'v1.wechat.Reply/delete')->option(['real_name' => '删除关键字回复']);
        //修改关键字回复状态
        Route::put('wechat/keyword/set_status/:id/:status', 'v1.wechat.Reply/set_status')->option(['real_name' => '修改关键字回复状态']);
        //图文列表
        Route::get('wechat/news', 'v1.wechat.WechatNewsCategory/index')->option(['real_name' => '图文列表']);
        //图文详情
        Route::get('wechat/news/:id', 'v1.wechat.WechatNewsCategory/read')->option(['real_name' => '图文详情']);
        //保存图文
        Route::post('wechat/news', 'v1.wechat.WechatNewsCategory/save')->option(['real_name' => '保存图文']);
        //删除图文
        Route::delete('wechat/news/:id', 'v1.wechat.WechatNewsCategory/delete')->option(['real_name' => '删除图文']);
        //发送图文消息
        Route::post('wechat/push', 'v1.wechat.WechatNewsCategory/push')->option(['real_name' => '发送图文消息']);
        //客服列表
        Route::get('wechat/kefu', 'v1.message.service.StoreService/index')->option(['real_name' => '客服列表']);
        //客服登录
        Route::get('wechat/kefu/login/:id', 'v1.message.service.StoreService/keufLogin')->option(['real_name' => '客服登录']);
        //新增客服选择用户列表
        Route::get('wechat/kefu/create', 'v1.message.service.StoreService/create')->option(['real_name' => '新增客服选择用户列表']);
		//获取移动端管理角色列表
		Route::get('wechat/kefu/role_list', 'v1.message.service.StoreService/roleList')->option(['real_name' => '获取移动端管理角色列表']);
        //客服信息
        Route::get('wechat/kefu/:id', 'v1.message.service.StoreService/getInfo')->option(['real_name' => '添加客服表单']);
        //添加、编辑客服
        Route::post('wechat/kefu/:id', 'v1.message.service.StoreService/save')->option(['real_name' => '添加、编辑客服']);
        //删除客服
        Route::delete('wechat/kefu/:id', 'v1.message.service.StoreService/delete')->option(['real_name' => '删除客服']);
        //修改客服状态
        Route::put('wechat/kefu/set_status/:id/:status', 'v1.message.service.StoreService/set_status')->option(['real_name' => '修改客服状态']);
        //聊天记录
        Route::get('wechat/kefu/record/:id', 'v1.message.service.StoreService/chat_user')->option(['real_name' => '聊天记录']);
        //查看对话
        Route::get('wechat/kefu/chat_list', 'v1.message.service.StoreService/chat_list')->option(['real_name' => '查看对话']);
        //下载小程序模版页面数据
        Route::get('routine/info', 'v1.wechat.RoutineTemplate/getDownloadInfo')->option(['real_name' => '下载小程序页面数据']);
        //下载小程序模版
        Route::post('routine/download', 'v1.wechat.RoutineTemplate/downloadTemp')->option(['real_name' => '下载小程序模版']);
        //小程序订阅消息资源路由
        Route::resource('routine', 'v1.wechat.RoutineTemplate')->name('RoutineResource')->option(['real_name' => [
            'index' => '获取小程序订阅消息列表',
            'read' => '获取小程序订阅消息详情',
            'create' => '获取创建小程序订阅消息表单',
            'save' => '保存小程序订阅消息',
            'edit' => '获取修改小程序订阅消息表单',
            'update' => '修改小程序订阅消息',
            'delete' => '删除小程序订阅消息'
        ]]);
        //公众号模版消息资源路由
        Route::resource('wechat/template', 'v1.wechat.WechatTemplate')->name('WechatTemplateResource')->option(['real_name' => [
            'index' => '获取公众号模版消息列表',
            'read' => '获取公众号模版消息详情',
            'create' => '获取创建公众号模版消息表单',
            'save' => '保存公众号模版消息',
            'edit' => '获取修改公众号模版消息表单',
            'update' => '修改公众号模版消息',
            'delete' => '删除公众号模版消息'
        ]]);
        //客服话术资源路由
        Route::resource('wechat/speechcraft', 'v1.message.service.StoreServiceSpeechcraft')->option(['real_name' => [
            'index' => '获取客服话术列表',
            'read' => '获取客服话术详情',
            'create' => '获取创建客服话术表单',
            'save' => '保存客服话术',
            'edit' => '获取修改客服话术表单',
            'update' => '修改客服话术',
            'delete' => '删除客服话术'
        ]]);
        //客服话术分类资源路由
        Route::resource('wechat/speechcraftcate', 'v1.message.service.StoreServiceSpeechcraftCate')->option(['real_name' => [
            'index' => '获取客服话术分类列表',
            'read' => '获取客服话术分类详情',
            'create' => '获取创建客服话术分类表单',
            'save' => '保存客服话术分类',
            'edit' => '获取修改客服话术分类表单',
            'update' => '修改客服话术分类',
            'delete' => '删除客服话术分类'
        ]]);
        //用户反馈资源路由
        Route::resource('feedback', 'v1.message.service.StoreServiceFeedback')->only(['index', 'delete', 'update', 'edit'])->option(['real_name' => [
            'index' => '获取用户反馈列表',
            'read' => '获取用户反馈详情',
            'create' => '获取创建用户反馈表单',
            'save' => '保存用户反馈',
            'edit' => '获取修改用户反馈表单',
            'update' => '修改用户反馈',
            'delete' => '删除用户反馈'
        ]]);
        //获取微信会员卡信息
        Route::get('wechat/card', 'v1.wechat.WechatCard/info')->option(['real_name' => '获取微信会员卡信息']);
        //保存微信会员卡信息
        Route::post('wechat/card', 'v1.wechat.WechatCard/save')->option(['real_name' => '保存微信会员卡信息']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 文章管理 相关路由
     */
    Route::group('cms', function () {
        //关联商品
        Route::put('cms/relation/:id', 'v1.cms.Article/relation')->name('Relation')->option(['real_name' => '文章关联商品']);
        //取消关联
        Route::put('cms/unrelation/:id', 'v1.cms.Article/unrelation')->name('UnRelation')->option(['real_name' => '取消文章关联商品']);
        //修改状态
        Route::put('category/set_status/:id/:status', 'v1.cms.ArticleCategory/set_status')->name('CategoryStatus')->option(['real_name' => '修改文章分类状态']);
        //分类列表
        Route::get('category_list', 'v1.cms.ArticleCategory/categoryList')->name('categoryList')->option(['real_name' => '选择文章分类列表']);
        //文章分类资源路由
        Route::resource('category', 'v1.cms.ArticleCategory')->name('ArticleCategoryResource')->option(['real_name' => [
            'index' => '获取文章分类列表',
            'read' => '获取文章分类详情',
            'create' => '获取创建文章分类表单',
            'save' => '保存文章分类',
            'edit' => '获取修改文章分类表单',
            'update' => '修改文章分类',
            'delete' => '删除文章分类'
        ]]);
        //文章资源路由
        Route::resource('cms', 'v1.cms.Article')->name('ArticleResource')->option(['real_name' => [
            'index' => '获取文章列表',
            'read' => '获取文章详情',
            'create' => '获取创建文章表单',
            'save' => '保存文章',
            'edit' => '获取修改文章表单',
            'update' => '修改文章',
            'delete' => '删除文章'
        ]]);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 首页Diy 相关路由
     */
    Route::group('diy', function () {

        //DIY列表
        Route::get('get_list', 'v1.diy.Diy/getList')->option(['real_name' => 'Diy模板列表']);
        //Diy数据详情
        Route::get('get_info/:id', 'v1.diy.Diy/getInfo')->option(['real_name' => 'Diy模板数据详情']);
        //删除DIY模板
        Route::delete('del/:id', 'v1.diy.Diy/del')->option(['real_name' => '删除DIY模板']);
        //使用DIY模板
        Route::put('set_status/:id', 'v1.diy.Diy/setStatus')->option(['real_name' => '使用DIY模板']);
		//修改模版名称
		Route::post('update/name/:id', 'v1.diy.Diy/updateName')->option(['real_name' => '修改模版名称']);
        //添加DIY模板
        Route::post('save/[:id]', 'v1.diy.Diy/saveData')->option(['real_name' => '添加DIY模板']);
        //获取前端页面路径
        Route::get('get_url', 'v1.diy.Diy/getUrl')->option(['real_name' => '获取前端页面路径']);
		//获取门店列表
		Route::get('get_system_store', 'v1.diy.Diy/getSystemStore')->option(['real_name' => '获取门店列表']);
        //获取商品分类
        Route::get('get_category', 'v1.diy.Diy/getCategory')->option(['real_name' => '获取商品分类']);
        //获取商品
        Route::get('get_product', 'v1.diy.Diy/getProduct')->option(['real_name' => '获取商品列表']);
        //获取门店核销开启状态
        Route::get('get_store_status', 'v1.diy.Diy/getStoreStatus')->option(['real_name' => '获取门店核销开启状态']);
        //还原Diy默认数据
        Route::get('recovery/:id', 'v1.diy.Diy/Recovery')->option(['real_name' => '还原Diy默认数据']);
        //设置Diy默认数据
        Route::get('set_default_data/:id', 'v1.diy.Diy/setDefaultData')->option(['real_name' => '设置Diy默认数据']);
        //获取所有二级分类
        Route::get('get_by_category', 'v1.diy.Diy/getByCategory')->option(['real_name' => '获取所有二级分类']);
        //获取推荐不同类型商品
        Route::get('groom_list/:type', 'v1.diy.Diy/groom_list')->name('groomList')->option(['real_name' => '获取推荐不同类型商品']);
        //换色和分类保存
        Route::put('color_change/:status/:type', 'v1.diy.Diy/colorChange')->option(['real_name' => '换色和分类保存']);
        //换色和分类详情
        Route::get('get_color_change/:type', 'v1.diy.Diy/getColorChange')->option(['real_name' => '换色和分类详情']);
        //获取diy小程序二维码
        Route::get('get_routine_code/:id', 'v1.diy.Diy/getRoutineCode')->option(['real_name' => 'diy小程序预览码']);
        //个人中心菜单获取
        Route::get('get_member', 'v1.diy.Diy/getMember')->option(['real_name' => '个人中心详情']);
        //个人中心菜单保存
        Route::post('member_save', 'v1.diy.Diy/memberSaveData')->option(['real_name' => '个人中心保存']);
		//获取商品详情diy
		Route::get('get_product_detail', 'v1.diy.Diy/getProductDetailDiy')->option(['real_name' => '获取商品详情diy']);
		//保存商品详情diy
		Route::post('save_product_detail', 'v1.diy.Diy/saveProductDetailDiy')->option(['real_name' => '保存商品详情diy']);
		//商品分类diy
		Route::get('get_product_category', 'v1.diy.Diy/getProductCategoryDiy')->option(['real_name' => '商品分类diy']);
		//商品分类diy保存
		Route::post('save_product_category', 'v1.diy.Diy/saveProductCategoryDiy')->option(['real_name' => '商品分类diy保存']);
        //获取悬浮窗diy
        Route::get('get_suspended_window', 'v1.diy.Diy/getSuspendedDiy')->option(['real_name' => '获取悬浮窗diy']);
        //保存悬浮窗diy
        Route::post('save_suspended_window', 'v1.diy.Diy/saveSuspendedDiy')->option(['real_name' => '保存悬浮窗diy']);

        //获取页面链接分类
        Route::get('get_page_category', 'v1.diy.PageLink/getCategory')->option(['real_name' => '获取页面链接分类']);
        //获取页面链接
        Route::get('get_page_link/:cate_id', 'v1.diy.PageLink/getLinks')->option(['real_name' => '获取页面链接']);
        //保存自定义链接
        Route::post('save_link/:cate_id', 'v1.diy.PageLink/saveLink')->option(['real_name' => '保存自定义链接']);
        //删除DIY模板
        Route::delete('del_link/:id', 'v1.diy.PageLink/del')->option(['real_name' => '删除链接']);
        //开屏广告
        Route::get('open_adv/info', 'v1.diy.Diy/getOpenAdv')->option(['real_name' => '获取开屏广告']);
        Route::post('open_adv/add', 'v1.diy.Diy/openAdvAdd')->option(['real_name' => '保存开屏广告']);

        //新人专享商品diy
        Route::get('newcomer_list', 'v1.diy.Diy/newcomerList')->option(['real_name' => '保存商品详情diy']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 导出excel相关路由
     */
    Route::group('export', function () {
        //用户资金
        Route::get('userFinance', 'v1.other.export.ExportExcel/userFinance')->option(['real_name' => '用户资金导出']);
        //用户佣金
        Route::get('userCommission', 'v1.other.export.ExportExcel/userCommission')->option(['real_name' => '用户佣金导出']);
        //用户积分
        Route::get('userPoint', 'v1.other.export.ExportExcel/userPoint')->option(['real_name' => '用户积分导出']);
        //用户储值
        Route::get('userRecharge', 'v1.other.export.ExportExcel/userRecharge')->option(['real_name' => '用户储值导出']);
        //分销员推广列表
        Route::get('userAgent', 'v1.other.export.ExportExcel/userAgent')->option(['real_name' => '分销员推广列表导出']);
        //微信用户
        Route::get('wechatUser', 'v1.other.export.ExportExcel/wechatUser')->option(['real_name' => '微信用户导出']);
        //砍价商品
        Route::get('storeBargain', 'v1.other.export.ExportExcel/storeBargain')->option(['real_name' => '砍价商品导出']);
        //拼团商品
        Route::get('storeCombination', 'v1.other.export.ExportExcel/storeCombination')->option(['real_name' => '拼团商品导出']);
        //秒杀商品
        Route::get('storeSeckill', 'v1.other.export.ExportExcel/storeSeckill')->option(['real_name' => '秒杀商品导出']);
        //商铺产品导出
        Route::get('storeProduct', 'v1.other.export.ExportExcel/storeProduct')->option(['real_name' => '商铺产品导出']);
        //商品导出
        Route::get('productImport', 'v1.other.export.ExportExcel/adminProductImport')->option(['real_name' => '商品导出']);
        //订单
        Route::get('storeOrder', 'v1.other.export.ExportExcel/storeOrder')->option(['real_name' => '订单导出']);
        //核销记录导出
        Route::post('writeoff', 'v1.other.export.ExportExcel/writeoff')->option(['real_name' => '核销记录导出']);
        //门店
        Route::get('storeMerchant', 'v1.other.export.ExportExcel/storeMerchant')->option(['real_name' => '门店导出']);
        //导出会员卡
        Route::get('memberCard/:id', 'v1.other.export.ExportExcel/memberCard')->option(['real_name' => '会员卡导出']);
        //导出批量发货记录
        Route::get('batchOrderDelivery/:id/:queueType/:cacheType', 'v1.other.export.ExportExcel/batchOrderDelivery')->option(['real_name' => '批量发货记录导出']);
        //物流公司对照表导出
        Route::get('expressList', 'v1.other.export.ExportExcel/expressList')->option(['real_name' => '物流公司对照表导出']);
        //积分兑换订单导出
        Route::get('storeIntegralOrder', 'v1.other.export.ExportExcel/storeIntegralOrder')->option(['real_name' => '积分兑换订单导出']);
        //门店账单导出
        Route::get('storeFinanceRecord', 'v1.other.export.ExportExcel/financeRecord')->option(['real_name' => '门店账单导出']);
        //门店流水导出
        Route::get('storeFlowExport', 'v1.other.export.ExportExcel/flowExport')->option(['real_name' => '门店流水导出']);
        //供应商流水导出
        Route::get('supplierWaterExport', 'v1.other.export.ExportExcel/waterExport')->option(['real_name' => '供应商流水导出']);
        //供应商账单导出
        Route::get('supplierWaterRecord', 'v1.other.export.ExportExcel/waterRecord')->option(['real_name' => '供应商账单导出']);
        //商品卡号、卡密模版导出
        Route::get('storeProductCardTemplate', 'v1.other.export.ExportExcel/storeProductCardTemplate')->option(['real_name' => '商品卡号、卡密模版导出']);
        //发票导出
        Route::get('invoiceExport', 'v1.other.export.ExportExcel/invoiceExport')->option(['real_name' => '发票导出']);
        //系统表单收集数据导出
        Route::get('systemFormDataExport/:id', 'v1.other.export.ExportExcel/systemFormDataExport')->option(['real_name' => '系统表单收集数据导出']);
        //店员列表导出
        Route::get('staffListExport', 'v1.other.export.ExportExcel/staffListExport')->option(['real_name' => '店员列表导出']);
        //抽奖记录导出
        Route::get('luckRecordExport', 'v1.other.export.ExportExcel/luckLotteryRecordExport')->option(['real_name' => '抽奖记录导出']);

        //用户导出
        Route::get('user', 'v1.other.export.ExportExcel/user')->option(['real_name' => '用户导出']);
        //下载记录
        Route::get('import/list', 'v1.other.export.ExportExcel/importUserList')->option(['real_name' => '下载记录']);
        //错误记录导出
        Route::get('import/error/down', 'v1.other.export.ExportExcel/importUserExport')->option(['real_name' => '错误记录导出']);
        //记录删除
        Route::get('import/del/:id', 'v1.user.UserImport/importDelete')->option(['real_name' => '记录删除']);

		//商品入库单导出
		Route::get('productStockInOrder', 'v1.other.export.ExportExcel/productStockInOrder')->option(['real_name' => '商品入库单导出']);
		//商品出库单导出
		Route::get('productStockOutOrder', 'v1.other.export.ExportExcel/productStockOutOrder')->option(['real_name' => '商品出库单导出']);
		//商品库存盘点导出
		Route::get('productStockCount', 'v1.other.export.ExportExcel/productStockCount')->option(['real_name' => '商品库存盘点导出']);
		//商品库存明细导出
		Route::get('productStockDetail', 'v1.other.export.ExportExcel/productStockDetail')->option(['real_name' => '商品库存明细导出']);
		//商品出入库存统计导出
		Route::get('productStockOrderStatistics', 'v1.other.export.ExportExcel/productStockOrderStatistics')->option(['real_name' => '商品出入库存统计导出']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 附件相关路由
     */
    Route::group('file', function () {
        //图片附件列表
        Route::get('file', 'v1.system.attachment.SystemAttachment/index')->option(['real_name' => '图片附件列表']);
        //删除图片
        Route::post('file/delete', 'v1.system.attachment.SystemAttachment/delete')->option(['real_name' => '删除图片']);
        //移动图片分类表单
        Route::get('file/move', 'v1.system.attachment.SystemAttachment/move')->option(['real_name' => '移动图片分类表单']);
        //移动图片分类
        Route::put('file/do_move', 'v1.system.attachment.SystemAttachment/moveImageCate')->option(['real_name' => '移动图片分类']);
        //修改图片名称
        Route::put('file/update/:id', 'v1.system.attachment.SystemAttachment/update')->option(['real_name' => '修改图片名称']);
        //上传图片
        Route::post('upload/[:upload_type]', 'v1.system.attachment.SystemAttachment/upload')->option(['real_name' => '上传图片']);
        //获取上传类型
        Route::get('upload_type', 'v1.system.attachment.SystemAttachment/uploadType')->option(['real_name' => '上传类型']);
        //分片上传本地视频
        Route::post('video_upload', 'v1.system.attachment.SystemAttachment/videoUpload')->option(['real_name' => '分片上传本地视频']);
        //oss视频素材保存
        Route::post('video_attachment', 'v1.system.attachment.SystemAttachment/saveVideoAttachment')->option(['real_name' => '视频素材保存']);

        //获取扫码上传页面链接以及参数
        Route::get('scan/qrcode', 'v1.system.attachment.SystemAttachment/scanUploadQrcode')->option(['real_name' => '获取扫码上传页面链接以及参数']);
        //删除二维码
        Route::get('remove/qrcode', 'v1.system.attachment.SystemAttachment/removeUploadQrcode')->option(['real_name' => '删除二维码']);
        //获取扫码上传的图片数据
        Route::get('scan/image/list/:scan_token', 'v1.system.attachment.SystemAttachment/scanUploadImage')->option(['real_name' => '获取扫码上传的图片数据']);
        //网络图片上传
        Route::post('online/upload', 'v1.system.attachment.SystemAttachment/onlineUpload')->option(['real_name' => '网络图片上传']);

        //获取上传信息
        Route::get('get/way_data', 'v1.system.attachment.SystemAttachment/getAdminsData')->option(['real_name' => '获取上传信息']);
        //保存上传信息
        Route::get('set/way_data/:is_way', 'v1.system.attachment.SystemAttachment/setAdminsData')->option(['real_name' => '保存上传信息']);

        //附件分类管理资源路由
        Route::resource('category', 'v1.system.attachment.SystemAttachmentCategory')->option(['real_name' => [
            'index' => '获取附件分类列表',
            'read' => '获取附件分类详情',
            'create' => '获取创建附件分类表单',
            'save' => '保存附件分类',
            'edit' => '获取修改附件分类表单',
            'update' => '修改附件分类',
            'delete' => '删除附件分类'
        ]]);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 财务模块 相关路由
     */
    Route::group('finance', function () {
        //资金记录类型
        Route::get('finance/bill_type', 'v1.finance.Finance/bill_type')->option(['real_name' => '资金记录类型']);
        //资金记录列表
        Route::get('finance/list', 'v1.finance.Finance/list')->option(['real_name' => '资金记录列表']);
        //佣金记录
        Route::get('finance/commission_list', 'v1.finance.Finance/get_commission_list')->option(['real_name' => '佣金记录列表']);
        //佣金详情用户信息
        Route::get('finance/user_info/:id', 'v1.finance.Finance/user_info')->option(['real_name' => '佣金详情用户信息']);
        //个人佣金记录列表
        Route::get('finance/extract_list/:id', 'v1.finance.Finance/getUserBrokeragelist')->option(['real_name' => '个人佣金记录列表']);
        //提现申请列表
        Route::get('extract', 'v1.finance.UserExtract/index')->option(['real_name' => '提现申请列表']);
        //提现记录修改表单
        Route::get('extract/:id/edit', 'v1.finance.UserExtract/edit')->option(['real_name' => '提现记录修改表单']);
        //提现记录修改
        Route::put('extract/:id', 'v1.finance.UserExtract/update')->option(['real_name' => '提现记录修改']);
        //拒绝提现申请
        Route::put('extract/refuse/:id', 'v1.finance.UserExtract/refuse')->option(['real_name' => '拒绝提现申请']);
        //通过提现申请
        Route::put('extract/adopt/:id', 'v1.finance.UserExtract/adopt')->option(['real_name' => '通过提现申请']);
        //储值记录列表
        Route::get('recharge', 'v1.finance.UserRecharge/index')->option(['real_name' => '储值记录列表']);
        //删除储值记录
        Route::delete('recharge/:id', 'v1.finance.UserRecharge/delete')->option(['real_name' => '删除储值记录']);
        //获取用户储值数据
        Route::get('recharge/user_recharge', 'v1.finance.UserRecharge/user_recharge')->option(['real_name' => '获取用户储值数据']);
        //储值退款表单
        Route::get('recharge/:id/refund_edit', 'v1.finance.UserRecharge/refund_edit')->option(['real_name' => '储值退款表单']);
        //储值退款
        Route::put('recharge/:id', 'v1.finance.UserRecharge/refund_update')->option(['real_name' => '储值退款']);

        //用户资金流水
        Route::get('flow/get_list', 'v1.finance.CapitalFlow/getFlowList')->option(['real_name' => '资金流水']);
        Route::post('flow/set_mark/:id', 'v1.finance.CapitalFlow/setMark')->option(['real_name' => '设置备注']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 物流公司相关路由
     */
    Route::group('freight', function () {
        //修改物流公司状态
        Route::put('express/set_status/:id/:status', 'v1.other.Express/set_status')->option(['real_name' => '修改物流公司状态']);
        //同步物流公司
        Route::get('express/sync_express', 'v1.other.Express/syncExpress')->option(['real_name' => '同步物流公司']);
        //物流公司资源路由
        Route::resource('express', 'v1.other.Express')->name('ExpressResource')->option(['real_name' => [
            'index' => '获取物流公司列表',
            'read' => '获取物流公司详情',
            'create' => '获取创建物流公司表单',
            'save' => '保存物流公司',
            'edit' => '获取修改物流公司表单',
            'update' => '修改物流公司',
            'delete' => '删除物流公司'
        ]]);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 直播相关路由
     */
    Route::group('live', function () {

        //主播列表
        Route::get('anchor/list', 'v1.marketing.live.LiveAnchor/list')->option(['real_name' => '主播列表']);
        //添加修改主播表单
        Route::get('anchor/add/:id', 'v1.marketing.live.LiveAnchor/add')->option(['real_name' => '添加修改主播表单']);
        //保存主播数据
        Route::post('anchor/save', 'v1.marketing.live.LiveAnchor/save')->option(['real_name' => '保存主播数据']);
        //删除主播
        Route::delete('anchor/del/:id', 'v1.marketing.live.LiveAnchor/delete')->option(['real_name' => '删除主播']);
        //设置是否显示
        Route::get('anchor/set_show/:id/:is_show', 'v1.marketing.live.LiveAnchor/setShow')->option(['real_name' => '设置主播是否显示']);
        //同步主播
        Route::get('anchor/syncAnchor', 'v1.marketing.live.LiveAnchor/syncAnchor')->option(['real_name' => '同步主播']);
        //直播商品列表
        Route::get('goods/list', 'v1.marketing.live.LiveGoods/list')->option(['real_name' => '直播商品列表']);
        //生成直播商品
        Route::post('goods/create', 'v1.marketing.live.LiveGoods/create')->option(['real_name' => '生成直播商品']);
        //添加修改直播商品
        Route::post('goods/add', 'v1.marketing.live.LiveGoods/add')->option(['real_name' => '添加修改直播商品']);
        //直播商品详情
        Route::get('goods/detail/:id', 'v1.marketing.live.LiveGoods/detail')->option(['real_name' => '直播商品详情']);
        //直播商品重新审核
        Route::get('goods/audit/:id', 'v1.marketing.live.LiveGoods/audit')->option(['real_name' => '直播商品重新审核']);
        //直播商品撤回审核
        Route::get('goods/resestAudit/:id', 'v1.marketing.live.LiveGoods/resetAudit')->option(['real_name' => '直播商品撤回审核']);
        //删除直播商品
        Route::delete('goods/del/:id', 'v1.marketing.live.LiveGoods/delete')->option(['real_name' => '删除直播商品']);
        //设置直播商品是否显示
        Route::get('goods/set_show/:id/:is_show', 'v1.marketing.live.liveGoods/setShow')->option(['real_name' => '设置直播商品是否显示']);
        //同步直播商品状态
        Route::get('goods/syncGoods', 'v1.marketing.live.liveGoods/syncGoods')->option(['real_name' => '同步直播商品状态']);
        //直播间列表
        Route::get('room/list', 'v1.marketing.live.LiveRoom/list')->option(['real_name' => '直播间列表']);
        //直播间添加
        Route::post('room/add', 'v1.marketing.live.LiveRoom/add')->option(['real_name' => '直播间添加']);
        //直播间详情
        Route::get('room/detail/:id', 'v1.marketing.live.LiveRoom/detail')->option(['real_name' => '直播间详情']);
        //直播间添加商品
        Route::post('room/add_goods', 'v1.marketing.live.LiveRoom/addGoods')->option(['real_name' => '直播间添加商品']);
        //删除直播间
        Route::delete('room/del/:id', 'v1.marketing.live.LiveRoom/delete')->option(['real_name' => '删除直播间']);
        //设置直播间是否显示
        Route::get('room/set_show/:id/:is_show', 'v1.marketing.live.LiveRoom/setShow')->option(['real_name' => '设置直播间是否显示']);
        //同步直播间状态
        Route::get('room/syncRoom', 'v1.marketing.live.LiveRoom/syncRoom')->option(['real_name' => '同步直播间状态']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 抽奖相关路由
     */
    Route::group('lottery', function () {
        //活动使用id
        Route::get('use', 'v1.marketing.lottery.LuckLottery/getfactorUse')->option(['real_name' => '活动使用id']);
        //抽奖活动列表
        Route::get('list', 'v1.marketing.lottery.LuckLottery/index')->option(['real_name' => '抽奖活动列表']);
        //抽奖活动详情
        Route::get('detail/:id', 'v1.marketing.lottery.LuckLottery/detail')->option(['real_name' => '抽奖活动详情']);
        //抽奖活动详情
        Route::get('factor_info/:factor', 'v1.marketing.lottery.LuckLottery/factorInfo')->option(['real_name' => '抽奖活动详情']);
        //添加抽奖活动
        Route::post('add', 'v1.marketing.lottery.LuckLottery/add')->option(['real_name' => '添加抽奖活动']);
        //修改抽奖活动数据
        Route::put('edit/:id', 'v1.marketing.lottery.LuckLottery/edit')->option(['real_name' => '修改抽奖活动数据']);
        //删除抽奖活动
        Route::delete('del/:id', 'v1.marketing.lottery.LuckLottery/delete')->option(['real_name' => '删除抽奖活动']);
        //设置抽奖活动是否显示
        Route::post('set_status/:id/:status', 'v1.marketing.lottery.LuckLottery/setStatus')->option(['real_name' => '设置抽奖活动是否显示']);
        //抽奖记录列表
        Route::get('record/list', 'v1.marketing.lottery.LuckLotteryRecord/list')->option(['real_name' => '抽奖记录列表']);
        //抽奖记录列表
        Route::get('record/list/:id', 'v1.marketing.lottery.LuckLotteryRecord/index')->option(['real_name' => '抽奖记录列表']);
        //抽奖中奖发货、备注处理
        Route::post('record/deliver', 'v1.marketing.lottery.LuckLotteryRecord/deliver')->option(['real_name' => '抽奖中奖发货、备注处理']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 优惠卷，砍价，拼团，秒杀 路由
     */
    Route::group('marketing', function () {
        //显示资源列表头部
        Route::get('coupon/header', 'v1.marketing.coupon.StoreCouponIssue/type_header')->option(['real_name' => '显示资源列表头部']);
        //已发布优惠券列表
        Route::get('coupon/released', 'v1.marketing.coupon.StoreCouponIssue/index')->option(['real_name' => '已发布优惠券列表']);
        //添加优惠券
        Route::post('coupon/save_coupon/:id', 'v1.marketing.coupon.StoreCouponIssue/saveCoupon')->option(['real_name' => '添加优惠券']);
        //优惠券审核表单
        Route::get('coupon/verify/form/:id', 'v1.marketing.coupon.StoreCouponIssue/verifyForm')->option(['real_name' => '内容审核']);
        //审核表单
        Route::post('coupon/set_verify/:id', 'v1.marketing.coupon.StoreCouponIssue/setVerify')->option(['real_name' => '审核']);
        //强制下架表单
        Route::get('coupon/take_down/form/:id', 'v1.marketing.coupon.StoreCouponIssue/takeDownForm')->option(['real_name' => '强制下架']);
        //修改优惠券状态
        Route::get('coupon/status/:id/:status', 'v1.marketing.coupon.StoreCouponIssue/status')->option(['real_name' => '修改优惠券状态']);
        //一键复制优惠券
        Route::get('coupon/copy/:id', 'v1.marketing.coupon.StoreCouponIssue/copy')->option(['real_name' => '一键复制优惠券']);
        //发送优惠券列表
        Route::get('coupon/grant', 'v1.marketing.coupon.StoreCouponIssue/index')->option(['real_name' => '发送优惠券列表']);
        //已发布优惠券删除
        Route::delete('coupon/released/:id', 'v1.marketing.coupon.StoreCouponIssue/delete')->option(['real_name' => '已发布优惠券删除']);
        //已发布优惠券修改状态表单
        Route::get('coupon/released/:id/status', 'v1.marketing.coupon.StoreCouponIssue/edit')->option(['real_name' => '已发布优惠券修改状态表单']);
        //已发布优惠券修改状态
        Route::put('coupon/released/status/:id', 'v1.marketing.coupon.StoreCouponIssue/status')->option(['real_name' => '已发布优惠券修改状态']);
        //已发布优惠券领取记录
        Route::get('coupon/released/issue_log/:id', 'v1.marketing.coupon.StoreCouponIssue/issue_log')->option(['real_name' => '已发布优惠券领取记录']);
        //会员领取记录
        Route::get('coupon/user', 'v1.marketing.coupon.StoreCouponUser/index')->option(['real_name' => '会员领取记录']);
        //发送优惠券
        Route::post('coupon/user/grant', 'v1.marketing.coupon.StoreCouponUser/grant')->option(['real_name' => '发送优惠券']);
        //砍价商品列表
        Route::get('bargain', 'v1.marketing.bargain.StoreBargain/index')->option(['real_name' => '砍价商品列表']);
        //砍价商品详情
        Route::get('bargain/:id', 'v1.marketing.bargain.StoreBargain/read')->option(['real_name' => '砍价商品详情']);
        //新增或编辑砍价商品
        Route::post('bargain/:id', 'v1.marketing.bargain.StoreBargain/save')->option(['real_name' => '新增或编辑砍价商品']);
        //删除砍价商品
        Route::delete('bargain/:id', 'v1.marketing.bargain.StoreBargain/delete')->option(['real_name' => '删除砍价商品']);
        //修改砍价商品状态
        Route::put('bargain/set_status/:id/:status', 'v1.marketing.bargain.StoreBargain/set_status')->option(['real_name' => '修改砍价商品状态']);
        //参与砍价列表
        Route::get('bargain_list', 'v1.marketing.bargain.StoreBargain/bargainList')->option(['real_name' => '参与砍价列表']);
        //砍价人列表
        Route::get('bargain_list_info/:id', 'v1.marketing.bargain.StoreBargain/bargainListInfo')->option(['real_name' => '砍价人列表']);
        //砍价统计
        Route::get('bargain/statistics/head/:id', 'v1.marketing.bargain.StoreBargain/bargainStatistics')->option(['real_name' => '砍价统计']);
        //砍价统计列表
        Route::get('bargain/statistics/list/:id', 'v1.marketing.bargain.StoreBargain/bargainStatisticsList')->option(['real_name' => '砍价统计列表']);
        //砍价统计订单
        Route::get('bargain/statistics/order/:id', 'v1.marketing.bargain.StoreBargain/bargainStatisticsOrder')->option(['real_name' => '砍价统计订单']);

        //拼团商品列表
        Route::get('combination', 'v1.marketing.combination.StoreCombination/index')->option(['real_name' => '拼团商品列表']);
        //拼团商品统计
        Route::get('combination/statistics', 'v1.marketing.combination.StoreCombination/statistics')->option(['real_name' => '拼团商品统计']);
        //拼团商品详情
        Route::get('combination/:id', 'v1.marketing.combination.StoreCombination/read')->option(['real_name' => '拼团商品详情']);
        //新增或编辑拼团商品
        Route::post('combination/:id', 'v1.marketing.combination.StoreCombination/save')->option(['real_name' => '新增或编辑拼团商品']);
        //删除拼团商品
        Route::delete('combination/:id', 'v1.marketing.combination.StoreCombination/delete')->option(['real_name' => '删除拼团商品']);
        //修改拼团商品状态
        Route::put('combination/set_status/:id/:status', 'v1.marketing.combination.StoreCombination/set_status')->option(['real_name' => '修改拼团商品状态']);
        //参与拼团列表
        Route::get('combination/combine/list', 'v1.marketing.combination.StoreCombination/combine_list')->option(['real_name' => '参与拼团列表']);
        //拼团人列表
        Route::get('combination/order_pink/:id', 'v1.marketing.combination.StoreCombination/order_pink')->option(['real_name' => '拼团人列表']);
        //拼团统计
        Route::get('combination/statistics/head/:id', 'v1.marketing.combination.StoreCombination/combinationStatistics')->option(['real_name' => '拼团统计']);
        //拼团统计列表
        Route::get('combination/statistics/list/:id', 'v1.marketing.combination.StoreCombination/combinationStatisticsList')->option(['real_name' => '拼团统计列表']);
        //拼团统计订单
        Route::get('combination/statistics/order/:id', 'v1.marketing.combination.StoreCombination/combinationStatisticsOrder')->option(['real_name' => '拼团统计订单']);

        //秒杀时间段列表
        Route::get('seckill/time', 'v1.marketing.seckill.StoreSeckillTime/index')->option(['real_name' => '秒杀时间段列表']);
        //秒杀时间段新增、编辑表单
        Route::get('seckill/time/create/:id', 'v1.marketing.seckill.StoreSeckillTime/create')->option(['real_name' => '秒杀时间段新增、编辑表单']);
        //秒杀时间段保存
        Route::post('seckill/time/:id', 'v1.marketing.seckill.StoreSeckillTime/save')->option(['real_name' => '秒杀时间段保存']);
        //删除秒杀时间段
        Route::delete('seckill/time/:id', 'v1.marketing.seckill.StoreSeckillTime/delete')->option(['real_name' => '删除秒杀时间段']);
        //秒杀时间段修改状态
        Route::put('seckill/time/set_status/:id/:status', 'v1.marketing.seckill.StoreSeckillTime/set_status')->option(['real_name' => '秒杀时间段修改状态']);
        //秒杀时间段列表
        Route::get('seckill/time_list', 'v1.marketing.seckill.StoreSeckillTime/time_list')->option(['real_name' => '秒杀时间段列表']);

        //秒杀活动列表
        Route::get('seckill', 'v1.marketing.seckill.StoreActivitySeckill/index')->option(['real_name' => '秒杀活动列表']);
        //秒杀商品列表
        Route::get('seckill/product', 'v1.marketing.seckill.StoreSeckill/index')->option(['real_name' => '秒杀商品列表']);

        //秒杀活动详情
        Route::get('seckill/:id', 'v1.marketing.seckill.StoreActivitySeckill/read')->option(['real_name' => '秒杀活动详情']);
        //新增或编辑秒杀活动
        Route::post('seckill/:id', 'v1.marketing.seckill.StoreActivitySeckill/save')->option(['real_name' => '新增或编辑秒杀活动']);
        //删除秒杀活动
        Route::delete('seckill/:id', 'v1.marketing.seckill.StoreActivitySeckill/delete')->option(['real_name' => '删除秒杀活动']);
        //修改秒杀活动状态
        Route::put('seckill/set_status/:id/:status', 'v1.marketing.seckill.StoreActivitySeckill/set_status')->option(['real_name' => '修改秒杀活动状态']);

        //秒杀商品详情
        Route::get('seckill/product/:id', 'v1.marketing.seckill.StoreSeckill/read')->option(['real_name' => '秒杀商品详情']);
        //新增或编辑秒杀商品
        Route::post('seckill/product/:id', 'v1.marketing.seckill.StoreSeckill/save')->option(['real_name' => '新增或编辑秒杀商品']);
        //删除秒杀商品
        Route::delete('seckill/product/:id', 'v1.marketing.seckill.StoreSeckill/delete')->option(['real_name' => '删除秒杀商品']);
        //修改秒杀商品状态
        Route::put('seckill/product/set_status/:id/:status', 'v1.marketing.seckill.StoreSeckill/set_status')->option(['real_name' => '修改秒杀商品状态']);
        //秒杀商品统计
        Route::get('seckill/statistics/head/:id', 'v1.marketing.seckill.StoreSeckill/seckillStatistics')->option(['real_name' => '秒杀统计']);
        //秒杀商品参与人
        Route::get('seckill/statistics/people/:id','v1.marketing.seckill.StoreSeckill/seckillPeople')->option(['real_name' => '秒杀活动参与人']);
        //秒杀商品订单
        Route::get('seckill/statistics/order/:id','v1.marketing.seckill.StoreSeckill/seckillOrder')->option(['real_name' => '秒杀活动订单']);

		//积分分类
		//积分分类列表
		Route::get('integral/category', 'v1.marketing.integral.StoreIntegralCategory/index')->option(['real_name' => '积分商品分类列表']);
		//积分分类新增表单
		Route::get('integral/category/create', 'v1.marketing.integral.StoreIntegralCategory/create')->option(['real_name' => '积分商品分类新增表单']);
		//积分分类新增
		Route::post('integral/category', 'v1.marketing.integral.StoreIntegralCategory/save')->option(['real_name' => '积分商品分类新增']);
		//积分分类编辑表单
		Route::get('integral/category/:id', 'v1.marketing.integral.StoreIntegralCategory/edit')->option(['real_name' => '积分商品分类编辑表单']);
		//积分分类编辑
		Route::post('integral/category/:id', 'v1.marketing.integral.StoreIntegralCategory/update')->option(['real_name' => '积分商品分类编辑']);
		//删除积分分类
		Route::delete('integral/category/:id', 'v1.marketing.integral.StoreIntegralCategory/delete')->option(['real_name' => '删除积分商品分类']);
		//积分分类修改状态
		Route::put('integral/category/set_show/:id/:is_show', 'v1.marketing.integral.StoreIntegralCategory/set_show')->option(['real_name' => '积分商品分类修改状态']);

        //积分日志列表
        Route::get('integral', 'v1.marketing.integral.UserPoint/index')->option(['real_name' => '积分日志列表']);
        //积分日志头部数据
        Route::get('integral/statistics', 'v1.marketing.integral.UserPoint/integral_statistics')->option(['real_name' => '积分日志头部数据']);
        //积分配置编辑表单
        Route::get('integral_config/edit_basics', 'v1.system.config.SystemConfig/edit_basics')->option(['real_name' => '积分配置编辑表单']);
        //积分配置保存数据
        Route::post('integral_config/save_basics', 'v1.system.config.SystemConfig/save_basics')->option(['real_name' => '积分配置保存数据']);
        //积分商品列表
        Route::get('integral_product', 'v1.marketing.integral.StoreIntegral/index')->option(['real_name' => '积分商品列表']);
        //积分商品批量保存
        Route::post('integral/batch', 'v1.marketing.integral.StoreIntegral/batch_add')->option(['real_name' => '积分商品批量保存']);
        //积分商品新增或编辑
        Route::post('integral/:id', 'v1.marketing.integral.StoreIntegral/save')->option(['real_name' => '积分商品新增或编辑']);
        //积分商品详情
        Route::get('integral/:id', 'v1.marketing.integral.StoreIntegral/read')->option(['real_name' => '积分商品详情']);
        //修改积分商品状态
        Route::put('integral/set_show/:id/:is_show', 'v1.marketing.integral.StoreIntegral/set_show')->option(['real_name' => '修改积分商品状态']);
        //积分商品删除
        Route::delete('integral/:id', 'v1.marketing.integral.StoreIntegral/delete')->option(['real_name' => '积分商品删除']);
        //积分订单列表获取配送员
        Route::get('integral/order/delivery/list', 'v1.order.DeliveryService/get_delivery_list')->option(['real_name' => '积分订单列表获取配送员']);
        //积分记录
        Route::get('point_record', 'v1.marketing.integral.UserPoint/pointRecord')->option(['read_name' => '积分记录列表']);
        Route::post('point_record/remark/:id', 'v1.marketing.integral.UserPoint/pointRecordRemark')->option(['read_name' => '积分记录列表备注']);
        Route::get('point/get_basic', 'v1.marketing.integral.UserPoint/getBasic')->option(['read_name' => '积分统计基本信息']);
        Route::get('point/get_trend', 'v1.marketing.integral.UserPoint/getTrend')->option(['read_name' => '积分统计趋势图']);
        Route::get('point/get_channel', 'v1.marketing.integral.UserPoint/getChannel')->option(['read_name' => '积分来源统计']);
        Route::get('point/get_type', 'v1.marketing.integral.UserPoint/getType')->option(['read_name' => '积分消耗统计']);


        //添加或修改优惠套餐商品
        Route::post('discounts/save', 'v1.marketing.discounts.StoreDiscounts/save')->option(['real_name' => '添加或修改优惠套餐商品']);
        //优惠套餐列表
        Route::get('discounts/list', 'v1.marketing.discounts.StoreDiscounts/getList')->option(['real_name' => '优惠套餐列表']);
        //优惠套餐详情
        Route::get('discounts/info/:id', 'v1.marketing.discounts.StoreDiscounts/getInfo')->option(['real_name' => '优惠套餐详情']);
        //优惠套餐上下架
        Route::get('discounts/set_status/:id/:status', 'v1.marketing.discounts.StoreDiscounts/setStatus')->option(['real_name' => '优惠套餐上下架']);
        //优惠套餐删除
        Route::delete('discounts/del/:id', 'v1.marketing.discounts.StoreDiscounts/del')->option(['real_name' => '优惠套餐删除']);

        //促销活动列表
        Route::get('promotions/list/:type', 'v1.marketing.promotions.StorePromotions/index')->option(['real_name' => '促销活动列表']);
        //促销活动详情
        Route::get('promotions/info/:id', 'v1.marketing.promotions.StorePromotions/getInfo')->option(['real_name' => '促销活动详情']);
        //添加或修改折扣活动
        Route::post('promotions/save_discount/:type/:id', 'v1.marketing.promotions.StorePromotions/saveDiscount')->option(['real_name' => '添加或修改折扣活动']);
        //添加或修改满减活动
        Route::post('promotions/save/:type/:id', 'v1.marketing.promotions.StorePromotions/save')->option(['real_name' => '添加或修改满减活动']);
        //促销活动上下架
        Route::get('promotions/set_status/:id/:status', 'v1.marketing.promotions.StorePromotions/setStatus')->option(['real_name' => '促销活动上下架']);
        //促销活动删除
        Route::delete('promotions/del/:id', 'v1.marketing.promotions.StorePromotions/delete')->option(['real_name' => '促销活动删除']);


        //短视频
        Route::get('video/index', 'v1.marketing.video.Video/index')->option(['real_name' => '短视频列表']);
        //短视频信息
        Route::get('video/info/:id', 'v1.marketing.video.Video/info')->option(['real_name' => '短视频信息']);
        //短视频保存
        Route::post('video/save/:id', 'v1.marketing.video.Video/save')->option(['real_name' => '短视频保存']);
        //短视频上下架
        Route::get('video/set_status/:id/:status', 'v1.marketing.video.Video/set_show')->option(['real_name' => '短视频上下架']);
        //短视频推荐
        Route::get('video/set_recommend/:id/:recommend', 'v1.marketing.video.Video/recommend')->option(['real_name' => '短视频推荐']);
        //短视频审核
        Route::get('video/verify/:id/:verify', 'v1.marketing.video.Video/verify')->option(['real_name' => '短视频审核']);
        //短视频强制下架
        Route::get('video/take_down/:id', 'v1.marketing.video.Video/takeDown')->option(['real_name' => '短视频强制下架']);
        //短视频删除
        Route::delete('video/del/:id', 'v1.marketing.video.Video/delete')->option(['real_name' => '短视频删除']);

        //短视频评论
        Route::get('video/comment', 'v1.marketing.video.VideoComment/index')->option(['real_name' => '短视频评论列表']);
        //短视频评论回复列表
        Route::get('video/comment/reply/:id', 'v1.marketing.video.VideoComment/getCommentReply')->option(['real_name' => '短视频评论回复列表']);
        //短视频评论回复
        Route::post('video/comment/reply/:id', 'v1.marketing.video.VideoComment/setReply')->option(['real_name' => '短视频评论回复']);
        //短视频评论获取管理员回复
        Route::get('video/comment/get_reply/:id', 'v1.marketing.video.VideoComment/getReply')->option(['real_name' => '短视频评论获取管理员回复']);
        //短视频评论删除
        Route::delete('video/comment/:id', 'v1.marketing.video.VideoComment/delete')->option(['real_name' => '短视频评论列表']);
        //短视频自评表单
        Route::get('video/comment/fictitious/:video_id', 'v1.marketing.video.VideoComment/fictitiousComment')->option(['real_name' => '短视频自评表单']);
        //短视频保存自评
        Route::post('video/comment/save_fictitious', 'v1.marketing.video.VideoComment/saveFictitiousComment')->option(['real_name' => '短视频保存自评']);

        //活动边框列表
        Route::get('activity_frame/list', 'v1.marketing.activityFrame.ActivityFrame/index')->option(['real_name' => '活动边框列表']);
        //活动边框详情
        Route::get('activity_frame/info/:id', 'v1.marketing.activityFrame.ActivityFrame/getInfo')->option(['real_name' => '活动边框详情']);
        //添加或修改活动边框
        Route::post('activity_frame/save/:id', 'v1.marketing.activityFrame.ActivityFrame/save')->option(['real_name' => '添加或修改活动边框']);
        //活动边框上下架
        Route::get('activity_frame/set_status/:id/:status', 'v1.marketing.activityFrame.ActivityFrame/setStatus')->option(['real_name' => '活动边框上下架']);
        //活动边框删除
        Route::delete('activity_frame/del/:id', 'v1.marketing.activityFrame.ActivityFrame/delete')->option(['real_name' => '活动边框删除']);

        //活动背景列表
        Route::get('activity_background/list', 'v1.marketing.activityBackground.ActivityBackground/index')->option(['real_name' => '活动背景列表']);
        //活动背景详情
        Route::get('activity_background/info/:id', 'v1.marketing.activityBackground.ActivityBackground/getInfo')->option(['real_name' => '活动背景详情']);
        //添加或修改活动背景
        Route::post('activity_background/save/:id', 'v1.marketing.activityBackground.ActivityBackground/save')->option(['real_name' => '添加或修改活动背景']);
        //活动背景上下架
        Route::get('activity_background/set_status/:id/:status', 'v1.marketing.activityBackground.ActivityBackground/setStatus')->option(['real_name' => '活动背景上下架']);
        //活动背景删除
        Route::delete('activity_background/del/:id', 'v1.marketing.activityBackground.ActivityBackground/delete')->option(['real_name' => '活动背景删除']);

        //储值档位赠送配置
        Route::get('recharge/gift_config/:id', 'v1.marketing.RechargeGift/read')->option(['real_name' => '储值档位赠送配置详情']);
        Route::post('recharge/gift_config/:id', 'v1.marketing.RechargeGift/save')->option(['real_name' => '储值档位赠送配置保存']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');


	/**
	 * 区域代理路由
	 */
	Route::group('region', function () {

		//区域代理商概览统计数据
		Route::get('home/header', 'v1.agent.Common/homeStatics')->option(['real_name' => '区域代理商概览统计数据']);
		//区域代理商销售额趋势统计
		Route::get('home/order', 'v1.agent.Common/orderChart')->option(['real_name' => '区域代理商销售额趋势统计']);
		//区域代理商门店交易排行、占比统计
		Route::get('home/store', 'v1.agent.Common/storeChart')->option(['real_name' => '区域代理商门店交易排行、占比统计']);

		//区域代理商所有门店
		Route::get('store/list', 'v1.agent.Common/storeList')->option(['real_name' => '区域代理商所有门店']);
		//所有区域代理商列表
		Route::get('agent/all', 'v1.agent.SystemRegionAgent/allAgent')->option(['real_name' => '所有区域代理商列表']);
		//区域代理商cascader列表
		Route::get('agent/cascader_list/[:type]', 'v1.agent.SystemRegionAgent/cascader_list')->option(['real_name' => '区域代理商cascader列表']);
		//区域代理商列表
		Route::get('agent/list', 'v1.agent.SystemRegionAgent/index')->option(['real_name' => '区域代理商列表']);
		//管理人员管辖门店（静态路由，避免 :id 路由未生效时 404）
		Route::get('agent/manage_stores', 'v1.agent.SystemRegionAgent/manageStoresInfo')->option(['real_name' => '管理人员管辖门店']);
		Route::post('agent/manage_stores', 'v1.agent.SystemRegionAgent/saveManageStores')->option(['real_name' => '保存管理人员管辖门店']);
		//获取已经添加所选城市数据
		Route::get('agent/city', 'v1.agent.SystemRegionAgent/getRegionCity')->option(['real_name' => '区域代理商区域详情']);
		//区域代理商详情
		Route::get('agent/info/:id', 'v1.agent.SystemRegionAgent/info')->option(['real_name' => '区域代理商详情']);
		//区域代理商保存
		Route::post('agent/:id', 'v1.agent.SystemRegionAgent/save')->option(['real_name' => '区域代理商保存']);
		//删除区域代理商
		Route::delete('agent/:id', 'v1.agent.SystemRegionAgent/delete')->option(['real_name' => '删除区域代理商']);
		//删除某区域内某些代理商
		Route::delete('agent/del_store/:id', 'v1.agent.SystemRegionAgent/delStore')->option(['real_name' => '删除某区域内某些代理商']);
		//兼容旧路径
		Route::get('agent/manage_stores/:id', 'v1.agent.SystemRegionAgent/manageStoresInfo')->option(['real_name' => '管理人员管辖门店']);
		Route::post('agent/manage_stores/:id', 'v1.agent.SystemRegionAgent/saveManageStores')->option(['real_name' => '保存管理人员管辖门店']);
		//区域代理商修改隔离状态
		Route::put('agent/set_alone/:id/:is_alone', 'v1.agent.SystemRegionAgent/setAlone')->option(['real_name' => '区域代理商修改隔离状态']);
		//代理商快捷登录
		Route::get('agent/login/:id', 'v1.agent.SystemRegionAgent/agentLogin')->option(['real_name' => '代理商快捷登录']);

		//区域架构列表（左侧树）
		Route::get('manage/tree', 'v1.store.SystemRegionManage/tree')->option(['real_name' => '区域架构完整树']);
		Route::get('manage/counts', 'v1.store.SystemRegionManage/counts')->option(['real_name' => '区域架构数量统计']);
		Route::get('manage/list', 'v1.store.SystemRegionManage/index')->option(['real_name' => '区域架构列表']);
		Route::get('manage/all', 'v1.store.SystemRegionManage/all')->option(['real_name' => '全部区域架构']);
		Route::get('manage/cascader_list', 'v1.store.SystemRegionManage/cascader_list')->option(['real_name' => '区域架构级联']);
		Route::get('manage/info/:id', 'v1.store.SystemRegionManage/info')->option(['real_name' => '区域架构详情']);
		Route::post('manage/:id', 'v1.store.SystemRegionManage/save')->option(['real_name' => '区域架构保存']);
		Route::delete('manage/:id', 'v1.store.SystemRegionManage/delete')->option(['real_name' => '区域架构删除']);
		Route::get('manage/agent_ids/:id', 'v1.store.SystemRegionManage/agent_ids')->option(['real_name' => '区域架构关联代理商']);

		
		// 组织架构（新）— 静态路径必须写在 organization/:id 之前，避免 migrate/bind_store 被当成 id
		Route::get('organization/tree', 'v1.organization.Organization/tree')->option(['real_name' => '组织架构树']);
		Route::get('organization/counts', 'v1.organization.Organization/counts')->option(['real_name' => '组织架构数量']);
		Route::get('organization/overview', 'v1.organization.Organization/overview')->option(['real_name' => '组织权限概况']);
		Route::get('organization/stores', 'v1.organization.Organization/stores')->option(['real_name' => '组织工作台门店分页']);
		Route::get('organization/employees', 'v1.organization.Organization/employees')->option(['real_name' => '组织工作台人员分页']);
		Route::get('organization/leader_candidates', 'v1.organization.Organization/leader_candidates')->option(['real_name' => '组织负责人候选分页']);
		Route::get('organization/change_log', 'v1.organization.Organization/change_log')->option(['real_name' => '组织架构操作记录']);
		Route::get('organization/source_status', 'v1.organization.Organization/source_status')->option(['real_name' => '组织数据源状态']);
		Route::get('organization/migrate_readiness', 'v1.organization.Organization/migrate_readiness')->option(['real_name' => '组织迁移就绪检查']);
		Route::get('organization/write_status', 'v1.organization.Organization/write_status')->option(['real_name' => '组织工作台写状态']);
		Route::get('organization/org_employees', 'v1.organization.Organization/org_employees')->option(['real_name' => '组织直属人员列表']);
		Route::post('organization/org_employees', 'v1.organization.Organization/org_employees_save')->option(['real_name' => '组织直属人员保存']);
		Route::delete('organization/org_employees/:id', 'v1.organization.Organization/org_employees_delete')->option(['real_name' => '组织直属人员删除']);
		Route::post('organization/:orgId/org_employees/:id/remove_completely', 'v1.organization.Organization/org_employees_remove_completely')->option(['real_name' => '完整移除组织人员']);
		Route::post('organization/employees/:id/leave', 'v1.organization.Organization/employee_leave')->option(['real_name' => '员工全局离职']);
		Route::post('organization/employees/:id/archive_delete', 'v1.organization.Organization/employee_archive_delete')->option(['real_name' => '软删除人员档案']);
		Route::get('organization/transfer_applies', 'v1.organization.Organization/transfer_applies')->option(['real_name' => '调店申请列表']);
		Route::post('organization/transfer_applies', 'v1.organization.Organization/transfer_apply_create')->option(['real_name' => '总部发起调店申请']);
		Route::post('organization/transfer_applies/:id/approve', 'v1.organization.Organization/transfer_apply_approve')->option(['real_name' => '批准调店申请']);
		Route::post('organization/transfer_applies/:id/reject', 'v1.organization.Organization/transfer_apply_reject')->option(['real_name' => '驳回调店申请']);
		// I2 授权中心 / 角色发布 / 资源选择（静态路径须在 organization/:id 前）
		Route::get('organization/employee_auth/:id', 'v1.organization.Organization/employee_auth')->option(['real_name' => '员工授权聚合读']);
		Route::get('organization/employee_auth/:id/audits', 'v1.organization.Organization/employee_auth_audits')->option(['real_name' => '员工授权审计']);
		Route::post('organization/employee_auth/:id/platform', 'v1.organization.Organization/employee_auth_platform')->option(['real_name' => '员工平台授权写']);
		Route::post('organization/employee_auth/:id/store', 'v1.organization.Organization/employee_auth_store')->option(['real_name' => '员工门店授权写']);
		Route::post('organization/employee_auth/:id/cashier', 'v1.organization.Organization/employee_auth_cashier')->option(['real_name' => '员工收银授权写']);
		Route::post('organization/employee_auth/:id/mobile', 'v1.organization.Organization/employee_auth_mobile')->option(['real_name' => '员工手机授权写']);
		Route::post('organization/employee_auth/:id/jobs', 'v1.organization.Organization/employee_auth_jobs')->option(['real_name' => '员工岗位保存']);
		Route::post('organization/employee_auth/:id/data_scope', 'v1.organization.Organization/employee_auth_data_scope')->option(['real_name' => '员工数据权限保存']);
		Route::post('organization/employee_auth/:id/entries', 'v1.organization.Organization/employee_auth_entries')->option(['real_name' => '员工渠道入口保存']);
		Route::post('organization/employee_auth/:id/tenure', 'v1.organization.Organization/employee_auth_tenure')->option(['real_name' => '单店停职复职删除']);
		Route::get('organization/job_positions', 'v1.organization.Organization/job_positions')->option(['real_name' => '岗位策略列表']);
		Route::get('organization/job_positions/menus', 'v1.organization.Organization/job_position_menus')->option(['real_name' => '岗位策略四端菜单树']);
		Route::get('organization/job_positions/:id', 'v1.organization.Organization/job_position_detail')->option(['real_name' => '岗位策略详情']);
		Route::post('organization/job_positions', 'v1.organization.Organization/job_position_save')->option(['real_name' => '岗位策略保存']);
		Route::post('organization/job_positions/publish', 'v1.organization.Organization/job_position_publish')->option(['real_name' => '岗位发布']);
		Route::post('organization/job_positions/publishes/:id/disable', 'v1.organization.Organization/job_position_publish_disable')->option(['real_name' => '岗位发布停用']);
		Route::post('organization/stores/:id/ops_status', 'v1.organization.Organization/store_ops_status')->option(['real_name' => '门店停用恢复']);
		Route::get('organization/role_templates', 'v1.organization.Organization/role_templates')->option(['real_name' => '角色模板列表']);
		Route::get('organization/role_templates/menus', 'v1.organization.Organization/role_template_menus')->option(['real_name' => '角色模板菜单树']);
		Route::get('organization/role_templates/:id', 'v1.organization.Organization/role_template_detail')->option(['real_name' => '角色模板详情']);
		Route::post('organization/role_templates/save', 'v1.organization.Organization/role_template_save')->option(['real_name' => '角色模板保存']);
		Route::post('organization/role_templates/:id/disable', 'v1.organization.Organization/role_template_disable')->option(['real_name' => '角色模板停用']);
		Route::get('organization/role_publishes', 'v1.organization.Organization/role_publishes')->option(['real_name' => '角色发布列表']);
		Route::post('organization/role_templates/publish', 'v1.organization.Organization/role_template_publish')->option(['real_name' => '角色模板发布']);
		Route::post('organization/role_publishes/:id/disable', 'v1.organization.Organization/role_publish_disable')->option(['real_name' => '角色发布停用']);
		Route::get('organization/resource_selector', 'v1.organization.Organization/resource_selector')->option(['real_name' => '组织资源选择']);
		Route::get('organization/:id/permissions', 'v1.organization.Organization/permissions')->option(['real_name' => '组织工作台权限只读']);
		Route::get('organization/:id/delete_blockers', 'v1.organization.Organization/delete_blockers')->option(['real_name' => '组织删除阻断项']);
		Route::get('organization/:id/admin_candidates', 'v1.organization.Organization/admin_candidates')->option(['real_name' => '组织权限授权候选']);
		Route::post('organization/migrate', 'v1.organization.Organization/migrate')->option(['real_name' => '组织架构数据迁移']);
		Route::post('organization/bind_store', 'v1.organization.Organization/bind_store')->option(['real_name' => '门店绑定组织']);
		Route::get('organization/admin_excludes/:orgAdminId', 'v1.organization.Organization/admin_excludes')->option(['real_name' => '管理员排除门店']);
		Route::get('organization/admin_excludes_by_agent/:legacyAgentId', 'v1.organization.Organization/admin_excludes_by_agent')->option(['real_name' => '按管理人员查排除门店']);
		Route::post('organization/admin_excludes/:orgAdminId', 'v1.organization.Organization/save_admin_excludes')->option(['real_name' => '保存管理员排除门店']);
		Route::post('organization/admin_excludes_by_agent/:legacyAgentId', 'v1.organization.Organization/save_admin_excludes_by_agent')->option(['real_name' => '按管理人员保存排除门店']);
		Route::post('organization/admin_permission/:orgAdminId', 'v1.organization.Organization/save_admin_permission')->option(['real_name' => '保存组织权限范围']);
		Route::post('organization/:id/admin_grants', 'v1.organization.Organization/admin_grants')->option(['real_name' => '授权组织权限人员']);
		Route::delete('organization/:id/admin_grants/:orgAdminId', 'v1.organization.Organization/revoke_admin_grant')->option(['real_name' => '撤销组织权限人员']);
		Route::post('organization/:id/leaders', 'v1.organization.Organization/save_leaders')->option(['real_name' => '保存组织负责人']);
		Route::post('organization/:id/ops_status', 'v1.organization.Organization/org_ops_status')->option(['real_name' => '组织停用恢复']);
		Route::post('organization/:id', 'v1.organization.Organization/save')->option(['real_name' => '组织架构保存']);
		Route::delete('organization/:id', 'v1.organization.Organization/delete')->option(['real_name' => '组织架构删除']);

	})->middleware([
		\app\http\middleware\admin\AdminAuthTokenMiddleware::class,
		\app\http\middleware\admin\AdminCkeckRoleMiddleware::class
	])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 门店相关路由
     */
    Route::group('store', function () {

        //门店首页头部统计数据
        Route::get('home/header', 'v1.store.Common/homeStatics')->option(['real_name' => '门店首页头部统计数据']);
        //门店首页营业趋势图表
        Route::get('home/operate', 'v1.store.Common/operateChart')->option(['real_name' => '门店首页营业趋势图表']);
        //门店首页交易图表
        Route::get('home/orderChart', 'v1.store.Common/orderChart')->option(['real_name' => '门店首页交易图表']);
        //门店首页店员统计
        Route::get('home/store', 'v1.store.Common/storeChart')->option(['real_name' => '门店首页门店统计']);

		//门店区域列表
        Route::get('statistics/overview', 'v1.store.BusinessDashboard/overview')->option(['real_name' => '经营看板概览']);
        Route::get('statistics/trend', 'v1.store.BusinessDashboard/trend')->option(['real_name' => '经营看板趋势']);
        Route::get('statistics/store-ranking', 'v1.store.BusinessDashboard/storeRanking')->option(['real_name' => '经营看板门店排行']);
        Route::get('statistics/reservation-detail', 'v1.store.BusinessDashboard/reservationDetail')->option(['real_name' => '经营看板预约明细']);
        Route::get('statistics/new-profile-detail', 'v1.store.BusinessDashboard/newProfileDetail')->option(['real_name' => '经营看板新建档明细']);
        Route::get('statistics/source-customer-detail', 'v1.store.BusinessDashboard/sourceCustomerDetail')->option(['real_name' => '经营看板散客新客明细']);
        Route::get('statistics/money-detail', 'v1.store.BusinessDashboard/moneyDetail')->option(['real_name' => '经营看板金额明细']);
		Route::get('all_region', 'v1.store.SystemStoreRegion/allRegion')->option(['real_name' => '门店区域列表']);
		//门店区域列表
		Route::get('region', 'v1.store.SystemStoreRegion/index')->option(['real_name' => '门店区域列表']);
		//获取已经添加所选城市数据
		Route::get('region/city', 'v1.store.SystemStoreRegion/getRegionCity')->option(['real_name' => '门店区域详情']);
		//门店区域详情
		Route::get('region/info/:id', 'v1.store.SystemStoreRegion/info')->option(['real_name' => '门店区域详情']);
		//门店区域保存
		Route::post('region/:id', 'v1.store.SystemStoreRegion/save')->option(['real_name' => '门店区域保存']);
		//删除门店区域
		Route::delete('region/:id', 'v1.store.SystemStoreRegion/delete')->option(['real_name' => '删除门店区域']);
		//删除某区域内某些门店
		Route::delete('region/del_store/:id', 'v1.store.SystemStoreRegion/delStore')->option(['real_name' => '删除某区域内某些门店']);
		//门店区域修改状态
		Route::put('region/set_show/:id/:is_show', 'v1.store.SystemStoreRegion/setShow')->option(['real_name' => '修改门店区域状态']);
		//门店区域修改隔离状态
		Route::put('region/set_alone/:id/:is_alone', 'v1.store.SystemStoreRegion/setAlone')->option(['real_name' => '修改门店区域隔离状态']);

        //门店分类树形列表
        Route::get('category/tree/:type', 'v1.store.StoreCategory/tree_list')->option(['real_name' => '门店分类树形列表']);
        //门店分类cascader行列表
        Route::get('category/cascader_list/[:type]', 'v1.store.StoreCategory/cascader_list')->option(['real_name' => '门店分类cascader行列表']);
        //门店分类列表
        Route::get('category', 'v1.store.StoreCategory/index')->option(['real_name' => '门店分类列表']);
        //门店分类新增、编辑表单
        Route::get('category/create/:id', 'v1.store.StoreCategory/create')->option(['real_name' => '门店分类新增、编辑表单']);
        //门店分类保存
        Route::post('category/:id', 'v1.store.StoreCategory/save')->option(['real_name' => '门店分类保存']);
        //删除门店分类
        Route::delete('category/:id', 'v1.store.StoreCategory/delete')->option(['real_name' => '删除门店分类']);
        //门店分类修改状态
        Route::put('category/set_show/:id/:is_show', 'v1.store.StoreCategory/set_show')->option(['real_name' => '门店分类修改状态']);

		/**
		 * 加盟店申请
		 */
		Route::group('apply', function () {
			Route::get('list', 'v1.store.StoreApply/index')->option(['real_name' => '加盟店申请列表']);
			Route::get('info/:id', 'v1.store.StoreApply/read')->option(['real_name' => '加盟店申请详情']);
			Route::get('verify/form/:id', 'v1.store.StoreApply/verifyForm')->option(['real_name' => '加盟店申请审核表单']);
			Route::post('verify/:id', 'v1.store.StoreApply/verifyApply')->option(['real_name' => '加盟店申请审核']);
			Route::get('mark/form/:id', 'v1.store.StoreApply/markForm')->option(['real_name' => '加盟店申请备注表单']);
			Route::post('mark/:id', 'v1.store.StoreApply/mark')->option(['real_name' => '加盟店申请备注']);
			Route::delete('del/:id', 'v1.store.StoreApply/delete')->option(['real_name' => '加盟店申请删除']);
		});

		//门店财务设置
		Route::get('finance_config/:id', 'v1.store.SystemStore/storeFinanceConfig')->option(['real_name' => '门店财务设置']);
		//门店列表
        Route::get('store', 'v1.store.SystemStore/index')->option(['real_name' => '门店列表']);
        //门店上下架
        Route::put('store/set_show/:id/:is_show', 'v1.store.SystemStore/set_show')->option(['real_name' => '门店上下架']);
        //门店删除
        Route::delete('store/del/:id', 'v1.store.SystemStore/delete')->option(['real_name' => '门店删除']);
        //门店位置选择
        Route::get('store/address', 'v1.store.SystemStore/select_address')->option(['real_name' => '门店位置选择']);
        //获取ERP门店列表
        Route::get('erp/shop', 'v1.store.SystemStore/getErpShop')->option(['real_name' => '获取ERP门店列表']);
        //门店详情
        Route::get('store/get_info/:id', 'v1.store.SystemStore/get_info')->option(['real_name' => '门店详情']);
        //保存修改门店信息
        Route::post('store/:id', 'v1.store.SystemStore/save')->option(['real_name' => '保存修改门店信息']);
        //门店快捷登录
        Route::get('store/login/:id', 'v1.store.SystemStore/storeLogin')->option(['real_name' => '门店快捷登录']);
        //获取门店重置账号密码表单
        Route::get('store/reset_admin/:id', 'v1.store.SystemStore/resetAdminForm')->option(['real_name' => '获取门店重置账号密码表单']);
        //门店重置账号密码
        Route::post('store/reset_admin/:id', 'v1.store.SystemStore/resetAdmin')->option(['real_name' => '门店重置账号密码']);

        //获取头部数据
        Route::get('order/header', 'v1.store.Order/header')->name('StoreOrderHeader')->option(['real_name' => '获取门店订单头部统计']);
        //订单列表
        Route::get('order/list', 'v1.store.Order/index')->name('StoreOrderList')->option(['real_name' => '订单列表']);
        //订单头部数据
        Route::get('order/chart', 'v1.store.Order/chart')->name('StoreOrderChart')->option(['real_name' => '订单头部数据']);
        Route::put('order/getCash', 'v1.store.Order/getCash')->name('getCash')->option(['real_name' => '保存会员备注']);
        //订单详情
        Route::get('order/info/:id', 'v1.store.Order/order_info')->name('StoreOrderorInfo')->option(['real_name' => '订单详情']);
        //修改备注信息
        Route::put('order/remark/:id', 'v1.store.Order/remark')->name('StoreOrderorRemark')->option(['real_name' => '修改备注信息']);
        //获取订单状态
        Route::get('order/status/:id', 'v1.store.Order/status')->name('StoreOrderorStatus')->option(['real_name' => '获取订单状态']);
        //订单分配
        Route::post('share/order', 'v1.store.Order/shareOrder')->option(['real_name' => '订单分配']);
        //撤销核销订单
        Route::put('order/postChexiao/:id', 'v1.store.Order/postChexiao')->option(['real_name' => '撤销本次核销（兼容旧入口）']);

		/**
		 * 售后 相关路由
		 */
		Route::group('refund', function () {
			//售后列表
			Route::get('list', 'v1.store.RefundOrder/getRefundList')->option(['real_name' => '售后订单列表']);
			//商家同意退款，等待用户退货
			Route::get('agree/:order_id', 'v1.store.RefundOrder/agreeRefund')->option(['real_name' => '商家同意退款，等待用户退货']);
			//售后订单备注
			Route::put('remark/:id', 'v1.store.RefundOrder/remark')->option(['real_name' => '售后订单备注']);
			//售后订单退款表单
			Route::get('refund/:id', 'v1.store.RefundOrder/refund')->name('StoreOrderRefund')->option(['real_name' => '售后订单退款表单']);
			//售后订单退款
			Route::put('refund/:id', 'v1.store.RefundOrder/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '售后订单退款']);
			//售后详情
			Route::get('detail/:id', 'v1.store.RefundOrder/refundDetail')->option(['real_name' => '售后订单详情']);
			//售后订单删除
			Route::delete('del/:id', 'v1.store.RefundOrder/del')->name('orderRefundDel')->option(['real_name' => '售后订单删除']);
			//售后退货物流
			Route::get('express/:id', 'v1.store.RefundOrder/getRefundExpress')->option(['real_name' => '售后退货物流']);
		});

        //储值订单列表
        Route::get('recharge', 'v1.store.Recharge/index')->name('RechargeOrderList')->option(['real_name' => '储值订单列表']);
        //保存储值订单备注
        Route::put('recharge/remark/:id', 'v1.store.Recharge/remarks')->option(['real_name' => '保存储值订单备注']);
        //获取储值订单备注
        Route::get('recharge/remark/:id', 'v1.store.Recharge/getRemark')->option(['real_name' => '获取储值订单备注']);
        //付费会员订单列表
        Route::get('vip_order', 'v1.store.PayVipOrder/index')->name('PayVipOrderList')->option(['real_name' => '付费会员订单列表']);
        //获取会员状态
        Route::get('vip/status/:id', 'v1.store.PayVipOrder/status')->name('getStatusList')->option(['real_name' => '获取会员状态']);
        //获取会员备注
        Route::get('vip/remark/:id', 'v1.store.PayVipOrder/getRemark')->name('getRemark')->option(['real_name' => '获取会员备注']);
        //保存会员备注
        Route::put('vip/remark/:id', 'v1.store.PayVipOrder/remark')->name('remarkSave')->option(['real_name' => '保存会员备注']);
        //门店转账列表
        Route::get('extract/list', 'v1.store.StoreExtract/index')->option(['real_name' => '门店转账列表']);
        //门店转账记录备注
        Route::post('extract/mark/:id', 'v1.store.StoreExtract/mark')->option(['real_name' => '门店转账记录备注']);
        //提现审核
        Route::post('extract/verify/:id', 'v1.store.StoreExtract/verify')->option(['real_name' => '门店转账记录备注']);
        //转账表单
        Route::get('extract/transfer/:id', 'v1.store.StoreExtract/transfer')->option(['real_name' => '转账表单']);
        //转账提交
        Route::post('extract/save_transfer/:id', 'v1.store.StoreExtract/save_transfer')->option(['real_name' => '转账提交']);
        //门店流水列表
        Route::get('finance_flow/list', 'v1.store.StoreFinanceFlow/index')->option(['real_name' => '门店流水列表']);
        //门店流水备注
        Route::put('finance_flow/mark/:id', 'v1.store.StoreFinanceFlow/mark')->option(['real_name' => '门店流水备注']);
        //门店账单记录
        Route::get('finance_flow/fund_record', 'v1.store.StoreFinanceFlow/fundRecord')->option(['real_name' => '门店账单记录']);
        //门店账单详情
        Route::get('finance_flow/fund_record_info', 'v1.store.StoreFinanceFlow/fundRecordInfo')->option(['real_name' => '门店账单详情']);
        //门店财务设置头部
        Route::get('finance/header_basics', 'v1.system.config.SystemConfig/header_basics')->option(['real_name' => '门店财务设置头部']);
        //门店财务编辑表单
        Route::get('finance/edit_basics', 'v1.system.config.SystemConfig/edit_basics')->option(['real_name' => '门店财务编辑表单']);
        //门店财务保存数据
        Route::post('finance/save_basics', 'v1.system.config.SystemConfig/save_basics')->option(['real_name' => '门店财务保存数据']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * mendi 相关路由
     */
    Route::group('merchant', function () {
        //门店列表
        Route::get('store', 'v1.merchant.SystemStore/index')->option(['real_name' => '门店列表']);
        //门店列表头部数据
        Route::get('store/get_header', 'v1.merchant.SystemStore/get_header')->option(['real_name' => '门店列表头部数据']);
        //门店上下架
        Route::put('store/set_show/:id/:is_show', 'v1.merchant.SystemStore/set_show')->option(['real_name' => '门店上下架']);
        //门店删除
        Route::delete('store/del/:id', 'v1.merchant.SystemStore/delete')->option(['real_name' => '门店删除']);
        //门店位置选择
        Route::get('store/address', 'v1.merchant.SystemStore/select_address')->option(['real_name' => '门店位置选择']);
        //门店详情
        Route::get('store/get_info/:id', 'v1.merchant.SystemStore/get_info')->option(['real_name' => '门店详情']);
        //保存修改门店信息
        Route::post('store/:id', 'v1.merchant.SystemStore/save')->option(['real_name' => '保存修改门店信息']);
        //获取门店店员列表
        Route::get('store_staff', 'v1.merchant.SystemStoreStaff/index')->option(['real_name' => '获取门店店员列表']);
        //获取店员列表
        Route::get('staff/list', 'v1.merchant.SystemStoreStaff/getStoreStaffList')->option(['real_name' => '获取门店店员列表']);
        //获取门店店员下拉（轻量）
        Route::get('staff/select', 'v1.merchant.SystemStoreStaff/getStaffSelect')->option(['real_name' => '获取门店店员下拉']);
        //获取店员专属客户
        Route::get('staff/customer/:id', 'v1.merchant.SystemStoreStaff/getStoreStaffCustomer')->option(['real_name' => '获取店员专属客户']);
        //获取店员业绩列表
        Route::get('staff/performance/:id', 'v1.merchant.SystemStoreStaff/getStaffPerformance')->option(['real_name' => '获取店员业绩列表']);
        //获取店员详情
        Route::get('staff/read/:id', 'v1.merchant.SystemStoreStaff/read')->option(['real_name' => '获取店员详情']);
        Route::get('staff/person_complete/:id', 'v1.merchant.SystemStoreStaff/personComplete')->option(['real_name' => '人员完整详情']);
        Route::post('staff/person_complete/save/:id', 'v1.merchant.SystemStoreStaff/savePersonComplete')->option(['real_name' => '人员完整保存']);
        //保存店员信息
        Route::post('staff/save/:id', 'v1.merchant.SystemStoreStaff/saveStaff')->option(['real_name' => '保存店员信息']);
        //店员调店
        Route::post('staff/transfer/:id', 'v1.merchant.SystemStoreStaff/transfer')->option(['real_name' => '店员调店']);
        //调店记录
        Route::get('staff/transfer_log', 'v1.merchant.SystemStoreStaff/transferLog')->option(['real_name' => '店员调店记录']);
        //列表列配置
        Route::get('staff/column_setting', 'v1.merchant.SystemStoreStaff/getColumnSetting')->option(['real_name' => '获取店员列表列配置']);
        Route::post('staff/column_setting', 'v1.merchant.SystemStoreStaff/saveColumnSetting')->option(['real_name' => '保存店员列表列配置']);
        //店员编辑辅助数据
        Route::get('staff/roleList', 'v1.merchant.SystemStoreStaff/staffRoleList')->option(['real_name' => '获取店员角色列表']);
        Route::get('staff/position', 'v1.merchant.SystemStoreStaff/staffPosition')->option(['real_name' => '获取职位列表']);
        Route::get('staff/positionLevel', 'v1.merchant.SystemStoreStaff/staffPositionLevel')->option(['real_name' => '获取职级列表']);
        Route::get('staff/workMember/list', 'v1.merchant.SystemStoreStaff/getWorkMemberList')->option(['real_name' => '获取企微员工列表']);
        //添加门店店员表单
        Route::get('store_staff/create', 'v1.merchant.SystemStoreStaff/create')->option(['real_name' => '添加门店店员表单']);
        //门店搜索列表
        Route::get('store_list', 'v1.merchant.SystemStoreStaff/store_list')->option(['real_name' => '门店搜索列表']);
        //修改店员状态
        Route::put('store_staff/set_show/:id/:is_show', 'v1.merchant.SystemStoreStaff/set_show')->option(['real_name' => '修改店员状态']);
        //修改店员表单
        Route::get('store_staff/:id/edit', 'v1.merchant.SystemStoreStaff/edit')->option(['real_name' => '修改店员表单']);
        //保存店员
        Route::post('store_staff/save/:id', 'v1.merchant.SystemStoreStaff/save')->option(['real_name' => '保存店员']);
        //删除店员
        Route::delete('store_staff/del/:id', 'v1.merchant.SystemStoreStaff/delete')->option(['real_name' => '删除店员']);
        //获取核销订单列表
        Route::get('verify_order', 'v1.merchant.SystemVerifyOrder/list')->option(['real_name' => '获取核销订单列表']);
        //获取核销订单头部
        Route::get('verify_badge', 'v1.merchant.SystemVerifyOrder/getVerifyBadge')->option(['real_name' => '获取核销订单头部']);
        //核销订单推荐人信息
        Route::get('verify/spread_info/:uid', 'v1.merchant.SystemVerifyOrder/order_spread_user')->option(['real_name' => '核销订单推荐人信息']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 消息通知管理、模版消息（列表，通知，添加，编辑）、短信 相关路由
     */
    Route::group('notify', function () {
        //保存短信配置
        Route::post('sms/config', 'v1.notification.sms.SmsConfig/save_basics')->option(['real_name' => '保存短信配置']);
        //短信发送记录
        Route::get('sms/record', 'v1.notification.sms.SmsConfig/record')->option(['real_name' => '短信发送记录']);
        //短信账号数据
        Route::get('sms/data', 'v1.notification.sms.SmsConfig/data')->option(['real_name' => '短信账号数据']);
        //查看短信账号是否登录
        Route::get('sms/is_login', 'v1.notification.sms.SmsConfig/is_login')->option(['real_name' => '查看短信账号是否登录']);
        //短信账号退出登录
        Route::get('sms/logout', 'v1.notification.sms.SmsConfig/logout')->option(['real_name' => '短信账号退出登录']);
        //发送短信验证码
        Route::post('sms/captcha', 'v1.notification.sms.SmsAdmin/captcha')->option(['real_name' => '发送短信验证码']);
        //修改或注册短信平台账号
        Route::post('sms/register', 'v1.notification.sms.SmsAdmin/save')->option(['real_name' => '修改或注册短信平台账号']);
        //短信模板列表
        Route::get('sms/temp', 'v1.notification.sms.SmsTemplateApply/index')->option(['real_name' => '短信模板列表']);
        //短信模板申请表单
        Route::get('sms/temp/create', 'v1.notification.sms.SmsTemplateApply/create')->option(['real_name' => '短信模板申请表单']);
        //短信模板申请
        Route::post('sms/temp', 'v1.notification.sms.SmsTemplateApply/save')->option(['real_name' => '短信模板申请']);
        //公共短信模板列表
        Route::get('sms/public_temp', 'v1.notification.sms.SmsPublicTemp/index')->option(['real_name' => '公共短信模板列表']);
        //短信剩余条数
        Route::get('sms/number', 'v1.notification.sms.SmsPay/number')->option(['real_name' => '短信剩余条数']);
        //获取短信购买套餐
        Route::get('sms/price', 'v1.notification.sms.SmsPay/price')->option(['real_name' => '获取短信购买套餐']);
        //获取短信购买支付码
        Route::post('sms/pay_code', 'v1.notification.sms.SmsPay/pay')->option(['real_name' => '获取短信购买支付码']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 同城配送
     */
    Route::group('delivery', function () {
        //同城配送配置
        Route::get('status/config', 'v1.store.SystemStore/deliveryStatus')->option(['real_name' => '同城配送配置']);
        //获取配送员select
        Route::get('get_delivery_select', 'v1.order.DeliveryService/getDeliverySelect')->name('getDeliverySelect')->option(['real_name' => '获取配送员select']);
        //配送员账单统计头部
        Route::get('statisticsHeader', 'v1.order.DeliveryService/statisticsHeader')->name('statisticsHeader')->option(['real_name' => '配送员账单头部']);
        //配送员账单统计
        Route::get('statistics', 'v1.order.DeliveryService/statistics')->name('statistics')->option(['real_name' => '配送员账单统计']);//配送员账单统计
        //配送数据统计导出
        Route::get('export/statistics', 'v1.other.export.ExportExcel/statistics')->option(['real_name' => '配送数据统计导出']);
        //统计数据
        Route::get('data', 'v1.order.StoreDeliveryOrder/getData')->option(['real_name' => '统计数据']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 社区相关路由
     */
    Route::group('community', function () {
        //获取所有社区话题
        Route::get('all_topic', 'v1.community.CommunityTopic/allTopic')->option(['real_name' => '获取所有社区话题']);
        //社区话题列表
        Route::get('topic/list', 'v1.community.CommunityTopic/index')->option(['real_name' => '社区话题列表']);
        //社区话题添加、编辑表单
        Route::get('topic/save_form/:id', 'v1.community.CommunityTopic/create')->option(['real_name' => '社区话题添加、编辑表单']);
        //社区话题添加、编辑
        Route::post('topic/save/:id', 'v1.community.CommunityTopic/save')->option(['real_name' => '社区话题添加、编辑']);
        //社区话题是否显示
        Route::get('topic/set_status/:id/:is_show', 'v1.community.CommunityTopic/setIsShow')->option(['real_name' => '设置社区话题是否显示']);
        //社区话题是否推荐
        Route::get('topic/set_hot/:id/:hot', 'v1.community.CommunityTopic/setHot')->option(['real_name' => '设置社区话题是否推荐']);
        //删除社区话题
        Route::delete('topic/del/:id', 'v1.community.CommunityTopic/delete')->option(['real_name' => '删除社区话题']);

        //社区内容顶部header
        Route::get('community/header', 'v1.community.Community/type_header')->option(['real_name' => '社区内容顶部header']);
        //社区内容列表
        Route::get('community/list', 'v1.community.Community/index')->option(['real_name' => '社区内容列表']);
        //社区内容详情
        Route::get('community/info/:id', 'v1.community.Community/info')->option(['real_name' => '社区内容添加、编辑表单']);
        //社区内容添加、编辑
        Route::post('community/save/:id', 'v1.community.Community/save')->option(['real_name' => '社区内容添加、编辑']);
        //社区内容是否显示
        Route::post('community/set_status/:id/:status', 'v1.community.Community/setStatus')->option(['real_name' => '设置社区内容是否显示']);
        //社区内容推荐指数表单
        Route::get('community/star/form/:id', 'v1.community.Community/setStarForm')->option(['real_name' => '设置社区内容推荐指数表单']);
        //设置社区内容推荐
        Route::post('community/star/:id', 'v1.community.Community/setStar')->option(['real_name' => '设置社区内容推荐指数']);
        //社区内容推荐
        Route::post('community/set_recommend/:id/:recommend', 'v1.community.Community/recommend')->option(['real_name' => '社区内容推荐']);
        //社区内容审核表单
        Route::get('community/verify/form/:id', 'v1.community.Community/verifyForm')->option(['real_name' => '社区内容审核']);
        //社区内容强制下架表单
        Route::get('community/take_down/form/:id', 'v1.community.Community/takeDownForm')->option(['real_name' => '社区内容强制下架表单']);
        //社区内容审核
        Route::post('community/set_verify/:id', 'v1.community.Community/setVerify')->option(['real_name' => '社区内容审核']);
		//社区内容批量审核
		Route::post('community/batchVerify', 'v1.community.Community/batchVerify')->option(['real_name' => '社区内容批量审核']);
        //删除社区内容
        Route::delete('community/del/:id', 'v1.community.Community/delete')->option(['real_name' => '删除社区内容']);

        //社区评论
        Route::get('comment/list', 'v1.community.CommunityComment/index')->option(['real_name' => '社区评论列表']);
        //社区评论回复列表
        Route::get('comment/reply/:id', 'v1.community.CommunityComment/getCommentReply')->option(['real_name' => '社区评论回复列表']);
        //社区评论回复表单
        Route::get('comment/reply/form/:id', 'v1.community.CommunityComment/replyForm')->option(['real_name' => '社区评论回复']);
        //社区评论回复
        Route::post('comment/reply/:id', 'v1.community.CommunityComment/setReply')->option(['real_name' => '社区评论回复']);
        //社区评论删除
        Route::delete('comment/del/:id', 'v1.community.CommunityComment/delete')->option(['real_name' => '社区评论列表']);
        //社区评论审核表单
        Route::get('comment/verify/form/:id', 'v1.community.CommunityComment/verifyForm')->option(['real_name' => '社区内容审核']);
        //社区内容强制下架表单
        Route::get('comment/take_down/form/:id', 'v1.community.CommunityComment/takeDownForm')->option(['real_name' => '社区内容强制下架表单']);
        //显示隐藏
        Route::put('comment/set_status/:id/:status', 'v1.community.CommunityComment/setStatus')->option(['real_name' => '显示隐藏']);
        //设置审核状态
        Route::post('comment/set_verify/:id', 'v1.community.CommunityComment/setVerify')->option(['real_name' => '设置社区内容审核状态']);
        //社区虚拟评论表单
        Route::get('comment/fictitious/:id', 'v1.community.CommunityComment/fictitiousComment')->option(['real_name' => '社区虚拟评论表单']);
        //社区保存虚拟评论
        Route::post('comment/save_fictitious', 'v1.community.CommunityComment/saveFictitiousComment')->option(['real_name' => '社区保存虚拟评论']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 门店目标
     */
    Route::group('target', function () {
        Route::get('list', 'v1.target.StoreTarget/list')->option(['real_name' => '目标列表']);
        Route::get('analysis', 'v1.target.StoreTarget/analysis')->option(['real_name' => '目标数据分析']);
        Route::get('ranking', 'v1.target.StoreTarget/ranking')->option(['real_name' => '目标员工排行']);
        Route::get('store_options', 'v1.target.StoreTarget/storeOptions')->option(['real_name' => '目标门店选项']);
        Route::get('metric_options', 'v1.target.StoreTarget/metricOptions')->option(['real_name' => '目标指标选项']);
        Route::get('monthly_detail', 'v1.target.StoreTarget/monthlyDetail')->option(['real_name' => '月度目标完成明细']);
        Route::get('store_detail', 'v1.target.StoreTarget/storeDetail')->option(['real_name' => '门店目标完成明细']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 报表
     */
    Route::group('report', function () {
        Route::get('business-catalog', 'v1.report.BusinessReportCatalog/index')->option(['real_name' => '业务报表中心目录']);
        Route::get('unified/catalog', 'v1.report.UnifiedReport/catalog')->option(['real_name' => '门店业务报表目录']);
        Route::get('unified/scope', 'v1.report.UnifiedReport/scope')->option(['real_name' => '门店业务报表权限范围']);
        Route::get('unified/personnel', 'v1.report.UnifiedReport/personnel')->option(['real_name' => '门店业务报表人员筛选']);
        Route::get('unified/query', 'v1.report.UnifiedReport/query')->option(['real_name' => '门店业务报表查询']);
        Route::get('unified/export', 'v1.report.UnifiedReport/export')->option(['real_name' => '门店业务报表导出']);
        Route::get('six-dimension/consumption-tiers', 'v1.report.UnifiedReport/consumptionTiers')->option(['real_name' => '消费分级设置列表']);
        Route::post('six-dimension/consumption-tiers', 'v1.report.UnifiedReport/saveConsumptionTier')->option(['real_name' => '保存消费分级设置']);
        Route::post('six-dimension/consumption-tiers/sort', 'v1.report.UnifiedReport/sortConsumptionTiers')->option(['real_name' => '消费分级排序']);
        Route::get('operations/categories', 'v1.report.UnifiedReport/operationsCategories')->option(['real_name' => '门店运营商品分类']);
        Route::post('operations/category', 'v1.report.UnifiedReport/saveCategory')->option(['real_name' => '保存合作方分类配置']);
        Route::get('operations/annotations', 'v1.report.UnifiedReport/annotations')->option(['real_name' => '门店运营补充记录']);
        Route::post('operations/annotation', 'v1.report.UnifiedReport/saveAnnotation')->option(['real_name' => '保存门店运营补充记录']);
        Route::get('reportSale', 'v1.report.ReportData/reportSale')->name('reportColumn')->option(['real_name' => '报表列信息']);
        Route::get('operating_screen', 'v1.report.ReportData/operatingScreen')->name('operatingScreen')->option(['real_name' => '总部经营数据大屏']);
        Route::get('reportList', 'v1.report.ReportData/reportList')->name('reportList')->option(['real_name' => '报表列表']);
        Route::get('fenxiList', 'v1.report.ReportData/fenxiList')->name('fenxiList')->option(['real_name' => '项目分析列表']);
        Route::get('xnList', 'v1.report.ReportData/xnList')->name('xnList')->option(['real_name' => '门店效能分析']);
        Route::get('pkInfo', 'v1.report.ReportPk/pkInfo')->name('pkInfo')->option(['real_name' => '报表PK']);
        Route::post('save_pk', 'v1.report.ReportPk/savePk')->name('savePk')->option(['real_name' => '修改PK']);
        Route::get('salaryColumn', 'v1.report.ReportSalary/salaryColumn')->name('salaryColumn')->option(['real_name' => '工资列']);
        Route::get('salaryList', 'v1.report.ReportSalary/salaryList')->name('salaryList')->option(['real_name' => '工资表']);
        Route::post('setSalary', 'v1.report.ReportSalary/setSalary')->name('setSalary')->option(['real_name' => '保存工资']);
        Route::post('makeFreeze', 'v1.report.ReportSalary/makeFreeze')->name('makeFreeze')->option(['real_name' => '保存工资']);
        Route::get('getFreeze', 'v1.report.ReportSalary/getFreeze')->name('getFreeze')->option(['real_name' => '保存工资']);
        Route::get('salaryCount', 'v1.report.ReportSalary/salaryCount')->name('salaryCount')->option(['real_name' => '工资合计']);
        Route::get('agentList', 'v1.report.ReportSalary/agentList')->name('agentList')->option(['real_name' => '代班列表']);
        Route::get('editAgent', 'v1.report.ReportSalary/editAgent')->name('editAgent')->option(['real_name' => '编辑代班']);
        Route::post('saveAgent/:id', 'v1.report.ReportSalary/saveAgent')->name('saveAgent')->option(['real_name' => '保存代班']);
        Route::put('delAgent/:id', 'v1.report.ReportSalary/delAgent')->name('delAgent')->option(['real_name' => '删除代班']);

        Route::get('order_data', 'v1.report.ReportData/orderData')->name('reportOrder')->option(['real_name' => '订单报表']);
        Route::get('receiveColumn', 'v1.report.ReportData/receiveColumn')->name('receiveColumn')->option(['real_name' => 'receiveColumn']);
        Route::get('selfCount', 'v1.report.ReportTable/selfCount')->name('selfCount')->option(['real_name' => '报表合计']);
        Route::get('selfColumn', 'v1.report.ReportTable/selfColumn')->name('selfColumn')->option(['real_name' => '报表列名']);
        Route::get('selfList', 'v1.report.ReportTable/selfList')->name('selfList')->option(['real_name' => '报表列表']);
        Route::get('selfSearch', 'v1.report.ReportTable/selfSearch')->name('selfSearch')->option(['real_name' => '报表搜索栏']);
        Route::get('searchFieldList', 'v1.report.ReportTable/searchFieldList')->name('searchFieldList')->option(['real_name' => '搜索项列表']);
        Route::post('searchFieldSave', 'v1.report.ReportTable/searchFieldSave')->name('searchFieldSave')->option(['real_name' => '保存搜索项']);
        Route::delete('searchFieldDelete/:id', 'v1.report.ReportTable/searchFieldDelete')->name('searchFieldDelete')->option(['real_name' => '删除搜索项']);
        Route::get('getTable', 'v1.report.ReportTable/getTable')->name('getTable')->option(['real_name' => '获取报表']);
        Route::delete('reportTable/del/:id', 'v1.report.ReportTable/delTable')->name('delTable')->option(['real_name' => '删除自定义表格行']);
        Route::post('setTableSalary', 'v1.report.ReportTable/setSalary')->name('setTableSalary')->option(['real_name' => '编辑自定义表格']);
        Route::post('addTableSalary', 'v1.report.ReportTable/addSalary')->name('addTableSalary')->option(['real_name' => '新增自定义表格']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 职位职级
     */
    Route::group('position', function () {
        Route::get('positionList', 'v1.position.position/positionList')->name('positionList')->option(['real_name' => '职位列表']);
        Route::get('editPosition', 'v1.position.position/editPosition')->name('editPosition')->option(['real_name' => '编辑职位']);
        Route::post('savePosition/:id', 'v1.position.position/savePosition')->name('savePosition')->option(['real_name' => '保存职位']);
        Route::put('delPosition/:id', 'v1.position.position/delPosition')->name('delPosition')->option(['real_name' => '删除职位']);
        Route::get('set_status/:id/:status', 'v1.position.position/set_status')->option(['real_name' => '修改职位状态']);
        Route::get('positionLevelList', 'v1.position.positionLevel/positionList')->name('positionLevelList')->option(['real_name' => '职级列表']);
        Route::get('editPositionLevel', 'v1.position.positionLevel/editPosition')->name('editPositionLevel')->option(['real_name' => '编辑职级']);
        Route::post('savePositionLevel/:id', 'v1.position.positionLevel/savePosition')->name('savePositionLevel')->option(['real_name' => '保存职级']);
        Route::put('delPositionLevel/:id', 'v1.position.positionLevel/delPosition')->name('delPositionLevel')->option(['real_name' => '删除职级']);
        Route::get('set_status_level/:id/:status', 'v1.position.positionLevel/set_status')->option(['real_name' => '修改职位状态']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 订单路由
     */
    Route::group('order', function () {
        //打印订单
        Route::get('print/:id', 'v1.order.StoreOrder/order_print')->name('StoreOrderPrint')->option(['real_name' => '打印订单']);
        //订单列表
        Route::get('list', 'v1.order.StoreOrder/lst')->name('StoreOrderList')->option(['real_name' => '订单列表']);
        //渠道已扣款待人工处理
        Route::get('channel_pay_pending/list', 'v1.order.CashierChannelPayPending/index')->option(['real_name' => '渠道已扣款待处理列表']);
        Route::get('channel_pay_pending/:id', 'v1.order.CashierChannelPayPending/info')->option(['real_name' => '渠道已扣款待处理详情']);
        Route::put('channel_pay_pending/done/:id', 'v1.order.CashierChannelPayPending/markDone')->option(['real_name' => '渠道已扣款待处理完成']);
        //欠款管理
        Route::get('debt/list', 'v1.order.StoreDebt/index')->option(['real_name' => '欠款列表']);
        Route::get('debt/user/:uid', 'v1.order.StoreDebt/userList')->option(['real_name' => '用户欠款记录']);
        Route::get('debt/repay/list', 'v1.order.StoreDebt/repayList')->option(['real_name' => '还款记录']);
        Route::get('debt/order/:order_id', 'v1.order.StoreDebt/orderDetail')->option(['real_name' => '订单欠款详情']);
        Route::get('debt/order/:order_id/items', 'v1.order.StoreDebt/orderRepayItems')->option(['real_name' => '订单欠款明细(核销补交)']);
        Route::put('debt/close/:id', 'v1.order.StoreDebt/close')->option(['real_name' => '关闭欠款']);
        Route::post('debt/repay/pay', 'v1.order.StoreDebt/repayPay')->option(['real_name' => '欠款补交支付']);
        Route::post('debt/repay/check', 'v1.order.StoreDebt/repayCheck')->option(['real_name' => '欠款还款状态查询']);
        Route::post('cashType', 'v1.order.StoreDebt/cashType')->option(['real_name' => '现金类型']);
        Route::post('cashSource', 'v1.order.StoreDebt/cashSource')->option(['real_name' => '来源']);
        Route::get('yejiList', 'v1.yeji.Yeji/yeji')->name('yeji')->option(['real_name' => '业绩分配']);
        Route::get('yejiRange', 'v1.yeji.Yeji/yejiRange')->name('yejiRange')->option(['real_name' => '业绩区间']);
        Route::get('yejiCommission', 'v1.yeji.Yeji/yejiCommission')->name('yejiCommission')->option(['real_name' => '业绩区间提成']);
        Route::post('saveCommission/:id', 'v1.yeji.Yeji/saveCommission')->name('saveCommission')->option(['real_name' => '保存业绩区间提成']);
        Route::post('saveRange/:id', 'v1.yeji.Yeji/saveRange')->name('saveRange')->option(['real_name' => '保存业绩区间范围']);
        Route::get('setRange', 'v1.yeji.Yeji/setRange')->name('setRange')->option(['real_name' => '设置区间']);
        Route::get('setCommission', 'v1.yeji.Yeji/setCommission')->name('setCommission')->option(['real_name' => '设置业绩区间提成']);
        Route::delete('yejiRange/del/:id', 'v1.yeji.Yeji/delRange')->name('delRange')->option(['real_name' => '删除业绩区间']);
        Route::delete('yejiCommission/del/:id', 'v1.yeji.Yeji/delCommission')->name('delCommission')->option(['real_name' => '删除提成']);
        Route::get('yejiColumn', 'v1.yeji.Yeji/yejiColumn')->name('yejiColumn')->option(['real_name' => '业绩区间']);
        Route::post('allList', 'v1.yeji.Yeji/allList')->name('allList')->option(['real_name' => '']);
        Route::post('save_yeji', 'v1.yeji.Yeji/save_yeji')->name('save_yeji')->option(['real_name' => '']);
        Route::post('get_yeji', 'v1.yeji.Yeji/getYeji')->option(['real_name' => '获得业绩']);
        Route::post('get_cart_yeji', 'v1.yeji.Yeji/getCartYeji')->option(['real_name' => '购物车行销售业绩摘要']);
        Route::post('get_remark_info', 'v1.yeji.Yeji/getRemark')->option(['real_name' => '获得订单备注']);
        Route::post('saveRemark', 'v1.yeji.Yeji/saveRemark')->option(['real_name' => '更新付款方式']);
        Route::post('saveSource', 'v1.yeji.Yeji/saveSource')->option(['real_name' => '更新订单来源']);
        Route::post('saveGendan', 'v1.yeji.Yeji/saveGendan')->option(['real_name' => '更新跟单状态']);
        //订单头部数据
        Route::get('chart', 'v1.order.StoreOrder/chart')->name('StoreOrderChart')->option(['real_name' => '订单头部数据']);
		//订单核销表单弹窗
		Route::get('write/form/:id', 'v1.order.StoreOrder/writeOrderFrom')->name('writeOrderForm')->option(['real_name' => '订单核销表单']);
		//订单核销表单提交
		Route::post('write/form/:id', 'v1.order.StoreOrder/writeoffFrom')->name('writeOrderForm')->option(['real_name' => '订单核销表单']);
		//订单核销
        Route::post('write', 'v1.order.StoreOrder/write_order')->name('writeOrder')->option(['real_name' => '订单核销']);
		//获取核销订单商品信息
		Route::get('writeOff/cartInfo', 'v1.order.StoreOrder/orderCartInfo')->name('writeOrderCartInfo')->option(['real_name' => '获取核销订单商品信息']);
        //订单号核销
        Route::put('write_update/:order_id', 'v1.order.StoreOrder/wirteoff')->name('writeOrderUpdate')->option(['real_name' => '订单号核销']);
        //获取订单编辑表单
        Route::get('edit/:id', 'v1.order.StoreOrder/edit')->name('StoreOrderEdit')->option(['real_name' => '获取订单编辑表单']);
		//订单改价获取商品信息
		Route::get('update/info/:id', 'v1.order.StoreOrder/getUpdateInfo')->name('StoreOrderUpdate')->option(['real_name' => '订单改价获取商品信息']);
        //订单改价
        Route::put('update/:id', 'v1.order.StoreOrder/update')->name('StoreOrderUpdate')->option(['real_name' => '订单改价']);
        //确认收货
        Route::put('take/:id', 'v1.order.StoreOrder/take_delivery')->name('StoreOrderTakeDelivery')->option(['real_name' => '确认收货']);
		//未发货订单修改收货地址
		Route::post('edit_address/:id', 'v1.order.StoreOrder/editAddress')->option(['real_name' => '修改收货地址']);
		//订单发送货
        Route::put('delivery/:id', 'v1.order.StoreOrder/update_delivery')->name('StoreOrderUpdateDelivery')->option(['real_name' => '订单发送货']);
        //获取订单可拆分商品列表
        Route::get('split_cart_info/:id', 'v1.order.StoreOrder/split_cart_info')->name('StoreOrderSplitCartInfo')->option(['real_name' => '获取订单可拆分商品列表']);
        //拆单发送货
        Route::put('split_delivery/:id', 'v1.order.StoreOrder/split_delivery')->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);
        //获取订单拆分子订单列表
        Route::get('split_order/:id', 'v1.order.StoreOrder/split_order')->name('StoreOrderSplitOrder')->option(['real_name' => '获取订单拆分子订单列表']);
        //管理员操作重新发单
        Route::get('reissue_order/:id', 'v1.order.StoreOrder/adminReissueOrder')->name('adminReissueOrder')->option(['real_name' => '管理员操作重新发单']);
        //订单退款表单
        Route::get('refund/:id', 'v1.order.StoreOrder/refund')->name('StoreOrderRefund')->option(['real_name' => '订单退款表单']);
        //订单退款
        Route::put('refund/:id', 'v1.order.StoreOrder/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '订单退款']);
        //后台拆单退款
        Route::post('open/refund/:id', 'v1.order.StoreOrder/open_order_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '后台拆单退款']);
        // 整单退款/作废/重开/撤销核销（与门店端路径对齐：本分组前缀已是 order）
        Route::post(':id/refund', 'v1.order.StoreOrder/terminal_order_refund')->option(['real_name' => '整单退款']);
        Route::post(':id/void', 'v1.order.StoreOrder/terminal_order_void')->option(['real_name' => '整单作废']);
        Route::post(':id/reopen', 'v1.order.StoreOrder/terminal_order_reopen')->option(['real_name' => '创建或获取重开草稿']);
        Route::get('reopen/:token', 'v1.order.StoreOrder/terminal_order_reopen_load')->option(['real_name' => '加载重开草稿']);
        
        
		// RH-GAP-WRITEOFF-BATCH-LIST（本分组前缀已是 order）
        Route::get('writeoff/batch/:id', 'v1.store.Order/writeoffBatchDetail')->option(['real_name' => '核销业务主单详情']);
        Route::put('writeoff/batch/:id/cancel', 'v1.store.Order/writeoffBatchCancel')->option(['real_name' => '核销业务主单整笔撤销']);
        Route::put('writeoff/:subOrderId/cancel', 'v1.order.StoreOrder/cancel_writeoff')->option(['real_name' => '撤销本次核销']);
        //快递公司电子面单模版
        Route::get('express/temp', 'v1.order.StoreOrder/express_temp')->option(['real_name' => '快递公司电子面单模版']);
        //获取物流信息
        Route::get('express/:id', 'v1.order.StoreOrder/get_express')->name('StoreOrderUpdateExpress')->option(['real_name' => '获取物流信息']);
        //获取物流公司
        Route::get('express_list', 'v1.order.StoreOrder/express')->name('StoreOrdeRexpressList')->option(['real_name' => '获取物流公司']);
        //订单详情
        Route::get('info/:id', 'v1.order.StoreOrder/order_info')->name('StoreOrderorInfo')->option(['real_name' => '订单详情']);
        //获取配送信息表单
        Route::get('distribution/:id', 'v1.order.StoreOrder/distribution')->name('StoreOrderorDistribution')->option(['real_name' => '获取配送信息表单']);
        //修改配送信息
        Route::put('distribution/:id', 'v1.order.StoreOrder/update_distribution')->name('StoreOrderorUpdateDistribution')->option(['real_name' => '修改配送信息']);
        //获取不退款表单
        Route::get('no_refund/:id', 'v1.order.StoreOrder/no_refund')->name('StoreOrderorNoRefund')->option(['real_name' => '获取不退款表单']);
        //修改不退款理由
        Route::put('no_refund/:id', 'v1.order.StoreOrder/update_un_refund')->name('StoreOrderorUpdateNoRefund')->option(['real_name' => '修改不退款理由']);
        //线下支付
        Route::post('pay_offline/:id', 'v1.order.StoreOrder/pay_offline')->name('StoreOrderorPayOffline')->option(['real_name' => '线下支付']);
        //获取退积分表单
        Route::get('refund_integral/:id', 'v1.order.StoreOrder/refund_integral')->name('StoreOrderorRefundIntegral')->option(['real_name' => '获取退积分表单']);
        //修改退积分
        Route::put('refund_integral/:id', 'v1.order.StoreOrder/update_refund_integral')->name('StoreOrderorUpdateRefundIntegral')->option(['real_name' => '修改退积分']);
        //修改备注信息
        Route::put('remark/:id', 'v1.order.StoreOrder/remark')->name('StoreOrderorRemark')->option(['real_name' => '修改备注信息']);
        //获取订单状态
        Route::get('status/:id', 'v1.order.StoreOrder/status')->name('StoreOrderorStatus')->option(['real_name' => '获取订单状态']);
        //删除订单单个
        Route::delete('del/:id', 'v1.order.StoreOrder/del')->name('StoreOrderorDel')->option(['real_name' => '删除订单单个']);
        //批量删除订单
        Route::post('dels', 'v1.order.StoreOrder/del_orders')->name('StoreOrderorDels')->option(['real_name' => '批量删除订单']);
        //面单默认配置信息
        Route::get('sheet_info', 'v1.order.StoreOrder/getDeliveryInfo')->option(['real_name' => '面单默认配置信息']);
        //获取线下付款二维码
        Route::get('offline_scan', 'v1.order.OtherOrder/offline_scan')->name('OfflineScan')->option(['real_name' => '获取线下付款二维码']);
        //线下收银列表
        Route::get('scan_list', 'v1.order.OtherOrder/scan_list')->name('ScanList')->option(['real_name' => '线下收银列表']);
        //发票列表头部统计
        Route::get('invoice/chart', 'v1.order.StoreOrderInvoice/chart')->name('StoreOrderorInvoiceChart')->option(['real_name' => '发票列表头部统计']);
        //申请发票列表
        Route::get('invoice/list', 'v1.order.StoreOrderInvoice/list')->name('StoreOrderorInvoiceList')->option(['real_name' => '申请发票列表']);
        //设置发票状态
        Route::post('invoice/set/:id', 'v1.order.StoreOrderInvoice/set_invoice')->name('StoreOrderorInvoiceSet')->option(['real_name' => '设置发票状态']);
        //开票订单详情
        Route::get('invoice_order_info/:id', 'v1.order.StoreOrderInvoice/orderInfo')->name('StoreOrderorInvoiceOrderInfo')->option(['real_name' => '开票订单详情']);
        //配送员列表
        Route::get('delivery/index', 'v1.order.DeliveryService/index')->option(['real_name' => '配送员列表']);
        //新增配送员选择用户列表
        Route::get('delivery/create', 'v1.order.DeliveryService/create')->option(['real_name' => '新增配送员选择用户列表']);
        //新增配送表单
        Route::get('delivery/add', 'v1.order.DeliveryService/add')->option(['real_name' => '新增配送表单']);
        //保存新建的配送员
        Route::post('delivery/save', 'v1.order.DeliveryService/save')->option(['real_name' => '保存新建的配送员']);
        //编辑配送员表单
        Route::get('delivery/:id/edit', 'v1.order.DeliveryService/edit')->option(['real_name' => '编辑配送员表单']);
        //修改配送员
        Route::put('delivery/update/:id', 'v1.order.DeliveryService/update')->option(['real_name' => '修改配送员']);
        //删除配送员
        Route::delete('delivery/del/:id', 'v1.order.DeliveryService/delete')->option(['real_name' => '删除配送员']);
        //修改配送员状态
        Route::get('delivery/set_status/:id/:status', 'v1.order.DeliveryService/set_status')->option(['real_name' => '修改配送员状态']);
        //订单列表获取配送员
        Route::get('delivery/list', 'v1.order.DeliveryService/get_delivery_list')->option(['real_name' => '订单列表获取配送员']);
        //电子面单模板列表
        Route::get('expr/temp', 'v1.order.StoreOrder/expr_temp')->option(['real_name' => '电子面单模板列表']);
        //更多操作打印电子面单
        Route::get('order_dump/:order_id', 'v1.order.StoreOrder/order_dump')->option(['real_name' => '更多操作打印电子面单']);
        //批量发货
        Route::get('hand/batch_delivery', 'v1.order.StoreOrder/hand_batch_delivery')->option(['real_name' => '批量发货']);
        //自动批量发货
        Route::post('other/batch_delivery', 'v1.order.StoreOrder/other_batch_delivery')->option(['real_name' => '自动批量发货']);
        //订单批量删除
        Route::post('batch/del_orders', 'v1.order.StoreOrder/del_orders')->option(['real_name' => '订单批量删除']);
        //订单提醒发货
        Route::put('deliver_remind/:supplier_id/:id', 'v1.supplier.StoreOrder/deliverRemind')->name('deliverRemind')->option(['real_name' => '订单提醒发货']);
        //打印配货单信息
        Route::get('distribution_info', 'v1.order.StoreOrder/distributionInfo')->name('StoreOrderDistributionInfo')->option(['real_name' => '打印配货单信息']);
        //订单核销记录
        Route::get('writeoff/records/:id', 'v1.order.StoreOrder/writeOffRecords')->name('writeOffRecords')->option(['real_name' => '订单核销记录']);
        //赠送记录
        Route::get('sendDetail/:id', 'v1.order.StoreOrder/sendDetail')->name('sendDetail')->option(['real_name' => '赠送记录']);
        //核销记录 --平台
        Route::post('writeoff/records', 'v1.order.StoreOrder/getWriteOffRecords')->name('getWriteOffRecords')->option(['real_name' => '核销记录']);
        //获取卡项权益
        Route::get('card/benefits/:id', 'v1.order.StoreOrder/getCardBenefits')->name('getCardBenefits')->option(['real_name' => '获取卡项权益']);
        //后台退款信息
        Route::get('benefits/:id', 'v1.order.StoreOrder/cardBenefits')->name('cardBenefits')->option(['real_name' => '后台退款信息']);

        //配送订单
        Route::get('delivery_order/list', 'v1.order.StoreDeliveryOrder/index')->name('StoreDeliveryOrderList')->option(['real_name' => '配送订单列表']);
        Route::get('delivery_order/info/:id', 'v1.order.StoreDeliveryOrder/detail')->name('StoreDeliveryOrderDetail')->option(['real_name' => '配送订单详情']);
        Route::get('delivery_order/cancelForm/:id', 'v1.order.StoreDeliveryOrder/cancelForm')->name('StoreDeliveryOrderCancelForm')->option(['real_name' => '配送订单取消表单']);
        Route::post('delivery_order/cancel/:id', 'v1.order.StoreDeliveryOrder/cancel')->name('StoreDeliveryOrderCancel')->option(['real_name' => '配送订单取消']);
        Route::delete('delivery_order/:id', 'v1.order.StoreDeliveryOrder/delete')->name('StoreDeliveryOrderDelete')->option(['real_name' => '配送订单删除']);

        Route::post('delivery/reassign/:id', 'v1.order.StoreOrder/reassign_delivery')->name('reassignDelivery')->option(['real_name' => '订单改派']);
        Route::post('confirm/delivery', 'v1.order.StoreOrder/confirm_delivery')->name('confirmDelivery')->option(['real_name' => '配送员确认送达']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');


    /**
     * 商品路由
     */
    Route::group('product', function () {
        // 结账来源仅允许在既有一级来源下新增、维护二级来源；不提供删除或一级来源维护入口。
        Route::get('business-config/sources', 'v1.product.CashierV3BusinessConfig/sources')->option(['real_name' => '读取结账来源设置']);
        Route::post('business-config/sources', 'v1.product.CashierV3BusinessConfig/createSecondarySource')->option(['real_name' => '新增二级结账来源']);
        Route::put('business-config/sources/:id', 'v1.product.CashierV3BusinessConfig/updateSource')->option(['real_name' => '修改结账来源']);
        Route::get('business-config/accounting-methods', 'v1.product.CashierV3BusinessConfig/accountingMethods')->option(['real_name' => '读取记账设置']);
        Route::put('business-config/accounting-methods/:code', 'v1.product.CashierV3BusinessConfig/updateAccountingMethod')->option(['real_name' => '修改记账设置']);
        Route::post('business-config/accounting-methods/restore-defaults', 'v1.product.CashierV3BusinessConfig/restoreAccountingDefaults')->option(['real_name' => '恢复记账默认名称']);
        //商品批量操作
        Route::post('batch_process', 'v1.product.StoreProduct/batchProcess')->option(['real_name' => '商品批量操作']);
        //商品导入
        Route::post('product_import', 'v1.product.StoreProduct/productImport')->option(['real_name' => '商品导入']);
        //商品分类列表
        Route::get('category', 'v1.product.StoreProductCategory/index')->option(['real_name' => '商品分类列表']);
        //用户标签（分类）树形列表
        Route::get('user_label', 'v1.user.UserLabel/tree_list')->option(['real_name' => '用户标签（分类）树形列表']);
        //商品标签（分类）树形列表
        Route::get('product_label', 'v1.product.label.StoreProductLabel/tree_list')->option(['real_name' => '用户标签（分类）树形列表']);
        Route::get('all_label', 'v1.product.label.StoreProductLabel/allLabel')->option(['real_name' => '所有的用户标签']);
        Route::get('all_ensure', 'v1.product.ensure.StoreProductEnsure/allEnsure')->option(['real_name' => '所有的保障服务']);
        Route::get('all_specs', 'v1.product.specs.StoreProductSpecs/allSpecs')->option(['real_name' => '所有的参数模版']);

        //商品分类树形列表
        Route::get('category/tree/:type', 'v1.product.StoreProductCategory/tree_list')->option(['real_name' => '商品分类树形列表']);
        //商品分类cascader行列表
        Route::get('category/cascader_list/[:type]', 'v1.product.StoreProductCategory/cascader_list')->option(['real_name' => '商品分类cascader行列表']);
        //商品分类新增表单
        Route::get('category/create', 'v1.product.StoreProductCategory/create')->option(['real_name' => '商品分类新增表单']);
        //商品分类新增
        Route::post('category', 'v1.product.StoreProductCategory/save')->option(['real_name' => '商品分类新增']);
        //商品分类编辑表单
        Route::get('category/:id', 'v1.product.StoreProductCategory/edit')->option(['real_name' => '商品分类编辑表单']);
        //商品分类编辑
        Route::put('category/:id', 'v1.product.StoreProductCategory/update')->option(['real_name' => '商品分类编辑']);
        //删除商品分类
        Route::delete('category/:id', 'v1.product.StoreProductCategory/delete')->option(['real_name' => '删除商品分类']);
        //商品分类修改状态
        Route::put('category/set_show/:id/:is_show', 'v1.product.StoreProductCategory/set_show')->option(['real_name' => '商品分类修改状态']);
        Route::put('category/set_mobile_card_show/:id/:mobile_card_show', 'v1.product.StoreProductCategory/set_mobile_card_show')->option(['real_name' => '商品分类手机端卡包展示']);
        //商品分类快捷编辑
        Route::put('category/set_category/:id', 'v1.product.StoreProductCategory/set_category')->option(['real_name' => '商品分类快捷编辑']);
        //商品列表
        Route::get('product', 'v1.product.StoreProduct/index')->option(['real_name' => '商品列表']);
        //获取退出未保存的数据
        Route::get('cache', 'v1.product.StoreProduct/getCacheData')->option(['real_name' => '获取退出未保存的数据']);
        //导入ERP商品到平台
        Route::post('import_erp_product', 'v1.product.StoreProduct/import_erp_product')->option(['real_name' => '导入ERP商品到平台']);
        //保存还未提交数据
        Route::post('cache', 'v1.product.StoreProduct/saveCacheData')->option(['real_name' => '保存还未提交数据']);
        //删除退出未保存的数据
        Route::delete('cache', 'v1.product.StoreProduct/deleteCacheData')->option(['real_name' => '删除退出未保存的数据']);
        //获取所有商品列表
        Route::get('product/list', 'v1.product.StoreProduct/search_list')->option(['real_name' => '获取所有商品列表']);
        //获取商品规格
        Route::get('product/attrs/:id/:type', 'v1.product.StoreProduct/get_attrs')->option(['real_name' => '获取商品规格']);
        //商品列表头部数据
        Route::get('product/type_header', 'v1.product.StoreProduct/type_header')->option(['real_name' => '商品列表头部数据']);
        //商品放入回收站
        Route::delete('product/:id', 'v1.product.StoreProduct/delete')->option(['real_name' => '商品放入回收站']);
		//回收站商品删除
		Route::delete('product/del/:id', 'v1.product.StoreProduct/delProduct')->option(['real_name' => '回收站商品删除']);
        //V3预约项目业绩模式与固定手工费
        Route::get('performance-rule/:id', 'v1.product.CashierV3PerformanceRule/read')->option(['real_name' => '读取项目业绩与固定手工费']);
        Route::put('performance-rule/:id', 'v1.product.CashierV3PerformanceRule/save')->option(['real_name' => '保存项目业绩与固定手工费']);
        //新建或修改商品
        Route::post('product/:id', 'v1.product.StoreProduct/save')->option(['real_name' => '新建或修改商品']);
        //修改商品状态
        Route::put('product/set_show/:id/:is_show', 'v1.product.StoreProduct/set_show')->option(['real_name' => '修改商品状态']);
        //商品快速编辑
        Route::put('product/set_product/:id', 'v1.product.StoreProduct/set_product')->option(['real_name' => '商品快速编辑']);
        //设置批量商品上架
        Route::put('product/product_show', 'v1.product.StoreProduct/product_show')->option(['real_name' => '设置批量商品上架']);
        //设置批量商品下架
        Route::put('product/product_unshow', 'v1.product.StoreProduct/product_unshow')->option(['real_name' => '设置批量商品下架']);
        //设置商品配送方式
        Route::put('product/setDeliveryType', 'v1.product.StoreProduct/setProductDeliveryType')->option(['real_name' => '设置商品配送方式']);
        //商品规则列表
        Route::get('product/rule', 'v1.product.StoreProductRule/index')->option(['real_name' => '商品规则列表']);
        //新建或编辑商品规则
        Route::post('product/rule/:id', 'v1.product.StoreProductRule/save')->option(['real_name' => '新建或编辑商品规则']);
        //商品规则详情
        Route::get('product/rule/:id', 'v1.product.StoreProductRule/read')->option(['real_name' => '商品规则详情']);
        //删除商品规则
        Route::delete('product/rule/delete/:id', 'v1.product.StoreProductRule/delete')->option(['real_name' => '删除商品规则']);
        //生成商品规格列表
        Route::post('generate_attr/:id/:type', 'v1.product.StoreProduct/is_format_attr')->option(['real_name' => '生成商品规格列表']);
        //商品评论列表
        Route::get('reply', 'v1.product.StoreProductReply/index')->option(['real_name' => '商品评论列表']);
        //商品回复评论
        Route::put('reply/set_reply/:id', 'v1.product.StoreProductReply/set_reply')->option(['real_name' => '商品回复评论']);
        //删除商品评论
        Route::delete('reply/:id', 'v1.product.StoreProductReply/delete')->option(['real_name' => '删除商品评论']);
        //获取复制商品配置
        Route::get('copy_config', 'v1.product.CopyTaobao/getConfig')->option(['real_name' => '获取复制商品配置']);
        //获取商品数据
        Route::post('crawl', 'v1.product.CopyTaobao/get_request_contents')->option(['real_name' => '获取采集商品数据']);
        //采集其他平台商品
        Route::post('copy', 'v1.product.CopyTaobao/copyProduct')->option(['real_name' => '复制其他平台商品']);
        //保存采集商品数据
        Route::post('crawl/save', 'v1.product.CopyTaobao/save_product')->option(['real_name' => '保存采集商品数据']);
        //自评表单
        Route::get('reply/fictitious_reply/:product_id', 'v1.product.StoreProductReply/fictitious_reply')->option(['real_name' => '自评表单']);
        //保存自评
        Route::post('reply/save_fictitious_reply', 'v1.product.StoreProductReply/save_fictitious_reply')->option(['real_name' => '保存自评']);
        //获取评论回复列表
        Route::get('reply/comment/:id', 'v1.product.StoreProductReply/getComment')->option(['real_name' => '获取评论回复列表']);
        //获取管理员评论回复
        Route::get('reply/get_reply/:id', 'v1.product.StoreProductReply/getReply')->option(['real_name' => '获取管理员评论回复']);
        //保存管理员回复
        Route::post('reply/save_comment/:replyId/:id', 'v1.product.StoreProductReply/saveComment')->option(['real_name' => '保存管理员回复']);
        //删除评论回复
        Route::delete('reply/delete_comment/:id', 'v1.product.StoreProductReply/deleteComment')->option(['real_name' => '删除评论回复']);
        //获取商品规则属性模板
        Route::get('product/get_rule', 'v1.product.StoreProduct/get_rule')->option(['real_name' => '获取商品规则属性模板']);
        //获取运费模板
        Route::get('product/get_template', 'v1.product.StoreProduct/get_template')->option(['real_name' => '获取运费模板']);
        //上传视频密钥接口
        Route::get('product/get_temp_keys', 'v1.product.StoreProduct/getTempKeys')->option(['real_name' => '上传视频密钥接口']);
        //检测是商品否有活动开启
        Route::get('product/check_activity/:id', 'v1.product.StoreProduct/check_activity')->option(['real_name' => '检测是商品否有活动开启']);
        //导入虚拟商品卡密
        Route::get('product/import_card', 'v1.product.StoreProduct/import_card')->option(['real_name' => '导入虚拟商品卡密']);
        //商品赠送配置
        Route::get('product/gift_config/:id', 'v1.product.ProductGift/read')->option(['real_name' => '商品赠送配置详情']);
        Route::post('product/gift_config/:id', 'v1.product.ProductGift/save')->option(['real_name' => '商品赠送配置保存']);
        //商品详情
        Route::get('product/:id', 'v1.product.StoreProduct/get_product_info')->option(['real_name' => '商品详情']);
        //获取商品规格
        Route::get('product/attrs/:id', 'v1.product.StoreProduct/getAttrs')->option(['real_name' => '获取商品规格']);
        //快速批量修改库存
        Route::put('product/saveStocks/:id', 'v1.product.StoreProduct/saveProductAttrsStock')->option(['real_name' => '快速批量修改库存']);
        //商品审核表单
        Route::get('product/verify/:id', 'v1.product.StoreProduct/verifyForm')->option(['real_name' => '商品审核表单']);
        //商品强制下架表单
        Route::get('product/remove/:id', 'v1.product.StoreProduct/removeForm')->option(['real_name' => '商品强制下架表单']);
        //商品审核
        Route::post('product/set_verify/:id', 'v1.product.StoreProduct/setVerify')->option(['real_name' => '商品审核']);
		//获取分佣/会员价
		Route::get('other_info/:id/:type', 'v1.product.StoreProduct/otherInfo')->option(['real_name' => '获取分佣/会员价']);
		//保存分佣/会员价
		Route::post('other_update/:id/:type', 'v1.product.StoreProduct/otherUpdate')->option(['real_name' => '保存分佣/会员价']);

        //商品分类列表
        Route::get('brand', 'v1.product.StoreBrand/index')->option(['real_name' => '品牌分类列表']);
        //商品品牌cascader行列表
        Route::get('brand/cascader_list/[:type]', 'v1.product.StoreBrand/cascader_list')->option(['real_name' => '商品品牌cascader行列表']);
        //商品品牌新增
        Route::post('brand', 'v1.product.StoreBrand/save')->option(['real_name' => '品牌品牌新增']);
        //商品品牌编辑
        Route::put('brand/:id', 'v1.product.StoreBrand/update')->option(['real_name' => '商品品牌编辑']);
        //删除商品品牌
        Route::delete('brand/:id', 'v1.product.StoreBrand/delete')->option(['real_name' => '删除商品品牌']);
        //商品品牌修改状态
        Route::put('brand/set_show/:id/:is_show', 'v1.product.StoreBrand/set_show')->option(['real_name' => '商品品牌修改状态']);
        //获取所有商品单位
        Route::get('get_all_unit', 'v1.product.StoreProductUnit/getAllUnit')->option(['real_name' => '获取所有商品单位']);
        //商品单位
        Route::resource('unit', 'v1.product.StoreProductUnit')->option(['real_name' => [
            'index' => '获取商品单位列表',
            'read' => '获取商品单位详情',
            'create' => '获取创建商品单位表单',
            'save' => '保存商品单位',
            'edit' => '获取修改商品单位表单',
            'update' => '修改商品单位',
            'delete' => '删除商品单位'
        ]]);
        //商品标签分类
        Route::resource('label_cate', 'v1.product.label.StoreProductLabelCate')->option(['real_name' => [
            'index' => '获取商品标签分类列表',
            'read' => '获取商品标签分类详情',
            'create' => '获取创建商品标签分类表单',
            'save' => '保存商品标签分类',
            'edit' => '获取修改商品标签分类表单',
            'update' => '修改商品标签分类',
            'delete' => '删除商品标签分类'
        ]]);

		//商品标签
		Route::get('label', 'v1.product.label.StoreProductLabel/index')->option(['real_name' => '商品标签列表']);
		Route::put('label/dels', 'v1.product.label.StoreProductLabel/del')->option(['real_name' => '批量删除商品标签']);
		Route::post('label/:id', 'v1.product.label.StoreProductLabel/save')->option(['real_name' => '保存商品标签']);
		Route::delete('label/:id', 'v1.product.label.StoreProductLabel/delete')->option(['real_name' => '删除商品标签']);
		Route::put('label/set_status/:id/:status', 'v1.product.label.StoreProductLabel/set_status')->option(['real_name' => '修改商品标签状态']);
		Route::put('label/set_show/:id/:show', 'v1.product.label.StoreProductLabel/set_show')->option(['real_name' => '修改商品标签移动端是否展示']);
		Route::get('label/form', 'v1.product.label.StoreProductLabel/getLabelForm')->option(['real_name' => '获取商品标签表单']);


        //商品保障服务
        Route::resource('ensure', 'v1.product.ensure.StoreProductEnsure')->option(['real_name' => [
            'index' => '获取商品保障服务列表',
            'read' => '获取商品保障服务详情',
            'create' => '获取创建商品保障服务表单',
            'save' => '保存商品保障服务',
            'edit' => '获取修改商品保障服务表单',
            'update' => '修改商品保障服务',
            'delete' => '删除商品保障服务'
        ]]);
        Route::put('ensure/set_show/:id/:is_show', 'v1.product.ensure.StoreProductEnsure/set_show')->option(['real_name' => '商品保障服务是否显示']);
        //商品参数
        Route::get('specs', 'v1.product.specs.StoreProductSpecs/index')->option(['real_name' => '商品参数模版列表']);
        Route::get('specs/:id', 'v1.product.specs.StoreProductSpecs/getInfo')->option(['real_name' => '获取商品参数模版详情']);
        Route::post('specs/:id', 'v1.product.specs.StoreProductSpecs/save')->option(['real_name' => '保存商品参数模版']);
        Route::delete('specs/:id', 'v1.product.specs.StoreProductSpecs/delete')->option(['real_name' => '删除商品参数模版']);

		//热词列表
		Route::get('words', 'v1.product.StoreProductWords/index')->option(['real_name' => '热词列表']);
		//所有热词
		Route::get('words/get_all', 'v1.product.StoreProductWords/getAllWords')->option(['real_name' => '所有热词']);
		//热词详情
		Route::get('words/:id', 'v1.product.StoreProductWords/info')->option(['real_name' => '商品热词编辑']);
		//热词添加、编辑
		Route::post('words/:id', 'v1.product.StoreProductWords/save')->option(['real_name' => '商品热词编辑']);
		//删除商品热词
		Route::delete('words/:id', 'v1.product.StoreProductWords/delete')->option(['real_name' => '删除商品热词']);
		//商品热词修改状态
		Route::put('words/set_show/:id/:is_show', 'v1.product.StoreProductWords/set_show')->option(['real_name' => '商品热词修改状态']);

		//库存路由
		Route::group('inventory', function () {
			//入库
			Route::get('in/order', 'v1.product.inventory.StoreProductStockInOrder/index')->option(['real_name' => '获取所有入库单列表']);
			Route::get('in/order/refundInfo', 'v1.product.inventory.StoreProductStockInOrder/getRefundOrderInfo')->option(['real_name' => '退货入库查询退款单信息']);
			Route::post('in/order/add', 'v1.product.inventory.StoreProductStockInOrder/save')->option(['real_name' => '保存入库单']);
			Route::get('in/order/info/:id', 'v1.product.inventory.StoreProductStockInOrder/info')->option(['real_name' => '获取入库单详情']);
			Route::get('in/order/remark/form/:id', 'v1.product.inventory.StoreProductStockInOrder/remarkForm')->option(['real_name' => '获取入库单备注表单']);
			Route::post('in/order/remark/:id', 'v1.product.inventory.StoreProductStockInOrder/remark')->option(['real_name' => '保存入库单备注']);
			Route::get('in/order/template', 'v1.product.inventory.StoreProductStockInOrder/downloadTemplate')->option(['real_name' => '下载入库导入模板']);
			Route::post('in/order/template', 'v1.product.inventory.StoreProductStockInOrder/downloadTemplate')->option(['real_name' => '下载入库导入模板']);
			Route::get('in/order/template/file', 'v1.product.inventory.StoreProductStockInOrder/downloadTemplateFile')->option(['real_name' => '流式下载入库导入模板']);
			Route::post('in/order/import', 'v1.product.inventory.StoreProductStockInOrder/import')->option(['real_name' => '导入入库Excel']);

			//出库
			Route::get('out/order', 'v1.product.inventory.StoreProductStockOutOrder/index')->option(['real_name' => '获取所有出库单列表']);
			Route::post('out/order/add', 'v1.product.inventory.StoreProductStockOutOrder/save')->option(['real_name' => '保存出库单']);
			Route::get('out/order/info/:id', 'v1.product.inventory.StoreProductStockOutOrder/info')->option(['real_name' => '获取出库单详情']);
			Route::get('out/order/remark/form/:id', 'v1.product.inventory.StoreProductStockOutOrder/remarkForm')->option(['real_name' => '获取出库单备注表单']);
			Route::post('out/order/remark/:id', 'v1.product.inventory.StoreProductStockOutOrder/remark')->option(['real_name' => '保存出库单备注']);
			Route::get('out/order/template', 'v1.product.inventory.StoreProductStockOutOrder/downloadTemplate')->option(['real_name' => '下载出库导入模板']);
			Route::post('out/order/template', 'v1.product.inventory.StoreProductStockOutOrder/downloadTemplate')->option(['real_name' => '下载出库导入模板']);
			Route::get('out/order/template/file', 'v1.product.inventory.StoreProductStockOutOrder/downloadTemplateFile')->option(['real_name' => '流式下载出库导入模板']);
			Route::post('out/order/import', 'v1.product.inventory.StoreProductStockOutOrder/import')->option(['real_name' => '导入出库Excel']);

			//库存盘点
			Route::get('count/list', 'v1.product.inventory.StoreProductStockCount/index')->option(['real_name' => '获取所有库存盘点列表']);
			Route::post('count/save/:id', 'v1.product.inventory.StoreProductStockCount/save')->option(['real_name' => '新增库存盘点']);
			Route::get('count/info/:id', 'v1.product.inventory.StoreProductStockCount/info')->option(['real_name' => '新增库存盘点']);
			Route::get('count/remark/form/:id', 'v1.product.inventory.StoreProductStockCount/remarkForm')->option(['real_name' => '获取出库单备注表单']);
			Route::post('count/remark/:id', 'v1.product.inventory.StoreProductStockCount/remark')->option(['real_name' => '保存出库单备注']);

			//出入库商品sku
			Route::get('productAttr/statistics', 'v1.product.inventory.StoreProductStockDetail/productAttrStatistics')->option(['real_name' => '出入库明细顶部统计']);
			Route::get('productAttr/list', 'v1.product.inventory.StoreProductStockDetail/getProductAttrList')->option(['real_name' => '出入库明细列表']);
			Route::get('productAttr/info', 'v1.product.inventory.StoreProductStockDetail/getProductAttrInfo')->option(['real_name' => '商品sku信息']);
			Route::get('productAttr/order/list', 'v1.product.inventory.StoreProductStockDetail/getProductAttrStockOrderList')->option(['real_name' => 'sku出入库明细']);

			//库存明细
			Route::get('detail/list', 'v1.product.inventory.StoreProductStockDetail/index')->option(['real_name' => '库存明细列表']);
			// Vue3 平台库存：仓库范围与批次库存均由服务端限定
			Route::get('v3/locations', 'v1.product.inventory.InventoryPlatformWarehouse/locations')->option(['real_name' => '平台库存仓库列表']);
			Route::get('v3/import/template', 'v1.product.inventory.InventoryPlatformExcelImport/template')->option(['real_name' => '平台库存导入模板']);
			Route::post('v3/import/upload', 'v1.product.inventory.InventoryPlatformExcelImport/upload')->option(['real_name' => '平台库存导入文件上传']);
			Route::post('v3/import', 'v1.product.inventory.InventoryPlatformExcelImport/import')->option(['real_name' => '平台多门店库存导入']);
			Route::post('v3/locations', 'v1.product.inventory.InventoryPlatformWarehouse/createLocation')->option(['real_name' => '平台库存创建仓库']);
			Route::get('v3/salon-usage', 'v1.product.inventory.InventoryPlatformSalonUsage/index')->option(['real_name' => '平台院装单据查询']);
			Route::get('v3/salon-usage/projects', 'v1.product.inventory.InventoryPlatformSalonUsage/projects')->option(['real_name' => '平台院装项目选择']);
			Route::get('v3/salon-usage/:id/detail', 'v1.product.inventory.InventoryPlatformSalonUsage/detail')->pattern(['id' => '\\d+'])->option(['real_name' => '平台院装单据详情']);
			Route::post('v3/salon-usage/issue', 'v1.product.inventory.InventoryPlatformSalonUsage/issue')->option(['real_name' => '平台院装领用']);
			Route::post('v3/salon-usage/return', 'v1.product.inventory.InventoryPlatformSalonUsage/returnToDefault')->option(['real_name' => '平台院装退回']);
			Route::get('v3/hq/inbound', 'v1.product.inventory.InventoryPlatformHqInbound/index')->option(['real_name' => '平台总部仓入库查询']);
			Route::get('v3/hq/inbound/:id/detail', 'v1.product.inventory.InventoryPlatformHqInbound/detail')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台总部仓入库详情']);
			Route::get('v3/hq/inbound/:id/outbound-details', 'v1.product.inventory.InventoryPlatformHqInbound/outboundDetails')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台总部仓入库批次后续出库明细']);
			Route::post('v3/hq/inbound', 'v1.product.inventory.InventoryPlatformHqInbound/create')->option(['real_name' => '平台总部仓批次入库']);
			Route::post('v3/hq/inbound/:id/void', 'v1.product.inventory.InventoryPlatformHqInbound/void')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台总部仓入库作废']);
			Route::get('v3/hq/outbound', 'v1.product.inventory.InventoryPlatformHqOutbound/index')->option(['real_name' => '平台总部仓出库查询']);
			Route::get('v3/hq/outbound/:id/detail', 'v1.product.inventory.InventoryPlatformHqOutbound/detail')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台总部仓出库详情']);
			Route::post('v3/hq/outbound', 'v1.product.inventory.InventoryPlatformHqOutbound/create')->option(['real_name' => '平台总部仓批次出库']);
			Route::post('v3/hq/outbound/:id/void', 'v1.product.inventory.InventoryPlatformHqOutbound/void')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台总部仓出库作废']);
			Route::post('v3/hq/count/confirm', 'v1.product.inventory.InventoryPlatformHqStockCount/confirm')->option(['real_name' => '平台总部仓批次库存盘点确认']);
			Route::get('v3/hq/count/:id/detail', 'v1.product.inventory.InventoryPlatformHqStockCount/detail')->pattern(['id' => '\\d+'])->option(['real_name' => '平台总部仓盘点单详情']);
			Route::get('v3/hq/request/suppliers', 'v1.product.inventory.InventoryPlatformHqStockRequest/suppliers')->option(['real_name' => '平台总部请货供货方目录']);
			Route::get('v3/hq/request/requester', 'v1.product.inventory.InventoryPlatformHqStockRequest/requester')->option(['real_name' => '平台总部请货人默认信息']);
			Route::get('v3/hq/request/parties', 'v1.product.inventory.InventoryPlatformHqStockRequest/parties')->option(['real_name' => '平台请货方选择']);
			Route::get('v3/hq/request/counterparties', 'v1.product.inventory.InventoryPlatformHqStockRequest/suppliers')->option(['real_name' => '平台总部请货供货方选择']);
			Route::get('v3/hq/request', 'v1.product.inventory.InventoryPlatformHqStockRequest/index')->option(['real_name' => '平台总部请货单查询']);
			Route::get('v3/hq/request/:id/detail', 'v1.product.inventory.InventoryPlatformHqStockRequest/detail')->pattern(['id' => '\\d+'])->option(['real_name' => '平台总部请货单详情']);
			Route::post('v3/hq/request/apply', 'v1.product.inventory.InventoryPlatformHqStockRequest/apply')->option(['real_name' => '平台总部请货申请']);
			Route::post('v3/hq/request/:id/cancel', 'v1.product.inventory.InventoryPlatformHqStockRequest/cancel')->option(['real_name' => '平台总部请货取消']);
			Route::post('v3/hq/request/:id/terminate', 'v1.product.inventory.InventoryPlatformHqStockRequest/terminate')->option(['real_name' => '平台总部请货终止剩余']);
			Route::get('v3/hq/product-summary', 'v1.product.inventory.InventoryPlatformHqReadModel/productSummary')->option(['real_name' => '平台总部库存商品汇总']);
			Route::get('v3/hq/product-summary/:productId/detail', 'v1.product.inventory.InventoryPlatformHqReadModel/productDetail')->pattern(['productId' => '\\d+'])->option(['real_name' => '平台总部库存商品详情']);
			Route::get('v3/hq/catalog', 'v1.product.inventory.InventoryPlatformHqCatalog/search')->option(['real_name' => '平台总部库存商品目录']);
			Route::get('v3/hq/catalog/barcode', 'v1.product.inventory.InventoryPlatformHqCatalog/barcode')->option(['real_name' => '平台总部库存商品精确条码查询']);
			Route::get('v3/hq/cross-transfer', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/index')->option(['real_name' => '平台总部仓跨主体调拨查询']);
			Route::get('v3/hq/cross-transfer/counterparties', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/counterparties')->option(['real_name' => '平台总部仓跨主体调拨对象']);
			Route::get('v3/hq/cross-transfer/incoming-requests', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/incomingRequests')->option(['real_name' => '平台总部仓待履约请货单']);
			Route::get('v3/hq/cross-transfer/transfer-staffs', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/transferStaffs')->option(['real_name' => '平台总部仓调拨人员候选']);
			Route::get('v3/hq/cross-transfer/:id/detail', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/detail')->option(['real_name' => '平台总部仓跨主体调拨详情']);
			Route::post('v3/hq/cross-transfer', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/create')->option(['real_name' => '平台总部仓跨主体调拨草稿']);
			Route::post('v3/hq/cross-transfer/:id/dispatch', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/dispatch')->option(['real_name' => '平台总部仓跨主体调拨发货']);
			Route::post('v3/hq/cross-transfer/:id/receive', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/receive')->option(['real_name' => '平台总部仓跨主体调拨收货']);
			Route::post('v3/hq/cross-transfer/:id/cancel', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/cancel')->option(['real_name' => '平台总部仓跨主体调拨取消']);
			Route::post('v3/hq/cross-transfer/:id/reverse', 'v1.product.inventory.InventoryPlatformHqCrossTransfer/reverse')->option(['real_name' => '平台总部仓跨主体调拨作废']);
			Route::get('v3/batch-stock', 'v1.product.inventory.InventoryPlatformWarehouse/batchStock')->option(['real_name' => '平台批次库存查询']);
			Route::get('v3/dashboard', 'v1.product.inventory.InventoryPlatformWarehouse/dashboard')->option(['real_name' => '平台库存首页权威概览']);
			Route::get('v3/presale-claims', 'v1.product.inventory.PresaleClaim/index')->option(['real_name' => '平台预售领用列表']);
			Route::get('v3/presale-claims/:id', 'v1.product.inventory.PresaleClaim/detail')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台预售领用明细']);
			Route::post('v3/presale-claims/claim', 'v1.product.inventory.PresaleClaim/claim')->option(['real_name' => '平台预售领用出库']);
			Route::post('v3/presale-claims/:id/void', 'v1.product.inventory.PresaleClaim/void')->pattern(['id' => '[A-Za-z0-9-]+'])->option(['real_name' => '平台预售领用作废']);
			Route::get('v3/unified-query/batch-stock', 'v1.product.inventory.InventoryPlatformWarehouse/unifiedBatchStock')->option(['real_name' => '平台统一查询批次库存']);
			Route::get('v3/unified-query/operational', 'v1.product.inventory.InventoryPlatformWarehouse/unifiedOperational')->option(['real_name' => '平台统一查询库存业务单据']);
			Route::get('v3/unified-query/capabilities', 'v1.product.inventory.InventoryPlatformWarehouse/unifiedCapabilities')->option(['real_name' => '平台库存统一查询能力']);
			Route::post('v3/unified-query/commands', 'v1.product.inventory.InventoryPlatformWarehouse/unifiedCommand')->option(['real_name' => '平台库存统一查询操作']);
			Route::get('v3/unified-query/export-task/:taskNo', 'v1.product.inventory.InventoryPlatformWarehouse/unifiedExportTask')->option(['real_name' => '平台库存统一查询导出任务']);
			//出入库统计
			Route::get('order/overall_statistics', 'v1.product.inventory.StoreProductStockDetail/stockOrderOverallStatistics')->option(['real_name' => '出入库顶部统计']);
			Route::get('order/statistics', 'v1.product.inventory.StoreProductStockDetail/stockOrderStatistics')->option(['real_name' => '出入库统计']);

			//请货
			Route::get('request/list', 'v1.product.inventory.StoreStockRequest/index')->option(['real_name' => '请货单列表']);
			Route::get('request/info/:id', 'v1.product.inventory.StoreStockRequest/info')->option(['real_name' => '请货单详情']);
			Route::post('request/save/:id', 'v1.product.inventory.StoreStockRequest/save')->option(['real_name' => '保存请货草稿']);
			Route::delete('request/:id', 'v1.product.inventory.StoreStockRequest/delete')->option(['real_name' => '删除请货草稿']);
			Route::post('request/confirm/:id', 'v1.product.inventory.StoreStockRequest/confirmApply')->option(['real_name' => '确认请货申请']);
			Route::post('request/reject/:id', 'v1.product.inventory.StoreStockRequest/reject')->option(['real_name' => '驳回请货']);
			Route::post('request/cancel/:id', 'v1.product.inventory.StoreStockRequest/cancel')->option(['real_name' => '取消请货']);
			Route::get('request/shared_skus', 'v1.product.inventory.StoreStockRequest/sharedSkus')->option(['real_name' => '双店同源SKU']);
			Route::get('request/pending_badge', 'v1.product.inventory.StoreStockRequest/pendingBadge')->option(['real_name' => '请货待办角标']);
			Route::post('request/retry_notice', 'v1.product.inventory.StoreStockRequest/retryNotice')->option(['real_name' => '请货通知重试']);

			//调拨
			Route::get('transfer/list', 'v1.product.inventory.StoreStockTransfer/index')->option(['real_name' => '调拨单列表']);
			Route::get('transfer/info/:id', 'v1.product.inventory.StoreStockTransfer/info')->option(['real_name' => '调拨单详情']);
			Route::post('transfer/save/:id', 'v1.product.inventory.StoreStockTransfer/save')->option(['real_name' => '保存调拨草稿']);
			Route::delete('transfer/:id', 'v1.product.inventory.StoreStockTransfer/delete')->option(['real_name' => '删除调拨草稿']);
			Route::post('transfer/cancel/:id', 'v1.product.inventory.StoreStockTransfer/cancel')->option(['real_name' => '取消调拨草稿']);
			Route::post('transfer/confirm/:id', 'v1.product.inventory.StoreStockTransfer/confirm')->option(['real_name' => '确认调拨']);
			Route::post('transfer/reverse/:id', 'v1.product.inventory.StoreStockTransfer/reverse')->option(['real_name' => '调拨冲销']);

			//院装·项目配方
			Route::get('recipe/list', 'v1.product.inventory.StoreProjectConsumableRecipe/index')->option(['real_name' => '项目配方列表']);
			Route::get('recipe/info/:id', 'v1.product.inventory.StoreProjectConsumableRecipe/info')->option(['real_name' => '项目配方详情']);
			Route::post('recipe/save/:id', 'v1.product.inventory.StoreProjectConsumableRecipe/save')->option(['real_name' => '保存项目配方']);
			Route::post('recipe/status/:id', 'v1.product.inventory.StoreProjectConsumableRecipe/setStatus')->option(['real_name' => '启用停用项目配方']);
			Route::delete('recipe/:id', 'v1.product.inventory.StoreProjectConsumableRecipe/delete')->option(['real_name' => '删除项目配方']);

			//院装·院装管理（领用/退回明细与统计）
			Route::get('salon/usage/list', 'v1.product.inventory.SalonStockReport/usageList')->option(['real_name' => '院装领用退回明细']);
			Route::get('salon/usage/statistics', 'v1.product.inventory.SalonStockReport/statistics')->option(['real_name' => '院装耗材统计']);
		});
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 用户模块 相关路由
     */
    Route::group('queue', function () {
        //队列任务列表
        Route::get('index', 'v1.other.queue.Queue/index')->option(['real_name' => '队列任务列表']);
        //队列批量发货记录
        Route::get('delivery/log/:id/:type', 'v1.other.queue.Queue/delivery_log')->option(['real_name' => '队列批量发货记录']);
        //再次执行批量队列任务
        Route::get('again/do_queue/:id/:type', 'v1.other.queue.Queue/again_do_queue')->option(['real_name' => '再次执行批量队列任务']);
        //清除异常任务队列
        Route::get('del/wrong_queue/:id/:type', 'v1.other.queue.Queue/del_wrong_queue')->option(['real_name' => '清除异常任务队列']);
        //停止队列任务
        Route::get('stop/wrong_queue/:id', 'v1.other.queue.Queue/stop_wrong_queue')->option(['real_name' => '停止队列任务']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 服务平台路由
     */
    Route::group('serve', function () {
        //一号通平台登录
        Route::post('login', 'v1.serve.Login/login')->option(['real_name' => '一号通平台登录']);
        //一号通获取验证码
        Route::post('captcha', 'v1.serve.Login/captcha')->option(['real_name' => '一号通获取验证码']);
        //一号通验证验证码
        Route::post('checkCode', 'v1.serve.Login/checkCode')->option(['real_name' => '一号通验证验证码']);
        //一号通注册
        Route::post('register', 'v1.serve.Login/register')->option(['real_name' => '一号通注册']);
        //开通电子面单
        Route::post('opn_express', 'v1.serve.Serve/openExpress')->option(['real_name' => '一号通开通电子面单']);
        //一号通账户信息
        Route::get('info', 'v1.serve.Serve/getUserInfo')->option(['real_name' => '一号通账户信息']);
        //一号通支付套餐列表
        Route::get('meal_list', 'v1.serve.Serve/mealList')->option(['real_name' => '一号通支付套餐列表']);
        //一号通支付二维码
        Route::post('pay_meal', 'v1.serve.Serve/payMeal')->option(['real_name' => '一号通支付二维码']);
        //一号通开通短信服务
        Route::get('sms/open', 'v1.serve.Sms/openServe')->option(['real_name' => '一号通开通短信服务']);
        //一号通开通其他服务
        Route::get('open', 'v1.serve.Serve/openServe')->option(['real_name' => '一号通开通其他服务']);
        //一号通修改签名
        Route::put('sms/sign', 'v1.serve.Sms/editSign')->option(['real_name' => '一号通修改签名']);
        //一号通获取短信模板
        Route::get('sms/temps', 'v1.serve.Sms/temps')->option(['real_name' => '一号通获取短信模板']);
        //一号通申请模板
        Route::post('sms/apply', 'v1.serve.Sms/apply')->option(['real_name' => '一号通申请模板']);
        //一号通获取申请记录
        Route::get('sms/apply_record', 'v1.serve.Sms/applyRecord')->option(['real_name' => '一号通获取申请记录']);
        //一号通消费记录
        Route::get('record', 'v1.serve.Serve/getRecord')->option(['real_name' => '一号通消费记录']);
        //一号通是否开启电子面单打印
        Route::get('dump_open', 'v1.serve.Export/dumpIsOpen')->name('dumpIsOpen')->option(['real_name' => '一号通是否开启电子面单打印']);
        //一号通获取全部物流公司
        Route::get('export_all', 'v1.serve.Export/getExportAll')->option(['real_name' => '一号通获取全部物流公司']);
        //一号通获取物流公司模板
        Route::get('export_temp', 'v1.serve.Export/getExportTemp')->option(['real_name' => '一号通获取物流公司模板']);
        //一号通修改密码
        Route::post('modify', 'v1.serve.Serve/modify')->option(['real_name' => '一号通修改密码']);
        //一号通修改手机号码
        Route::post('update_phone', 'v1.serve.Serve/updatePhone')->option(['real_name' => '一号通修改手机号码']);
        //一号通短信配置编辑表单
        Route::get('sms_config/edit_basics', 'v1.system.config.SystemConfig/edit_basics')->option(['real_name' => '一号通短信配置编辑表单']);
        //一号通短信配置保存数据
        Route::post('sms_config/save_basics', 'v1.system.config.SystemConfig/save_basics')->option(['real_name' => '一号通短信配置保存数据']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 系统设置维护 系统权限管理、系统菜单管理 系统配置 相关路由
     */
    Route::group('setting', function () {

        //修改管理员状态
        Route::put('set_status/:id/:status', 'v1.system.admin.SystemAdmin/set_status')->name('SystemAdminSetStatus')->option(['real_name' => '修改管理员状态']);
        //修改权限规格显示状态
        Route::put('menus/show/:id', 'v1.system.SystemMenus/show')->name('SystemMenusShow')->option(['real_name' => '修改权限规格显示状态']);
        //管理员身份列表
        Route::get('role', 'v1.system.SystemRole/index')->option(['real_name' => '管理员身份列表']);
        //管理员身份权限列表
        Route::get('role/create', 'v1.system.SystemRole/create')->option(['real_name' => '管理员身份权限列表']);
        //编辑管理员详情
        Route::get('role/:id/edit', 'v1.system.SystemRole/edit')->option(['real_name' => '编辑管理员详情']);
        //新建或编辑管理员
        Route::post('role/:id', 'v1.system.SystemRole/save')->option(['real_name' => '新建或编辑管理员']);
        //修改管理员身份状态
        Route::put('role/set_status/:id/:status', 'v1.system.SystemRole/set_status')->option(['real_name' => '修改管理员身份状态']);
        //删除管理员身份
        Route::delete('role/:id', 'v1.system.SystemRole/delete')->option(['real_name' => '删除管理员身份']);
        //修改配置分类状态
        Route::put('config_class/set_status/:id/:status', 'v1.system.config.SystemConfigTab/set_status')->option(['real_name' => '修改配置分类状态']);
        //修改配置状态
        Route::put('config/set_status/:id/:status', 'v1.system.config.SystemConfig/set_status')->option(['real_name' => '修改配置状态']);
        //基本配置编辑头部数据
        Route::get('config/header_basics', 'v1.system.config.SystemConfig/header_basics')->option(['real_name' => '基本配置编辑头部数据']);
        //基本配置编辑表单
        Route::get('config/edit_basics', 'v1.system.config.SystemConfig/edit_basics')->option(['real_name' => '基本配置编辑表单']);
        //新配置编辑表单
        
        // RH-GAP-WRITEOFF-WORKBENCH
        Route::get('writeoff-performance-mode', 'v1.system.WriteoffPerformanceMode/read')->option(['real_name' => '读取核销业绩计算方式']);
        Route::put('writeoff-performance-mode', 'v1.system.WriteoffPerformanceMode/update')->option(['real_name' => '保存核销业绩计算方式']);
        Route::get('config/edit_new_build/:type', 'v1.system.config.SystemConfig/getNewFormBuild')->option(['real_name' => '新配置编辑表单']);
        //获取置缩略图配置信息
        Route::get('config/image', 'v1.system.config.SystemConfig/getImageConfig')->option(['real_name' => '获取置缩略图配置信息']);
        //获取用户配置信息
        Route::get('config/user/:type', 'v1.system.config.SystemConfig/getUserConfig')->option(['real_name' => '获取用户配置信息']);
        //获取用户配置信息
        Route::post('config/user/:type', 'v1.system.config.SystemConfig/saveUserConfig')->option(['real_name' => '获取用户配置信息']);
        //基本配置保存数据
        Route::post('config/save_basics', 'v1.system.config.SystemConfig/save_basics')->option(['real_name' => '基本配置保存数据']);
        //获取单个配置值
        Route::get('config/get_system/:name', 'v1.system.config.SystemConfig/get_system')->option(['real_name' => '基本配置编辑表单']);
        //基本配置上传文件
        Route::post('config/upload', 'v1.system.config.SystemConfig/file_upload')->option(['real_name' => '基本配置上传文件']);
        //获取版权信息
        Route::get('get_version', 'v1.system.config.SystemConfig/getVersion')->option(['real_name' => '获取版权信息']);
        //组合数据全部
        Route::get('group_all', 'v1.system.config.SystemGroup/getGroup')->option(['real_name' => '组合数据全部']);
        //组合数据头部
        Route::get('group_data/header', 'v1.system.config.SystemGroupData/header')->option(['real_name' => '组合数据头部']);
        //修改组合数据状态
        Route::put('group_data/set_status/:id/:status', 'v1.system.config.SystemGroupData/set_status')->option(['real_name' => '修改组合数据状态']);
        //获取城市数据列表
        Route::get('city/list/:parent_id', 'v1.other.CityArea/index')->option(['real_name' => '获取城市数据列表']);
        //添加城市数据表单
        Route::get('city/add/:parent_id', 'v1.other.CityArea/add')->option(['real_name' => '添加城市数据表单']);
        //修改城市数据表单
        Route::get('city/:id/edit', 'v1.other.CityArea/edit')->option(['real_name' => '修改城市数据表单']);
        //新增/修改城市数据
        Route::post('city/save', 'v1.other.CityArea/save')->option(['real_name' => '新增/修改城市数据']);
        //删除城市数据
        Route::delete('city/del/:city_id', 'v1.other.CityArea/delete')->option(['real_name' => '删除城市数据']);
        //清除城市数据缓存
        Route::get('city/clean_cache', 'v1.other.CityArea/clean_cache')->option(['real_name' => '清除城市数据缓存']);
        //运费模板列表
        Route::get('shipping_templates/list', 'v1.product.ShippingTemplates/temp_list')->option(['real_name' => '运费模板列表']);
        //修改运费模板数据
        Route::get('shipping_templates/:id/edit', 'v1.product.ShippingTemplates/edit')->option(['real_name' => '修改运费模板数据']);
        //新增或修改运费模版
        Route::post('shipping_templates/save/:id', 'v1.product.ShippingTemplates/save')->option(['real_name' => '新增或修改运费模版']);
        //删除运费模板
        Route::delete('shipping_templates/del/:id', 'v1.product.ShippingTemplates/delete')->option(['real_name' => '删除运费模板']);
        //城市数据接口
        Route::get('shipping_templates/city_list', 'v1.product.ShippingTemplates/city_list')->option(['real_name' => '城市数据接口']);
        //获取客服广告
        Route::get('get_kf_adv', 'v1.system.config.SystemGroupData/getKfAdv')->option(['real_name' => '获取客服广告']);
        //设置客服广告
        Route::post('set_kf_adv', 'v1.system.config.SystemGroupData/setKfAdv')->option(['real_name' => '设置客服广告']);
        //获取隐私协议
        Route::get('get_user_agreement/:type', 'v1.system.config.SystemGroupData/getUserAgreement')->option(['real_name' => '获取隐私协议']);
        //设置隐私协议
        Route::post('set_user_agreement/:type', 'v1.system.config.SystemGroupData/setUserAgreement')->option(['real_name' => '设置隐私协议']);
        //签到数据头部
        Route::get('sign_data/header', 'v1.system.config.SystemGroupData/header')->option(['real_name' => '签到数据头部']);
        //修改签到数据状态
        Route::put('sign_data/set_status/:id/:status', 'v1.system.config.SystemGroupData/set_status')->option(['real_name' => '修改签到数据状态']);

        //每日签到
        //签到奖励列表
        Route::get('sign/rewards', 'v1.system.SystemSignReward/index')->option(['real_name' => '签到奖励列表']);
        //添加签到奖励
        Route::get('sign/add_rewards', 'v1.system.SystemSignReward/addRewards')->option(['real_name' => '添加签到奖励']);
        //编辑签到奖励
        Route::get('sign/edit_rewards/:id', 'v1.system.SystemSignReward/editRewards')->option(['real_name' => '保存签到奖励']);
        //保存签到奖励
        Route::post('sign/save_rewards/:id', 'v1.system.SystemSignReward/saveRewards')->option(['real_name' => '保存签到奖励']);
        //删除签到奖励
        Route::delete('sign/del_rewards/:id', 'v1.system.SystemSignReward/delRewards')->option(['real_name' => '删除签到奖励']);


        //订单数据字段
        Route::get('order_data/header', 'v1.system.config.SystemGroupData/header')->option(['real_name' => '订单数据字段']);
        //订单数据状态
        Route::put('order_data/set_status/:id/:status', 'v1.system.config.SystemGroupData/set_status')->option(['real_name' => '订单数据状态']);
        //个人中心菜单数据字段
        Route::get('usermenu_data/header', 'v1.system.config.SystemGroupData/header')->option(['real_name' => '个人中心菜单数据字段']);
        //个人中心菜单数据状态
        Route::put('usermenu_data/set_status/:id/:status', 'v1.system.config.SystemGroupData/set_status')->option(['real_name' => '个人中心菜单数据状态']);
        //分享海报数据字段
        Route::get('poster_data/header', 'v1.system.config.SystemGroupData/header')->option(['real_name' => '分享海报数据字段']);
        //分享海报数据状态
        Route::put('poster_data/set_status/:id/:status', 'v1.system.config.SystemGroupData/set_status')->option(['real_name' => '分享海报数据状态']);
        //秒杀数据字段
        Route::get('seckill_data/header', 'v1.system.config.SystemGroupData/header')->option(['real_name' => '秒杀数据字段']);
        //秒杀数据状态
        Route::put('seckill_data/set_status/:id/:status', 'v1.system.config.SystemGroupData/set_status')->option(['real_name' => '秒杀数据状态']);

        //云存储列表
        Route::get('config/storage/save_type/:type', 'v1.system.config.SystemStorage/uploadType')->name('SystemStorageUploadType')->option(['real_name' => '选择存储方式']);
        //云存储列表
        Route::get('config/storage', 'v1.system.config.SystemStorage/index')->name('SystemStorageIndex')->option(['real_name' => '云存储列表']);
        //获取云存储创建表单
        Route::get('config/storage/create/:type', 'v1.system.config.SystemStorage/create')->name('SystemStorageCreate')->option(['real_name' => '获取云存储创建表单']);
        //获取云存储配置表单
        Route::get('config/storage/form/:type', 'v1.system.config.SystemStorage/getConfigForm')->name('getConfigForm')->option(['real_name' => '获取云存储配置表单']);
        //获取云存储配置
        Route::get('config/storage/config', 'v1.system.config.SystemStorage/getConfig')->name('SystemStorageConfig')->option(['real_name' => '获取云存储配置']);
        //保存云存储配置
        Route::post('config/storage/config', 'v1.system.config.SystemStorage/saveConfig')->name('SystemStorageSaveConfig')->option(['real_name' => '保存云存储配置']);
        //同步云存储列表
        Route::put('config/storage/synch/:type', 'v1.system.config.SystemStorage/synch')->name('SystemStorageSynch')->option(['real_name' => '同步云存储列表']);
        //获取修改云存储域名表单
        Route::get('config/storage/domain/:id', 'v1.system.config.SystemStorage/getUpdateDomainForm')->name('getUpdateDomainForm')->option(['real_name' => '获取修改云存储域名表单']);
        //修改云存储域名
        Route::post('config/storage/domain/:id', 'v1.system.config.SystemStorage/updateDomain')->name('updateDomain')->option(['real_name' => '修改云存储域名']);
        //保存云存储数据
        Route::post('config/storage/:type', 'v1.system.config.SystemStorage/save')->name('SystemStorageSave')->option(['real_name' => '保存云存储数据']);
        //删除云存储
        Route::delete('config/storage/:id', 'v1.system.config.SystemStorage/delete')->name('SystemStorageDelete')->option(['real_name' => '删除云存储']);
        //修改云存储状态
        Route::put('config/storage/status/:id', 'v1.system.config.SystemStorage/status')->name('SystemStorageStatus')->option(['real_name' => '修改云存储状态']);
        //订单详情动态图配置资源
        Route::resource('order_data', 'v1.system.config.SystemGroupData')->option(['real_name' => [
            'index' => '获取订单详情动态图列表',
            'read' => '获取订单详情动态图详情',
            'create' => '获取创建订单详情动态图表单',
            'save' => '保存订单详情动态图',
            'edit' => '获取修改订单详情动态图表单',
            'update' => '修改订单详情动态图',
            'delete' => '删除订单详情动态图'
        ]]);
        //签到天数配置资源
        Route::resource('sign_data', 'v1.system.config.SystemGroupData')->option(['real_name' => [
            'index' => '获取签到天数列表',
            'read' => '获取签到天数详情',
            'create' => '获取创建签到天数表单',
            'save' => '保存签到天数',
            'edit' => '获取修改签到天数表单',
            'update' => '修改签到天数',
            'delete' => '删除签到天数'
        ]]);
        //个人中心菜单配置资源
        Route::resource('usermenu_data', 'v1.system.config.SystemGroupData')->option(['real_name' => [
            'index' => '获取个人中心菜单列表',
            'read' => '获取个人中心菜单详情',
            'create' => '获取创建个人中心菜单表单',
            'save' => '保存个人中心菜单',
            'edit' => '获取修改个人中心菜单表单',
            'update' => '修改个人中心菜单',
            'delete' => '删除个人中心菜单'
        ]]);
        //分享海报配置资源
        Route::resource('poster_data', 'v1.system.config.SystemGroupData')->option(['real_name' => [
            'index' => '获取分享海报列表',
            'read' => '获取分享海报详情',
            'create' => '获取创建分享海报表单',
            'save' => '保存分享海报',
            'edit' => '获取修改分享海报表单',
            'update' => '修改分享海报',
            'delete' => '删除分享海报'
        ]]);
        //秒杀配置资源
        Route::resource('seckill_data', 'v1.system.config.SystemGroupData')->option(['real_name' => [
            'index' => '获取秒杀配置列表',
            'read' => '获取秒杀配置详情',
            'create' => '获取创建秒杀配置表单',
            'save' => '保存秒杀配置',
            'edit' => '获取修改秒杀配置表单',
            'update' => '修改秒杀配置',
            'delete' => '删除秒杀配置'
        ]]);
        //组合数据资源路由
        Route::resource('group', 'v1.system.config.SystemGroup')->option(['real_name' => [
            'index' => '获取组合数据列表',
            'read' => '获取组合数据详情',
            'create' => '获取创建组合数据表单',
            'save' => '保存组合数据',
            'edit' => '获取修改组合数据表单',
            'update' => '修改组合数据',
            'delete' => '删除组合数据'
        ]]);
        //组合数据子数据资源路由
        Route::resource('group_data', 'v1.system.config.SystemGroupData')->option(['real_name' => [
            'index' => '获取组合数据子数据列表',
            'read' => '获取组合数据子数据详情',
            'create' => '获取创建组合数据子数据表单',
            'save' => '保存组合数据子数据',
            'edit' => '获取修改组合数据子数据表单',
            'update' => '修改组合数据子数据',
            'delete' => '删除组合数据子数据'
        ]]);
        //系统配置分类资源路由
        Route::resource('config_class', 'v1.system.config.SystemConfigTab')->option(['real_name' => [
            'index' => '获取系统配置分类列表',
            'read' => '获取系统配置分类详情',
            'create' => '获取创建系统配置分类表单',
            'save' => '保存系统配置分类',
            'edit' => '获取修改系统配置分类表单',
            'update' => '修改系统配置分类',
            'delete' => '删除系统配置分类'
        ]]);
        //系统配置资源路由
        Route::resource('config', 'v1.system.config.SystemConfig')->option(['real_name' => [
            'index' => '获取系统配置列表',
            'read' => '获取系统配置详情',
            'create' => '获取创建系统配置表单',
            'save' => '保存系统配置',
            'edit' => '获取修改系统配置表单',
            'update' => '修改系统配置',
            'delete' => '删除系统配置'
        ]]);
        //权限菜单资源路由
        Route::resource('menus', 'v1.system.SystemMenus')->option(['real_name' => [
            'index' => '获取权限菜单列表',
            'read' => '获取权限菜单详情',
            'create' => '获取权限菜单表单',
            'save' => '保存权限菜单',
            'edit' => '获取修改权限菜单表单',
            'update' => '修改权限菜单',
            'delete' => '删除权限菜单'
        ]]);
        //未添加的权限规则列表
        Route::get('ruleList', 'v1.system.SystemMenus/ruleList')->option(['real_name' => '未添加的权限规则列表']);
        //管理员资源路由
        Route::resource('admin', 'v1.system.admin.SystemAdmin')->except(['read'])->option(['real_name' => [
            'index' => '获取管理员列表',
            'read' => '获取管理员详情',
            'create' => '获取创建管理员表单',
            'save' => '保存管理员',
            'edit' => '获取修改管理员表单',
            'update' => '修改管理员',
            'delete' => '删除管理员'
        ]]);
        //系统通知
        //系统通知列表
        Route::get('notification/index', 'v1.message.SystemNotification/index')->option(['real_name' => '系统通知列表']);
        //获取单条数据
        Route::get('notification/info', 'v1.message.SystemNotification/info')->option(['real_name' => '获取单条通知数据']);
        //保存通知设置
        Route::post('notification/save', 'v1.message.SystemNotification/save')->option(['real_name' => '保存通知设置']);
        //修改消息状态
        Route::put('notification/set_status/:type/:status/:id', 'v1.message.SystemNotification/set_status')->option(['real_name' => '修改消息状态']);

        //管理员路由
        Route::resource('admin', 'v1.system.admin.SystemAdmin')->except(['read'])->option(['real_name' => '管理员']);

        //数据配置保存
        Route::post('group_data/save_all', 'v1.system.config.SystemGroupData/saveAll')->option(['real_name' => '提交数据配置']);
        //对外接口账号信息
        Route::get('system_out/index', 'v1.out.SystemOut/index')->option(['real_name' => '对外接口账号信息']);
        //对外接口账号信息详情
        Route::get('system_out/info/:id', 'v1.out.SystemOut/info')->option(['real_name' => '对外接口账号信息详情']);
        //对外接口账号添加
        Route::post('system_out/save', 'v1.out.SystemOut/save')->option(['real_name' => '对外接口账号添加']);
        //对外接口账号修改
        Route::post('system_out/update/:id', 'v1.out.SystemOut/update')->option(['real_name' => '对外接口账号修改']);
        //设置账号是否禁用
        Route::put('system_out/set_status/:id/:status', 'v1.out.SystemOut/set_status')->option(['real_name' => '设置账号是否禁用']);
        //删除账号
        Route::delete('system_out/delete/:id', 'v1.out.SystemOut/delete')->option(['real_name' => '删除账号']);

        //系统更新日志
        Route::get('changelog', 'v1.system.SystemChangelog/index')->option(['real_name' => '更新日志列表']);
        Route::post('changelog', 'v1.system.SystemChangelog/save')->option(['real_name' => '新增更新日志']);
        Route::post('changelog/upsert_release', 'v1.system.SystemChangelog/upsertRelease')->option(['real_name' => '按release_key幂等写入更新日志']);
        Route::post('changelog/copy/:id', 'v1.system.SystemChangelog/copy')->option(['real_name' => '复制更新日志为草稿']);
        Route::put('changelog/publish/:id', 'v1.system.SystemChangelog/publish')->option(['real_name' => '发布更新日志']);
        Route::put('changelog/offline/:id', 'v1.system.SystemChangelog/offline')->option(['real_name' => '下架更新日志']);
        Route::put('changelog/:id', 'v1.system.SystemChangelog/update')->option(['real_name' => '编辑更新日志']);
        Route::delete('changelog/:id', 'v1.system.SystemChangelog/delete')->option(['real_name' => '删除更新日志草稿']);
        Route::get('changelog/:id', 'v1.system.SystemChangelog/read')->option(['real_name' => '更新日志详情']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 分销管理 相关路由
     */
    Route::group('statistic', function () {
        /** 用户统计 */
        //用户基础
        Route::get('user/get_basic', 'v1.statistic.UserStatistic/getBasic')->option(['real_name' => '用户基础统计']);
        //用户增长趋势
        Route::get('user/get_trend', 'v1.statistic.UserStatistic/getTrend')->option(['real_name' => '用户增长趋势']);
        //微信用户
        Route::get('user/get_wechat', 'v1.statistic.UserStatistic/getWechat')->option(['real_name' => '微信用户统计']);
        //微信用户成长趋势
        Route::get('user/get_wechat_trend', 'v1.statistic.UserStatistic/getWechatTrend')->option(['real_name' => '微信用户成长趋势']);
        //用户地域排行
        Route::get('user/get_region', 'v1.statistic.UserStatistic/getRegion')->option(['real_name' => '用户地域排行']);
        //用户性别分布
        Route::get('user/get_sex', 'v1.statistic.UserStatistic/getSex')->option(['real_name' => '用户性别分布']);
        //用户数据导出
        Route::get('user/get_excel', 'v1.statistic.UserStatistic/getExcel')->option(['real_name' => '用户数据导出']);
        /** 商品统计 */
        //商品基础
        Route::get('product/get_basic', 'v1.statistic.ProductStatistic/getBasic')->option(['real_name' => '商品基础统计']);
        //商品趋势
        Route::get('product/get_trend', 'v1.statistic.ProductStatistic/getTrend')->option(['real_name' => '商品趋势']);
        //商品排行
        Route::get('product/get_product_ranking', 'v1.statistic.ProductStatistic/getProductRanking')->option(['real_name' => '商品排行']);
        //商品数据导出
        Route::get('product/get_excel', 'v1.statistic.ProductStatistic/getExcel')->option(['real_name' => '商品数据导出']);
        /** 交易统计 */
        //今日营业额统计
        Route::get('trade/top_trade', 'v1.statistic.TradeStatistic/topTrade')->option(['real_name' => '今日营业额统计']);
        //交易统计底部数据
        Route::get('trade/bottom_trade', 'v1.statistic.TradeStatistic/bottomTrade')->option(['real_name' => '交易统计底部数据']);

        /** 订单统计 */
        Route::group(function () {
            //订单基础
            Route::get('order/get_basic', 'v1.statistic.OrderStatistic/getBasic')->option(['real_name' => '订单基础统计']);
            //订单趋势
            Route::get('order/get_trend', 'v1.statistic.OrderStatistic/getTrend')->option(['real_name' => '订单趋势']);
            //订单来源
            Route::get('order/get_channel', 'v1.statistic.OrderStatistic/getChannel')->option(['real_name' => '订单来源']);
            //订单类型
            Route::get('order/get_type', 'v1.statistic.OrderStatistic/getType')->option(['real_name' => '订单类型']);
        })->option(['parent' => 'statistic', 'cate_name' => '订单统计']);

        /** 余额统计 */
        Route::group(function () {
            //余额基础统计
            Route::get('balance/get_basic', 'v1.statistic.BalanceStatistic/getBasic')->option(['real_name' => '余额基础统计']);
            //余额趋势
            Route::get('balance/get_trend', 'v1.statistic.BalanceStatistic/getTrend')->option(['real_name' => '余额趋势']);
            //余额来源
            Route::get('balance/get_channel', 'v1.statistic.BalanceStatistic/getChannel')->option(['real_name' => '余额来源']);
            //余额消耗
            Route::get('balance/get_type', 'v1.statistic.BalanceStatistic/getType')->option(['real_name' => '余额消耗']);
        })->option(['parent' => 'statistic', 'cate_name' => '余额统计']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 维护 相关路由
     */
    Route::group('system', function () {
        //系统日志
        Route::get('log', 'v1.system.log.SystemLog/index')->name('SystemLog')->option(['real_name' => '系统日志']);
        //系统日志管理员搜索条件
        Route::get('log/search_admin', 'v1.system.log.SystemLog/search_admin')->option(['real_name' => '系统日志管理员搜索条件']);
        //文件校验
        Route::get('file', 'v1.system.log.SystemFile/index')->name('SystemFile')->option(['real_name' => '文件校验']);
        //打开目录
        //Route::get('file/opendir', 'v1.system.log.SystemFile/opendir')->option(['real_name' => '打开目录']);
        //读取文件
        //Route::get('file/openfile', 'v1.system.log.SystemFile/openfile')->option(['real_name' => '读取文件']);
        //保存文件
        //Route::post('file/savefile', 'v1.system.log.SystemFile/savefile')->option(['real_name' => '保存文件']);
        //数据库所有表
        Route::get('backup', 'v1.system.SystemDatabackup/index')->option(['real_name' => '数据库所有表']);
        //数据备份详情
        Route::get('backup/read', 'v1.system.SystemDatabackup/read')->option(['real_name' => '数据备份详情']);
        //数据备份 优化表
        Route::put('backup/optimize', 'v1.system.SystemDatabackup/optimize')->option(['real_name' => '数据备份优化表']);
        //数据备份 修复表
        Route::put('backup/repair', 'v1.system.SystemDatabackup/repair')->option(['real_name' => '数据备份修复表']);
        //数据备份 备份表
        Route::put('backup/backup', 'v1.system.SystemDatabackup/backup')->option(['real_name' => '数据备份备份表']);
        //数据库备份记录
        Route::get('backup/file_list', 'v1.system.SystemDatabackup/fileList')->option(['real_name' => '数据库备份记录']);
        //删除数据库备份记录
        Route::delete('backup/del_file', 'v1.system.SystemDatabackup/delFile')->option(['real_name' => '删除数据库备份记录']);
        //导入数据库备份记录
        Route::post('backup/import', 'v1.system.SystemDatabackup/import')->option(['real_name' => '导入数据库备份记录']);
        //清除用户数据
        Route::get('clear/:type', 'v1.system.SystemClearData/index')->option(['real_name' => '清除用户数据']);
        //清除系统缓存
        Route::get('refresh_cache/cache', 'v1.system.log.Clear/refresh_cache')->option(['real_name' => '清除系统缓存']);
        //清除系统日志
        Route::get('refresh_cache/log', 'v1.system.log.Clear/delete_log')->option(['real_name' => '清除系统日志']);
        //域名替换接口
        Route::post('replace_site_url', 'v1.system.SystemClearData/replaceSiteUrl')->option(['real_name' => '域名替换']);
        //预热营销商品redis库存
        Route::post('hot_product_stock', 'v1.system.SystemClearData/hotProductStock')->option(['real_name' => '预热营销商品redis库存']);

        //定时任务名称及标识
        Route::get('timer/task', 'v1.system.SystemTimer/task_name')->option(['real_name' => '定时任务名称及标识']);
        //定时任务列表
        Route::get('timer/index', 'v1.system.SystemTimer/index')->option(['real_name' => '定时任务列表']);
        //定时任务删除
        Route::get('timer/del/:id', 'v1.system.SystemTimer/delete')->option(['real_name' => '定时任务删除']);
        //修改定时任务状态
        Route::get('timer/set_show/:id/:is_show', 'v1.system.SystemTimer/set_show')->option(['real_name' => '修改定时任务状态']);
        //获取定时任务信息
        Route::get('timer/one/:id', 'v1.system.SystemTimer/get_timer_one')->option(['real_name' => '获取定时任务信息']);
        //保存定时任务
        Route::post('timer/save', 'v1.system.SystemTimer/save')->option(['real_name' => '保存定时任务']);
        //更新定时任务
        Route::post('timer/update/:id', 'v1.system.SystemTimer/update')->option(['real_name' => '更新定时任务']);

        //系统表单列表
        Route::get('form/index', 'v1.system.form.SystemForm/index')->option(['real_name' => '系统表单列表']);
		//修改名称
		Route::post('form/update_name/:id', 'v1.system.form.SystemForm/updateName')->option(['real_name' => '修改表单名称']);
        //保存、更新系统表单
        Route::post('form/save/:id', 'v1.system.form.SystemForm/save')->option(['real_name' => '保存系统表单']);
        //系统表单删除
        Route::delete('form/del/:id', 'v1.system.form.SystemForm/del')->option(['real_name' => '系统表单删除']);
        //修改系统表单状态
        Route::get('form/set_show/:id/:is_show', 'v1.system.form.SystemForm/set_show')->option(['real_name' => '修改系统表单状态']);
        //获取系统表单信息
        Route::get('form/info/:id', 'v1.system.form.SystemForm/getInfo')->option(['real_name' => '获取系统表单信息']);
        //获取所有系统表单
        Route::get('form/all_system_form', 'v1.system.form.SystemForm/allSystemForm')->option(['real_name' => '获取所有系统表单']);
        //获取某个系统表单收集数据列表
        Route::get('form/data/:id', 'v1.system.form.SystemForm/formData')->option(['real_name' => '获取系统表单收集数据列表']);

		//保存自定义菜单
		Route::get('custom/menus/:id', 'v1.system.SystemCustomMenus/info')->option(['real_name' => '获取单个后台自定义菜单']);
		Route::post('custom/menus/:id', 'v1.system.SystemCustomMenus/save')->option(['real_name' => '保存后台自定义菜单']);
		Route::delete('custom/menus/:id', 'v1.system.SystemCustomMenus/delete')->option(['real_name' => '删除后台自定义菜单']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 用户模块 相关路由
     */
    Route::group('user', function () {
        // 顾客资料中的推荐人必须走 V3 受控命令；不得落回旧 user/user 直写链路。
        Route::get('v3/member/:memberId/referrer', 'v1.user.CustomerReferrerProfile/read')->option(['real_name' => '读取顾客推荐人资料']);
        Route::put('v3/member/:memberId/referrer', 'v1.user.CustomerReferrerProfile/update')->option(['real_name' => '修改顾客推荐人资料']);
        //用户导入
        Route::post('import_user', 'v1.user.UserImport/import')->option(['real_name' => '用户导入']);
        Route::post('import_user_card', 'v1.user.UserImport/importCard')->option(['real_name' => '用户卡项导入']);
        //用户导入列表
        Route::get('import_user/list', 'v1.user.UserImport/importList')->option(['real_name' => '用户导入列表']);
        //用户批量操作
        Route::post('batch_process', 'v1.user.user/batchProcess')->option(['real_name' => '用户批量操作']);
        //添加用户
        Route::post('user/save', 'v1.user.user/save_info')->option(['real_name' => '添加用户']);
        //同步微信用户
        Route::get('user/syncUsers', 'v1.user.user/syncWechatUsers')->option(['real_name' => '同步微信用户']);
        //用户列表头部数据
        Route::get('user/type_header', 'v1.user.user/type_header')->option(['real_name' => '用户列表头部数据']);
        //赠送用户等级
        Route::get('give_level/:id', 'v1.user.user/give_level')->option(['real_name' => '赠送用户等级']);
        //执行赠送用户等级
        Route::put('save_give_level/:id', 'v1.user.user/save_give_level')->option(['real_name' => '执行赠送用户等级']);
        //赠送付费会员时长
        Route::get('give_level_time/:id', 'v1.user.user/give_level_time')->option(['real_name' => '赠送付费会员时长']);
        //执行赠送付费会员时长
        Route::put('save_give_level_time/:id', 'v1.user.user/save_give_level_time')->option(['real_name' => '执行赠送付费会员时长']);
        //清除用户等级
        Route::delete('del_level/:id', 'v1.user.user/del_level')->option(['real_name' => '清除用户等级']);
        //修改积分余额表单
        Route::get('edit_other/:id', 'v1.user.user/edit_other')->option(['real_name' => '修改积分余额表单']);
        //修改积分余额
        Route::put('update_other/:id', 'v1.user.user/update_other')->option(['real_name' => '修改积分余额']);
        //修改用户本金/赠金
        Route::post('changeMoney', 'v1.user.user/changeMoney')->option(['real_name' => '修改用户本金赠金']);
        //修改用户状态
        Route::put('set_status/:status/:id', 'v1.user.user/set_status')->option(['real_name' => '修改用户状态']);
        //获取指定用户的信息
        Route::get('one_info/:id', 'v1.user.user/oneUserInfo')->option(['real_name' => '获取指定用户的信息']);
        //获取单个卡项信息
        Route::get('card_holder/:id', 'v1.user.user/cardHolder')->option(['real_name' => '卡项信息']);
        //商品浏览列表
        Route::get('visit_list/:id', 'v1.user.user/visitList')->option(['real_name' => '商品浏览列表']);
        //推荐人记录列表
        Route::get('spread_list/:id', 'v1.user.user/spreadList')->option(['real_name' => '推荐人记录列表']);
        /*会员设置模块*/
        //添加用户等级表单
        Route::get('user_level/create', 'v1.user.UserLevel/create')->option(['real_name' => '添加用户等级表单']);
        //添加或修改用户等级
        Route::post('user_level', 'v1.user.UserLevel/save')->option(['real_name' => '添加或修改用户等级']);
        //用户等级详情
        Route::get('user_level/read/:id', 'v1.user.UserLevel/read')->option(['real_name' => '用户等级详情']);
        //获取系统设置的用户等级列表
        Route::get('user_level/vip_list', 'v1.user.UserLevel/get_system_vip_list')->option(['real_name' => '获取系统设置的用户等级列表']);
        //删除用户等级
        Route::put('user_level/delete/:id', 'v1.user.UserLevel/delete')->option(['real_name' => '删除用户等级']);
        //设置用户等级上下架
        Route::put('user_level/set_show/:id/:is_show', 'v1.user.UserLevel/set_show')->option(['real_name' => '设置用户等级上下架']);
        //用户等级列表快速编辑
        Route::put('user_level/set_value/:id', 'v1.user.UserLevel/set_value')->option(['real_name' => '用户等级列表快速编辑']);
        //用户等级任务列表
        Route::get('user_level/task/:level_id', 'v1.user.UserLevel/get_task_list')->option(['real_name' => '用户等级任务列表']);
        //快速编辑用户等级任务
        Route::put('user_level/set_task/:id', 'v1.user.UserLevel/set_task_value')->option(['real_name' => '快速编辑用户等级任务']);
        //设置用户等级任务显示|隐藏
        Route::put('user_level/set_task_show/:id/:is_show', 'v1.user.UserLevel/set_task_show')->option(['real_name' => '设置用户等级任务显示|隐藏']);
        //设置用户等级任务是否务必达成
        Route::put('user_level/set_task_must/:id/:is_must', 'v1.user.UserLevel/set_task_must')->option(['real_name' => '设置用户等级任务是否务必达成']);
        //添加用户等级任务表单
        Route::get('user_level/create_task', 'v1.user.UserLevel/create_task')->option(['real_name' => '添加用户等级任务表单']);
        //保存或修改用户等级任务
        Route::post('user_level/save_task', 'v1.user.UserLevel/save_task')->option(['real_name' => '保存或修改用户等级任务']);
        //删除用户等级任务
        Route::delete('user_level/delete_task/:id', 'v1.user.UserLevel/delete_task')->option(['real_name' => '删除用户等级任务']);
        //获取用户分组列表
        Route::get('user_group/list', 'v1.user.UserGroup/index')->option(['real_name' => '获取用户分组列表']);
        //添加修改分组表单
        Route::get('user_group/add/:id', 'v1.user.UserGroup/add')->option(['real_name' => '添加修改分组表单']);
        //保存分组表单数据
        Route::post('user_group/save', 'v1.user.UserGroup/save')->option(['real_name' => '保存分组表单数据']);
        //删除用户分组数据
        Route::delete('user_group/del/:id', 'v1.user.UserGroup/delete')->option(['real_name' => '删除用户分组数据']);
        //用户分组表单
        Route::post('set_group', 'v1.user.user/set_group')->option(['real_name' => '用户分组表单']);
        //设置用户分组
        Route::put('save_set_group', 'v1.user.user/save_set_group')->option(['real_name' => '设置用户分组']);
        //用户标签列表
        Route::get('user_label', 'v1.user.UserLabel/index')->option(['real_name' => '用户标签列表']);
        //同步企业微信标签
        Route::get('synchro/work/label', 'v1.user.UserLabel/synchroWorkLabel')->option(['real_name' => '同步企业微信标签']);
        //添加或修改用户标签表单
        Route::get('user_label/add/:id', 'v1.user.UserLabel/add')->option(['real_name' => '添加或修改用户标签表单']);
        //添加或修改用户标签
        Route::post('user_label/save', 'v1.user.UserLabel/save')->option(['real_name' => '添加或修改用户标签']);
        //删除用户标签
        Route::delete('user_label/del/:id', 'v1.user.UserLabel/delete')->option(['real_name' => '删除用户标签']);
        //设置用户标签
        Route::post('set_label', 'v1.user.user/set_label')->option(['real_name' => '设置用户标签']);
        //获取用户标签
        Route::get('label/:uid', 'v1.user.UserLabel/getUserLabel')->option(['real_name' => '获取用户标签']);
        //获取用户标签分类全部
        Route::get('user_label_cate/all', 'v1.user.UserLabelCate/getAll')->option(['real_name' => '获取用户标签分类全部']);
        //设置和取消用户标签
        Route::post('label/:uid', 'v1.user.UserLabel/setUserLabel')->option(['real_name' => '设置和取消用户标签']);
        //保存用户标签
        Route::put('save_set_label', 'v1.user.user/save_set_label')->option(['real_name' => '保存用户标签']);
        //会员卡批次列表
        Route::get('member_batch/index', 'v1.user.member.MemberCardBatch/index')->option(['real_name' => '会员卡批次列表']);
        //添加会员卡批次
        Route::post('member_batch/save/:id', 'v1.user.member.MemberCardBatch/save')->option(['real_name' => '添加会员卡批次']);
        //会员卡列表
        Route::get('member_card/index/:card_batch_id', 'v1.user.member.MemberCard/index')->option(['real_name' => '会员卡列表']);
        //会员卡修改状态
        Route::get('member_card/set_status', 'v1.user.member.MemberCard/set_status')->option(['real_name' => '会员卡修改状态']);
        //会员卡批次快速修改
        Route::get('member_batch/set_value/:id', 'v1.user.member.MemberCardBatch/set_value')->option(['real_name' => '会员卡批次快速修改']);
        //会员类型列表
        Route::get('member/ship', 'v1.user.member.MemberCard/member_ship')->option(['real_name' => '会员类型列表']);
        //会员类型修改状态
        Route::get('member_ship/set_ship_status', 'v1.user.member.MemberCard/set_ship_status')->option(['real_name' => '会员类型修改状态']);
        //会员卡类型编辑
        Route::post('member_ship/save/:id', 'v1.user.member.MemberCard/ship_save')->option(['real_name' => '会员卡类型编辑']);
        //会员类型删除
        Route::delete('member_ship/delete/:id', 'v1.user.member.MemberCard/delete')->option(['real_name' => '会员类型删除']);
        //兑换会员卡二维码
        Route::get('member_scan', 'v1.user.member.MemberCardBatch/member_scan')->option(['real_name' => '兑换会员卡二维码']);
        //会员记录
        Route::get('member/record', 'v1.user.member.MemberCard/member_record')->option(['real_name' => '会员记录']);
        //会员权益列表
        Route::get('member/right', 'v1.user.member.MemberCard/member_right')->option(['real_name' => '会员权益列表']);
        //会员权益修改
        Route::post('member_right/save/:id', 'v1.user.member.MemberCard/right_save')->option(['real_name' => '会员权益修改']);
        //添加权益内容
        Route::post('member/save/content/:id', 'v1.user.member.MemberCard/right_save_content')->option(['real_name' => '添加权益内容']);
        //保存会员协议
        Route::post('member_agreement/save/:id', 'v1.user.member.MemberCardBatch/save_member_agreement')->option(['real_name' => '会员协议']);
        //获取会员协议
        Route::get('member/agreement', 'v1.user.member.MemberCardBatch/getAgreement')->option(['real_name' => '获取会员协议']);
        //会员类型select
        Route::get('member/ship_select', 'v1.user.member.MemberCard/get_ship_select')->option(['real_name' => '会员类型select']);
        //一键解绑店员
        Route::get('unbind/staff/:id', 'v1.user.User/unbindStoreStaff')->option(['real_name' => '一键解绑店员']);
       //用户补充信息表单
        Route::get('user/extend_info/:id', 'v1.user.User/extendInfoForm')->option(['real_name' => '用户补充信息表单']);
        //用户补充信息保存
        Route::post('user/extend_info/:id', 'v1.user.User/saveExtendForm')->option(['real_name' => '用户补充信息保存']);
        //用户管理资源路由
        Route::resource('user', 'v1.user.user')->option(['real_name' => [
            'index' => '获取用户列表',
            'read' => '获取用户详情',
            'create' => '获取创建用户表单',
            'save' => '保存用户',
            'edit' => '获取修改用户表单',
            'update' => '修改用户',
            'delete' => '删除用户'
        ]]);
        //标签分类
        Route::resource('user_label_cate', 'v1.user.UserLabelCate')->option(['real_name' => [
            'index' => '获取标签分类列表',
            'read' => '获取标签分类详情',
            'create' => '获取创建标签分类表单',
            'save' => '保存标签分类',
            'edit' => '获取修改标签分类表单',
            'update' => '修改标签分类',
            'delete' => '删除标签分类'
        ]]);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /**
     * 售后 相关路由
     */
    Route::group('refund', function () {
        //售后列表
        Route::get('list', 'v1.order.RefundOrder/getRefundList')->option(['real_name' => '售后订单列表']);
        //商家同意退款，等待用户退货
        Route::get('agree/:order_id', 'v1.order.RefundOrder/agreeRefund')->option(['real_name' => '商家同意退款，等待用户退货']);
        //售后订单备注
        Route::put('remark/:id', 'v1.order.RefundOrder/remark')->option(['real_name' => '售后订单备注']);
        //售后订单退款表单
        Route::get('refund/:id', 'v1.order.RefundOrder/refund')->name('StoreOrderRefund')->option(['real_name' => '售后订单退款表单']);
        //售后订单退款
        Route::put('refund/:id', 'v1.order.RefundOrder/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '售后订单退款']);
        //售后详情
        Route::get('detail/:id', 'v1.order.RefundOrder/refundDetail')->option(['real_name' => '售后订单详情']);
        //订单退款理由
        Route::get('reason', 'v1.order.RefundOrder/refund_reason')->name('orderRefundReason')->option(['real_name' => '订单退款理由']);
		//售后订单删除
		Route::delete('del/:id', 'v1.order.RefundOrder/del')->name('orderRefundDel')->option(['real_name' => '售后订单删除']);
		//售后退货物流
		Route::get('express/:id', 'v1.order.RefundOrder/getRefundExpress')->option(['real_name' => '售后退货物流']);

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 小票打印
     */
    Route::group('print', function () {
        Route::get('list', 'v1.system.SystemPrinter/index')->option(['real_name' => '小票打印列表']);
        Route::post('save/:id', 'v1.system.SystemPrinter/save')->option(['real_name' => '添加修改小票打印']);
        Route::get('set_status/:id/:status', 'v1.system.SystemPrinter/set_status')->option(['real_name' => '修改小票打印状态']);
        Route::delete('del/:id', 'v1.system.SystemPrinter/delete')->option(['real_name' => '删除小票打印']);
        Route::get('content/:id', 'v1.system.SystemPrinter/getPrintContent')->option(['real_name' => '获取小票打印详情']);
        Route::post('save_content/:id', 'v1.system.SystemPrinter/savePrintContent')->option(['real_name' => '保存小票打印详情']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    /**
     * 供应商 相关路由
     */
    Route::group('supplier', function () {
        //供应商管理员资源路由
        Route::resource('supplier', 'v1.supplier.SystemSupplier')->option(['real_name' => [
            'index' => '获取供应商列表',
            'read' => '获取供应商详情',
            'save' => '保存供应商',
            'update' => '修改供应商',
            'delete' => '删除供应商'
        ]]);
        //供应商财务接口
        //转账提交
        Route::post('extract/save_transfer/:id', 'v1.supplier.SupplierExtract/save_transfer')->option(['real_name' => '转账提交']);
        //供应商转账列表
        Route::get('extract/list', 'v1.supplier.SupplierExtract/index')->option(['real_name' => '供应商转账列表']);
        //供应商转账记录备注
        Route::post('extract/mark/:id', 'v1.supplier.SupplierExtract/mark')->option(['real_name' => '供应商转账记录备注']);
        //提现审核
        Route::post('extract/verify/:id', 'v1.supplier.SupplierExtract/verify')->option(['real_name' => '供应商转账记录备注']);
        //转账表单
        Route::get('extract/transfer/:id', 'v1.supplier.SupplierExtract/transfer')->option(['real_name' => '转账表单']);
        //转账提交
        Route::post('extract/save_transfer/:id', 'v1.supplier.SupplierExtract/save_transfer')->option(['real_name' => '转账提交']);
        //供应商流水列表
        Route::get('flowing_water/list', 'v1.supplier.SupplierFlowingWater/index')->option(['real_name' => '供应商流水列表']);
        //供应商流水备注
        Route::put('flowing_water/mark/:id', 'v1.supplier.SupplierFlowingWater/mark')->option(['real_name' => '供应商流水备注']);
        //供应商账单记录
        Route::get('flowing_water/fund_record', 'v1.supplier.SupplierFlowingWater/fundRecord')->option(['real_name' => '供应商账单记录']);
        //供应商账单详情
        Route::get('flowing_water/fund_record_info', 'v1.supplier.SupplierFlowingWater/fundRecordInfo')->option(['real_name' => '供应商账单详情']);

        /**
         * 供应商申请
         */
        Route::group('apply', function () {
            Route::get('list', 'v1.supplier.SupplierApply/index')->option(['real_name' => '供应商申请列表']);
            Route::get('info/:id', 'v1.supplier.SupplierApply/read')->option(['real_name' => '供应商申请详情']);
            Route::get('verify/form/:id', 'v1.supplier.SupplierApply/verifyForm')->option(['real_name' => '供应商申请审核表单']);
            Route::post('verify/:id', 'v1.supplier.SupplierApply/verifyApply')->option(['real_name' => '供应商申请审核']);
            Route::get('mark/form/:id', 'v1.supplier.SupplierApply/markForm')->option(['real_name' => '供应商申请备注表单']);
            Route::post('mark/:id', 'v1.supplier.SupplierApply/mark')->option(['real_name' => '供应商申请备注']);
            Route::delete('del/:id', 'v1.supplier.SupplierApply/delete')->option(['real_name' => '供应商申请删除']);
        });

        //修改供应商状态
        Route::put('supplier/set_status/:id/:status', 'v1.supplier.SystemSupplier/set_status')->option(['real_name' => '修改供应商状态']);
        //供应商快捷登录
        Route::get('supplier/login/:id', 'v1.supplier.SystemSupplier/supplierLogin')->option(['real_name' => '供应商快捷登录']);

        //供应商首页头部统计数据
        Route::get('home/header', 'v1.supplier.Common/homeStatics')->option(['real_name' => '供应商首页头部统计数据']);
        //供应商首页营业趋势图表
        Route::get('home/order', 'v1.supplier.Common/orderChart')->option(['real_name' => '供应商首页交易图表']);
        //首页订单来源分析
        Route::get('home/order_channel', 'v1.supplier.Common/orderChannel')->option(['real_name' => '订单来源分析']);
        //首页订单类型分析
        Route::get('home/order_type', 'v1.supplier.Common/orderType')->option(['real_name' => '订单订单类型分析']);
        //订单提醒发货
        Route::put('order/deliver_remind/:supplier_id/:id', 'v1.supplier.StoreOrder/deliverRemind')->name('deliverRemind')->option(['real_name' => '订单提醒发货']);
        //供应商首页数据统计
        Route::get('home/supplier', 'v1.supplier.Common/supplierChart')->option(['real_name' => '供应商首页供应商统计']);
        //供应商筛选列表
        Route::get('list', 'v1.supplier.SystemSupplier/search')->option(['real_name' => '供应商筛选列表']);
        /**
         * 订单路由
         */
        Route::group('order', function () {
            //订单顶部统计
            Route::get('chart', 'v1.supplier.StoreOrder/chart')->name('StoreOrderChart')->option(['real_name' => '订单列表顶部统计']);
			//订单列表
			Route::get('list', 'v1.supplier.StoreOrder/index')->name('StoreOrderList')->option(['real_name' => '订单列表']);
            //订单列表获取配送员
            Route::get('delivery/list', 'v1.supplier.StoreOrder/get_delivery_list')->option(['real_name' => '订单列表获取配送员']);
            //获取物流公司
            Route::get('express_list', 'v1.supplier.StoreOrder/express')->name('StoreOrdeRexpressList')->option(['real_name' => '获取物流公司']);
            //获取订单可拆分商品列表
            Route::get('split_cart_info/:id', 'v1.supplier.StoreOrder/split_cart_info')->name('StoreOrderSplitCartInfo')->option(['real_name' => '获取订单可拆分商品列表']);
            //拆单发送货
            Route::put('split_delivery/:id', 'v1.supplier.StoreOrder/split_delivery')->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);
            //面单默认配置信息
            Route::get('sheet_info', 'v1.supplier.StoreOrder/getDeliveryInfo')->option(['real_name' => '面单默认配置信息']);
            //获取物流信息
            Route::get('express/:id', 'v1.supplier.StoreOrder/get_express')->name('StoreOrderUpdateExpress')->option(['real_name' => '获取物流信息']);
            //快递公司电子面单模版
            Route::get('express/temp', 'v1.supplier.StoreOrder/express_temp')->option(['real_name' => '快递公司电子面单模版']);
            //订单发送货
            Route::put('delivery/:id', 'v1.supplier.StoreOrder/update_delivery')->name('StoreOrderUpdateDelivery')->option(['real_name' => '订单发送货']);
            //打印订单
            Route::get('print/:id', 'v1.supplier.StoreOrder/order_print')->name('StoreOrderPrint')->option(['real_name' => '打印订单']);
            //确认收货
            Route::put('take/:id', 'v1.supplier.StoreOrder/take_delivery')->name('StoreOrderTakeDelivery')->option(['real_name' => '确认收货']);
            //修改备注信息
            Route::put('remark/:id', 'v1.supplier.StoreOrder/remark')->name('StoreOrderorRemark')->option(['real_name' => '修改备注信息']);
            //获取订单状态
            Route::get('status/:id', 'v1.supplier.StoreOrder/status')->name('StoreOrderorStatus')->option(['real_name' => '获取订单状态']);
            //拆单发送货
            Route::put('split_delivery/:id', 'v1.supplier.StoreOrder/split_delivery')->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);
            //获取订单拆分子订单列表
            Route::get('split_order/:id', 'v1.supplier.StoreOrder/split_order')->name('StoreOrderSplitOrder')->option(['real_name' => '获取订单拆分子订单列表']);
            //订单退款表单
            Route::get('refund/:id', 'v1.supplier.StoreOrder/refund')->name('StoreOrderRefund')->option(['real_name' => '订单退款表单']);
            //订单退款
            Route::put('refund/:id', 'v1.supplier.StoreOrder/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '订单退款']);
            //订单详情
            Route::get('info/:id', 'v1.supplier.StoreOrder/order_info')->name('SupplierOrderInfo')->option(['real_name' => '订单详情']);
            //批量发货
            Route::get('hand/batch_delivery', 'v1.supplier.StoreOrder/hand_batch_delivery')->option(['real_name' => '批量发货']);
            //获取不退款表单
            Route::get('no_refund/:id', 'v1.supplier.StoreOrder/no_refund')->name('StoreOrderorNoRefund')->option(['real_name' => '获取不退款表单']);
            //修改不退款理由
            Route::put('no_refund/:id', 'v1.supplier.StoreOrder/update_un_refund')->name('StoreOrderorUpdateNoRefund')->option(['real_name' => '修改不退款理由']);
            //线下支付
            Route::post('pay_offline/:id', 'v1.supplier.StoreOrder/pay_offline')->name('StoreOrderorPayOffline')->option(['real_name' => '线下支付']);
            //获取退积分表单
            Route::get('refund_integral/:id', 'v1.supplier.StoreOrder/refund_integral')->name('StoreOrderorRefundIntegral')->option(['real_name' => '获取退积分表单']);
            //修改退积分
            Route::put('refund_integral/:id', 'v1.supplier.StoreOrder/update_refund_integral')->name('StoreOrderorUpdateRefundIntegral')->option(['real_name' => '修改退积分']);
            //更多操作打印电子面单
            Route::get('order_dump/:order_id', 'v1.supplier.StoreOrder/order_dump')->option(['real_name' => '更多操作打印电子面单']);
            //删除单个订单
            Route::delete('del/:id', 'v1.supplier.StoreOrder/del')->name('StoreOrderorDel')->option(['real_name' => '删除订单单个']);
            //批量删除订单
            Route::post('dels', 'v1.supplier.StoreOrder/del_orders')->name('StoreOrderorDels')->option(['real_name' => '批量删除订单']);
            //获取订单编辑表单
            Route::get('edit/:id', 'v1.supplier.StoreOrder/edit')->name('StoreOrderEdit')->option(['real_name' => '获取订单编辑表单']);
            //修改订单
            Route::put('update/:id', 'v1.supplier.StoreOrder/update')->name('StoreOrderUpdate')->option(['real_name' => '修改订单']);
            //获取配送信息表单
            Route::get('distribution/:id', 'v1.supplier.StoreOrder/distribution')->name('StoreOrderDistribution')->option(['real_name' => '获取配送信息表单']);
            //修改配送信息
            Route::put('distribution/:id', 'v1.supplier.StoreOrder/update_distribution')->name('StoreOrderUpdateDistribution')->option(['real_name' => '修改配送信息']);
            //订单核销
            Route::post('write', 'v1.supplier.StoreOrder/write_order')->name('writeOrder')->option(['real_name' => '订单核销']);
            //订单号核销
            Route::put('write_update/:order_id', 'v1.supplier.StoreOrder/wirteoff')->name('writeOrderUpdate')->option(['real_name' => '订单号核销']);
            //快递公司电子面单模版
            Route::get('express/temp', 'v1.supplier.StoreOrder/express_temp')->option(['real_name' => '快递公司电子面单模版']);

            //获取线下付款二维码
            Route::get('offline_scan', 'v1.order.OtherOrder/offline_scan')->name('OfflineScan')->option(['real_name' => '获取线下付款二维码']);
            //线下收银列表
            Route::get('scan_list', 'v1.order.OtherOrder/scan_list')->name('ScanList')->option(['real_name' => '线下收银列表']);
            //发票列表头部统计
            Route::get('invoice/chart', 'v1.order.StoreOrderInvoice/chart')->name('StoreOrderorInvoiceChart')->option(['real_name' => '发票列表头部统计']);
            //申请发票列表
            Route::get('invoice/list', 'v1.order.StoreOrderInvoice/list')->name('StoreOrderorInvoiceList')->option(['real_name' => '申请发票列表']);
            //设置发票状态
            Route::post('invoice/set/:id', 'v1.order.StoreOrderInvoice/set_invoice')->name('StoreOrderorInvoiceSet')->option(['real_name' => '设置发票状态']);
            //开票订单详情
            Route::get('invoice_order_info/:id', 'v1.order.StoreOrderInvoice/orderInfo')->name('StoreOrderorInvoiceOrderInfo')->option(['real_name' => '开票订单详情']);
            //电子面单模板列表
            Route::get('expr/temp', 'v1.order.StoreOrder/expr_temp')->option(['real_name' => '电子面单模板列表']);
            //打印配货单信息
            Route::get('distribution_info', 'v1.supplier.StoreOrder/distributionInfo')->name('StoreOrderDistributionInfo')->option(['real_name' => '打印配货单信息']);

        });
        /**
         * 售后 相关路由
         */
        Route::group('refund', function () {
            //售后列表
            Route::get('list', 'v1.supplier.RefundOrder/getRefundList')->option(['real_name' => '售后订单列表']);
            //商家同意退款，等待用户退货
            Route::get('agree/:order_id', 'v1.supplier.RefundOrder/agreeRefund')->option(['real_name' => '商家同意退款，等待用户退货']);
            //售后订单备注
            Route::put('remark/:id', 'v1.supplier.RefundOrder/remark')->option(['real_name' => '售后订单备注']);
            //售后订单退款表单
            Route::get('refund/:id', 'v1.supplier.RefundOrder/refund')->name('StoreOrderRefund')->option(['real_name' => '售后订单退款表单']);
            //售后订单退款
            Route::put('refund/:id', 'v1.supplier.RefundOrder/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '售后订单退款']);
            //售后详情
            Route::get('detail/:id', 'v1.supplier.RefundOrder/refundDetail')->option(['real_name' => '售后订单详情']);
			//售后订单删除
			Route::delete('del/:id', 'v1.supplier.RefundOrder/del')->name('orderRefundDel')->option(['real_name' => '售后订单删除']);
			//售后退货物流
			Route::get('express/:id', 'v1.supplier.RefundOrder/getRefundExpress')->option(['real_name' => '售后退货物流']);
        });

    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');
    /** 培训资料中心 */
    Route::group('training/document', function () {
        Route::get('list', 'v1.system.TrainingDocument/index')->option(['real_name' => '培训资料列表']);
        Route::post('upload', 'v1.system.TrainingDocument/upload')->option(['real_name' => '上传培训资料']);
        Route::post('save', 'v1.system.TrainingDocument/save')->option(['real_name' => '保存培训资料']);
        Route::post('status/:id', 'v1.system.TrainingDocument/status')->option(['real_name' => '更新培训资料状态']);
        Route::get('download/:id', 'v1.system.TrainingDocument/download')->option(['real_name' => '下载培训资料']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

    Route::group('fund', function () {
        Route::get('subjects', 'v1.fund.StoreFund/subjects')->option(['real_name' => '费用科目']);
        Route::post('subjects', 'v1.fund.StoreFund/saveSubject')->option(['real_name' => '保存费用科目']);
        Route::get('scope', 'v1.fund.StoreFund/scope')->option(['real_name' => '费用可选门店范围']);
        Route::get('documents', 'v1.fund.StoreFund/documents')->option(['real_name' => '费用收支单']);
        Route::get('documents/:id', 'v1.fund.StoreFund/detail')->option(['real_name' => '费用收支单详情']);
        Route::post('documents', 'v1.fund.StoreFund/save')->option(['real_name' => '保存费用收支单']);
        Route::post('documents/:id/audit', 'v1.fund.StoreFund/audit')->option(['real_name' => '审核费用收支单']);
        Route::post('documents/:id/reverse', 'v1.fund.StoreFund/reverse')->option(['real_name' => '冲销费用收支单']);
        Route::get('ledger', 'v1.fund.StoreFund/ledger')->option(['real_name' => '费用收支台账']);
        Route::get('report', 'v1.fund.StoreFund/report')->option(['real_name' => '费用专项报表']);
        Route::get('ledger/export', 'v1.fund.StoreFund/exportLedger')->option(['real_name' => '导出费用收支台账']);
        Route::get('report/export', 'v1.fund.StoreFund/exportReport')->option(['real_name' => '导出费用专项报表']);
        Route::get('export-files/:fileKey', 'v1.fund.StoreFund/exportFile')->option(['real_name' => '下载费用导出文件']);
    })->middleware([
        \app\http\middleware\admin\AdminAuthTokenMiddleware::class,
        \app\http\middleware\admin\AdminCkeckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'admin');

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
})->prefix('admin.')->middleware([
	InstallMiddleware::class,
	\app\http\middleware\AllowOriginMiddleware::class
]);
