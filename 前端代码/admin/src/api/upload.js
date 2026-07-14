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
 * @description 上传
 * @param {Object} param data {Object} 传值参数
 */
export function upload(data, config) {
  return request({
    url: 'file/video_upload',
    method: 'post',
    file: true,
    data
  });
}

/**
 * @description 云存储 url 上传
 * @param {Object} param data {Object} 传值参数
 */
export function ossUpload(url, data) {
  return request({
    url,
    method: 'post',
    file: true,
    data
  });
}

/**
 * 图片上传
 * @param {*} data
 * @returns
 */
export function uploadFile(data) {
  return request({
    url: 'file/upload',
    method: 'post',
    data
  });
}

/**
 * 获取扫码上传页面链接以及参数
 * @param {*} params
 * @returns
 */
export function scanQRCodeText(params) {
  return request({
    url: '/file/scan/qrcode',
    params
  });
}

/**
 * 网络图片上传
 * @param {*} data
 * @returns
 */
export function uploadOnlineFile(data) {
  return request({
    url: '/file/online/upload',
    method: 'post',
    data
  });
}

/**
 * 获取扫码上传的图片数据
 * @param {*} scan_token
 * @returns
 */
export function scanQRCodeImageList(scan_token) {
  return request({
    url: `/file/scan/image/list/${scan_token}`
  });
}

/**
 * 删除二维码
 * @returns
 */
export function removeQRCode() {
  return request({
    url: '/file/remove/qrcode'
  });
}

/**
 * 获取上传信息
 * @returns
 */
export function getWayData() {
  return request({
    url: '/file/get/way_data'
  });
}

/**
 * 保存上传信息
 * @param {*} is_way
 * @returns
 */
export function setWayData(is_way) {
  return request({
    url: `/file/set/way_data/${is_way}`
  });
}
