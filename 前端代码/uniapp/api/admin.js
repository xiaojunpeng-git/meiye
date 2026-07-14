// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------


import request from "@/utils/request.js";

/**
 * 统计数据
 */
export function getStatisticsInfo() {
	return request.get("admin/order/statistics", {}, {
		login: true
	});
}
/**
 * 订单月统计
 */
export function getStatisticsMonth(where) {
	return request.get("admin/order/data", where, {
		login: true
	});
}
/**
 * 订单月统计
 */
export function getAdminOrderList(where) {
	return request.get("admin/order/list", where, {
		login: true
	});
}
/**
 * 门店中心-订单统计
 */
export function getStoreOrderList(where) {
	return request.get("store/order/list", where, {
		login: true
	});
}
/**
 * 平台-提醒发货
 */
export function getDelivery(id) {
	return request.get(`admin/order/delivery/remind/${id}`,{
		login: true
	});
}

/**
 * 订单改价
 */
export function setAdminOrderPrice(data) {
	return request.post("admin/order/price", data, {
		login: true
	});
}
/**
 * 门店中心-订单改价
 */
export function setStoreOrderPrice(data) {
	return request.post("store/order/price", data, {
		login: true
	});
}
/**
 * 订单备注
 */
export function setAdminOrderRemark(data) {
	return request.post("admin/order/remark", data, {
		login: true
	});
}
/**
 * 门店-订单备注
 */
export function setStoreOrderRemark(data) {
	return request.post("admin/order/remark", data, {
		login: true
	});
}
/**
 * 订单备注（退款）
 */
export function setAdminRefundRemark(data) {
	return request.post("admin/refund_order/remark", data, {
		login: true
	});
}
/**
 * 门店-订单备注（退款）
 */
export function setStoreRefundRemark(data) {
	return request.post("admin/refund_order/remark", data, {
		login: true
	});
}

/**
 * 订单详情
 */
export function getAdminOrderDetail(orderId) {
	return request.get("admin/order/detail/" + orderId, {}, {
		login: true
	});
}

/**
 * 门店-订单详情
 */
export function getStoreOrderDetail(orderId) {
	return request.get("store/order/detail/" + orderId, {}, {
		login: true
	});
}

/**
 * 订单详情(退款)
 */
export function getAdminRefundDetail(orderId) {
	return request.get("admin/refund_order/detail/" + orderId, {}, {
		login: true
	});
}

/**
 * 门店-订单详情(退款)
 */
export function getStoreRefundDetail(orderId) {
	return request.get("store/refund_order/detail/" + orderId, {}, {
		login: true
	});
}

/**
 * 订单发货信息获取
 */
export function getAdminOrderDelivery(orderId) {
	return request.get(
		"admin/order/delivery/gain/" + orderId, {}, {
			login: true
		}
	);
}

/**
 * 门店中心-订单发货信息获取
 */
export function getStoreOrderDelivery(orderId) {
	return request.get(
		"store/order/delivery/gain/" + orderId, {}, {
			login: true
		}
	);
}

/**
 * 订单发货保存
 */
export function setAdminOrderDelivery(id, data) {
	return request.post("admin/order/delivery/keep/" + id, data, {
		login: true
	});
}

/**
 * 门店-订单发货保存
 */
export function setStoreOrderDelivery(id, data) {
	return request.post("store/order/delivery/keep/" + id, data, {
		login: true
	});
}

/**
 * 订单统计图
 */
export function getStatisticsTime(data) {
	return request.get("admin/order/time", data, {
		login: true
	});
}
/**
 * 线下付款订单确认付款
 */
export function setOfflinePay(data) {
	return request.post("admin/order/offline", data, {
		login: true
	});
}
/**
 * 门店中心-线下付款订单确认付款
 */
export function setStoreOfflinePay(data) {
	return request.post("store/order/offline", data, {
		login: true
	});
}
/**
 * 订单确认退款
 */
export function setOrderRefund(data) {
	return request.post("admin/order/refund", data, {
		login: true
	});
}

/**
 * 门店中心 - 订单确认退款
 */
export function setStoreOrderRefund(data) {
	return request.post("store/order/refund", data, {
		login: true
	});
}

/**
 * 获取快递公司
 * @returns {*}
 */
export function getLogistics(data) {
	return request.get("logistics", data, {
		login: false
	});
}

/**
 * 订单核销
 * @returns {*}
 */
export function orderVerific(code, auth) {
	return request.post("admin/order/order_verific", {
		code,
		auth,
	});
}

/**
 * 门店-订单核销
 * @returns {*}
 */
export function storeOrderVerific(code, auth) {
	return request.post("store/order/order_verific", {
		code,
		auth,
	});
}

