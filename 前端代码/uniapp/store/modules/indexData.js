// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import {CART_NUM} from '@/config/cache';
import Cache from '@/utils/cache';
export default {
	namespaced: true,
	state: {
		cartNum: Cache.get(CART_NUM) || 0
	},
	getters: {},
	mutations: {
		setCartNum(state, data) {
			Cache.set(CART_NUM, data);
			state.cartNum = data;
		}
	}
}
