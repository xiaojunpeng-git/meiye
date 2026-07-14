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

export function getYeji(data) {
    return request({
        url: 'order/get_yeji',
        method: 'post',
        params: data
    });
}
export function saveYeji(data) {
    return request({
        url: 'order/save_yeji',
        method: 'post',
        data
    });
}

export function cashType(data) {
    return request({
        url: 'order/cashType',
        method: 'post',
        data
    });
}
export function cashSource(data) {
    return request({
        url: 'order/cashSource',
        method: 'post',
        data
    });
}

export function staffYeji(data) {
    return request({
        url: 'order/staff_yeji',
        method: 'post',
        data
    });
}
/**
 *收银台-获取收银台商品信息
 */
export function cashierProduct(data) {
    return request({
        url: 'product/get_list',
        method: 'get',
        params: data
    });
}

/**
 *收银台-获取收银台商品信息
 */
export function cashierCate(data) {
    return request({
        url: 'product/get_one_category',
        method: 'get',
        params: data
    });
}

/**
 *收银台-获取三级分类信息
 */
export function productCate(data) {
    return request({
        url: 'product/category',
        method: 'get',
        params: data
    });
}

/**
 *收银台-获取收银台用户信息
 */
export function cashierUser(data) {
    return request({
        url: 'user/user_Info',
        method: 'post',
        data
    });
}

/**
 *收银台-扫码自动解析
 */
export function cashierCode(data) {
    return request({
        url: 'order/cashier/code',
        method: 'post',
        data
    });
}

/**
 *收银台-店员列表
 */
export function cashierStaff(data) {
    return request({
        url: 'order/cashier/staff',
        method: 'get',
        params: data
    });
}

/**
 *收银台-添加购物车
 */
export function cashierCart(uid, data) {
    return request({
        url: `cart/set_cart/${uid}`,
        method: 'post',
        data
    });
}

/**
 *收银台-商品详情
 */
export function cashierDetail(id, uid) {
    return request({
        url: `product/get_info/${id}/${uid}`,
        method: 'get'
    });
}
/**
 *收银台-秒杀商品规格
 */
export function cashierGetAttr(id, uid) {
    return request({
        url: `product/get_attr/${id}/${uid}`,
        method: 'get'
    });
}
/**
 *收银台-购物车信息
 */
export function cashierCartList(uid, staff_id, data) {
    return request({
        url: `cart/get_cart/${uid}/${staff_id}`,
        method: 'get',
        params: data
    });
}

/**
 *收银台-购物车数量
 */
export function cashierCartNum(uid, data) {
    return request({
        url: `cart/set_cart_num/${uid}`,
        method: 'put',
        data
    });
}

/**
 *收银台-购物车规格
 */
export function cashierchangeCart(data) {
    return request({
        url: `cart/change_cart`,
        method: 'put',
        data
    });
}

/**
 *收银台-删除购物车信息
 */
export function cashierCartDel(uid, ids) {
    return request({
        url: `cart/del_cart/${uid}`,
        method: 'DELETE',
        data: ids
    });
}

/**
 *收银台-活动商品数量统计
 */
export function promotionsCount(uid) {
    return request({
        url: `promotions/count/${uid}`,
        method: 'get',
    });
}

/**
 *收银台-计算订单金额
 */
export function cashierCompute(uid, data) {
    return request({
        url: `order/compute/${uid}`,
        method: 'post',
        data
    });
}


/**
 *收银台-创建订单
 */
export function cashierCreate(uid, data) {
    return request({
        url: `/order/create/${uid}`,
        method: 'post',
        data
    });
}

/**
 *收银台-订单支付
 */
export function cashierPay(orderId, data) {
    return request({
        url: `order/pay/${orderId}`,
        method: 'post',
        data
    });
}

/**
 *订单-订单列表
 */
export function orderList(data) {
    return request({
        url: `order/list`,
        method: 'get',
        params: data
    });
}

/**
 *订单-订单头部数据
 */
export function orderChart(data) {
    return request({
        url: `order/chart`,
        method: 'get',
        params: data
    });
}

/**
 *订单-获取门店订单头部统计
 */
export function orderHeader(data) {
    return request({
        url: `order/header`,
        method: 'get'
    });
}