/**
 * 获取物流公司模板
 * @returns {*}
 */
export function orderExportTemp(data) {
	return request.get("admin/order/export_temp", data);
}

/**
 * 门店-获取物流公司模板
 * @returns {*}
 */
export function storeOrderExportTemp(data) {
	return request.get("store/order/export_temp", data);
}

/**
 * 获取订单打印默认配置
 * @returns {*}
 */
export function orderDeliveryInfo() {
	return request.get("admin/order/delivery_info");
}

/**
 * 门店-获取订单打印默认配置
 * @returns {*}
 */
export function storeOrderDeliveryInfo() {
	return request.get("store/order/delivery_info");
}

/**
 * 配送员列表
 * @returns {*}
 */
export function orderOrderDelivery() {
	return request.get("admin/order/delivery");
}

/**
 * 门店-配送员列表
 * @returns {*}
 */
export function storeOrderDelivery() {
	return request.get("store/order/delivery");
}


// 门店

/**
 * 店员用户信息
 */
export function userInfo() {
	return request.get("store/staff/info");
}

/**
 * 工作台-今日销售额统计
 */
export function staffStaging(data) {
	return request.get("store/staff/staging",data);
}

/**
 * 工作台-数据统计
 */
export function statisticsMenuApi(data) {
	return request.get("store/staff/statistics",data);
}

/**
 * 门店中心-订单统计
 */
export function orderInfo(data) {
	return request.get("store/order/statistics",data);
}

/**
 * 门店中心-详细数据列表
 */
export function getListApi(data) {
	return request.get("store/order/data",data);
}


/**
 * 门店中心-数据详情-列表
 */
export function getStatisticsListApi(type,data) {
	return request.get("store/staff/data/"+type,data);
}


/**
 * 配送员-获取用户信息
 */
export function deliveryInfo(id) {
	return request.get("store/delivery/info");
}


/**
 * 配送员-获取配送统计数据
 */
export function deliveryStatistics(data) {
	return request.get("store/delivery/statistics",data);
}


/**
 * 配送员-获取配送统计数据列表
 */
export function deliveryList(data) {
	return request.get("store/delivery/data",data);
}



/**
 * 配送员-获取订单列表数据列表
 */
export function deliveryOrderList(data) {
	return request.get("store/delivery/order",data);
}


/**
 * 门店中心-订单取消、删除
 */
export function OrderDel(id) {
	return request.delete("store/order/del/"+id);
}


/**
 * 门店中心-订单取消、取消
 */
export function OrderCancel(id) {
	return request.post("store/order/cancel/"+id);
}


/**
 * 配送员-扫码核销获取订单信息
 */
export function orderWriteoffInfo(type,data) {
	return request.get("store/order/writeoff_info/"+type,data);
}

/**
 * 代客下单-购物车删除
 */
export function adminCartDel(uid,data) {
	return request.delete(`admin/order/cart/del/${uid}`,data);
}

/**
 * 代客下单-修改购物车数量
 */
export function adminCartNum(uid,data) {
	return request.post(`admin/order/cart/num/${uid}`,data);
}





/**
 * 配送员-核销订单获取商品信息 (门店平台一个接口)
 */
export function orderCartInfo(data) {
	return request.post("store/order/cart_info",data);
}


/**
 * 配送员-订单核销 (门店平台一个接口)
 */
export function orderWriteoff(data) {
	return request.post("store/order/writeoff",data);
}

/**
 * 统计管理-获取订单可拆分商品列表
 */
export function orderSplitInfo(id) {
	return request.get("admin/order/split_cart_info/"+id);
}

/**
 * 门店-统计管理-获取订单可拆分商品列表
 */
export function storeOrderSplitInfo(id) {
	return request.get("store/order/split_cart_info/"+id);
}

/**
 * 统计管理-提交
 */
export function orderSplitDelivery(id,data) {
	return request.put("admin/order/split_delivery/"+id,data);
}

/**
 * 门店-统计管理-提交
 */
export function storeOrderSplitDelivery(id,data) {
	return request.put("store/order/split_delivery/"+id,data);
}

/**
 * 统计管理-退货退款
 */
export function orderRefundAgree(id) {
	return request.post("admin/order/refund_agree/"+id);
}

/**
 * 门店中心-退货退款
 */
export function storeOrderRefundAgree(id) {
	return request.post("store/order/refund_agree/"+id);
}

/**
 * 门店中心-获取订单可拆分商品列表
 */
export function storeSplitInfo(id) {
	return request.get("store/order/split_cart_info/"+id);
}

/**
 * 门店中心-提交
 */
export function storeSplitDelivery(id,data) {
	return request.put("store/order/split_delivery/"+id,data);
}

/**
 * 门店中心-退货退款
 */
