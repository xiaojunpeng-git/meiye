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
import {
  classifyOrgWriteResolved,
  classifyOrgWriteRejected,
} from './orgWriteHelpers';

/**
 * 组织写专用响应包装：不改全平台 request。
 * - 明确 200 → resolve
 * - 明确业务拒绝(400*) → reject business_fail（可废弃 token）
 * - undefined / 网络 / 超时 / 5xx → reject unknown（保留 token，不报成功）
 */
function wrapOrgWriteResponse(promise) {
  return Promise.resolve(promise).then(
    (res) => {
      const c = classifyOrgWriteResolved(res);
      if (c.kind === 'success') return c.res;
      return Promise.reject({
        __orgWriteKind: 'unknown',
        status: c.status,
        msg: c.msg,
      });
    },
    (err) => {
      const c = classifyOrgWriteRejected(err);
      return Promise.reject({
        ...(err && typeof err === 'object' ? err : {}),
        __orgWriteKind: c.kind,
        status: c.status || (err && err.status) || 0,
        msg: c.msg,
      });
    }
  );
}

/**
 *店员列表-获取门店
 */
export function staffListInfo(data) {
  return request({
    url: 'merchant/store_list',
    method: 'get',
	    params: data
  });
}

/**
 *订单-订单列表
 */
export function orderList(data) {
  return request({
    url: `store/order/list`,
    method: 'get',
    params: data
  });
}

/**
 *订单-订单头部数据
 */
export function orderChart(data) {
  return request({
    url: `store/order/chart`,
    method: 'get',
    params: data
  });
}

/**
 *订单-获取门店订单头部统计
 */
export function orderHeader(data) {
  return request({
    url: `store/order/header`,
    method: 'get'
  });
}

/**
 *订单-充值订单列表
 */
export function orderRecharge(data) {
  return request({
    url: `store/recharge`,
    method: 'get',
    params: data
  });
}

/**
 *订单-付费会员订单列表
 */
export function orderVip(data) {
  return request({
    url: `store/vip_order`,
    method: 'get',
    params: data
  });
}

/**
 *订单-获取订单编辑表单
 */
export function getOrdeDatas(id) {
  return request({
    url: `store/order/edit/${id}`,
    method: 'get'
  });
}

/**
 * 订单-获取快递公司
 */
export function getExpressData(status) {
  return request({
    url: `order/express_list?status=` + status,
    method: 'get'
  });
}

/**
 * @description 发送货提交表单
 * @param {Number} param data.id {Number} 订单id
 * @param {Object} param data.datas {Object} 表单信息
 */
export function putDelivery(data) {
  return request({
    url: `/order/delivery/${data.id}`,
    method: 'put',
    data: data.datas
  });
}

/**
 * @description 拆单发送货
 * @param {Number} param data.id {Number} 订单id
 * @param {Object} param data.datas {Object} 表单信息
 */
export function splitDelivery(data) {
  return request({
    url: `/order/split_delivery/${data.id}`,
    method: 'put',
    data: data.datas
  });
}

/**
 * 电子面单模板
 * @param {com} data 快递公司编号
 */
export function orderExpressTemp(data) {
  return request({
    url: '/order/express/temp',
    method: 'get',
    params: data
  });
}

/**
 * 订单时获取所有配送员列表
 */
export function orderDeliveryList(params) {
  return request({
    url: '/order/delivery/list',
    method: 'get',
    params
  });
}

// 面单默认配置信息
export function orderSheetInfo() {
  return request({
    url: '/order/sheet_info',
    method: 'get'
  });
}

/**
 * @description 获取订单可拆分商品列表
 * @param {Object} param data {Object} 传值参数
 */
export function splitCartInfo(id) {
  return request({
    url: `order/split_cart_info/${id}`,
    method: 'get'
  });
}

/**
 * @description 配送信息表单
 * @param {Number} param id {Number} 订单id
 */
export function getDistribution(id) {
  return request({
    url: `store/order/distribution/${id}`,
    method: 'get'
  });
}

/**
 * @description 订单号核销
 */
export function writeUpdate(id) {
  return request({
    url: `store/order/write_update/${id}`,
    method: 'put'
  });
}

/**
 * @description 订单物流信息
 * @param {Number} param id {Number} 订单id
 */
export function getExpress(id) {
  return request({
    url: `/order/express/${id}`,
    method: 'get'
  });
}

/**
 * @description 订单表单详情数据
 * @param {Number} param id {Number} 订单id
 */