/**
 *订单-充值订单列表
 */
export function orderRecharge(data) {
    return request({
        url: `order/recharge`,
        method: 'get',
        params: data
    });
}

/**
 *订单-付费会员订单列表
 */
export function orderVip(data) {
    return request({
        url: `order/vip_order`,
        method: 'get',
        params: data
    });
}

/**
 *订单-获取订单编辑表单
 */
export function getOrdeDatas(id) {
    return request({
        url: `order/edit/${id}`,
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
    })
};

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
};

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
};

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
        url: '/order/delivery_list',
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
    })
};

/**
 * @description 配送信息表单
 * @param {Number} param id {Number} 订单id
 */
export function getDistribution(id) {
    return request({
        url: `/order/distribution/${id}`,
        method: 'get'
    });
};


/**
 * @description 订单物流信息
 * @param {Number} param id {Number} 订单id
 */
export function getExpress(id) {
    return request({
        url: `/order/express/${id}`,
        method: 'get'
    });
};

/**
 * @description 订单表单详情数据
 * @param {Number} param id {Number} 订单id
 */
export function getDataInfo(id) {
    return request({
        url: `/order/info/${id}`,
        method: 'get'
    });
};

/**
 * @description 订单表单详情数据-退款详情
 * @param {Number} param id {Number} 订单id
 */
export function getRefundDataInfo(id) {
    return request({
        url: `/refund/detail/${id}`,
        method: 'get'
    })
};

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
};

/**
 * @description 修改备注信息
 * @param {Number} param data.id {Number} 订单id
 * @param {String} param data.remark {String} 备注信息
 */
export function putRemarkData(data) {
    return request({
        url: `/order/remark/${data.id}`,
        method: 'put',
        data: data.remark
    });
};

/**
 * @description 修改退款/退货订单备注信息
 * @param {Number} param data.id {Number} 订单id
 * @param {String} param data.remark {String} 备注信息
 */
export function putRefundRemarkData(data) {
    return request({
        url: `order/refund/remark/${data.id}`,
        method: 'put',
        data: data.remark
    });
};

/**
 * @description 子订单列表---拆单
 * @param {Object} param data {Object} 传值参数
 */
export function splitOrderList(id) {
    return request({
        url: `order/split_order/${id}`,
        method: 'get'
    })
};

/**
 * @description 售后订单
 * @param {Object} param data {Object} 传值参数
 */
export function orderRefundList(data) {
    return request({
        url: 'refund/list',
        method: 'get',
        params: data
    })
};

/**
 * @description 获取主动退款表单数据
 * @param {Number} param id {Number} 订单id
 */
export function getRefundFrom(id) {
    return request({
        url: `/order/refund/${id}`,
        method: 'get'
    });
};

/**
 * @description 获取售后退款表单数据
 * @param {Number} param id {Number} 订单id
 */
export function getRefundOrderFrom(id) {
    return request({
        url: `/refund/refund/${id}`,
        method: 'get'
    });
};

/**
 * @description 获取不退款表单数据
 * @param {Number} param id {Number} 订单id
 */
export function getnoRefund(id) {
    return request({
        url: `/order/no_refund/${id}`,
        method: 'get'
    });
};

/**
 * @description 获取退积分表单
 * @param {Number} param id {Number} 订单id
 */
export function refundIntegral(id) {
    return request({
        url: `/order/refund_integral/${id}`,
        method: 'get'
    });
};

/**
 * @description 导出
 */
export function orderExport(data, type) {
    return request({
        url: `order/export/${type}`,
        method: 'post',
        data
    });
}

/**
 * @description 获取充值订单备注
 */
export function rechargeRemark(id) {
    return request({
        url: `order/recharge/remark/${id}`,
        method: 'get'
    });
}

/**
 * @description 获取会员订单备注
 */
export function vipRemark(id) {
    return request({
        url: `order/vip/remark/${id}`,
        method: 'get'
    });
}

/**
 * @description 保存会员备注
 */
export function postVipRemark(id, data) {
    return request({
        url: `order/vip/remark/${id}`,
        method: 'put',
        data
    });
}

/**
 * @description 保存充值订单备注
 */
