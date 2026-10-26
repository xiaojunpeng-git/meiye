// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

module.exports = {
	//token
	LOGIN_STATUS: 'LOGIN_STATUS_TOKEN',
	//uid
	UID:'UID',
	//用户信息
	USER_INFO: 'USER_INFO',
	//token过期时间
	EXPIRES_TIME: 'EXPIRES_TIME',
	//微信登录
	WX_AUTH: 'WX_AUTH',
	//公众号登录code
	STATE_KEY: 'wx_authorize_state',
	//登录类型
	LOGINTYPE: 'loginType',
	//登录跳转地址
	BACK_URL: 'login_back_url',
	//小程序登录状态code
	STATE_R_KEY: 'roution_authorize_state',
	//logo 地址
	LOGO_URL: 'LOGO_URL',
	//模板缓存
	SUBSCRIBE_MESSAGE: 'SUBSCRIBE_MESSAGE',

	TIPS_KEY: 'TIPS_KEY',

	SPREAD: 'spid',
	//缓存经度
	CACHE_LONGITUDE: 'LONGITUDE',
	//缓存纬度
	CACHE_LATITUDE: 'LATITUDE',
	//判断是门店还是平台管理端；
	STORE_NUM: 'STORE_NUM',
	// 购物车数量统计
	CART_NUM: 'CART_NUM',
  // 移动网络下视频自动播放
  NON_WIFI_AUTOPLAY: 'NON_WIFI_AUTOPLAY',
  // 店员门店信息
	STORE_STAFF_INFO: 'STORE_STAFF_INFO',
	// 商家端员工账号会话。它与会员 LOGIN_STATUS_TOKEN 分开保存，不能互相替代。
	MERCHANT_STAFF_TOKEN: 'MERCHANT_STAFF_TOKEN',
	MERCHANT_STAFF_SESSION: 'MERCHANT_STAFF_SESSION',
  // 更新日志最后阅读时间（服务端时间戳）
  LAST_CHANGELOG_READ_TIME: 'LAST_CHANGELOG_READ_TIME',
  // 重要更新弹窗已展示日志 ID
  LAST_IMPORTANT_CHANGELOG_POPUP_ID: 'LAST_IMPORTANT_CHANGELOG_POPUP_ID',
}