export function getDataInfo(id) {
  return request({
    url: `store/order/info/${id}`,
    method: 'get'
  });
}

/**
 * @description 获取订单记录
 * @param {Number} param data.id {Number} 订单id
 * @param {String} param data.datas {String} 分页参数
 */
export function getOrderRecord(data) {
  return request({
    url: `/order/status/${data.id}`,
    method: 'get',
    params: data.datas
  });
}

/**
 * @description 修改备注信息
 * @param {Number} param data.id {Number} 订单id
 * @param {String} param data.remark {String} 备注信息
 */
export function putRemarkData(data) {
  return request({
    url: `order/remark/${data.id}`,
    method: 'put',
    data: data.remark
  });
}

/**
 * 撤销核销订单
 */
export function postChexiao(id, data) {
  return request({
    url: `store/order/postChexiao/${id}`,
    method: 'put',
    data,
  });
}

/**
 * @description 修改充值备注信息
 * @param {Number} param data.id {Number} 订单id
 * @param {String} param data.remark {String} 备注信息
 */
export function putRechargeRemarkData(data) {
  return request({
    url: `store/recharge/remark/${data.id}`,
    method: 'put',
    data: data.remark
  });
}

/**
 * @description 修改会员备注信息
 * @param {Number} param data.id {Number} 订单id
 * @param {String} param data.remark {String} 备注信息
 */
export function putVipRemarkData(data) {
  return request({
    url: `store/vip/remark/${data.id}`,
    method: 'put',
    data: data.remark
  });
}

/**
 * @description 子订单列表---拆单
 * @param {Object} param data {Object} 传值参数
 */
export function splitOrderList(id) {
  return request({
    url: `order/split_order/${id}`,
    method: 'get'
  });
}

/**
 * @description 售后订单
 * @param {Object} param data {Object} 传值参数
 */
export function orderRefundList(data) {
  return request({
    url: 'store/refund/list',
    method: 'get',
    params: data
  });
}

/**
 * @description 获取退款表单数据
 * @param {Number} param id {Number} 订单id
 */
export function getRefundFrom(id) {
  return request({
    url: `/order/refund/${id}`,
    method: 'get'
  });
}

/**
 * @description 获取不退款表单数据
 * @param {Number} param id {Number} 订单id
 */
export function getnoRefund(id) {
  return request({
    url: `/order/no_refund/${id}`,
    method: 'get'
  });
}

/**
 * @description 获取退积分表单
 * @param {Number} param id {Number} 订单id
 */
export function refundIntegral(id) {
  return request({
    url: `/order/refund_integral/${id}`,
    method: 'get'
  });
}

/**
 * @description 导出
 */
export function orderExport(data, type) {
  return request({
    url: `store/order/export/${type}`,
    method: 'post',
    data
  });
}

/**
 *门店流水-获取列表
 */
export function storeFinanceInfo(data) {
  return request({
    url: 'store/finance_flow/list',
    method: 'get',
    params: data
  });
}

/**
 *门店流水--备注
 */
export function storeFinanceMarkApi(id, data) {
  return request({
    url: `store/finance_flow/mark/${id}`,
    method: 'put',
    params: data
  });
}

/**
 *门店流水--获取账单记录列表
 */
export function storeFfundRecordApi(data) {
  return request({
    url: `store/finance_flow/fund_record`,
    method: 'get',
    params: data
  });
}

/**
 *门店流水--账单记录列表-账单详情
 */
export function storeFfundRecordInfoApi(data) {
  return request({
    url: `store/finance_flow/fund_record_info`,
    method: 'get',
    params: data
  });
}

/**
 *门店流水--账单记录列表-账单下载
 */
export function exportfundRecordApi(data) {
  return request({
    url: `/export/storeFinanceRecord`,
    method: 'get',
    params: data
  });
}
/**
 *转账申请-申请列表
 */
export function storeExtractInfo(data) {
  return request({
    url: '/store/extract/list',
    method: 'get',
    params: data
  });
}

/**
 *转账申请-备注
 */
export function storeExtractMarkApi(id, data) {
  return request({
    url: `store/extract/mark/${id}`,
    method: 'post',
    data

  });
}

/**
 *转账申请-审核
 */
export function storeExtractVerifyApi(id, data) {
  return request({
    url: `store/extract/verify/${id}`,
    method: 'post',
    data

  });
}

/**
 *转账申请-转账
 */
