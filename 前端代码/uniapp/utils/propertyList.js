let propertyList = [{
		label: "余额",
		value: 0,
		k: "now_money",
		url: '/pages/users/user_money/index'
	},
	{
		label: "优惠券",
		value: 1,
		k: "couponCount",
		url: '/pages/users/user_coupon/index'
	},
	{
		label: "积分",
		value: 2,
		k: "integral",
		url: '/pages/users/user_integral/index'
	},
	{
		label: "我的卡包",
		value: 9,
		k: "card_sum",
		url: '/pages/users/user_card_list/index'
	},
	{
		label: "收藏(商品)",
		value: 3,
		k: "collectProductCount",
		url: '/pages/users/user_goods_collection/index'
	},
	{
		label: "收藏(视频)",
		value: 4,
		k: "collectVideoCount",
		url: '/pages/users/user_goods_collection/index?active=1'
	},
	{
		label: "浏览记录",
		value: 5,
		k: "visit_num",
		url: '/pages/users/visit_list/index'
	},
	{
		label: "推广佣金",
		value: 6,
		k: "brokerage_price",
		url: '/pages/users/user_spread_user/index'
	},
	{
		label: "推广人",
		value: 7,
		k: "spread_user_count",
		url: '/pages/users/user_spread_user/index'
	},
	{
		label: "推广订单",
		value: 8,
		k: "spread_order_count",
		url: '/pages/users/user_spread_user/index'
	},
];
export default propertyList;