export function storeRefundAgree(id) {
	return request.post("store/order/refund_agree/"+id);
}

/**
 * 平台-退款列表
 */
export function adminRefundList(data) {
	return request.get("admin/refund_order/list",data);
}

/**
 * 门店中心-退款列表
 */
export function storeRefundList(data) {
	return request.get("store/refund_order/list",data);
}

/**
 * 平台-商品列表
 */
export function adminProductList(data) {
	return request.get("admin/product/admin_list",data);
}

/**
 * 门店-商品列表
 */
export function storeProductList(data) {
	return request.get("store/product/admin_list",data);
}

/**
 * 平台-商品列表-上下架
 */
export function productSetShow(data) {
	return request.post('admin/product/set_show',data);
}

/**
 * 门店-商品列表-上下架
 */
export function storeProductSetShow(data) {
	return request.post('store/product/set_show',data);
}

/**
 * 平台-商品列表-标签
 */
export function getProductLabel() {
	return request.get('admin/product/product_label');
}

/**
 * 门店-商品列表-标签
 */
export function storeProductLabel() {
	return request.get('store/product/product_label');
}

/**
 * 平台-商品列表-提交标签/分类
 */
export function postBatchProcess(data) {
	return request.post('admin/product/batch_process',data);
}

/**
 * 门店-商品列表-提交标签/分类
 */
export function storeBatchProcess(data) {
	return request.post('store/product/batch_process',data);
}

/**
 * 平台-商品列表-修改库存价格
 */
export function postUpdateAttrs(id,data) {
	return request.post(`admin/product/update_attrs/${id}`,data);
}

/**
 * 门店-商品列表-修改库存价格
 */
export function postStoreUpdateAttrs(id,data) {
	return request.post(`store/product/update_attrs/${id}`,data);
}

/**
 * 平台-商品列表-获取规格
 */
export function getGetAttrs(id) {
	return request.get(`admin/product/get_attr/${id}`);
}

/**
 * 门店-商品列表-获取规格
 */
export function getStoreGetAttrs(id) {
	return request.get(`store/product/get_attr/${id}`);
}

/**
 * 平台-商品列表-获取分类
 */
export function getCategory() {
	return request.get(`admin/product/category`);
}

/**
 * 门店-商品列表-获取分类
 */
export function getStoreCategory(data) {
	return request.get(`store/product/category`,data);
}

/**
 * 平台-用户列表
 */
export function getUserList(data) {
	return request.get(`admin/user/list`,data);
}

/**
 * 门店-用户列表
 */
export function getStoreUserList(data) {
	return request.get(`store/user/list`,data);
}

/**
 * 平台-修改余额、积分
 */
export function postUserUpdateOther(uid,data) {
	return request.post(`admin/user/update_other/${uid}`,data);
}

/**
 * 平台-分组列表
 */
export function getGroupList() {
	return request.get(`admin/user/group/list`);
}

/**
 * 平台-门店列表
 */
export function getStoreGroupList() {
	return request.get(`store/user/group/list`);
}

/**
 * 平台-修改用户信息
 */
export function postUserUpdate(data) {
	return request.post(`admin/user/update`,data);
}

/**
 * 平台-优惠券
 */
export function getUserCoupon(data) {
	return request.get(`admin/user/coupon/grant`,data);
}

/**
 * 平台-用户标签
 */
export function getUserLabel() {
	return request.get(`admin/user/label/0`);
}

/**
 * 门店-用户标签
 */
export function getStoreUserLabel() {
	return request.get(`store/user/label/0`);
}

/**
 * 平台-等级列表
 */
export function getLevelList() {
	return request.get(`admin/user/level/list`);
}

/**
 * 门店-等级列表
 */
export function getStoreLevelList() {
	return request.get(`store/user/level/list`);
}
//用户订单列表
export function getOrderList(data) {
	return request.get(`store/user/orderList`,data);
}
export function saveUser(data) {
	return request.post(`store/user/saveUser`,data);
}
/**
 * 平台-用户详情
 */
export function getUserInfo(uid) {
	return request.get(`admin/user/info/${uid}`);
}

/**
 * 门店-用户详情
 */
export function getStoreUserInfo(uid) {
	return request.get(`store/user/info/${uid}`);
}

/**
 * 代客下单-用户列表
 */
export function adminUserList(data) {
	return request.get("admin/user/list",data);
}

/**
 * 代客下单-商品分类
 */
export function adminCategory() {
	return request.get("admin/product/category");
}

/**
 * 代客下单-商品列表
 */
export function adminProList(data) {
	return request.get("admin/product/list",data);
}

/**
 * 代客下单-加入购物车
 */
export function adminCartAdd(uid,data) {
	return request.post(`admin/order/cart/add/${uid}`,data);
}

