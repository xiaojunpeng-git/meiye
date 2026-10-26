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

/** 项目耗材配方列表 */
export function salonRecipeListApi(data) {
    return request({
        url: '/product/inventory/recipe/list',
        method: 'get',
        params: data
    });
}

/** 项目耗材配方详情 */
export function salonRecipeInfoApi(id) {
    return request({
        url: `/product/inventory/recipe/info/${id}`,
        method: 'get'
    });
}

/** 保存项目耗材配方（id=0 新建） */
export function salonRecipeSaveApi(id, data) {
    return request({
        url: `/product/inventory/recipe/save/${id}`,
        method: 'post',
        data
    });
}

/** 启用/停用项目耗材配方 */
export function salonRecipeStatusApi(id, data) {
    return request({
        url: `/product/inventory/recipe/status/${id}`,
        method: 'post',
        data
    });
}

/** 删除项目耗材配方 */
export function salonRecipeDeleteApi(id) {
    return request({
        url: `/product/inventory/recipe/${id}`,
        method: 'delete'
    });
}

/** 院装领用/退回明细 */
export function salonUsageListApi(data) {
    return request({
        url: '/product/inventory/salon/usage/list',
        method: 'get',
        params: data
    });
}

/** 院装耗材统计 */
export function salonUsageStatisticsApi(data) {
    return request({
        url: '/product/inventory/salon/usage/statistics',
        method: 'get',
        params: data
    });
}
