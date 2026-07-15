// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

export default {
	token: state => state.app.token,
	isLogin: state => !!state.app.token,
	backgroundColor: state => state.app.backgroundColor,
	userInfo: state => state.app.userInfo || {},
	uid: state => state.app.uid,
	homeActive: state => state.app.homeActive,
	home: state => state.app.home,
	cartNum: state => state.indexData.cartNum,
	diyProduct: state => state.app.diyProduct,
	diyCategory: state => state.app.diyCategory,
	productVideoStatus: state => state.app.productVideoStatus,
	storeNum: state => state.app.storeNum,
	merchantCanEnter: state => state.merchant.canEnter,
	merchantMode: state => state.merchant.mode,
};
