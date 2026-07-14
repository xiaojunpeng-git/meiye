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

/** 请货单列表 */
export function stockRequestListApi(data) {
    return request({
        url: '/product/inventory/request/list',
        method: 'get',
        params: data
    });
}

/** 请货单详情 */
export function stockRequestInfoApi(id) {
    return request({
        url: `/product/inventory/request/info/${id}`,
        method: 'get'
    });
}

/** 保存请货草稿（id=0 新建） */
export function stockRequestSaveApi(id, data) {
    return request({
        url: `/product/inventory/request/save/${id}`,
        method: 'post',
        data
    });
}

/** 删除请货草稿 */
export function stockRequestDeleteApi(id) {
    return request({
        url: `/product/inventory/request/${id}`,
        method: 'delete'
    });
}

/** 确认请货申请（不动库存） */
export function stockRequestConfirmApi(id) {
    return request({
        url: `/product/inventory/request/confirm/${id}`,
        method: 'post'
    });
}

/** 驳回请货 */
export function stockRequestRejectApi(id, data) {
    return request({
        url: `/product/inventory/request/reject/${id}`,
        method: 'post',
        data
    });
}

/** 取消请货 */
export function stockRequestCancelApi(id) {
    return request({
        url: `/product/inventory/request/cancel/${id}`,
        method: 'post'
    });
}

/** 双店同源 SKU */
export function stockRequestSharedSkusApi(data) {
    return request({
        url: '/product/inventory/request/shared_skus',
        method: 'get',
        params: data
    });
}

/** 调拨单列表 */
export function stockTransferListApi(data) {
    return request({
        url: '/product/inventory/transfer/list',
        method: 'get',
        params: data
    });
}

/** 调拨单详情 */
export function stockTransferInfoApi(id) {
    return request({
        url: `/product/inventory/transfer/info/${id}`,
        method: 'get'
    });
}

/** 保存调拨草稿（id=0 新建） */
export function stockTransferSaveApi(id, data) {
    return request({
        url: `/product/inventory/transfer/save/${id}`,
        method: 'post',
        data
    });
}

/** 删除调拨草稿 */
export function stockTransferDeleteApi(id) {
    return request({
        url: `/product/inventory/transfer/${id}`,
        method: 'delete'
    });
}

/** 取消调拨草稿 */
export function stockTransferCancelApi(id) {
    return request({
        url: `/product/inventory/transfer/cancel/${id}`,
        method: 'post'
    });
}

/** 确认调拨（改库存） */
export function stockTransferConfirmApi(id) {
    return request({
        url: `/product/inventory/transfer/confirm/${id}`,
        method: 'post'
    });
}

/** 调拨冲销 */
export function stockTransferReverseApi(id, data) {
    return request({
        url: `/product/inventory/transfer/reverse/${id}`,
        method: 'post',
        data
    });
}
