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
      style: 1 // 风格
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
      getMember().then((res) => {
        const merMenu = [];
        const storeMenu = [];
        const agentMenu = [];
        const myMenu = [];
        res.data.routine_my_menus.forEach((el) => {
          if (el.type == '2') {
            merMenu.push({
              name: el.name,
              pic: el.pic[0],
              url: el.url,
              type: el.type
            });
          } else if (el.type == '3') {
			  storeMenu.push({
			    name: el.name,
			    pic: el.pic[0],
			    url: el.url,
			    type: el.type
			  });
		  } else if (el.type == '4') {
			  agentMenu.push({
			    name: el.name,
			    pic: el.pic[0],
			    url: el.url,
			    type: el.type
			  });
		  } else {
            myMenu.push({
              name: el.name,
              pic: el.pic[0],
              url: el.url,
              type: el.type
            });
          }
        });
        const posterList = res.data.routine_my_banner.map((e) => {
          return {
            name: e.name,
            pic: e.pic[0],
            url: e.url
          };
        });
        res.data.member.menu.list = myMenu;
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
