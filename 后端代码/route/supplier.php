<?php


use app\http\middleware\AllowOriginMiddleware;
use app\http\middleware\InstallMiddleware;
use app\http\middleware\supplier\AuthTokenMiddleware;
use app\http\middleware\StationOpenMiddleware;
use think\facade\Config;
use think\facade\Route;
use think\Response;

/**
 * 供应商路由配置
 */
Route::group('supplierapi', function () {

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
        Route::post('login', 'Login/login')->name('login')->option(['real_name' => '账号密码登录']);
        Route::get('login/info', 'Login/info')->name('loginInfo')->option(['real_name' => '登录信息']);
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
        //退出登录
        Route::get('logout', 'Login/logOut')->option(['real_name' => '退出登录']);
        //修改密码
        Route::put('updatePwd', 'staff.StoreStaff/updateStaffPwd')->option(['real_name' => '修改密码']);
        //获取供应商信息
        Route::get('supplier', 'system.Supplier/read')->name('read')->option([['real_name' => '获取供应商信息']]);
        //更新供应商信息
        Route::put('supplier', 'system.Supplier/update')->name('update')->option([['real_name' => '更新供应商信息']]);
        //获取小票打印信息
        Route::get('printing', 'system.SupplierTicketPrint/read')->name('read')->option([['real_name' => '获取小票打印信息']]);
        //更新供应商信息
        Route::put('printing', 'system.SupplierTicketPrint/update')->name('update')->option([['real_name' => '更新小票打印']]);

        //管理员资源路由
        Route::resource('admin', 'system.SupplierAdmin')->option(['real_name' => [
            'index' => '获取管理员列表',
            'read' => '获取管理员详情',
            'create' => '获取创建管理员表单',
            'save' => '保存管理员',
            'edit' => '获取修改管理员表单',
            'update' => '修改管理员',
            'delete' => '删除管理员'
        ]]);

        //修改管理员状态
        Route::put('admin/set_status/:id/:status', 'system.SupplierAdmin/set_status')->option(['real_name' => '修改管理员状态']);
        //首页统计数据
        Route::get('home/header', 'Common/homeStatics')->option(['real_name' => '首页统计数据']);
        //首页订单图表
        Route::get('home/order', 'Common/orderChart')->option(['real_name' => '首页订单图表']);
        //订单来源分析
        Route::get('home/order_channel', 'Common/orderChannel')->option(['real_name' => '订单来源分析']);
        //订单类型分析
        Route::get('home/order_type', 'Common/orderType')->option(['real_name' => '订单订单类型分析']);
    })->middleware(AuthTokenMiddleware::class)->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');


	/**
	 * 基础管理
	 */
	Route::group('system', function () {
		//获取系统表单信息
		Route::get('form/info/:id', 'system.form.SystemForm/getInfo')->option(['real_name' => '获取系统表单信息']);
		//获取所有系统表单
		Route::get('form/all_system_form', 'system.form.SystemForm/allSystemForm')->option(['real_name' => '获取所有系统表单']);

		Route::get('config/edit_new_build/:type', 'system.Config/getFormBuild')->option(['real_name' => '供应商配置表单']);
		Route::post('config', 'system.Config/save')->option(['real_name' => '保存供应商配置']);

	})->middleware([
		AuthTokenMiddleware::class,
		\app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
	])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

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
    })->middleware([
        AuthTokenMiddleware::class,
        \app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');
	/**
	 * 财务
	 */
	Route::group('finance', function () {
		//获取供应商财务信息
		Route::get('info', 'system.Supplier/getFinanceInfo')->option(['real_name' => '获取供应商财务信息']);
		//设置供应商财务信息
		Route::post('info', 'system.Supplier/setFinanceInfo')->option(['real_name' => '设置供应商财务信息']);
		//供应商转账列表
		Route::get('supplier_extract/list', 'finance.SupplierExtract/index')->option(['real_name' => '供应商转账列表']);
		//供应商转账记录备注
		Route::post('supplier_extract/mark/:id', 'finance.SupplierExtract/mark')->option(['real_name' => '供应商转账记录备注']);
		//供应商申请转账
		Route::post('supplier_extract/cash', 'finance.SupplierExtract/cash')->option(['real_name' => '供应商申请转账']);
		//供应商流水列表
		Route::get('supplier_flowing_water/list', 'finance.SupplierFlowingWater/index')->option(['real_name' => '供应商流水列表']);
		//获取交易类型
		Route::get('supplier_flowing_water/type', 'finance.SupplierFlowingWater/getType')->option(['real_name' => '获取交易类型']);
		//供应商流水备注
		Route::post('supplier_flowing_water/mark/:id', 'finance.SupplierFlowingWater/mark')->option(['real_name' => '供应商流水备注']);
		//供应商账单记录
		Route::get('supplier_flowing_water/fund_record', 'finance.SupplierFlowingWater/fundRecord')->option(['real_name' => '供应商账单记录']);
		//供应商账单详情
		Route::get('supplier_flowing_water/fund_record_info', 'finance.SupplierFlowingWater/fundRecordInfo')->option(['real_name' => '供应商账单详情']);
	})->middleware([
		AuthTokenMiddleware::class,
		\app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
	])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');


	/**
	 * 运费模版
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

	})->middleware([
		AuthTokenMiddleware::class,
		\app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
	])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

	/**
	 * 商品
	 */
	Route::group('product', function () {
		//商品批量操作
		Route::post('batch_process', 'product.StoreProduct/batchProcess')->option(['real_name' => '商品批量操作']);
        //商品导入
        Route::post('product_import', 'product.StoreProduct/productImport')->option(['real_name' => '商品导入']);
        //商品分类列表
		Route::get('category', 'product.StoreProductCategory/index')->option(['real_name' => '商品分类列表']);

		//商品标签（分类）树形列表
		Route::get('product_label', 'product.label.StoreProductLabel/tree_list')->option(['real_name' => '用户标签（分类）树形列表']);
		Route::get('all_label', 'product.label.StoreProductLabel/allLabel')->option(['real_name' => '所有的用户标签']);
		Route::get('all_ensure', 'product.ensure.StoreProductEnsure/allEnsure')->option(['real_name' => '所有的保障服务']);
		Route::get('all_specs', 'product.specs.StoreProductSpecs/allSpecs')->option(['real_name' => '所有的参数模版']);
		Route::get('get_all_unit', 'product.StoreProductUnit/getAllUnit')->option(['real_name' => '获取所有商品单位']);

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
		//商品分类快捷编辑
		Route::put('category/set_category/:id', 'product.StoreProductCategory/set_category')->option(['real_name' => '商品分类快捷编辑']);
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

		//商品列表
		Route::get('product', 'product.StoreProduct/index')->option(['real_name' => '商品列表']);
		//新建或修改商品
		Route::post('product/:id', 'product.StoreProduct/save')->option(['real_name' => '新建或修改商品']);
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

		//库存路由
		Route::group('inventory', function () {
			//入库
			Route::get('in/order', 'product.inventory.StoreProductStockInOrder/index')->option(['real_name' => '获取所有入库单列表']);
			Route::get('in/order/refundInfo', 'product.inventory.StoreProductStockInOrder/getRefundOrderInfo')->option(['real_name' => '退货入库查询退款单信息']);
			Route::post('in/order/add', 'product.inventory.StoreProductStockInOrder/save')->option(['real_name' => '保存入库单']);
			Route::get('in/order/info/:id', 'product.inventory.StoreProductStockInOrder/info')->option(['real_name' => '获取入库单详情']);
			Route::get('in/order/remark/form/:id', 'product.inventory.StoreProductStockInOrder/remarkForm')->option(['real_name' => '获取入库单备注表单']);
			Route::post('in/order/remark/:id', 'product.inventory.StoreProductStockInOrder/remark')->option(['real_name' => '保存入库单备注']);

			//出库
			Route::get('out/order', 'product.inventory.StoreProductStockOutOrder/index')->option(['real_name' => '获取所有出库单列表']);
			Route::post('out/order/add', 'product.inventory.StoreProductStockOutOrder/save')->option(['real_name' => '保存出库单']);
			Route::get('out/order/info/:id', 'product.inventory.StoreProductStockOutOrder/info')->option(['real_name' => '获取出库单详情']);
			Route::get('out/order/remark/form/:id', 'product.inventory.StoreProductStockOutOrder/remarkForm')->option(['real_name' => '获取出库单备注表单']);
			Route::post('out/order/remark/:id', 'product.inventory.StoreProductStockOutOrder/remark')->option(['real_name' => '保存出库单备注']);

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
		});

	})->middleware([
		AuthTokenMiddleware::class,
		\app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
	])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

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
    })->middleware([AuthTokenMiddleware::class])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

    /**
     * 订单路由
     */
    Route::group('order', function () {
        //订单列表
        Route::get('list', 'Order/lst')->name('lst')->option(['real_name' => '订单列表']);
        //订单列表获取配送员
        Route::get('delivery/list', 'Order/get_delivery_list')->option(['real_name' => '订单列表获取配送员']);
        //获取物流公司
        Route::get('express_list', 'Order/express')->name('StoreOrdeRexpressList')->option(['real_name' => '获取物流公司']);
        //获取订单可拆分商品列表
        Route::get('split_cart_info/:id', 'Order/split_cart_info')->name('StoreOrderSplitCartInfo')->option(['real_name' => '获取订单可拆分商品列表']);
        //拆单发送货
        Route::put('split_delivery/:id', 'Order/split_delivery')->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);
        //面单默认配置信息
        Route::get('sheet_info', 'Order/getDeliveryInfo')->option(['real_name' => '面单默认配置信息']);
        //获取物流信息
        Route::get('express/:id', 'Order/get_express')->name('StoreOrderUpdateExpress')->option(['real_name' => '获取物流信息']);
        //快递公司电子面单模版
        Route::get('express/temp', 'Order/express_temp')->option(['real_name' => '快递公司电子面单模版']);
		//未发货订单修改地址
		Route::post('edit_address/:id', 'Order/editAddress')->option(['real_name' => '修改收货地址']);
		//订单发送货
        Route::put('delivery/:id', 'Order/update_delivery')->name('StoreOrderUpdateDelivery')->option(['real_name' => '订单发送货']);
        //打印订单
        Route::get('print/:id', 'Order/order_print')->name('StoreOrderPrint')->option(['real_name' => '打印订单']);
        //确认收货
        Route::put('take/:id', 'Order/take_delivery')->name('StoreOrderTakeDelivery')->option(['real_name' => '确认收货']);
        //修改备注信息
        Route::put('remark/:id', 'Order/remark')->name('StoreOrderorRemark')->option(['real_name' => '修改备注信息']);
        //获取订单状态
        Route::get('status/:id', 'Order/status')->name('StoreOrderorStatus')->option(['real_name' => '获取订单状态']);
        //拆单发送货
        Route::put('split_delivery/:id', 'Order/split_delivery')->name('StoreOrderSplitDelivery')->option(['real_name' => '拆单发送货']);
        //获取订单拆分子订单列表
        Route::get('split_order/:id', 'Order/split_order')->name('StoreOrderSplitOrder')->option(['real_name' => '获取订单拆分子订单列表']);
        //订单退款表单
        Route::get('refund/:id', 'Order/refund')->name('StoreOrderRefund')->option(['real_name' => '订单退款表单']);
        //订单退款
        Route::put('refund/:id', 'Order/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '订单退款']);
        //订单详情
        Route::get('info/:id', 'Order/order_info')->name('SupplierOrderInfo')->option(['real_name' => '订单详情']);
        //批量发货
        Route::get('hand/batch_delivery', 'Order/hand_batch_delivery')->option(['real_name' => '批量发货']);
        //面单默认配置信息
        Route::get('sheet_info', 'Order/getDeliveryInfo')->option(['real_name' => '面单默认配置信息']);
        //获取不退款表单
        Route::get('no_refund/:id', 'Order/no_refund')->name('StoreOrderorNoRefund')->option(['real_name' => '获取不退款表单']);
        //修改不退款理由
        Route::put('no_refund/:id', 'Order/update_un_refund')->name('StoreOrderorUpdateNoRefund')->option(['real_name' => '修改不退款理由']);
        //线下支付
        Route::post('pay_offline/:id', 'Order/pay_offline')->name('StoreOrderorPayOffline')->option(['real_name' => '线下支付']);
        //获取退积分表单
        Route::get('refund_integral/:id', 'Order/refund_integral')->name('StoreOrderorRefundIntegral')->option(['real_name' => '获取退积分表单']);
        //修改退积分
        Route::put('refund_integral/:id', 'Order/update_refund_integral')->name('StoreOrderorUpdateRefundIntegral')->option(['real_name' => '修改退积分']);
        //更多操作打印电子面单
        Route::get('order_dump/:order_id', 'Order/order_dump')->option(['real_name' => '更多操作打印电子面单']);
        //删除单个订单
        Route::delete('del/:id', 'Order/del')->name('StoreOrderorDel')->option(['real_name' => '删除订单单个']);
        //批量删除订单
        Route::post('dels', 'Order/del_orders')->name('StoreOrderorDels')->option(['real_name' => '批量删除订单']);
        //获取订单编辑表单
        Route::get('edit/:id', 'Order/edit')->name('StoreOrderEdit')->option(['real_name' => '获取订单编辑表单']);
        //修改订单
        Route::put('update/:id', 'Order/update')->name('StoreOrderUpdate')->option(['real_name' => '修改订单']);
        //获取配送信息表单
        Route::get('distribution/:id', 'Order/distribution')->name('StoreOrderDistribution')->option(['real_name' => '获取配送信息表单']);
        //修改配送信息
        Route::put('distribution/:id', 'Order/update_distribution')->name('StoreOrderUpdateDistribution')->option(['real_name' => '修改配送信息']);
        // //订单核销 TODO:供应商暂时无需核销
        // Route::post('write', 'Order/write_order')->name('writeOrder')->option(['real_name' => '订单核销']);
        // //订单号核销
        // Route::put('write_update/:order_id', 'Order/write_update')->name('writeOrderUpdate')->option(['real_name' => '订单号核销']);
        //快递公司电子面单模版
        Route::get('express/temp', 'Order/express_temp')->option(['real_name' => '快递公司电子面单模版']);
        //打印配货单信息
        Route::get('distribution_info', 'Order/distributionInfo')->name('StoreOrderDistributionInfo')->option(['real_name' => '打印配货单信息']);

        //订单核销记录
        Route::get('writeoff/records/:id', 'Order/writeOffRecords')->name('writeOffRecords')->option(['real_name' => '订单核销记录']);
    })->middleware([
        AuthTokenMiddleware::class,
        \app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

    /**
     * 导出excel相关路由
     */
    Route::group('export', function () {
        //订单
        Route::get('storeOrder', 'export.ExportExcel/storeOrder')->option(['real_name' => '订单导出']);
        //物流公司对照表导出
        Route::get('expressList', 'export.ExportExcel/expressList')->option(['real_name' => '物流公司对照表导出']);
        //导出批量发货记录
        Route::get('batchOrderDelivery/:id/:queueType/:cacheType', 'export.ExportExcel/batchOrderDelivery')->option(['real_name' => '批量发货记录导出']);
        //供应商账单导出
        Route::get('financeRecord', 'export.ExportExcel/financeRecord')->option(['real_name' => '供应商账单导出']);

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
        Route::get('productImport', 'export.ExportExcel/supplierProductImport')->option(['real_name' => '商品导出']);
        //错误记录导出
        Route::get('import/error/down', 'export.ExportExcel/importUserExport')->option(['real_name' => '错误记录导出']);

    })->middleware([
       	AuthTokenMiddleware::class,
        \app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

    /**
     * 用户模块 相关路由
     */
    Route::group('user', function () {
        //用户信息
        Route::get('user/:id', 'user.User/read')->name('read')->option(['real_name' => '用户信息']);
        //获取指定用户的信息
        Route::get('one_info/:id', 'user.User/oneUserInfo')->name('oneUserInfo')->option(['real_name' => '获取指定用户的信息']);
        //商品浏览列表
        Route::get('visit_list/:id', 'user.User/visitList')->name('visitList')->option(['real_name' => '商品浏览列表']);
        //推荐人记录列表
        Route::get('spread_list/:id', 'user.User/spreadList')->name('spreadList')->option(['real_name' => '推荐人记录列表']);
    })->middleware([
        AuthTokenMiddleware::class,
        \app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

    /**
     * 队列任务 相关路由
     */
    Route::group('queue', function () {
        //队列任务列表
        Route::get('index', 'queue.Queue/index')->name('index')->option(['real_name' => '队列任务列表']);
        //队列批量发货记录
        Route::get('delivery/log/:id/:type', 'queue.Queue/delivery_log')->option(['real_name' => '队列批量发货记录']);
        //再次执行批量队列任务
        Route::get('again/do_queue/:id/:type', 'queue.Queue/again_do_queue')->option(['real_name' => '再次执行批量队列任务']);
        //清除异常任务队列
        Route::get('del/wrong_queue/:id/:type', 'queue.Queue/del_wrong_queue')->option(['real_name' => '清除异常任务队列']);
        //停止队列任务
        Route::get('stop/wrong_queue/:id', 'queue.Queue/stop_wrong_queue')->option(['real_name' => '停止队列任务']);
    })->middleware([
        AuthTokenMiddleware::class,
        \app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

    /**
     * 售后 相关路由
     */
    Route::group('refund', function () {
        //售后列表
        Route::get('list', 'Refund/getRefundList')->option(['real_name' => '售后订单列表']);
        //商家同意退款，等待用户退货
        Route::get('agree/:order_id', 'Refund/agreeRefund')->option(['real_name' => '商家同意退款，等待用户退货']);
        //售后订单备注
        Route::put('remark/:id', 'Refund/remark')->option(['real_name' => '售后订单备注']);
        //售后订单退款表单
        Route::get('refund/:id', 'Refund/refund')->name('StoreOrderRefund')->option(['real_name' => '售后订单退款表单']);
        //售后订单退款
        Route::put('refund/:id', 'Refund/update_refund')->name('StoreOrderUpdateRefund')->option(['real_name' => '售后订单退款']);
        //售后详情
        Route::get('detail/:id', 'Refund/detail')->option(['real_name' => '售后订单详情']);
        //订单退款理由
        Route::get('reason', 'Refund/refund_reason')->name('orderRefundReason')->option(['real_name' => '订单退款理由']);
		//售后订单删除
		Route::delete('del/:id', 'Refund/del')->name('orderRefundDel')->option(['real_name' => '售后订单删除']);
		//售后退货物流
		Route::get('express/:id', 'Refund/getRefundExpress')->option(['real_name' => '售后退货物流']);
    })->middleware([
        AuthTokenMiddleware::class,
        \app\http\middleware\supplier\SupplierCheckRoleMiddleware::class
    ])->middleware(\app\http\middleware\SystemLogMiddleware::class, 'supplier');

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

})->prefix('supplier.')->middleware([
	InstallMiddleware::class,
	AllowOriginMiddleware::class,
	StationOpenMiddleware::class
]);


