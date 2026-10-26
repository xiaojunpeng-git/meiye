/**
 * 会员中心服务入口图标。
 *
 * 后台可在菜单配置中传入 icon（完整 iconfont class）；未配置时，按菜单名称
 * 自动选用统一的图标，避免图片未上传或失效时出现空白占位。
 */
const menuIconRules = [
	[/预约|预定/, 'icon-ic_clock'],
	[/积分兑换/, 'icon-ic_gift2'],
	[/中奖|抽奖/, 'icon-ic_crown2'],
	[/付费会员|会员等级|用户等级/, 'icon-huiyuandengji'],
	[/发票/, 'icon-ic_fapiao'],
	[/积分中心/, 'icon-ic_statistics'],
	[/客服|联系/, 'icon-ic_customerservice'],
	[/优惠券/, 'icon-ic_coupon'],
	[/收藏/, 'icon-ic_collect'],
	[/地址/, 'icon-ic_location2'],
	[/余额|储值/, 'icon-ic_money'],
	[/卡包|卡项|项目/, 'icon-ic_card'],
	[/砍价/, 'icon-ic_sale'],
	[/订单/, 'icon-ic_order1'],
	[/记录/, 'icon-kefujilu']
];

export const resolveMemberMenuIcon = (item = {}) => {
	const configuredIcon = typeof item.icon === 'string' ? item.icon.trim() : '';
	if (configuredIcon) return configuredIcon;

	const name = String(item.name || '');
	const matchedRule = menuIconRules.find(([pattern]) => pattern.test(name));
	return matchedRule ? matchedRule[1] : 'icon-ic_user';
};

const orderIconByKey = {
	unpaid: 'icon-ic_daifukuan',
	debt: 'icon-ic_money',
	unshipped: 'icon-ic_daifahuo',
	received: 'icon-ic_daishouhuo',
	evaluated: 'icon-ic_daipingjia',
	refund: 'icon-ic_returnmoney'
};

export const resolveMemberOrderIcon = (item = {}) => {
	const configuredIcon = typeof item.icon === 'string' ? item.icon.trim() : '';
	return configuredIcon || orderIconByKey[item.key] || 'icon-ic_order1';
};
