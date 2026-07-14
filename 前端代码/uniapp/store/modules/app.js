// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import { getUserInfo } from "@/api/user.js";
import { diyProductApi } from "@/api/store.js"
import { getAgentBrokerage } from "@/api/public.js"
import {
	LOGIN_STATUS,
	NON_WIFI_AUTOPLAY,
	UID,
	USER_INFO,
	STORE_NUM,
	STORE_STAFF_INFO,
} from '@/config/cache';
import Cache from '@/utils/cache';

const state = {
	token: Cache.get(LOGIN_STATUS) || false,
	backgroundColor: "#fff",
	userInfo: Cache.get(USER_INFO) || {},
	uid: Cache.get(UID) || 0,
	homeActive: false,
	phoneStatus: true,
	pageFooter: uni.getStorageSync('pageFoot') || {},
	autoplay: Cache.get(NON_WIFI_AUTOPLAY) || false,
	// 商品详情可视化数据
	diyProduct: {
		navList: [0, 1, 2, 3, 4], // 顶部菜单内容
		openShare: 1, //是否开启分享
		pictureConfig: 0, //轮播图模式 0 固定方图 1 高度自适应
		swiperDot: 1, //是否展示轮播指示点
		showPrice: [0, 1], //是否显示付费会员价和等级会员
		isOpen: [0, 1, 2], //是否展示 0 划线价 1 累计销量 2 库存
		showSvip: 1, //是否展示付费会员卡片
		showRank: 1, // 是否展示 排行榜卡片
		showService: [0, 1, 2, 3], //服务区卡片 0 营销活动入口 1 sku选择 2 服务保障 3 参数
		showReply: 1, //是否展示评论区
		replyNum: 3, //评论数量
		showMatch: 1, //是否展示搭配购
		matchNum: 3, //搭配套餐数量
		showRecommend: 1, //是否展示推荐商品
		recommendNum: 12, //推荐商品数量
		menuList: [0, 1, 2], //底部左侧菜单
		showCart: 1, //是否显示购物车
		showCommunity: 0, //是否显示种草
	},
	// 商品分类可视化数据
	diyCategory: {
		level: 2,
		index: 0
	},
	productVideoStatus: true,
	nearbyStore: 0,
	storeNum: Cache.get(STORE_NUM) || 0 ,//1是平台，0是门店
	storeStaffInfo: Cache.get(STORE_STAFF_INFO, true) || {} ,//店员门店信息
	brokerageFuncStatus: 0, // 分销启用
	storeBrokerageStatu: 1, // 分销模式
	storeBrokeragePrice: 0, // 满额分销-最低累计消费金额
};

const mutations = {
	SETPHONESTATUS(state, val) {
		state.phoneStatus = val;
	},
	LOGIN(state, opt) {
		state.token = opt.token;
		Cache.set(LOGIN_STATUS, opt.token, opt.time);
	},
	SETUID(state, val) {
		state.uid = val;
		Cache.set(UID, val);
	},
	UPDATE_LOGIN(state, token) {
		state.token = token;
	},
	LOGOUT(state) {
		Cache.clear(LOGIN_STATUS);
		Cache.clear(UID);
		Cache.clear(USER_INFO);
		Cache.clear('newcomerGift');
		state.token = undefined;
		state.uid = undefined
		state.userInfo = {}
	},
	BACKGROUND_COLOR(state, color) {
		state.color = color;
		document.body.style.backgroundColor = color;
	},
	UPDATE_USERINFO(state, userInfo) {
		state.userInfo = userInfo;
		Cache.set(USER_INFO, userInfo);
	},
	OPEN_HOME(state) {
		state.homeActive = true;
	},
	CLOSE_HOME(state) {
		state.homeActive = false;
	},
	FOOT_UPLOAD(state, data) {
		state.pageFooter = data
	},
	SET_AUTOPLAY(state, data) {
		state.autoplay = data
		Cache.set(NON_WIFI_AUTOPLAY, data)
	},
	SET_PRODUCT_DIY(state, data){
		state.diyProduct = data.product_detail;
		state.diyCategory = data.product_category;
		state.productVideoStatus = data.product_video_status;
	},
	SET_NEARBY(state,data){
		state.nearbyStore = data
	},
	SET_STORE(state,data){
		Cache.set(STORE_NUM, data);
		state.storeNum = data
	},
	SET_STORE_STAFF_INFO(state,data){
		Cache.set(STORE_STAFF_INFO, data);
		state.storeStaffInfo = data;
	},
	SET_BROKERAGE_STATUS(state, data){
		state.brokerageFuncStatus = data.brokerage_func_status;
		state.storeBrokerageStatu = data.store_brokerage_statu;
		state.storeBrokeragePrice = data.store_brokerage_price;
	}
};

const actions = {
	USERINFO({
		state,
		commit
	}, force) {
		if (state.userInfo !== null && !force)
			return Promise.resolve(state.userInfo);
		else
			return new Promise(reslove => {
				getUserInfo().then(res => {
					commit("UPDATE_USERINFO", res.data);
					Cache.set(USER_INFO, res.data);
					reslove(res.data);
				});
			}).catch(() => {

			});
	},
	// div商品详情
	async getDiyProduct({commit}) {
		let result = await diyProductApi();
		if(result.status == 200){
			commit("SET_PRODUCT_DIY",result.data);
		}
	},
	// 获取分销信息
	async getBrokerage({ commit }) {
		let result = await getAgentBrokerage();
		if (result.status == 200) {
			commit("SET_BROKERAGE_STATUS", result.data);
		}
	}
};

export default {
	state,
	mutations,
	actions
};
