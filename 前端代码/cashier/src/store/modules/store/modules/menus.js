// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
/**
 * 布局菜单配置
 * */
import { menusApi } from '@/api/account';
import Setting from '@/setting';
//cashierMenu
function getMenusName () {
    let storage = window.localStorage, menuList = storage.getItem('cashierMenu'), menuData = []
    try {
        menuData = menuList !== undefined ? JSON.parse(menuList) : [];
    } catch (e) {}
    if (typeof menuData !== 'object' || menuData === null) {
        menuData = []
    }
    return menuData
}

function makemenusNav(menuList) {
  for (let i = 0; i < menuList.length; i++) {
    menuList[i].path = `${Setting.roterPre}${menuList[i].path}`
    if (menuList[i].children) {
      makemenusNav(menuList[i].children)
    }
  }
}

export default {
    namespaced: true,
    state: {
        menusName: getMenusName()
    },
    mutations: {
        getmenusNav (state, menuList) {
          makemenusNav(menuList)
            state.menusName = menuList;
            let storage = window.localStorage;
            storage.setItem('cashierMenu', JSON.stringify(menuList));
        }
    },
    actions: {
        getMenusNavList ({ commit }) {
            return new Promise((resolve, reject) => {
                menusApi().then(async res => {
                    resolve(res);
                    commit('getmenusNav', res.data.menus);
                    let storage = window.localStorage;
                    storage.setItem('cashierMenu', JSON.stringify(res.data.menus));
                }).catch(res => {
                    reject(res);
                })
            })
        }
    }
};
