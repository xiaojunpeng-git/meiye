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

/**
 * @description 社区话题--列表
 * @param {Object} param data {Object} 传值参数
 */
export function topicListApi(data) {
  return request({
    url: 'community/topic/list',
    method: 'get',
    params: data
  });
}

/**
 * @description 社区话题--新增编辑
 * @param {Object} param data {Object} 传值参数
 */
export function topicSave(id) {
  return request({
    url: `community/topic/save_form/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区话题--设置话题推荐
 * @param {Number} param id {Number}
 */
export function topicSetHotApi(data) {
  return request({
    url: `community/topic/set_hot/${data.id}/${data.hot}`,
    method: 'get'
  });
}

/**
 * @description 社区话题--设置话题状态
 * @param {Number} param id {Number}
 */
export function topicSetStatusApi(data) {
  return request({
    url: `/community/topic/set_status/${data.id}/${data.status}`,
    method: 'get'
  });
}

/**
 * @description 社区话题--获取所有社区话题
 */
export function allTopicApi() {
  return request({
    url: `community/all_topic`,
    method: 'get'
  });
}

/**
 * @description 社区内容--顶部header
 * @param {Object} param params {Object} 传值
 */
export function communityHeaderApi(params) {
  return request({
    url: `community/community/header`,
    method: 'get',
    params
  });
}

/**
 * @description 社区内容--列表
 * @param {Number} param id {Number}
 */
export function communityListApi(data) {
  return request({
    url: `community/community/list`,
    method: 'get',
    params: data
  });
}

/**
 * @description 社区内容--推荐指数表单
 * @param {Number} param id {Number}
 */
export function communityStarApi(id) {
  return request({
    url: `community/community/star/form/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区内容--推荐指数表单
 * @param {Number} param id {Number}
 */
export function communityStatusApi(data) {
  return request({
    url: `community/community/set_status/${data.id}/${data.status}`,
    method: 'post'
  });
}

/**
 * @description 社区内容--强制下架
 * @param {Number} param id {Number}
 */
export function communityDownApi(id) {
  return request({
    url: `community/community/take_down/form/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区内容--虚拟评价
 * @param {Number} param id {Number}
 */
export function communityCommentApi(id) {
  return request({
    url: `community/comment/fictitious/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区内容--审核
 * @param {Number} param id {Number}
 */
export function communityVerifyApi(id, data) {
  return request({
    url: `community/community/set_verify/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 社区内容--添加、编辑
 * @param {Object} param data {Object} 传值
 */
export function communitySaveApi(data, id) {
  return request({
    url: `community/community/save/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 社区内容--详情
 * @param {Number} param id {Number} 内容id
 * @param {Object} param data {Object} 传值
 */
export function communityInfoApi(id) {
  return request({
    url: `community/community/info/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区内容--评价列表
 * @param {Number} param id {Number} 内容id
 * @param {Object} param data {Object} 传值
 */
export function commentAllListApi(id) {
  return request({
    url: `community/comment/list/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区评论--评价列表
 * @param {Number} param id {Number} 内容id
 * @param {Object} param data {Object} 传值
 */
export function commentListApi(data) {
  return request({
    url: `community/comment/list`,
    method: 'get',
    params: data
  });
}

/**
 * @description 社区评论--社区评论回复
 * @param {Number} param id {Number} 内容id
 * @param {Object} param data {Object} 传值
 */
export function commentReplyApi(id) {
  return request({
    url: `community/comment/reply/form/${id}`,
    method: 'get'
  });
}

/**
 * @description 社区评论--社区评论审核表单
 * @param {Number} param id {Number} 内容id
 * @param {Object} param data {Object} 传值
 */
export function commentVerifyApi(id, data) {
  return request({
    url: `community/comment/set_verify/${id}`,
    method: 'post',
    data
  });
}

export function communityReplyStatusApi(id, status) {
  return request({
    url: `community/comment/set_status/${id}/${status}`,
    method: 'put'
  });
}

/**
 * @description 社区评论--社区评论批量审核表单
 * @param {Object} param data {Object} 传值
 */
export function commentBatchVerifyApi(data) {
  return request({
    url: `community/community/batchVerify`,
    method: 'post',
    data
  });
}
