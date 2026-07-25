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

function normalizeMenuPath (path) {
    const pre = Setting.routePre || '';
    const p = typeof path === 'string' ? path : '';
    if (!pre) {
        return p;
    }
    if (p === pre || p.startsWith(`${pre}/`)) {
        return p;
    }
    return `${pre}${p}`;
}

function mapMenusNav (menuList) {
    if (!Array.isArray(menuList)) {
        return [];
    }
    return menuList.map((item) => {
        if (Array.isArray(item.children)) {
            item.children = mapMenusNav(item.children);
        }
        item.path = normalizeMenuPath(item.path);
        item.isShow = true;
        return item;
    });
}

function getMenusName () {
    let storage = window.localStorage, menuList = storage.getItem('menuListStore'), menuData = []
    try {
        menuData = menuList !== undefined && menuList !== null ? JSON.parse(menuList) : [];
    } catch (e) {}
    if (!Array.isArray(menuData)) {
        menuData = []
    }
    // 启动读缓存时补齐 isShow，并避免路径重复加前缀
    const normalized = mapMenusNav(menuData);
    try {
        storage.setItem('menuListStore', JSON.stringify(normalized));
    } catch (e) {}
    return normalized
}

export default {
    namespaced: true,
    state: {
        menusName: getMenusName()
    },
    mutations: {
        getmenusNav (state, menuList) {
          state.menusName = mapMenusNav(menuList);
          let storage = window.localStorage;
          storage.setItem('menuListStore', JSON.stringify(state.menusName));
        },
        setmenusNav(state, menuList) {
          state.menusName = mapMenusNav(menuList);
          let storage = window.localStorage;
          storage.setItem('menuListStore', JSON.stringify(state.menusName));
        }
    },
    actions: {
        getMenusNavList ({ commit }) {
            return new Promise((resolve, reject) => {
                menusApi().then(async res => {
                    resolve(res);
                    // 只走 getmenusNav（已 map + 写缓存），禁止再用原始 menus 覆盖
                    commit('getmenusNav', res.data.menus);
                }).catch(res => {
                    reject(res);
                })
            })
        }
    }
};
