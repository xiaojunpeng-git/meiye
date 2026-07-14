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
 * 用户信息
 * */
import {
  cashierList
} from "@/api/user";
export default {
    namespaced: true,
    state: {
        // 用户信息
        info: {},
        pageName: '',
        logoutType: '', // 登出类型
        staffList: [], // 店员列表
    },
    mutations: {
        setPageName(state,id){
            state.pageName = id
        },
        setLogoutType(state,type){
            state.logoutType = type
        },
        setStaffList(state,staffList){
            state.staffList = staffList
        },
    },
    actions: {
        getPageName ({ commit }) {
            let storage = window.localStorage;
            commit('setPageName', storage.getItem('pageName'));
        },
        /**
         * @description 设置用户数据
         * @param {Object} state vuex state
         * @param {Object} dispatch vuex dispatch
         * @param {*} info info
         */
        set ({ state, dispatch }, info) {
            return new Promise(async resolve => {
                // store 赋值
                state.info = info;
                // 持久化
                await dispatch('store/db/set', {
                    dbName: 'sys',
                    path: 'user.info',
                    value: info,
                    user: true
                }, { root: true });
                // end
                resolve();
            })
        },
        /**
         * @description 从数据库取用户数据
         * @param {Object} state vuex state
         * @param {Object} dispatch vuex dispatch
         */
        load ({ state, dispatch }) {
            return new Promise(async resolve => {
                // store 赋值
                state.info = await dispatch('store/db/get', {
                    dbName: 'sys',
                    path: 'user.info',
                    defaultValue: {},
                    user: true
                }, { root: true });
                // end
                resolve();
            })
        },
        async cashierList(context) {
          const res = await cashierList();
          context.commit('setStaffList', res.data.staffList);
        },
    }
}
