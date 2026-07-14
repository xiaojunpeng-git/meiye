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
 * 获取产品详情
 * @param int id
 * 
 */
export function getProductDetail(id,data) {
	return request.get('product/detail/' + id, data, {
		noAuth: true
	});
}

/**
 * 获取门店产品详情
 * @param int id
 * 
 */
export function getProductStoreDetail(id,data) {
	return request.get('product/store/detail/' + id, data, {
		noAuth: true
	});
}

/**
 * 获取产品活动相关详情
 * @param int id
 * 
 */
export function getProductCtivity(id,data) {
	return request.get('product/detail/activity/' + id, data, {
		noAuth: true
	});
}

/**
 * 获取产品详情中推荐商品列表
 * @param int id
 * 
 */
export function getProductRecommend(id) {
	return request.get('product/detail/recommend/' + id, {},{
		noAuth: true
	});
}

/**
 * 产品分享二维码 推广员
 * @param int id
 */
// #ifdef H5  || APP-PLUS
export function getProductCode(id) {
	return request.get('product/code/' + id, {});
}
// #endif
// #ifdef MP
export function getProductCode(id) {
	return request.get('product/code/' + id, {
		user_type: 'routine'
	});
}
// #endif

/**
 * 添加收藏
 * @param int id
 * @param string category product=普通产品,product_seckill=秒杀产品
 */
export function collectAdd(id, category) {
	return request.post('collect/add', {
		id: id,
		'category': category === undefined ? 'product' : category
	});
}

/**
 * 删除收藏产品
 * @param int id
 * @param string category product=普通产品,product_seckill=秒杀产品
 */
export function collectDel(id, category) {
	return request.post('collect/del', {
		id: id,
		category: category === undefined ? 'product' : category
	});
}

/**
 * 购车添加
 * 
 */
export function postCartAdd(data) {
	return request.post('cart/add', data);
}

/**
 * 获取分类列表
 * 
 */
export function getCategoryList() {
	return request.get('category', {
		store_id:uni.getStorageSync('user_store_id')
	}, {
		noAuth: true
	});
}

/**
 * 商品详情diy
 * @param {*} data 
 */
export function diyProductApi() {
	return request.get('v2/diy/product_detail', {}, {
		noAuth: true
	});
}

/**
 * 获取产品列表
 * @param object data
 */
export function getProductslist(data) {
	return request.get('products', data, {
		noAuth: true
	});
}



/**
 * 获取推荐产品
 * 
 */
export function getProductHot(page, limit) {
	return request.get("product/hot", {
		page: page === undefined ? 1 : page,
		limit: limit === undefined ? 4 : limit
	}, {
		noAuth: true
	});
}
/**
 * 批量收藏
 * 
 * @param object id  产品编号 join(',') 切割成字符串
 * @param string category 
 */
export function collectAll(id, category) {
	return request.post('collect/all', {
		id: id,
		category: category === undefined ? 'product' : category
	});
}

/**
 * 首页产品的轮播图和产品信息
 * @param int type 
 * 
 */
export function getGroomList(type, data) {
	return request.get('groom/list/' + type, data, {
		noAuth: true
	});
}

/**
 * 获取收藏列表
 * @param object data
 */
export function getCollectUserList(data) {
	return request.get('collect/user', data)
}

/**
 * 获取浏览记录列表
 * @param object data
 */
export function getVisitList(data) {
	return request.get('user/visit_list', data)
}

/**
 * 获取浏览记录列表-删除
 * @param object data
 */
export function deleteVisitList(data) {
	return request.delete('user/visit', data)
}

/**
 * 获取产品评论
 * @param int id
 * @param object data
 * 
 */
export function getReplyList(id, data) {
	return request.get('v2/reply/list/' + id, data,{noAuth: true})
}

/**
 * 产品评价数量和好评度
 * @param int id
 */
export function getReplyConfig(id) {
	return request.get('reply/config/' + id,{},{noAuth: true});
}

/**
 * 评论点赞
 * @param int id
 */
export function getReplyPraise(id) {
	return request.post('reply/reply_praise/' + id);
}

/**
 * 取消评论点赞
 * @param int id
 */
export function getUnReplyPraise(id) {
	return request.post('reply/un_reply_praise/' + id);
}

/**
 * 获取评论详情
 * @param int id
 */
export function getReplyInfo(id) {
	return request.get('reply/info/' + id);
}

/**
 * 获取评论回复列表
 * @param int id
 */
export function getReplyComment(id,data) {
	return request.get('reply/comment/' + id,data);
}

/**
 * 评论回复点赞
 * @param int id
 */
export function postReplyPraise(id) {
	return request.post('reply/praise/' + id);
}

/**
 * 取消评论回复点赞
 * @param int id
 */
