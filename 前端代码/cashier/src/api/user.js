// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import request from '@/plugins/request';
// 用户标签
/**
 *用户标签-标签分类
 */
export function userlabelListInfo() {
    return request({
        url: 'user/user_label_cate',
        method: 'get',
    });
}

/**
 *用户标签-添加标签分类
 */
export function userLabelCreate() {
    return request({
        url: `user/user_label_cate/create`,
        method: 'get'
    });
}

/**
 *用户标签-标签分类编辑
 */
export function userLabelEdit(id) {
    return request({
        url: `user/user_label_cate/${id}/edit`,
        method: 'get'
    });
}

/**
 *用户标签-获取标签列表
 */
export function LabeInfo(data) {
    return request({
        url: `user/user_label`,
        method: 'get',
		params: data
    });
}

/**
 *用户标签-添加标签列表
 */

export function usercreateApi() {
    return request({
        url: `user/user_label/create`,
        method: 'get'
    });
}

/**
 *用户标签-标签列表编辑
 */
export function userEditApi(id) {
    return request({
        url: `user/user_label/${id}/edit`,
        method: 'get'
    });
}


/**
 *用户-用户列表-获取用户列表
 */
export function userListApi(data) {
    return request({
        url: `user/get_list`,
        method: 'get',
		params: data
    });
}
/**
 *用户-当前门店店员列表和店员信息
 */
export function cashierList(data) {
    return request({
        url: `user/cashier_list`,
        method: 'get',
		params: data
    });
}


/**
 *用户-用户列表-获取搜索用户列表
 */
export function usersearchApi(data) {
    return request({
        url: `user/search`,
        method: 'get',
		params: data
    });
}

/**
 * 获取用户标签
 */
export function getUserLabel(uid) {
    return request({
        url: `user/label/${uid}`,
        method: 'get'
    });
}

/**
 * 设置用户标签
 */
export function putUserLabel(uid, data) {
    return request({
        url: `user/label/${uid}`,
        method: 'post',
        data
    });
}
export function changeMoney(data) {
    return request({
        url: `user/changeMoney`,
        method: 'post',
        data
    });
}


/**
 *用户-用户列表- 详情
 */
export function detailsApi(id) {
    return request({
        url: `user/info/${id}`,
        method: 'get'
    });
}


/**
 *用户-用户列表-详情中tab选项
 */
export function infoApi(data) {
    return request({
        url: `user/record/${data.id}`,
        method: 'get',
        params: data.datas
    });
}


/**
 *用户-用户列表- 获取设置会员标签表单
 */
export function userSetLabelApi(data) {
    return request({
        url: `user/set_label`,
        method: 'post',
        data
    });
}

/**
 *用户-用户列表- 充值列表
 */
export function userRechargelApi() {
    return request({
        url: `store/recharge_info`,
        method: 'get'
    });
}


/**
 *用户-用户列表- 充值会员列表
 */
export function usermemberApi() {
    return request({
        url: `user/member/ship`,
        method: 'get'
    });
}


/**
 *用户-用户列表- 充值保存
 */
export function userSaveApi(data) {
    return request({
        url: `store/recharge`,
        method: 'post',
        data
    });
}


/**
 *用户-用户列表- 充值保存
 */
export function usermeberApi(data) {
    return request({
        url: `/user/member`,
        method: 'post',
        data
    });
}

/**
 *用户-用户列表- 修改店员保存
 */
export function setUserSaveApi(data) {
    return request({
        url: `staff/binding/user`,
        method: 'post',
        data
    });
}

/**
 * @description 个人中心 --- 修改密码
 * data 请求参数
 */
export function updtaeAdmin(data) {
    return request({
        url: `updatePwd`,
        method: 'PUT',
        data
    });
}

/**
 *用户-用户列表- 修改店员保存
 */
export function checkOrderApi(type,data) {
    return request({
        url: `check_order_status/${type}`,
        method: 'post',
        data
    });
}
//店员列表 所有信息
export function staffallList(data) {
    return request({
        url: 'user/allList',
        method: 'post',
        data
    });
}
/**
 *用户-个人中心
 */
export function staffInfoApi() {
    return request({
        url: `user/cashier_info `,
        method: 'get'
    });
}

/**
 *收银台-获取用户详情
 */
export function getUserInfo(uid) {
    return request({
        url: `user/info/${uid}`,
        method: 'get',
    });
}

/**
 * 显示指定的资源
 * @param {*} id
 * @returns
 */
export function readUserInfo(id) {
  return request({
      url: `user/read/${id}`,
      method: 'get',
  });
}

/**
 * 获取指定用户的信息
 * @param {*} id
 * @param {*} params
 * @returns
 */
export function getUserOneInfo(id, params) {
  return request({
      url: `user/one_info/${id}`,
      method: 'get',
      params
  });
}

/**
 * 获取会员类型
 * @param {*} params
 * @returns
 */
export function getMemberCard(params) {
  return request({
      url: `user/member_card`,
      method: 'get',
      params
  });
}

/**
 * 会员充值
 * @param {*} data
 * @returns
 */
export function memberRecharge(data) {
  return request({
      url: `user/mer_recharge`,
      method: 'post',
      data
  });
}


/**
 * 搜索用户信息
 * @param {*} data
 * @returns
 */
export function postSearchUserInfo(data) {
  return request({
      url: `user/search_user_info`,
      method: 'post',
      data
  });
}

/**
 * 收银台注册用户
 * @param {*} data
 * @returns
 */
export function postRegisterUser(data) {
  return request({
      url: `user/register_user`,
      method: 'post',
      data
  });
}

/**
 * 提交用户信息
 * @param {*} data
 * @returns
 */
export function postUserUpdate(uid,data) {
  return request({
      url: `user/update/${uid}`,
      method: 'post',
      data
  });
}

/**
 * 卡项信息
 * @param {*} id
 * @returns
 */
export function userCardHolder(id) {
  return request({
      url: `/user/card_holder/${id}`,
      method: 'get',
  });
}