export function postRechargeRemark(id, data) {
    return request({
        url: `order/recharge/remark/${id}`,
        method: 'put',
        data
    });
}

/**
 * @description 会员记录
 */
export function vipStatus(id) {
    return request({
        url: `order/vip/status/${id}`,
        method: 'get'
    });
}

/**
 * @description 跳转移动端支付列表页
 */
export function cashierScan() {
    return request({
        url: `order/cashier/cashier_scan`,
        method: 'get'
    });
}

/**
 * @description 收银台优惠券列表
 */
export function cashierCouponList(uid, data) {
    return request({
        url: `order/coupon_list/${uid}`,
        method: 'post',
        data
    });
}

/**
 * @description 收银台-卡升级：获取会员旧的有效卡项
 */
export function cashierValidCardUpgradeList(uid) {
    return request({
        url: `order/valid_card_upgrade/${uid}`,
        method: 'get'
    });
}

/**
 * @description 订单-消耗商品列表
 */
export function writeCartList(data) {
    return request({
        url: `order/verify_cart_info`,
        method: 'get',
        params: data
    });
}

/**
 * @description 订单-消耗订单提交
 */
export function putWriteUpdate(id, data) {
    return request({
        url: `order/write_off/${id}`,
        method: 'put',
        data
    });
}

/**
 * @description 收银台-收银台切换购物车用户
 */
export function postCashierSwitch(data, staffId) {
    return request({
        url: `user/switch/${staffId}`,
        method: 'post',
        data
    });
}

/**
 * @description 收银台-收银台收银台保存挂单
 */
export function postCashierHang(data) {
    return request({
        url: `order/cashier/hang`,
        method: 'post',
        data
    });
}

/**
 * @description 收银台-收银台获取挂单列表分页
 */
export function getHangList(id, data) {
    return request({
        url: `order/get_hang_list/${id}`,
        method: 'get',
        params: data
    });
}

/**
 * @description 收银台-收银台获取挂单
 */
export function getHang(id) {
    return request({
        url: `order/get_user_list/${id}`,
        method: 'get',
    });
}

/**
 * @description 收银台-收银台删除挂单
 */
export function cashierHang(ids) {
    return request({
        url: `order/cashier/hang`,
        method: 'DELETE',
        params: {id: ids}
    });
}

/**
 * @description 充值订单-退款
 */
export function refundRecharge(id) {
    return request({
        url: `order/recharge/${id}/refund_edit`,
        method: 'get'
    });
}

/**
 * @description
 */
export function getOrderList(data) {
    return request({
        url: `order/get_order_list`,
        method: 'post',
        data
    });
}

/**
 * @description 充值订单-退款
 */
export function getOrderInfo(id) {
    return request({
        url: `order/get_order_Info/${id}`,
        method: 'get',
    });
}/**
 * @description 订单详情-退款
 */
export function getOrderRefundInfo(id) {
    return request({
        url: `order/get_refund_Info/${id}`,
        method: 'get',
    });
}

/**
 * @description 退款订单列表
 */
export function getRefundList(data) {
    return request({
        url: `order/get_refund_list`,
        method: 'get',
        params: data
    });
}

/**
 * @description 消耗订单列表
 */
export function getVerifyList(data) {
    return request({
        url: `order/get_verify_list`,
        method: 'post',
        data
    });
}
export function getPrice(data) {
    return request({
        url: `order/getPrice`,
        method: 'post',
        data
    });
}
export function getService(data) {
    return request({
        url: `order/getService`,
        method: 'post',
        data
    });
}
/**
 * @description 消耗项目
 */
export function writeUpdate(id) {
    return request({
        url: `/order/write_off/${id}`,
        method: 'put',
    });
}

/**
 * 卡项订单转让
 */
export function cardTransfer(data) {
    return request({
        url: 'order/card_transfer',
        method: 'post',
        data
    });
}

/**
 * 卡项订单延期
 */
export function cardExtend(data) {
    return request({
        url: 'order/card_extend',
        method: 'post',
        data
    });
}

/**
 * @description 收银台-订单记录
 */
export function getOrderStatusList(id) {
    return request({
        url: `order/get_order_status/${id}`,
        method: 'get',
    });
}

/**
 * @description 售后订单-退款
 */