export function postUnReplyPraise(id) {
	return request.post('reply/un_praise/' + id);
}

/**
 * 保存商品评价回复
 * @param int id
 */
export function replyComment(id,data) {
	return request.post('reply/comment/' + id,data);
}

/**
 * 获取搜索关键字获取
 * 
 */
export function getSearchKeyword(data) {
	return request.get('search/keyword', data, {
		noAuth: true
	});
}

/**
 * 获取搜索关键字获取
 * 
 */
export function getHotWordApi() {
	return request.get('search/hot_keyword', {}, {
		noAuth: true
	});
}

/**
 * 获取搜索销量评分商品列表
 * 
 */
export function getSearchRecommendApi(type) {
	return request.get(`search/recommend/${type}`, {}, {
		noAuth: true
	});
}

/**
 * 门店列表
 * @returns {*}
 */
export function storeListApi(data) {
	return request.get("store_list", data, {
		noAuth: true
	});
}

/**
 * 套餐列表
 * @param int id
 * 
 */
export function storeDiscountsList(id) {
	return request.get('store_discounts/list/' + id, {}, {
		noAuth: true
	});
}

/**
 * 购车添加、减少、修改
 * 
*/
export function postCartNum(data) {
  return request.post('v2/set_cart_num', data);
}

/**
 * 获取首页的属性
 * @returns {*}
 */
export function getAttr(id,type) {
  return request.get("v2/get_attr/"+id+"/"+type);
}

/**
 * 获取店员推广微信会员卡二维码
 */
export function storeCardApi() {
	return request.get("store/staff/card/code");
}

/**
 * 分类列表-品牌列表
 */
export function searchFilterApi(data) {
	return request.get("search/filter",data,{
		noAuth: true
	});
}

/**
 * 分类列表-同级分类
 */
export function levelCategoryApi(data) {
	return request.get("level_category",data,{
		noAuth: true
	});
}

/**
 * 新人专享商品详情
 */
export function newcomerDetail(id) {
	return request.get("marketing/newcomer/product_detail/"+id,{},{
		noAuth: true
	});
}

/**
 * 排行榜商品分类
 */
export function rankCategoryApi() {
	return request.get("product/rank/category",{},{
		noAuth: true
	});
}

/**
 * 排行榜商品列表
 * @param {type} type 1:销量 2:评分
 * @param {data} 分页
 */
export function rankListApi(type, data) {
	return request.get(`product/rank/${type}`,data,{
		noAuth: true
	});
}

/**
 * 搭配够商品列表
 * @param {id} 商品id
 * @param {data} 分页参数
 */
export function matchPayListApi(id, data) {
	return request.get(`store_discounts/list/${id}`,data,{
		noAuth: true
	});
}

/**
 * 发起拼单
 * @param {Object} data
 */
export function initCollage(data) {
	return request.get("v2/user/initiate/collage",data);
}

/**
 * 检查用户是否发起拼单
 */
export function hasCollage() {
	return request.get("v2/is/user/initiate/collage");
}

/**
 * 检查拼单
 * @param {Object} data
 */
export function getCollage(data) {
	return request.get("v2/is/initiate/collage", data);
}

/**
 * 获取用户拼单数据
 * @param {Object} data
 */
export function getCollagePartake(data) {
	return request.get("v2/user/collage/partake", data);
}

/**
 * 复制他人拼单商品
 * @param {Object} data
 */
export function duplicateCollagePartake(data) {
	return request.get("v2/duplicate/collage/partake", data);
}

/**
 * 用户清空拼单数据
 * @param {Object} data
 */
export function emptyCollagePartake(data) {
	return request.get("v2/empty/collage/partake", data);
}

/**
 * 取消拼单
 * @param {Object} data
 */
export function cancelCollage(data) {
	return request.get("v2/user/cancel", data);
}

/**
 * 结算拼单
 * @param {Object} data
 */
export function settleCollage(data) {
	return request.get("v2/user/settle/collage", data);
}

/**
 * 验证是否在配送范围
 * @param {Object} data
 */
export function isWithin(data) {
	return request.get("v2/is/within", data);
}

/**
 * 拼单购物车列表
 * @param {Object} data
 */
export function getCollageCart(data) {
	return request.get("v2/user/initiate/collage/cart_list", data);
}

/**
 * 拼单用户购物车统计数量
 * @param {Object} data
 */
export function getCollageCount(data) {
	return request.get("v2/user/initiate/collage/count", data);
}

/**
 * 用户添加拼单商品
 * @param {Object} data
 */
export function addCollageCart(data) {
  return request.post('v2/add/collage/partake', data);
}

/**
 * 门店桌码配置
 */
export function getTableData(data) {
	return request.get("v2/table/data", data);
}