export function storepaying(id) {
  return request({
    url: `store/extract/transfer/${id}`,
    method: 'get'

  });
}

/**
 *转账申请-设置
 */
export function headerListApi(data) {
  return request({
    url: 'store/finance/header_basics',
    method: 'get',
    params: data
  });
}

/**
 *转账申请-设置-表单
 */
export function dataFromApi(data, url) {
  return request({
    url: url,
    // url: '/setting/config/edit_basics',
    method: 'get',
    params: data
  });
}

/**
 *门店列表-获取列表数据
 */
export function storeListApi(data) {
  return request({
    url: 'store/store',
    method: 'get',
    params: data
  });
}

/**
 * @description 门店列表 -- 门店修改信息；
 */
export function storeGetInfoApi(id) {
  return request({
    url: `store/store/get_info/${id}`,
    method: 'get'
  });
}

/**
 * @description 门店设置 获取省市区街道
 */
export function cityApi(data) {
  return request({
    url: 'city',
    method: 'get',
    params: data
  });
}

/**
 * @description 门店设置 获取当前登录门店信息
 */
export function storeUpdateApi(id, data) {
  return request({
    url: `store/store/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 门店设置 获取地图key
 */
export function keyApi() {
  return request({
    url: 'store/store/address',
    method: 'get'
  });
}

/**
 * 店设置 进入门店
 */
export function storeLogin(id) {
  return request({
    url: `store/store/login/${id}`,
    method: 'get'
  });
}

/**
 * 门店设置 修改营业状态
 */
export function storeSetShowApi(id, type) {
  return request({
    url: `store/store/set_show/${id}/${type}`,
    method: 'put'
  });
}

/**
门店订单 分配
 */
export function storeShareApi(data) {
  return request({
    url: `store/share/order`,
    method: 'post',
    params: data
  });
}

export function headerApi(data) {
  return request({
    url: 'store/home/header',
    method: 'get',
    params: data

  });
}

export function orderCharts(data) {
  return request({
    url: 'store/home/orderChart',
    method: 'get',
    params: data
  });
}

export function storeApi(data) {
  return request({
    url: 'store/home/store',
    method: 'get',
    params: data
  });
}

export function operateApi(data) {
  return request({
    url: 'store/home/operate',
    method: 'get',
    params: data
  });
}

export function resetApi(id) {
  return request({
    url: `store/store/reset_admin/${id}`,
    method: 'get'
  });
}

export function exportTableList(id, keyword, data) {
  return request({
    url: `export/storeFlowExport?store_id=${id}&keyword=${keyword}&data=${data}`,
    method: 'get'
  });
}

// 分类列表
export function storeCategory(data) {
  return request({
    url: `/store/category`,
    params: data,
    method: 'get'
  });
}

// 添加、编辑表单
export function categoryCreate(id) {
  return request({
    url: `/store/category/create/${id}`,
    method: 'get'
  });
}

// 树形列表
export function categoryTree(type) {
  return request({
    url: `/store/category/tree/${type}`,
    method: 'get'
  });
}

// 修改状态
export function categorySetShow(data) {
  return request({
    url: `/store/category/set_show/${data.id}/${data.is_show}`,
    method: 'PUT'
  });
}

// 门店分类搜索列表
export function cascaderList(type) {
  return request({
    url: `store/category/cascader_list/${type}`,
    method: 'get'
  });
}

/**
 * @description 订单表单详情数据-退款详情
 * @param {Number} param id {Number} 订单id
 */
export function getRefundDataInfo(id) {
  return request({
    url: `/store/refund/detail/${id}`,
    method: 'get'
  });
}

/**
 * @description 获取售后退款表单数据
 * @param {Number} param id {Number} 订单id
 */
export function getRefundOrderFrom(id) {
  return request({
    url: `/store/refund/refund/${id}`,
    method: 'get'
  });
}

/**
 * @description 区域架构-子级列表（树）
 */
export function getRegionManageList(data) {
  return request({
    url: '/region/manage/list',
    method: 'get',
    params: data
  });
}

/**
 * @description 区域架构-完整树
 */
export function getRegionManageTree() {
  return request({
    url: '/region/manage/tree',
    method: 'get'
  });
}

/**
 * @description 区域架构-数量统计（仅刷新树节点数字）
 */
export function getRegionManageCounts() {
  return request({
    url: '/region/manage/counts',
    method: 'get'
  });
}

/**
 * @description 区域架构-全部
 */
export function getRegionManageAll() {
  return request({
    url: '/region/manage/all',
    method: 'get'
  });
}

/**
 * @description 区域架构-级联
 */
export function getRegionManageCascader() {
  return request({
    url: '/region/manage/cascader_list',
    method: 'get'
  });
}

/**
 * @description 区域架构-保存
 */
export function postRegionManage(data, id = 0) {
  return request({
    url: `/region/manage/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 区域架构-详情
 */
export function getRegionManageInfo(id) {
  return request({
    url: `/region/manage/info/${id}`,
    method: 'get'
  });
}

/**
 * @description 区域架构-删除
 */
export function deleteRegionManage(id) {
  return request({
    url: `/region/manage/${id}`,
    method: 'delete'
  });
}

/**
 * @description 管理人员-管辖门店数据
 */
export function getAgentManageStores(id, params = {}) {
  return request({
    url: '/region/agent/manage_stores',
    method: 'get',
    params: { id, ...params }
  });
}

/**
 * @description 管理人员-保存管辖门店
 */
export function saveAgentManageStores(id, data) {
  return request({
    url: '/region/agent/manage_stores',
    method: 'post',
    data: { ...data, id }
  });
}

/**
 * @description 区域列表
 */
export function getRegionList(data) {
  return request({
    url: '/region/agent/list',
    method: 'get',
    params: data
  });
}

/**
 * @description 区域列表-是否隔离开关
 */
export function putRegionSetAlone(id, is_alone) {
  return request({
    url: `region/agent/set_alone/${id}/${is_alone}`,
    method: 'put'
  });
}

/**
 * @description 区域详情
 */
export function getRegionInfo(id) {
  return request({
    url: `region/agent/info/${id}`,
    method: 'get'
  });
}

/**
 * @description 区域-提交
 */
export function postRegion(data, id) {
  return request({
    url: `region/agent/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 区域-全部区域
 * data--区分是否为状态开启的区域
 */
export function getAllRegion(data) {
  return request({
    url: `store/all_region`,
    method: 'get',
    params: data
  });
}

/**
 * @description 区域-列表
 * data--上级区域
 */

export function getRegionCascader() {
  return request({
    url: `/region/agent/cascader_list`,
    method: 'get'
  });
}

/**
 * @description 门店-添加门店
 * data--地址转id
 */
export function getResolveCity(data) {
  return request({
    url: `resolve/city`,
    method: 'get',
    params: data
  });
}

/**
 * @description 区域管理-添加区域-禁止选的区域
 * data--地址转id
 */
export function getStoreResolveCity(data) {
  return request({
    url: `store/region/city`,
    method: 'get',
    params: data
  });
}

/**
 * @description 加盟门店申请列表
 * @param {Number} param id {Number}
 */
export function getStoreApplyList(data) {
  return request({
    url: `/store/apply/list`,
    method: 'get',
    params: data
  });
}

/**
 * @description 加盟门店备注表单
 * @param {Number} param id {Number}
 */
export function getStoreMarkForm(id) {
  return request({
    url: `/store/apply/mark/form/${id}`,
    method: 'get'
  });
}

/**
 * @description 加盟店申请审核
 */
export function postApplyVerify(id, data) {
  return request({
    url: `/store/apply/verify/${id}`,
    method: 'post',
    data
  });
}

/**
 * @description 区域代理商后台授权统计头部
 * @param {Number} param id {Number}
 */
export function getRegionHeader(data) {
  return request({
    url: `/region/home/header`,
    method: 'get',
    params: data
  });
}

/**
 * @description 区域代理商销售额趋势统计
 * @param {Number} param id {Number}
 */
export function getRegionOrder(data) {
  return request({
    url: `/region/home/order`,
    method: 'get',
    params: data
  });
}

/**
 * @description 区域代理商门店交易排行、占比统计
 * @param {Number} param id {Number}
 */
export function getRegionStore(data) {
  return request({
    url: `/region/home/store`,
    method: 'get',
    params: data
  });
}

/**
 * @description  区域代理商快捷登录（越权）
 * @param {Number} param id {Number}
 */
export function getAgentLogin(id) {
  return request({
    url: `region/agent/login/${id}`,
    method: 'get'
  });
}

/**
 * 门店订单-核销记录
 * @param {*} data
 * @returns
 */
export function writeoffRecords(data) {
  return request({
    url: `order/writeoff/records`,
    method: 'post',
    data
  });
}
export function exportWriteoffRecords(data) {
  return request({
    url: `export/writeoff`,
    method: 'post',
    data
  });
}

/**
 * @description  添加门店-默认手续费
 * @param {Number} param id {Number}
 */
export function getFinanceConfig(id) {
  return request({
    url: `store/finance_config/${id}`,
    method: 'get'
  });
}

/**
 * 订单-改派
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function deliveryReassignApi(id, data) {
  return request({
    url: `/order/delivery/reassign/${id}`,
    method: 'post',
    data
  });
}

/**
 * 订单-重新发单
 * @param {*} id
 * @param {*} params
 * @returns
 */
export function orderReissueOrderApi(id, params) {
  return request({
    url: `/order/reissue_order/${id}`,
    method: 'get',
		  params
  });
}

/**
 * @description 组织架构-完整树
 */
export function getOrganizationTree() {
  return request({
    url: '/region/organization/tree',
    method: 'get'
  });
}

/**
 * @description 组织架构-保存（O4：需 X-Request-Token）
 */
export function saveOrganization(id, data, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: `/region/organization/${id || 0}`,
    method: 'post',
    data,
    headers
  }));
}

/** 组织工作台写状态 */
export function getOrganizationWriteStatus() {
  return request({
    url: '/region/organization/write_status',
    method: 'get'
  });
}

/** 删除组织 */
export function deleteOrganization(id, data = {}, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: `/region/organization/${id}`,
    method: 'delete',
    data,
    headers
  }));
}

/** 门店绑定组织 */
export function bindOrganizationStore(data, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: '/region/organization/bind_store',
    method: 'post',
    data,
    headers
  }));
}

/** 保存组织负责人 */
export function saveOrganizationLeaders(orgId, data, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: `/region/organization/${orgId}/leaders`,
    method: 'post',
    data,
    headers
  }));
}

/** 保存权限范围（scope_mode + allowed_store_ids） */
export function saveOrganizationAdminPermission(orgAdminId, data, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: `/region/organization/admin_permission/${orgAdminId}`,
    method: 'post',
    data,
    headers
  }));
}

/**
 * @description 组织架构-迁移旧数据
 */
export function migrateOrganization(data) {
  return request({
    url: '/region/organization/migrate',
    method: 'post',
    data
  });
}

/**
 * @description 组织架构-管理员排除门店
 */
export function getOrganizationAdminExcludes(orgAdminId) {
  return request({
    url: `/region/organization/admin_excludes/${orgAdminId}`,
    method: 'get'
  });
}

export function getOrganizationAdminExcludesByAgent(legacyAgentId) {
  return request({
    url: `/region/organization/admin_excludes_by_agent/${legacyAgentId}`,
    method: 'get'
  });
}

export function saveOrganizationAdminExcludes(orgAdminId, data, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: `/region/organization/admin_excludes/${orgAdminId}`,
    method: 'post',
    data,
    headers
  }));
}

export function saveOrganizationAdminExcludesByAgent(legacyAgentId, data, headers = {}) {
  return wrapOrgWriteResponse(request({
    url: `/region/organization/admin_excludes_by_agent/${legacyAgentId}`,
    method: 'post',
    data,
    headers
  }));
}

export function getOrganizationOverview(params) {
  return request({
    url: '/region/organization/overview',
    method: 'get',
    params
  });
}

/** 组织工作台概况（强制 scene=workspace，不走旧 overview 语义） */
export function getOrganizationWorkspaceOverview(params) {
  return request({
    url: '/region/organization/overview',
    method: 'get',
    params: Object.assign({}, params || {}, { scene: 'workspace' })
  });
}

export function getOrganizationWorkspaceStores(params) {
  return request({
    url: '/region/organization/stores',
    method: 'get',
    params
  });
}

export function getOrganizationWorkspaceEmployees(params) {
  return request({
    url: '/region/organization/employees',
    method: 'get',
    params
  });
}

export function getOrganizationLeaderCandidates(params) {
  return request({
    url: '/region/organization/leader_candidates',
    method: 'get',
    params
  });
}

export function getOrganizationWorkspacePermissions(orgId) {
  return request({
    url: `/region/organization/${orgId}/permissions`,
    method: 'get'
  });
}

/**
 * @description 组织架构-操作记录
 */
export function getOrganizationChangeLog(params) {
  return request({
    url: '/region/organization/change_log',
    method: 'get',
    params
  });
}
