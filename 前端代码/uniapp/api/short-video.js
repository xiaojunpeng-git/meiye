// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import request from "@/utils/request.js";

/**
 * 短视频列表
 * 
 */
export function videoList(data) {
  return request.get(
    "marketing/short_video",data,{ noAuth: true }
  );
}

/**
 * 短视频点赞、收藏、分享
 * 
 */
export function markeVideo(type,id) {
  return request.get(
    `marketing/short_video/${type}/${id}`
  );
}

/**
 * 短视频评论列表
 * 
 */
export function commentList(id,data) {
  return request.get(
    `marketing/short_video/comment/${id}`,data
  );
}

/**
 * 短视频评价、评价回复
 * 
 */
export function markeComment(data) {
  return request.post(
    `marketing/short_video/comment/${data.id}/${data.pid}`,{content:data.content}
  );
}

/**
 * 短视频评价回复列表
 * 
 */
export function replyCommentList(pid,data) {
  return request.get(
    `marketing/short_video/comment_reply/${pid}`,data
  );
}

/**
 * 视频评论点赞
 * 
 */
export function replyCommentLike(type,id) {
  return request.get(
    `marketing/short_video/comment/${type}/${id}`
  );
}

/**
 * 短视频关联商品列表
 * 
 */
export function videoProduct(id,data) {
  return request.get(
    `marketing/short_video/product/${id}`,data
  );
}

/**
 * 热门视频信息
 * 
 */
export function videoInfo(id) {
  return request.get(
    `marketing/short_video/info/${id}`
  );
}