export function refund(id,data) {
    return request({
        url: `order/order_refund/${id}`,
        method: 'put',
        data
    });
}
/**
 * @description 删除挂单订单
 */
export function delHang(data) {
    return request({
        url: `order/del_hang`,
        method: 'delete',
        params: data
    });
}

/**
 * @description erp配置
 */
export function erpConfig() {
    return request({
        url: `erp/config`,
        method: 'get',
    });
}

/**
 * @description 收银台获取副屏信息
 */
export function auxScreen() {
    return request({
        url: `user/aux_screen`,
        method: 'get',
    });
}

/**
 * @description 切换用户切换店员
 */
export function swithUser(data) {
    return request({
        url: `user/swith_user`,
        method: 'post',
        data
    });
}

/**
 * 桌码管理
 * @returns
 */
export function getCodeList() {
    return request({
        url: `/code/list`,
        method: 'get',
    });
}

/**
 * @description 订单列表-卡项消耗
 */
export function orderWriteForm(id, params) {
    return request({
        url: `/order/write/form/${id}`,
        method: 'get',
        params
    });
}

/**
 * @description 订单列表-立即退款-商品信息
 */
export function orderBenefits(id) {
    return request({
        url: `order/benefits/${id}`,
        method: 'get'
    })
};

/**
 * 桌码订单列表
 * @returns
 */
export function getTableList(params) {
    return request({
        url: `/get/table/list`,
        method: 'get',
        params
    });
}

/**
 * 获取全部点餐用户信息
 * @param {*} params
 * @returns
 */
export function getTableUidAll(params) {
    return request({
        url: `/table/uid/all`,
        method: 'get',
        params
    });
}

/**
 * 购物车处理
 * @param {*} params
 * @returns
 */
export function getCartList(params) {
    return request({
        url: `/get/cart/list`,
        method: 'get',
        params
    });
}

/**
 * 取消桌码
 * @param {*} params
 * @returns
 */
export function cancelTable(params) {
    return request({
        url: `/cancel/table`,
        method: 'get',
        params
    });
}

/**
 * 收银台购物车数量操作
 * @param {*} data
 * @returns
 */
export function editTableCart(data) {
    return request({
        url: `/edit/table/cart`,
        method: 'post',
        data
    });
}

/**
 * 手动打单
 * @param {*} params
 * @returns
 */
export function staffPlace(params) {
    return request({
        url: `/staff/place`,
        method: 'get',
        params
    });
}

/**
 * 桌码订单购物车信息
 * @param {*} oid
 * @returns
 */
export function getTableOrderInfo(oid) {
    return request({
        url: `/get/order/info/${oid}`,
        method: 'get'
    });
}

/**
 * 线下支付
 * @param {*} id
 * @returns
 */
export function payOffline(id) {
    return request({
        url: `/pay_offline/${id}`,
        method: 'post'
    });
}

/**
 * 退款
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function orderRefund(id, data) {
    return request({
        url: `order/refund/${id}`,
        method: 'put',
        data
    });
}

/**
 * 分单退款
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function openRefund(id, data) {
    return request({
        url: `open/refund/${id}`,
        method: 'post',
        data
    });
}

/**
 * 收银台的改价获取订单商品信息
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function getTableInfo(data) {
    return request({
        url: `table/update/info`,
        method: 'get',
        params:data
    });
}

/**
 * 收银台的改价提交接口
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function postTableUpdate(data) {
    return request({
        url: `table/update`,
        method: 'post',
        data
    });
}

export function cardBenefits(id) {
    return request({
        url: `card/benefits/${id}`,
        method: 'get'
    });
}

export function writeoffRecords(id) {
    return request({
        url: `order/writeoff/records/${id}`,
        method: 'get'
    });
}

/**
 * 订单-重新发单
 * @param {*} id
 * @param {*} params
 * @returns
 */
export function orderReissueApi(id, params) {
    return request({
        url: `/order/reissue_order/${id}`,
        method: 'get',
        params
    });
}

/**
 * 订单-订单改派
 * @param {*} id
 * @param {*} data
 * @returns
 */
export function orderReassignApi(id, data) {
    return request({
        url: `/order/reassign/${id}`,
        method: 'post',
        data
    });
};
