// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import { getMember } from '@/api/diy';

export default {
  namespaced: true,
  state: {
    // 用户信息
    member: {
      style: 1, // 风格
      property: [0, 1, 2, 3, 4],
      avatar_url: ''
    },
    // 订单
    order: {
      style: 1, // 风格
      list: [
        // 全部订单不携带状态参数；显示、排序和图标仍由装修配置保存。
        { key: 'all', title: '全部订单', url: '/pages/goods/order_list/index', icon: 'icon-ic_order1', is_show: 1 },
        { key: 'unpaid', title: '待付款', url: '/pages/goods/order_list/index?status=0', icon: 'icon-ic_daifukuan', is_show: 1 },
        { key: 'debt', title: '欠款', url: '/pages/users/debt/index', icon: 'icon-ic_money', is_show: 1 },
        { key: 'unshipped', title: '待发货', url: '/pages/goods/order_list/index?status=1', icon: 'icon-ic_daifahuo', is_show: 0 },
        { key: 'received', title: '待收货', url: '/pages/goods/order_list/index?status=10', icon: 'icon-ic_daishouhuo', is_show: 0 },
        { key: 'evaluated', title: '待评价', url: '/pages/goods/order_list/index?status=3', icon: 'icon-ic_daipingjia', is_show: 1 },
        { key: 'refund', title: '售后', url: '/pages/users/user_return_list/index', icon: 'icon-ic_returnmoney', is_show: 1 }
      ]
    },
    // 运营统计
    orderStatic: {
      style: 1, // 风格
      is_show: 1
    },
    // 广告位
    poster: {
      is_show: 1,
      list: []
    },
    // 菜单
    menu: {
      title: '我的服务',
      is_show: 1,
      style: 1, // 风格
      list: []
    },
    // 平台菜单
    merMenu: {
      title: '平台管理',
      is_show: 1,
      style: 1, // 风格
      list: []
    },
    // 门店菜单
    storeMenu: {
	  title: '门店管理',
	  is_show: 1,
	  style: 1, // 风格
	  list: []
    },
    // 区域菜单
    agentMenu: {
	  title: '区域管理',
	  is_show: 1,
	  style: 1, // 风格
	  list: []
    }
  },
  mutations: {
    setMemberStyle(state, date) {
	  state.member.style = date;
    },
    setMember(state, date) {
      state.member = date;
    },
    setAvatarUrl(state, url) {
      state.avatar_url = url;
    },
    SET_DATA(state, data) {
      state.member = data.member;
      state.order = data.order;
      state.orderStatic = data.orderStatic;
      state.poster = data.poster;
      state.menu = data.menu;
      state.merMenu = data.merMenu;
	  state.agentMenu = data.agentMenu;
	  state.storeMenu = data.storeMenu;
    }
  },
  actions: {
    getMember({ commit, state }) {
      // Return the request so the editor cannot save its initial empty state
      // after a failed load. Normalize both legacy uploads and icon-only menus.
      return getMember().then((res) => {
        const merMenu = [];
        const storeMenu = [];
        const agentMenu = [];
        const myMenu = [];
        res.data.routine_my_menus.forEach((el) => {
          if (el.type == '2') {
            merMenu.push({
              name: el.name,
              pic: Array.isArray(el.pic) ? (el.pic[0] || '') : (el.pic || ''),
              url: el.url,
              icon: el.icon || '',
              type: el.type
            });
          } else if (el.type == '3') {
			  storeMenu.push({
			    name: el.name,
			    pic: Array.isArray(el.pic) ? (el.pic[0] || '') : (el.pic || ''),
			    url: el.url,
			    icon: el.icon || '',
			    type: el.type
			  });
		  } else if (el.type == '4') {
			  agentMenu.push({
			    name: el.name,
			    pic: Array.isArray(el.pic) ? (el.pic[0] || '') : (el.pic || ''),
			    url: el.url,
			    icon: el.icon || '',
			    type: el.type
			  });
		  } else {
            myMenu.push({
              name: el.name,
              pic: Array.isArray(el.pic) ? (el.pic[0] || '') : (el.pic || ''),
              url: el.url,
              icon: el.icon || '',
              type: el.type
            });
          }
        });
        const posterList = res.data.routine_my_banner.map((e) => {
          return {
            name: e.name,
            pic: Array.isArray(e.pic) ? (e.pic[0] || '') : (e.pic || ''),
            url: e.url
          };
        });
        const configuredServices = res.data.member.menu && res.data.member.menu.list;
        // 单项开关使用后以DIY完整列表读回，不能被缺少显隐字段的旧镜像覆盖而丢失隐藏项。
        // 未使用开关的历史配置继续原镜像回填，保留既有图片兼容行为。
        const hasVisibility = Array.isArray(configuredServices) && configuredServices.some(item => Object.prototype.hasOwnProperty.call(item, 'is_show'));
        res.data.member.menu.list = hasVisibility ? configuredServices.map(item => ({
          ...item,
          pic: Array.isArray(item.pic) ? (item.pic[0] || '') : (item.pic || ''),
          icon: item.icon || ''
        })) : myMenu;
        if (!res.data.member.merMenu) {
          res.data.member.merMenu = state.merMenu;
        }
        if (!res.data.member.agentMenu) {
		  res.data.member.agentMenu = state.agentMenu;
        }
        if (!res.data.member.storeMenu) {
		  res.data.member.storeMenu = state.storeMenu;
        }
        res.data.member.member.avatar_url = res.data.h5_avatar;
        res.data.member.member.per_show_type = parseInt(res.data.member.member.per_show_type) || 0;
        res.data.member.merMenu.list = merMenu;
        res.data.member.agentMenu.list = agentMenu;
        res.data.member.storeMenu.list = storeMenu;
        res.data.member.poster.list = posterList;
        commit('SET_DATA', res.data.member);
      });
    }
  }
};