/**
 * 代客下单-购物车列表
 */
export function adminCartList(uid,data) {
	return request.get(`admin/order/cart/${uid}`,data);
}

/**
 * 代客下单-用户地址列表
 */
export function adminUserAddressList(uid,data) {
	return request.get(`admin/user/address/list/${uid}`,data);
}

/**
 * 代客下单-订单确认
 */
export function orderConfirm(uid,data) {
	return request.post(`admin/order/confirm/${uid}`,data);
}

/**
 * 代客下单-订单计算
 */
export function postOrderComputed(key,uid,data) {
	return request.post(`admin/order/computed/${key}/${uid}`,data);
}

/**
 * 代客下单-获取用户可使用优惠券列表
 */
export function getCouponsOrderPrice(uid,data) {
	return request.get(`admin/order/coupons/${uid}`,data);
}

/**
 * 代客下单-订单查创建
 */
export function orderCreate(key,uid,data) {
	return request.post(`admin/order/create/${key}/${uid}`,data);
}

/**
 * 代客下单-订单收银台
 */
export function getCashierApi(uid,data) {
	return request.post(`admin/order/pay/${uid}`,data);
}

/**
 * 代客下单-轮询获取订单支付状态
 */
export function getPayStatusApi(data) {
	return request.get(`admin/order/pay/status`,data);
}

/**
 * 代客下单-下单订单记录
 */
export function adminOrderRecordList(data) {
	return request.get(`admin/order/place/list`,data);
}

/**
 * 工作台折线图数据统计
 */
export function getOrderChart(data) {
	return request.get('admin/order/time/chart', data);
}

/**
 * 门店工作台折线图数据统计
 */
export function getStoreChart(type,data) {
	return request.get(`store/staff/chart/${type}`, data);
}

/**
 * 工作台待发货等数据
 */
export function getOrderStaging() {
	return request.get('admin/order/staging');
}

/**
 * 工作台今日订单数等数据
 */
export function getOrderTime(data) {
	return request.get('admin/order/time', data);
}

/**
 * 拆单退款
 * @param {Object} id
 * @param {Object} data
 */
export function openRefund(id, data) {
	return request.post(`admin/order/open/refund/${id}`,data);
}

/**
 * 核销订单商品列表
 * @param {Object} data
 */
export function offOrderInfo(data) {
	return request.post(`admin/order/info`,data);
}

/**
 * 核销订单
 * @param {Object} order_id
 * @param {Object} data
 */
export function orderWirteOff(order_id, data) {
	return request.post(`admin/order/wirteoff/${order_id}`,data);
}

/**
 * 订单核销记录
 * @param {Object} id
 * @param {Object} data
 */
export function orderWirteOffRecords(id, data) {
	return request.post(`admin/order/writeoff/records/${id}`, data);
}

/**
 * 门店-订单核销记录
 * @param {Object} id
 * @param {Object} data
 */
export function storeWirteOffRecords(id, data) {
	return request.post(`store/order/writeoff/records/${id}`, data);
}

/**
 * 确认订单详情（是否显示快递配送）
 * @param {Object} data
 */
export function check_shipping(data) {
	return request.post(`admin/order/check_shipping`, data);
}

/**
 * 配送员确认送达
 * @param {Object} data
 */
export function confirmDelivery(data) {
	return request.post('admin/order/confirm/delivery', data);
}

/**
 * 区域头部统计
 * @param {Object} data
 */
export function getAgentHeader(data) {
	return request.get('agent/home/header', data);
}

/**
 * 区域代理商销售额、订单量、客单价趋势统计
 * @param {Object} data
 */
export function getAgentOrder(data) {
	return request.get('agent/home/order', data);
}

/**
 * 区域代理商门店交易排行、占比统计
 * @param {Object} data
 */
export function getAgentStore(data) {
	return request.get('agent/home/store', data);
}

/**
 * 区域代理商所有门店
 * @param {Object} data
 */
export function getStoreList(data) {
	return request.get('agent/store/list', data);
}

/**
 * 商家管理鉴权信息
 * @param {Object} data
 */
export function getServiceInfo() {
	return request.get('admin/service/info');
}

export function orderAllCartApi(uid, data) {
	return request.get(`admin/order/all/cart/${uid}`, data);
}

export function storeDeliveryListApi(data) {
	return request.get('store/delivery/search/list', data);
}

export function orderReassignDeliveryApi(id,data) {
	return request.post(`admin/order/reassign/delivery/${id}`, data);
}

/**
 * 管理员操作重新发单
 * @param {Object} id
 * @param {Object} data
 */
export function orderReissueOrderApi(id, data) {
	return request.get(`admin/order/reissue_order/${id}`, data);
}