/**
 * 记录桌码
 * @param {Object} data
 */
export function addTableCode(data) {
	return request.get("v2/add/table/code", data);
}

/**
 * 检查桌码记录
 * @param {Object} data
 */
export function isTableCode(data) {
	return request.get("v2/is/table/code", data);
}

/**
 * 处理换桌商品
 * @param {Object} data
 */
export function changeTable(data) {
	return request.get("v2/changing/table", data);
}

/**
 * 桌码购物车统计数量
 * @param {Object} data
 */
export function getTableCartCount(data) {
	return request.get("v2/table/cart/count", data);
}

/**
 * 获取门店信息
 * @param {Object} data
 */
export function getStoreData(data) {
	return request.get("v2/get/store/data", data);
}

/**
 * 获取购物车
 * @param {Object} data
 */
export function getCateList(data) {
	return request.get("v2/get/cate/list", data);
}

/**
 * 用户添加桌码商品
 * @param {Object} data
 */
export function addTableCate(data) {
	return request.get("v2/add/table/cate", data);
}

/**
 * 确认下单
 * @param {Object} data
 */
export function placeOrder(data) {
	return request.get("v2/user/place/order", data);
}

/**
 * 用户清空购物车
 * @param {Object} data
 */
export function emptyTableData(data) {
	return request.get("v2/user/empty/data", data);
}

/**
 * 获取桌码数据
 * @param {Object} data
 */
export function getTablePartake(data) {
	return request.get("v2/get/table/partake", data);
}

/**
 * 获取二维码信息
 * @param {Object} data
 */
export function getCodeData(data) {
	return request.get("v2/get/code/data", data);
}

/**
 * 获取桌码记录
 * @param {Object} data
 */
export function getTableCode(data) {
	return request.get("v2/get/table/code", data);
}

/**
 * 桌码结账
 * @param {Object} data
 */
export function settleTable(data) {
	return request.get("v2/user/settle/table", data);
}

/**
 * 首页门店列表
 * @param {Object} data
 */
export function homeStoreList(data) {
	return request.get("home/store_list",data,{noAuth: true});
}

/**
 * 预约单列表
 * @param {Object} data
 */
export function storeReservationList(data) {
	return request.get("store/reservation/list", data);
}

/**
 * 管家中心状态统计
 */
export function storeReservationStatistics(data) {
	return request.get("store/reservation/statistics", data);
}

/**
 * 管家/店长修改预约
 */
export function storeReservationUpdate(id, data) {
	return request.post(`store/reservation/update/${id}`, data);
}

/**
 * 预约单详情
 * @param {Object} data
 */
export function storeReservationDetail(id) {
	return request.get(`store/reservation/detail/${id}`);
}

/**
 * 预约单详情-服务设置
 * @param {Object} data
 */
export function reservationServiceSet(id,data) {
	return request.post(`store/reservation/service/set/${id}`,data);
}

/**
 * 老师服务标签选项
 */
export function storeReservationServiceTagList() {
	return request.get('store/reservation/service/tag/list');
}

/**
 * 设置老师服务标签
 */
export function storeReservationSetServiceTag(id, data) {
	return request.post(`store/reservation/service/tag/set/${id}`, data);
}

/**
 * 管家/店长接单确认
 */
export function storeReservationConfirm(id, data = {}) {
	return request.post(`store/reservation/confirm/${id}`, data);
}

/**
 * 门店房间列表
 */
export function storeReservationTableList() {
	return request.get('store/reservation/table/list');
}

/**
 * 管家/店长拒绝预约
 */
export function storeReservationRefuse(id, data) {
	return request.post(`store/reservation/refuse/${id}`, data);
}

/**
 * 老师中心信息
 */
export function staffTeacherCenter() {
	return request.get('store/staff/teacher/center');
}

/**
 * 更新老师中心资料
 */
export function staffTeacherUpdate(data) {
	return request.post('store/staff/teacher/update', data);
}

/**
 * 老师休息列表
 */
export function staffTeacherRestList(data) {
	return request.get('store/staff/teacher/rest/list', data);
}

/**
 * 老师休息可选班次
 */
export function staffTeacherRestShiftOptions() {
	return request.get('store/staff/teacher/rest/shift_options');
}

/**
 * 添加老师休息
 */
export function staffTeacherRestSave(data) {
	return request.post('store/staff/teacher/rest/save', data);
}

/**
 * 删除老师休息
 */
export function staffTeacherRestDelete(id) {
	return request.delete(`store/staff/teacher/rest/${id}`);
}

/**
 * 卡项商品关联商品获取
 * @param {Object} id
 */
export function productCardRelated(id) {
	return request.get(`product/card/related/${id}`);
}
