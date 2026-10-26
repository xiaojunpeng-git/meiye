<template>
  <div>
    <Drawer
      :closable="false"
      width="1000"
      class-name="order_box"
      v-model="modals"
      :styles="{ padding: 0 }"
    >
      <div v-if="orderDatalist">
        <div class="head">
          <div class="full">
            <Icon
              :class="{
                'sale-after': orderDatalist.orderInfo._status._type === -1,
              }"
              custom="iconfont icondingdan"
              size="60"
            />
            <div class="text">
              <div class="title">{{ orderData.pink_name || "售后订单" }}</div>
              <div>订单编号：{{ orderDatalist.orderInfo.order_id }}</div>
            </div>
            <div v-if="rowActive && rowActive.delete_time == null && !isAgentAdmin">
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="
                  orderData._status_new === 1 &&
                  orderData.paid === 0 &&
                  orderData.pay_type === 'offline'
                "
                @click="changeMenu('1')"
                >确认收款
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="
                  orderData._status_new == 2 &&
                  (rowActive && !rowActive.split.length) &&
                  (!formType || !orderData.refund.length) &&
                  !distHide
                "
                @click="distribution"
                >分配
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="orderData._status_new == 1"
                @click="edit"
                >订单改价
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="
                  (orderData._status_new === 2 ||
                    orderData._status_new === 8 ||
                    orderData.status === 4) &&
                  (orderData.shipping_type === 1 ||
                    orderData.shipping_type === 3 &&
                    orderData.store_delivery_type === 1) &&
                  (orderData.pinkStatus === null || orderData.pinkStatus === 2)
                "
                @click="sendOrder"
                >发送货
              </Button>
			  <Button @click="showEditAddress" type="default" goast v-if="
			    orderData.status === 0 &&
			    (orderData.shipping_type === 1 || orderData.shipping_type === 3) &&
			    !orderData.refund.length
			  ">修改地址</Button>
              <!-- 同城配送 -->
              <template v-if="orderData.shipping_type === 3 && orderData.store_delivery_type === 2">
                <Button v-if="orderData._status_new === 2" @click="openModal3">派单</Button>
                <Button v-if="orderData._status_new === 2 && orderData.status_name.is_reissue_order === 1" @click="orderReissueOrder">重新发单</Button>
                <Button v-if="orderData._status_new === 4" @click="openModal3">改派</Button>
                <Button v-if="orderData._status_new === 4" @click="deliveryConfirm">确认送达</Button>
              </template>
              <!-- <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="orderData._status_new === 4 && rowActive && !rowActive.split.length"
                @click="delivery"
              >
                配送信息
              </Button> -->
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="
                  orderData.shipping_type == 2 &&
                  (orderData.status == 0 || orderData.status == 5) &&
                  orderData.paid == 1 &&
                  orderData.refund_status === 0
                "
                @click="bindWrite"
                >立即核销
              </Button>
              <Button
                v-if="orderData._status_new >= 2"
                @click="changeMenu('10')"
                >小票打印</Button
              >
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="orderData._status_new >= 3 && orderData.express_dump"
                @click="changeMenu('11')"
                >电子面单打印
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="orderData.kuaidi_label"
                @click="changeMenu('13')"
                >快递面单打印
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="
                  (orderData.apply_type == 1 ||
                    orderData.refund_type == 5 ||
                    (orderData.refund_type == 4 &&
                      orderData.apply_type == 3)) &&
                  ![3, 6].includes(orderData.refund_type) &&
                  (parseFloat(orderData.pay_price) >
                    parseFloat(orderData.refunded_price) ||
                    orderData.pay_price == 0) &&
                  !formType
                "
                @click="changeMenu('5')"
                >立即退款
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="
                  [2, 3].includes(orderData.apply_type) &&
                  [0, 1, 2].includes(orderData.refund_type) &&
                  !formType
                "
                @click="changeMenu('55')"
                >同意退货
              </Button>
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="[0, 1, 2, 5].includes(orderData.refund_type) && !formType"
                @click="changeMenu('7')"
                >不退款
              </Button>
              <Button v-if="!formType" @click="changeMenu('4')"
                >售后备注</Button
              >
              <Button
                :class="openErp ? 'on' : ''"
                :disabled="openErp"
                v-if="orderData.is_del == 1"
                @click="changeMenu('9')"
                >删除订单
              </Button>
              <Dropdown
                @on-click="changeMenu"
                v-if="orderData._status_new !== 1 && formType"
              >
                <Button icon="ios-more"></Button>
                <DropdownMenu slot="list">
                  <DropdownItem
                    v-if="
                      orderData._status_new !== 1 ||
                      (orderData._status_new === 3 &&
                        orderData.use_integral > 0 &&
                        orderData.use_integral >= orderData.back_integral)
                    "
                    name="4"
                    >订单备注
                  </DropdownItem>
                  <DropdownItem
                    :disabled="openErp"
                    v-if="
                      (orderData.refund_type == 0 ||
                        orderData.refund_type == 1 ||
                        orderData.refund_type == 4 ||
                        orderData.refund_type == 6 ||
                        orderData.refund_type == 5) &&
                      orderData.paid == 1 &&
                      orderData.refund_status !== 2
                    "
                    name="5"
                    >立即退款
                  </DropdownItem>
                  <DropdownItem
                    :disabled="openErp"
                    v-if="orderData._status_new === 4"
                    name="8"
                    >已收货</DropdownItem
                  >
                  <DropdownItem v-if="orderData.paid" name="12"
                    >打印配货单</DropdownItem
                  >
                </DropdownMenu>
              </Dropdown>
            </div>
          </div>
          <ul class="list">
            <li class="item">
              <div class="title">订单状态</div>
              <div v-if="!formType">
                <div
                  v-if="orderData.refund_type == 0 && orderData.apply_type == 1"
                  class="value1"
                >
                  仅退款
                </div>
                <div
                  v-else-if="
                    orderData.refund_type == 0 &&
                    (orderData.apply_type == 2 || orderData.apply_type == 3)
                  "
                  class="value1"
                >
                  退货退款
                </div>
                <div v-else-if="orderData.refund_type == 3" class="value1">
                  拒绝退款
                </div>
                <div v-else-if="orderData.refund_type == 4" class="value1">
                  商品待退货
                </div>
                <div v-else-if="orderData.refund_type == 5" class="value1">
                  退货待收货
                </div>
                <div v-else-if="orderData.refund_type == 6" class="value2">
                  已退款
                </div>
              </div>
              <div class="value1" v-else>
                <span v-html="orderData.status_name.status_name"></span>
                <span v-if="!orderData.is_all_refund && orderData.refund.length"
                  >,部分退款中</span
                >
                <span
                  v-if="
                    orderData.is_all_refund &&
                    orderData.refund.length &&
                    orderData.refund_type != 6
                  "
                  >,退款中</span
                >
              </div>
            </li>
            <li v-if="orderData.orderStatus" class="item">
              <div class="title">主订单状态</div>
              <div>
                <div>
                  {{ orderData.orderStatus._title }}
                </div>
              </div>
            </li>
            <li class="item">
              <div class="title">实际支付</div>
              <div>
                ¥{{
                  orderDatalist.orderInfo.paid > 0
                    ? orderDatalist.orderInfo.pay_price
                    : 0
                }}
              </div>
            </li>
            <li class="item" v-if="!formType">
              <div class="title">退款件数</div>
              <div>{{ orderDatalist.orderInfo.total_num || 0 }}</div>
            </li>
            <li class="item" v-else>
              <div class="title">支付方式</div>
              <div>{{ orderDatalist.orderInfo._status._payType || "-" }}</div>
            </li>
            <li class="item" v-if="!formType">
              <div class="title">退款时间</div>
              <div>{{ orderDatalist.orderInfo._refund_time || "-" }}</div>
            </li>
            <li class="item" v-else>
              <div class="title">支付时间</div>
              <div>{{ orderDatalist.orderInfo._pay_time || "-" }}</div>
            </li>
          </ul>
        </div>
        <Tabs v-model="activeName" :animated="false">
          <TabPane label="订单信息" name="detail" :index="6">
            <div class="section" v-if="!formType">
              <div class="title">退款信息</div>
              <ul class="list">
                <li class="item">
                  <div>退款原因：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.refund_reason || "-" }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="
                    parseFloat(orderDatalist.orderInfo.refund_price) ||
                    parseFloat(orderDatalist.orderInfo.refunded_price)
                  "
                >
                  <div>退款金额：</div>
                  <div class="value">
                    {{
                      parseFloat(orderDatalist.orderInfo.refunded_price)
                        ? parseFloat(orderDatalist.orderInfo.refunded_price)
                        : parseFloat(orderDatalist.orderInfo.refund_price) || 0
                    }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="parseFloat(orderDatalist.orderInfo.back_integral)"
                >
                  <div>退回积分：</div>
                  <div class="value">
                    {{
                      parseFloat(orderDatalist.orderInfo.back_integral) || "-"
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>退款说明：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.refund_explain || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>退款凭证：</div>
                  <div class="value">
                    <div
                      class="image"
                      v-for="(img, i) in orderDatalist.orderInfo.refund_img"
                      :key="i"
                      v-viewer
                    >
                      <img v-lazy="img" />
                    </div>
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="!formType && orderDatalist.orderInfo.refund_express_name"
            >
              <div class="title">退货物流信息</div>
              <ul class="list">
                <li class="item">
                  <div>物流公司：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.refund_express_name || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>物流单号：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.refund_express || "-" }}
                    <span class="logisticsLook" @click="openRefundLogistics"
                      >查询</span
                    >
                  </div>
                </li>
                <li class="item">
                  <div>联系电话：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.refund_phone || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>退货说明：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.refund_goods_explain || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>退货凭证：</div>
                  <div class="value">
                    <div
                      class="image"
                      v-for="(img, i) in orderDatalist.orderInfo
                        .refund_goods_img"
                      :key="i"
                      v-viewer
                    >
                      <img v-lazy="img" />
                    </div>
                  </div>
                </li>
              </ul>
            </div>
            <div class="section">
              <div class="title">用户信息</div>
              <ul class="list">
                <li class="item">
                  <div>用户UID：</div>
                  <div class="value">
                    {{
                      orderDatalist.userInfo.uid
                        ? orderDatalist.userInfo.uid
                        : "游客"
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>用户昵称：</div>
                  <div class="value">
                    {{
                      orderDatalist.userInfo.uid
                        ? orderDatalist.userInfo.nickname
                        : "游客"
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>绑定电话：</div>
                  <div class="value">
                    {{ orderDatalist.userInfo.phone || "-" }}
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="orderDatalist.orderInfo.product_type == 0"
            >
              <div class="title">收货信息</div>
              <ul class="list">
                <li class="item">
                  <div class="value">
                    收货人：{{ orderDatalist.orderInfo.real_name || "-" }}
                  </div>
                </li>
              </ul>
              <ul class="list">
                <li class="mt10">
                  <div class="value">
                    收货电话：{{ orderDatalist.orderInfo.user_phone || "-" }}
                  </div>
                </li>
              </ul>
              <ul class="list">
                <li class="mt10">
                  <div class="value">
                    收货地址：{{ orderDatalist.orderInfo.user_address || "-" }}
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="
                orderDatalist.orderInfo.fictitious_content &&
                orderDatalist.orderInfo.cartInfo[0].product_type != 1
              "
            >
              <div class="title">虚拟发货</div>
              <ul class="list">
                <li class="item">
                  <div class="value">
                    {{ orderDatalist.orderInfo.fictitious_content }}
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="orderDatalist.orderInfo.cartInfo[0].product_type == 1"
            >
              <div class="title">卡密发货</div>
              <div v-if="orderDatalist.orderInfo.virtual.length">
                <div
                  class="list"
                  v-for="(item, index) in orderDatalist.orderInfo.virtual"
                  :key="index"
                >
                  <div class="item">
                    <div>卡号{{ index + 1 }}：</div>
                    <div class="value">{{ item.card_no }}</div>
                  </div>
                  <div class="item">
                    <div>密码{{ index + 1 }}：</div>
                    <div class="value">{{ item.card_pwd }}</div>
                  </div>
                </div>
              </div>
              <ul class="list" v-else>
                <li class="item">
                  <div class="value">
                    {{ orderDatalist.orderInfo.virtual_info || "-" }}
                  </div>
                </li>
              </ul>
            </div>
            <!-- 供应商 -->
            <div class="section" v-if="orderDatalist.orderInfo.supplierInfo">
              <div class="title">供应商信息</div>
              <ul class="list">
                <li class="item">
                  <div>供应商：</div>
                  <div class="value">
                    {{
                      orderDatalist.orderInfo.supplierInfo.supplier_name
                        ? orderDatalist.orderInfo.supplierInfo.supplier_name
                        : "游客"
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>供应商姓名：</div>
                  <div class="value">
                    {{
                      orderDatalist.orderInfo.supplierInfo.name
                        ? orderDatalist.orderInfo.supplierInfo.name
                        : "游客"
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>联系方式：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.supplierInfo.phone || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>供应商邮箱：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.supplierInfo.email || "-" }}
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="orderDatalist.orderInfo.product_type == 6"
            >
              <div class="title">预约信息</div>
              <ul class="list">
                <li class="item">
                  <div>服务类型：</div>
                  <div class="value">
                    {{
                      orderDatalist.orderInfo.reservation_type == 3
                        ? "上门服务"
                        : "到店服务"
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>预约模式：</div>
                  <div class="value">
                    {{
                      orderDatalist.orderInfo.reservation_time_id > 0
                        ? "购买时预约"
                        : "先买后约"
                    }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="orderDatalist.orderInfo.reservation_time"
                >
                  <div>预约日期：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.reservation_time }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="orderDatalist.orderInfo.reservation_show_time"
                >
                  <div>预约时段：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.reservation_show_time }}
                  </div>
                </li>
                <li class="item">
                  <div>预约人：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.real_name }}
                  </div>
                </li>
                <li class="item">
                  <div>预约电话：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.user_phone }}
                  </div>
                </li>
              </ul>
              <div
                class="item"
                v-if="orderDatalist.orderInfo.user_address.trim()"
              >
                <div>预约地址：</div>
                <div class="value">
                  {{ orderDatalist.orderInfo.user_address }}
                </div>
              </div>
            </div>
            <div class="section">
              <div class="title">订单信息</div>
              <ul class="list">
                <li class="item">
                  <div>创建时间：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo._add_time || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>商品总数：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.total_num || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>商品总价：</div>
                  <div class="value">
                    ￥{{ orderDatalist.orderInfo.total_price || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>优惠券来源：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.give_coupon[0] ? orderDatalist.orderInfo.give_coupon[0].author : "-" }}
                  </div>
                </li>
                <li class="item" v-if="orderDatalist.orderInfo.pay_integral">
                  <div>实付积分：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.pay_integral }}
                  </div>
                </li>
                <li class="item">
                  <div>优惠券金额：</div>
                  <div class="value">
                    ￥{{ orderDatalist.orderInfo.coupon_price || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>积分抵扣：</div>
                  <div class="value">
                    ￥{{ orderDatalist.orderInfo.deduction_price || 0.0 }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="parseFloat(orderDatalist.orderInfo.use_integral)"
                >
                  <div>使用积分：</div>
                  <div class="value">
                    {{ parseFloat(orderDatalist.orderInfo.use_integral) }}
                  </div>
                </li>
                <li class="item">
                  <div>支付邮费：</div>
                  <div class="value">
                    ￥{{ orderDatalist.orderInfo.pay_postage || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>会员商品优惠：</div>
                  <div class="value">
                    ￥{{ orderDatalist.orderInfo.vip_true_price || 0.0 }}
                  </div>
                </li>
                <li
                  v-if="orderDatalist.orderInfo.first_order_price != 0"
                  class="item"
                >
                  <div>新人首单优惠：</div>
                  <div class="value">
                    ￥{{ orderDatalist.orderInfo.first_order_price }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="
                    orderDatalist.orderInfo.shipping_type === 2 &&
                    orderDatalist.orderInfo.refund_status === 0 &&
                    orderDatalist.orderInfo.paid === 1
                  "
                >
                  <div>门店名称：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo._store_name || "-" }}
                  </div>
                </li>
                <li
                  class="item"
                  v-if="
                    orderDatalist.orderInfo.shipping_type === 2 &&
                    orderDatalist.orderInfo.refund_status === 0 &&
                    orderDatalist.orderInfo.paid === 1
                  "
                >
                  <div>核销码：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.verify_code || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>推广人：</div>
                  <div class="value">
                    {{ orderDatalist.userInfo.spread_name }}/ID:{{
                      orderDatalist.userInfo.spread_uid
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>支付时间：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo._pay_time || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>支付方式：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo._status._payType || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>来源：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.source_name|| "-" }}
                  </div>
                </li>
                <li class="item" v-if="orderDatalist.orderInfo.store_order_sn">
                  <div>原订单号：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.store_order_sn }}
                  </div>
                </li>
                <li
                  class="item"
                  v-for="(item, index) in orderDatalist.orderInfo
                    .promotions_detail"
                  :key="index"
                >
                  <div>{{ item.title }}：</div>
                  <div class="value">
                    ￥{{ parseFloat(item.promotions_price).toFixed(2) }}
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="orderDatalist.orderInfo.delivery_type === 'express'"
            >
              <div class="title">物流信息</div>
              <ul class="list">
                <li class="item">
                  <div>快递公司：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.delivery_name || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>快递单号：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.delivery_id
                    }}<span class="logisticsLook" @click="openLogistics"
                      >查询</span
                    >
                  </div>
                </li>
              </ul>
            </div>
            <div
              class="section"
              v-if="orderDatalist.orderInfo.delivery_type === 'send'"
            >
              <div class="title">配送信息</div>
              <ul class="list">
                <li class="item">
                  <div>配送类型：</div>
                  <div class="value">
                    送货
                  </div>
                </li>
                <li class="item">
                  <div>送货人姓名：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.delivery_name || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>送货人电话：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.delivery_id || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>配送时间：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.delivery_time || orderDatalist.orderInfo.estimate_time || '-' }}
                  </div>
                </li>
                <li class="item">
                  <div>送达凭证：</div>
                  <div class="value">
                    <div>
                      {{ orderDatalist.orderInfo.delivery_voucher || "-" }}
                    </div>
                    <div
                      v-if="orderDatalist.orderInfo.delivery_voucher_img"
                      v-viewer
                    >
                      <div
                        v-for="(img, i) in orderDatalist.orderInfo
                          .delivery_voucher_img"
                        :key="i"
                        class="image"
                      >
                        <img v-lazy="img" />
                      </div>
                    </div>
                  </div>
                </li>
              </ul>
            </div>
            <div
              v-if="
                (orderDatalist.orderInfo.custom_form &&
                  orderDatalist.orderInfo.custom_form.length &&
                  isShow) ||
                (orderDatalist.orderInfo.product_type == 6 &&
                  orderDatalist.orderInfo.reservation_time_id > 0) ||
                orderDatalist.orderInfo.product_type != 6
              "
              class="section"
            >
              <div class="title">自定义留言</div>
              <div
                v-for="(j, jindex) in orderDatalist.orderInfo.custom_form"
                :key="jindex"
              >
                <div
                  class="item"
                  v-if="orderDatalist.orderInfo.product_type == 6 && j.length"
                >
                  {{ orderDatalist.orderInfo.custom_form_title
                  }}{{ jindex + 1 }}
                </div>
                <ul class="list">
                  <li
                    v-for="(item, index) in j"
                    :key="index"
                    class="item"
                    v-if="
                      (item.value &&
                        ['uploadPicture', 'dateranges'].indexOf(item.name) ==
                          -1) ||
                      (item.value.length &&
                        ['uploadPicture', 'dateranges'].indexOf(item.name) !=
                          -1)
                    "
                  >
                    <div class="txtVal">{{ item.titleConfig.value }}：</div>
                    <div v-if="item.name === 'dateranges'" class="value">
                      {{ item.value[0] + "/" + item.value[1] }}
                    </div>
                    <div
                      v-else-if="item.name === 'uploadPicture'"
                      class="value"
                      v-viewer
                    >
                      <div
                        v-for="(img, i) in item.value"
                        :key="i"
                        class="image"
                      >
                        <img v-lazy="img" />
                      </div>
                    </div>
                    <div v-else class="value">{{ item.value || "-" }}</div>
                  </li>
                </ul>
              </div>
            </div>
            <div class="section" v-if="orderDatalist.orderInfo.mark">
              <div class="title">买家备注</div>
              <ul class="list">
                <li class="item">
                  <div class="value">
                    {{ orderDatalist.orderInfo.mark || "-" }}
                  </div>
                </li>
              </ul>
            </div>
            <div class="section" v-if="orderDatalist.orderInfo.remark">
              <div class="title">订单备注</div>
              <ul class="list">
                <li class="item">
                  <div>备注：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.remark || "-" }}
                  </div>
                </li>
              </ul>
            </div>
            <div class="section" v-if="orderDatalist.orderInfo.refuse_reason">
              <div class="title">拒绝退款原因</div>
              <ul class="list">
                <li class="item">
                  <div class="value">
                    {{ orderDatalist.orderInfo.refuse_reason }}
                  </div>
                </li>
              </ul>
            </div>
            <div v-if="orderDatalist.orderInfo.invoice" class="section">
              <div class="title">发票信息</div>
              <ul class="list">
                <li class="item">
                  <div>发票类型：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.type | invoiceType }}
                  </div>
                </li>
                <li class="item">
                  <div>抬头类型：</div>
                  <div class="value">
                    {{
                      orderDatalist.orderInfo.invoice.header_type
                        | invoiceHeaderType
                    }}
                  </div>
                </li>
                <li class="item">
                  <div>发票抬头：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.name || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>税号：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.duty_number || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>邮箱：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.email || "-" }}
                  </div>
                </li>
                <li
                  v-if="orderDatalist.orderInfo.invoice.type == 2"
                  class="item"
                >
                  <div>开户银行：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.bank || "-" }}
                  </div>
                </li>
                <li
                  v-if="orderDatalist.orderInfo.invoice.type == 2"
                  class="item"
                >
                  <div>企业地址：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.address || "-" }}
                  </div>
                </li>
                <li class="item">
                  <div>企业电话：</div>
                  <div class="value">
                    {{ orderDatalist.orderInfo.invoice.drawer_phone || "-" }}
                  </div>
                </li>
              </ul>
            </div>
          </TabPane>
          <TabPane label="销售业绩分配" name="product" :index="1">
            <Table
              :load-data="handleorderCardBenefits"
              :columns="columns1"
              :data="orderDatalist.orderInfo.cartInfo"
              highlight-row
              row-key="id"
            >
              <template slot-scope="{ row }" slot="product">
                <Tooltip theme="dark" max-width="300" :delay="600">
                  <div class="product" style="max-width: 400px">
                    <div class="image" v-viewer>
                      <img
                        v-lazy="
                          row.productInfo.attrInfo
                            ? row.productInfo.attrInfo.image
                            : row.productInfo.image
                        "
                      />
                    </div>
                    <div class="title">
                      <div class="line2">
                        <span class="font-color-red" v-if="row.is_gift"
                          >[赠品]</span
                        >
                        {{ row.productInfo.store_name }} |
                        {{
                          row.productInfo.attrInfo
                            ? row.productInfo.attrInfo.suk
                            : ""
                        }}
                      </div>
					  <div v-if="row.productInfo.attrInfo.code">【商品编号：{{row.productInfo.attrInfo.code}}】</div>
                    </div>
                  </div>
                  <div slot="content">
                    <div>
                      <p class="font-color-red" v-if="row.is_gift">[赠品]</p>
                      <p>{{ row.productInfo.store_name }}</p>
                      <p>
                        {{
                          row.productInfo.attrInfo
                            ? row.productInfo.attrInfo.suk
                            : ""
                        }}
                      </p>
                    </div>
                  </div>
                </Tooltip>
              </template>
              <template slot-scope="{ row, index }" slot="yeji">
                <div v-if="row.is_dingzhi == 1">
                  <div @click="doYeji(row, index, 2)" v-if="row.yeji_staff">{{ row.yeji_staff }}</div>
                  <div @click="doYeji(row, index, 2)">未分配</div>
                </div>
                <div v-else>
                  <a @click="doYeji(row, index, 2)" v-if="row.yeji_staff">{{ row.yeji_staff }}</a>
                  <a v-else @click="doYeji(row, index, 2)">未分配</a>
                </div>
              </template>
			  <template slot-scope="{ row }" slot="writeOffCount">
			    <div v-if="row.card_product_id || row.product_type == 4 || row.product_type == 5">
			    	<div>已核销：{{row.writeOffNum}}</div>
			    	<div>剩余次数：{{row.write_surplus_times}}</div>
			    </div>
			  </template>
            </Table>
          </TabPane>
          <TabPane label="订单记录" name="record" :index="3">
            <Table
              :columns="columns2"
              :data="recordData"
              :loading="loading"
              no-data-text="暂无数据"
              highlight-row
              no-filtered-data-text="暂无筛选结果"
            ></Table>
          </TabPane>
          <TabPane
            label="发货记录"
            name="recordList"
            v-if="splitList.length"
            :index="4"
          >
            <Table
              :columns="columnSplit"
              :data="splitList"
              :loading="loading"
              no-data-text="暂无数据"
              highlight-row
              no-filtered-data-text="暂无筛选结果"
            >
              <template slot-scope="{ row }" slot="order_id">
                <div>{{ row.order_id }}</div>
                <span class="supplierName" v-if="row.supplier_name"
                  >[{{ row.supplier_name }}]</span
                >
                <span class="supplierName" v-if="row.store_name"
                  >[{{ row.store_name }}]</span
                >
                <div class="red" v-if="row.refund_status == 2">[已退款]</div>
              </template>
              <template slot-scope="{ row }" slot="product">
                <Tooltip theme="dark" max-width="300" :delay="600">
                  <div
                    class="product productTime"
                    v-for="(j, index) in row._info"
                    :key="index"
                  >
                    <div class="image" v-viewer>
                      <img
                        v-lazy="
                          j.cart_info.productInfo.attrInfo
                            ? j.cart_info.productInfo.attrInfo.image
                            : j.cart_info.productInfo.image
                        "
                      />
                    </div>
                    <div class="title">
                      <div class="line2">
                        <span class="font-color-red" v-if="j.cart_info.is_gift"
                          >[赠品]</span
                        >
                        {{ j.cart_info.productInfo.store_name }} |
                        {{
                          j.cart_info.productInfo.attrInfo
                            ? j.cart_info.productInfo.attrInfo.suk
                            : ""
                        }}
                      </div>
                    </div>
                  </div>
                  <div slot="content">
                    <div v-for="(j, index) in row._info" :key="index">
                      <p class="font-color-red" v-if="j.cart_info.is_gift">
                        [赠品]
                      </p>
                      <p>{{ j.cart_info.productInfo.store_name }}</p>
                      <p>
                        {{
                          j.cart_info.productInfo.attrInfo
                            ? j.cart_info.productInfo.attrInfo.suk
                            : ""
                        }}
                      </p>
                      <p class="tabBox_pice">
                        {{
                          "￥" +
                          j.cart_info.sum_price +
                          " x " +
                          j.cart_info.cart_num
                        }}
                      </p>
                    </div>
                  </div>
                </Tooltip>
              </template>
              <template slot-scope="{ row }" slot="deliveryInfo">
                <div v-if="row.add_time">
                  <span>创建时间：</span>{{ row.add_time }}
                </div>
                <div v-if="row.delivery_time">
                  <span>发货时间：</span>{{ row.delivery_time }}
                </div>
                <div
                  v-if="
                    row.delivery_type == 'express' ||
                    row.delivery_type == 'send' ||
                    row.delivery_type == 'fictitious'
                  "
                >
                  发货方式：
                  <span v-if="row.delivery_type == 'express'">物流发货</span>
                  <span v-if="row.delivery_type == 'send'">送货</span>
                  <span v-if="row.delivery_type == 'fictitious'">虚拟发货</span>
                </div>
                <div v-if="row.delivery_name">
                  快递公司：{{ row.delivery_name }}
                </div>
                <template v-if="row.delivery_id">
                  <div
                    v-if="row.delivery_type == 'express'"
                    @click="openItemLogistics(row)"
                  >
                    快递单号：<span style="color: #1890ff; cursor: pointer">{{
                      row.delivery_id
                    }}</span>
                  </div>
                  <div v-if="row.delivery_type == 'send'">
                    快递单号：<span style="color: #1890ff">{{
                      row.delivery_id
                    }}</span>
                  </div>
                </template>
              </template>
              <template
                slot-scope="{ row, index }"
                slot="action"
                v-if="!isAgentAdmin && !formPage"
              >
                <a
                  :disabled="openErp"
                  @click="distribution(row, 1)"
                  v-if="row._status === 2 && !row.supplier_name && !distHide"
                  >分配</a
                >
                <Divider
                  type="vertical"
                  v-if="
                    row._status === 2 && !row.supplier_name && row.store_id == 0
                  "
                />
                <a
                  :disabled="openErp"
                  @click="edit(row, 1)"
                  v-if="row._status === 1"
                  >订单改价</a
                >
                <a
                  :disabled="openErp"
                  @click="sendOrder(row, 1)"
                  v-if="
                    (row._status === 2 ||
                      row._status === 8 ||
                      row.status === 4) &&
                    row.shipping_type === 1 &&
                    (row.pinkStatus === null || row.pinkStatus === 2) &&
                    !row.supplier_name &&
                    row.store_id == 0
                  "
                  >发送货</a
                >
                <a
                  :disabled="openErp"
                  @click="delivery(row, 1)"
                  v-if="row._status === 4 && !row.split.length"
                  >配送信息</a
                >
                <Divider
                  type="vertical"
                  v-if="
                    row._status === 4 &&
                    !row.split.length &&
                    row.supplier_name &&
                    row.store_id == 0
                  "
                />
                <a
                  :disabled="openErp"
                  @click="bindWrite(row, 1)"
                  v-if="
                    row.shipping_type == 2 &&
                    (row.status == 0 || row.status == 5) &&
                    row.paid == 1 &&
                    row.refund_status === 0
                  "
                  >立即核销</a
                >

                <a
                  :disabled="openErp"
                  @click="btnClick(row)"
                  v-if="
                    row.supplier_name && row.status_name.status_name == '未发货'
                  "
                  >提醒发货</a
                >
                <Divider
                  type="vertical"
                  v-if="
                    row._status === 1 ||
                    ((row._status === 2 || row.split.length) &&
                      row.shipping_type === 1 &&
                      (row.pinkStatus === null || row.pinkStatus === 2)) ||
                    row._status === 4 ||
                    (row.shipping_type == 2 &&
                      (row.status == 0 || row.status == 5) &&
                      row.paid == 1 &&
                      row.refund_status === 0) ||
                    (row.supplier_name &&
                      row.supplier_name &&
                      row.status_name.status_name == '未发货')
                  "
                />
                <template>
                  <Dropdown
                    :transfer="true"
                    @on-click="changeMenu('', row, $event, 1)"
                  >
                    <a href="javascript:void(0)">
                      更多
                      <Icon type="ios-arrow-down"></Icon>
                    </a>
                    <DropdownMenu slot="list">
                      <DropdownItem
                        :disabled="openErp"
                        name="1"
                        ref="ones"
                        v-show="
                          row._status === 1 &&
                          row.paid === 0 &&
                          row.pay_type === 'offline'
                        "
                        >确认收款</DropdownItem
                      >
                      <DropdownItem :disabled="openErp" name="3"
                        >订单记录</DropdownItem
                      >
                      <DropdownItem
                        :disabled="openErp"
                        name="11"
                        v-show="row._status >= 3 && row.express_dump"
                        >电子面单打印</DropdownItem
                      >
                      <DropdownItem name="10" v-show="row._status >= 2"
                        >小票打印</DropdownItem
                      >
                      <DropdownItem
                        name="4"
                        v-show="
                          row._status !== 1 ||
                          (row._status === 3 &&
                            row.use_integral > 0 &&
                            row.use_integral >= row.back_integral)
                        "
                        >订单备注</DropdownItem
                      >
                      <DropdownItem
                        :disabled="openErp"
                        name="5"
                        v-show="
                          row.refund_type != 2 &&
                          row.refund_type != 4 &&
                          row.refund_type != 6 &&
                          row.paid == 1 &&
                          row.refund_status !== 2 &&
                          (row.split == null || row.split.length == 0)
                        "
                        >立即退款</DropdownItem
                      >
                      <DropdownItem
                        :disabled="openErp"
                        name="55"
                        v-show="row.refund_type == 2"
                        >同意退货</DropdownItem
                      >
                      <DropdownItem
                        :disabled="openErp"
                        name="8"
                        v-show="row._status === 4 && !row.split.length"
                        >已收货</DropdownItem
                      >
                      <DropdownItem
                        :disabled="openErp"
                        name="9"
                        v-if="row.is_del == 1"
                        >删除订单</DropdownItem
                      >
                    </DropdownMenu>
                  </Dropdown>
                </template>
              </template>
            </Table>
          </TabPane>
          <TabPane
            label="劳动业绩分配"
            name="writeOff"
            v-if="orderData.shipping_type == 2"
            :index="2"
          >
            <Table
              :columns="writeOffColumns"
              :data="writeOffData"
              no-data-text="暂无数据"
              highlight-row
              no-filtered-data-text="暂无筛选结果"
            >
              <template slot-scope="{ row, index }" slot="shouyi">
                <a @click="doYeji(row, index, 3)" v-if="row.yeji_staff">{{ row.yeji_staff }}</a>
                <a v-else @click="doYeji(row, index, 3)">未分配</a>
              </template>
              <template slot-scope="{ row }" slot="product">
                <div class="product">
                  <div class="image" v-viewer>
                    <img v-lazy="row.image" />
                  </div>
                </div>
              </template>
            </Table>
          </TabPane>
          <TabPane label="赠送记录" name="sendInfo" :index="5">
            <Table
              :columns="sendDetailColumn"
              :data="sendData"
              no-data-text="暂无数据"
              highlight-row
              no-filtered-data-text="暂无筛选结果"
            >
              <template slot-scope="{ row }" slot="product_type">
                <span v-if="row.product_type == 0">产品</span>
                <span v-else-if="row.product_type == 5">卡项</span>
                <span v-else-if="row.product_type == 6">项目</span>
              </template>
              <template slot-scope="{ row }" slot="begin_time_label">
                <span v-if="row.type == 1">-----</span>
                <span v-else>{{ row.begin_time_label }}</span>
              </template>
              <template slot-scope="{ row }" slot="write_days">
                <span v-if="row.type == 1">-----</span>
                <span v-else>{{ row.write_days }}</span>
              </template>
              <template slot-scope="{ row }" slot="end_time_label">
                <span v-if="row.type == 1">-----</span>
                <span v-else>{{ row.end_time_label }}</span>
              </template>
            </Table>
          </TabPane>
        </Tabs>
      </div>
    </Drawer>
    <Modal
      v-model="modal2"
      scrollable
      title="物流查询"
      width="350"
      class="order_box2"
    >
      <div class="logistics acea-row row-top">
        <div class="logistics_img">
          <img src="../../../../assets/images/expressi.jpg" />
        </div>
        <div class="logistics_cent">
          <span>物流公司：{{ logisticsChecked.delivery_name }}</span>
          <span>物流单号：{{ logisticsChecked.delivery_id }}</span>
        </div>
      </div>
      <div class="acea-row row-column-around trees-coadd">
        <div class="scollhide">
          <Timeline>
            <TimelineItem v-for="(item, i) in result" :key="i">
              <p class="time" v-text="item.time"></p>
              <p class="content" v-text="item.status"></p>
            </TimelineItem>
          </Timeline>
        </div>
      </div>
    </Modal>
	<addressEdit ref="addressEdit" @submitSuccess='submitSuccess'></addressEdit>
  <!-- 派单-配送员弹窗 -->
    <Modal v-model="modal3" :mask-closable="false" title="选择配送员" width="657" ok-text="确认" class-name="delivery-modal" @on-ok="onDeliveryOk">
      <Form
        ref="formItem"
        :model="formItem"
        @submit.native.prevent
      >
        <FormItem label="配送员信息：">
          <Input v-model="formItem.keyword" placeholder="请输入配送员ID/名称/手机号" v-width="202"></Input>
          <Button type="primary" class="ml-20" @click="getDeliveryList">查询</Button>
          <Button class="ml-10" @click="onReset">重置</Button>
        </FormItem>
      </Form>
      <Table ref="selection" :columns="columns3" :data="deliveryList"></Table>
    </Modal>
    <yeji :syncProduct="syncProduct" :yeji="setYeji" :staffIds="staffIds"  @closeYeji="closeYeji" :visible="yejiVisible" ref="yeji"></yeji>
  </div>
</template>

<script>
import { getYeji } from '@/api/yeji';
import {
  getExpress,
  getRefundExpress,
  getOrderRecord,
  splitOrder,
  remindOrder,
  writeoffRecords,
  orderCardBenefits,
  sendDetail,
  putDelivery,
} from "@/api/order";
import {
  orderDeliveryList,
  deliveryReassignApi,
  orderReissueOrderApi,
} from "@/api/store";
import template from "../../../setting/devise/template.vue";
import addressEdit from "../components/addressEdit.vue";
import yeji from '@/components/yeji';
export default {
  components: { template, addressEdit,yeji },
  name: "orderDetails",
  filters: {
    invoiceType: (value) => (value == 1 ? "电子普通发票" : "纸质专用发票"),
    invoiceHeaderType: (value) => (value == 1 ? "个人" : "企业"),
  },
  data() {
    return {
      staffIds:[],
      cart_id: 0,
      cart_index: 0,
      setYeji:{
        link_id:0,
        cart_id:0,
        price:0,
        goods_id:0,
        type:2,
        staffChoose:[]
      },
      syncProduct:[],
      yejiVisible: false,
      isShow: 0,
      modal2: false,
      modals: false,
      grid: {
        xl: 8,
        lg: 8,
        md: 12,
        sm: 24,
        xs: 24,
      },
      result: [],
      // columns1Items: [
      //   {
      //     title: "商品编码",
      //     render: (h, params) => {
      //       return h(
      //         "div",
      //         params.row.productInfo.spec_type
      //           ? params.row.productInfo.attrInfo.code
      //           : params.row.productInfo.code
      //       );
      //     },
      //   },
      // ],
      columns1: [
        {
          tree: true,
          title: "商品信息",
          slot: "product",
          minWidth: 300,
          className: "table-product-column",
        },
        {
          title: "售价",
          render: (h, params) => {
            return h("div", params.row.productInfo.attrInfo.price);
          },
        },
        {
          title: "数量",
          render: (h, params) => {
            return h(
              "div",
               params.row.card_product_id ? params.row.write_times : params.row.cart_num
            );
          },
        },
        {
          title: "小计",
          render: (h, params) => {
            return h(
              "div",
              params.row.card_product_id
                ? params.row.productInfo.attrInfo.price
                : params.row.productInfo.attrInfo.price * params.row.cart_num
            );
          },
        },
        {
          title: "实付金额",
          render: (h, params) => {
            return h("div", params.row.pay_price);
          },
        },
        {
          title: "余额支付",
          render: (h, params) => {
            const v = Number(params.row.yue_pay_amount || 0);
            return h("div", isNaN(v) ? 0 : v);
          },
        },
        {
          title: "卡升级抵扣",
          render: (h, params) => {
            const v = Number(params.row.card_upgrade_amount || 0);
            return h("div", isNaN(v) ? 0 : v);
          },
        },
        {
          tree: true,
          title: "销售",
          slot: "yeji",
          width: 120,
        },
      ],
      columns2: [
        {
          title: "订单ID",
          key: "oid",
          minWidth: 40,
        },
        {
          title: "操作记录",
          key: "change_message",
          minWidth: 280,
        },
        {
          title: "操作人",
          key: "change_manager",
          minWidth: 180,
        },
        {
          title: "操作时间",
          key: "change_time",
          width: 160,
        },
      ],
      columnSplit: [
        {
          title: "订单号",
          slot: "order_id",
          minWidth: 100,
        },
        {
          title: "商品信息",
          slot: "product",
          minWidth: 250,
        },
        {
          title: "发货信息",
          slot: "deliveryInfo",
          minWidth: 100,
        },
        {
          title: "操作",
          slot: "action",
          minWidth: 90,
        },
      ],
      recordData: [],
      activeName: "product",
      orderData: {},
      splitList: [],
      logisticsChecked: {
        delivery_name: "",
        delivery_id: "",
      },
      writeOffColumns: [
        {
          title: "商品ID",
          key: "product_id",
          minWidth: 40,
        },
        {
          title: "图片",
          slot: "product",
          minWidth: 60,
        },
        {
          title: "商品名称",
          key: "store_name",
          minWidth: 100,
        },
        {
          title: "核销数",
          key: "writeoff_num",
          minWidth: 40,
        },
        {
          title: "核销时间",
          key: "add_time",
          minWidth: 180,
        },
        {
          title: "核销门店",
          key: "name",
          minWidth: 100,
        },
        {
          title: "核销人员",
          key: "staff_name",
          minWidth: 100,
        },
        {
          title: "手艺人",
          slot: "shouyi",
          minWidth: 100,
        },
      ],
      sendDetailColumn: [
        {
          title: "类型",
          slot: "product_type",
          minWidth: 40,
        },
        {
          title: "名称",
          key: "store_name",
          minWidth: 40,
        },
        {
          title: "数量",
          key: "num",
          minWidth: 40,
        },
        {
          title: "生效时间",
          slot: "begin_time_label",
          minWidth: 40,
        },
        {
          title: "有效天数",
          slot: "write_days",
          minWidth: 40,
        },
        {
          title: "结束时间",
          slot: "end_time_label",
          minWidth: 40,
        },
      ],
      sendData: [],
      writeOffData: [],
      isAgentAdmin: this.__isAgentPath(),
      modal3: false,
      columns3: [
        {
            title: ' ',
            width: 60,
            align: 'center',
            render: (h, params) => {
              return h('Radio', {
                props: {
                  value: params.row.id == this.deliveryId,
                  disabled: params.row.disabled,
                },
                on: {
                  'on-change': () => {
                    this.deliveryId = params.row.id;
                  },
                }
              });
            },
        },
        {
            title: 'ID',
            key: 'id'
        },
        {
            title: '配送员名称',
            key: 'wx_name'
        },
        {
            title: '手机号码',
            key: 'phone'
        },
        {
            title: '待配送数量',
            key: 'unsend',
        },
      ],
      deliveryList: [],
	    deliveryId: 0,
	    formItem: {
        type: 1,
        relation_id: 0,
        field_key: 'all',
        keyword: '',
      },
    };
  },
  props: {
    orderDatalist: Object,
    orderId: Number,
    rowActive: Object,
    formType: {
      type: Number,
      default: 0,
    },
    openErp: {
      type: Boolean,
      default: false,
    },
    distHide: {
      type: Number,
      default: 0,
    },
	formPage: {
	  type: String,
	  default:''
	}
  },
  watch: {
    orderDatalist(value) {
      this.orderData = value.orderInfo;
	  value.orderInfo.cartInfo.forEach(item=>{
	  	item.writeOffNum = this.$computes.Sub(item.write_times,item.write_surplus_times)
	  })
      this.getList(
        !this.formType ? value.orderInfo.store_order_id : value.orderInfo.id
      );
      if (this.formType) {
        this.getSplitOrder(value.orderInfo.id);
      }
      // if (value.orderInfo.type == 11) {
      //   if (this.columns1[0].title == "商品编码") {
      //     this.columns1.shift();
      //   }
      // } else {
      //   if (this.columns1[0].title != "商品编码") {
      //     this.columns1.unshift(this.columns1Items[0]);
      //   }
      // }
	  const writeOffIndex = this.columns1.findIndex(
	    (item) => item.title === '核销次数'
	  );
	  if(writeOffIndex == -1 && [4, 5].includes(value.orderInfo.product_type)){
		  let obj = {
			  title: "核销次数",
			  slot: "writeOffCount",
			  minWidth: 40,
		  }
		  this.columns1.push(obj);
	  }else if(writeOffIndex !== -1 && ![4, 5].includes(value.orderInfo.product_type)){
		  this.columns1.splice(writeOffIndex, 1)
	  }
    },
    orderData(value) {
      if (value && value.custom_form && value.custom_form.length) {
        value.custom_form.forEach((item) => {
          if (item.length) {
            item.forEach((j) => {
              if (j.value) {
                return (this.isShow = 1);
              }
            });
          }
        });
      }
      // 如果是核销订单，则获取核销记录
      if (value.shipping_type == 2) {
        this.writeoffRecords();
      }
      if (this.orderId) {
        this.getSendDetails();
      }
    },
  },
  mounted() {
    if (
      this.orderData &&
      this.orderData.custom_form &&
      this.orderData.custom_form.length
    ) {
      this.orderData.custom_form.forEach((item) => {
        if (item.length) {
          item.forEach((j) => {
            if (j.value) {
              return (this.isShow = 1);
            }
          });
        }
      });
    }
  },
  methods: {
    closeYeji(){
      this.yejiVisible=false;
      const keepTab = this.activeName;
      this.$parent.getData(this.orderId, 1, keepTab);
      this.$parent.getList();
    },
    doYeji(row, index, yejiType){
      this.cart_index = index;
      const cartLineId = row.cart_id || row.id;
      this.cart_id = cartLineId;
      this.setYeji.staffChoose=[];
      this.staffIds=[];
      let that=this;
      const t = yejiType != null ? yejiType : 2;
      const oid = this.orderData.id || this.orderId || (this.orderDatalist && this.orderDatalist.orderInfo && this.orderDatalist.orderInfo.id);
      let linkId = oid;
      if (t === 3) {
        linkId = row.id;
      }
      if (!linkId || !cartLineId) {
        this.$Message.warning('订单或购物车数据异常，无法分配业绩');
        return;
      }
      getYeji({ link_id: linkId, type: t, goods_id: row.product_id, price: row.pay_price, cart_id: cartLineId }).then((res)=>{
        if(res.data) {
          that.setYeji = res.data;
          const pt = row.product_type != null && row.product_type !== ''
            ? Number(row.product_type)
            : (row.productInfo && row.productInfo.product_type != null
              ? Number(row.productInfo.product_type)
              : null);
          that.$set(that.setYeji, 'product_type', pt);
          res.data.staffChoose.forEach(function (item){
            that.staffIds.push(item.staff_id);
          })
        }
        const storeId = row.store_id || (that.orderData && that.orderData.store_id) || 0;
        that.$nextTick(() => {
          if (!that.$refs.yeji) {
            that.$Message.error('业绩组件未就绪，请重试');
            return;
          }
          that.$refs.yeji.staffForm.store_id = storeId;
          that.$refs.yeji.getStaff();
          that.yejiVisible=true;
        });
      }).catch((err) => {
        that.$Message.error((err && err.msg) || '获取业绩信息失败');
      });
    },
	//修改地址
	showEditAddress(){
		let orderInfo = this.orderDatalist.orderInfo;
		this.$refs.addressEdit.editAddressFormShow = true;
		this.$refs.addressEdit.id = this.orderId;
		this.$refs.addressEdit.formEditAddress.real_name = orderInfo.real_name;
		this.$refs.addressEdit.formEditAddress.user_phone = orderInfo.user_phone;
		this.$refs.addressEdit.formEditAddress.user_address = orderInfo.user_address;
	},
	submitSuccess(){
		this.$parent.getData(this.orderId);
		this.$parent.getList();
	},
    openItemLogistics(row) {
      this.logisticsChecked.delivery_name = row.delivery_name;
      this.logisticsChecked.delivery_id = row.delivery_id;
      this.modal2 = true;
      getExpress(row.id)
        .then(async (res) => {
          this.result = res.data.result;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    openLogistics() {
      this.logisticsChecked.delivery_name =
        this.orderDatalist.orderInfo.delivery_name;
      this.logisticsChecked.delivery_id =
        this.orderDatalist.orderInfo.delivery_id;
      this.modal2 = true;
      this.getOrderData();
    },
    openRefundLogistics() {
      this.modal2 = true;
      getRefundExpress(this.orderDatalist.orderInfo.id)
        .then((res) => {
          this.logisticsChecked.delivery_name = res.data.delivery_name;
          this.logisticsChecked.delivery_id = res.data.delivery_id;
          this.result = res.data.result;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 提醒发货
    btnClick(row) {
      let data = {
        supplier_id: row.supplier_id,
        id: row.id,
      };
      remindOrder(data)
        .then(async (res) => {
          this.$Message.success(res.msg);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 获取订单物流信息
    getOrderData() {
      getExpress(
        !this.formType
          ? this.orderDatalist.orderInfo.store_order_id
          : this.orderDatalist.orderInfo.id
      )
        .then(async (res) => {
          this.result = res.data.result;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    //发货记录
    getSplitOrder(id) {
      if (!id) {
        return;
      }
      splitOrder(id, { status: 2 })
        .then((res) => {
          this.splitList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getList(id) {
      let data = {
        id: id,
        datas: this.page,
      };
      this.loading = true;
      getOrderRecord(data)
        .then(async (res) => {
          this.recordData = res.data;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    changeMenu(value, row, name, num) {
      const extractCartInfo = (list) => {
        const cartInfo = [];
        list.forEach((item) => {
          Object.values(item._info).forEach(value => {
            if (!value.cart_info.is_gift) {
              cartInfo.push(value.cart_info);
            }
          });
        });
        return cartInfo;
      };
      if (num) {
        const rowActive = JSON.parse(JSON.stringify(row));
        const refundList = this.splitList.filter(item => item.id == rowActive.id);
        rowActive.cart_info = extractCartInfo(refundList);
        this.$parent.changeMenu(rowActive, name, num);
      } else {
        if (value == 5) {
          const rowActive = JSON.parse(JSON.stringify(this.rowActive));
          const refundList = this.splitList.filter(item => !item.is_all_refund);
          rowActive.cart_info = extractCartInfo(refundList);
          this.$parent.changeMenu(rowActive, value);
        } else {
          this.$parent.changeMenu(this.rowActive, value);
        }
      }
    },
    distribution(row, num) {
      if (num) {
        this.$parent.distribution(row);
      } else {
        this.$parent.distribution(this.rowActive);
      }
    },
    edit(row, num) {
      if (num) {
        this.$parent.edit(row);
      } else {
        this.$parent.edit(this.rowActive);
      }
    },
    sendOrder(row, num) {
      if (num) {
        this.$parent.sendOrder(row, num);
      } else {
        this.$parent.sendOrder(this.rowActive);
      }
    },
    delivery(row, num) {
      if (num) {
        this.$parent.delivery(row, num);
      } else {
        this.$parent.delivery(this.rowActive);
      }
    },
    bindWrite(row, num) {
      if (num) {
        this.$parent.bindWrite(row);
      } else {
        this.$parent.bindWrite(this.rowActive);
      }
    },
    // 获取订单核销记录
    writeoffRecords() {
      writeoffRecords(this.orderId).then((res) => {
        this.writeOffData = res.data;
      });
    },
    getSendDetails() {
      sendDetail(this.orderId).then((res) => {
        this.sendData = res.data;
      });
    },
    // 卡项-卡项权益
    handleorderCardBenefits(item, callback) {
      orderCardBenefits(
        this.orderDatalist.orderInfo.store_order_id || this.orderId
      ).then((res) => {
        let list = res.data.map((item) => {
          return {
            ...item.cart_info,
            pay_price: item.payPrice,
			write_surplus_times: item.write_surplus_times,
			writeOffNum: this.$computes.Sub(item.write_times,item.write_surplus_times)
          };
        });
        callback(list);
      });
    },
    // 派单-打开配送员弹窗
    openModal3() {
      this.modal3 = true;
      this.deliveryId = 0;
      this.formItem.keyword = '';
      console.log(this.orderData)
      this.formItem.relation_id = this.orderData.store_id;
      this.getDeliveryList();
    },
    // 获取配送员
    getDeliveryList() {
      orderDeliveryList(this.formItem).then((res) => {
        this.deliveryList = res.data.list.map((item) => {
          return {
            ...item,
            disabled: item.uid === this.orderData.delivery_uid,
          };
        });
      });
    },
    // 重置
    onReset() {
      this.deliveryId = 0;
      this.formItem.keyword = '';
      this.getDeliveryList();
    },
    // 派单-确认
    async onDeliveryOk() {
      if (!this.deliveryId) {
        return this.$Message.error('请选择配送员');
      }
      let response;
      const datas = {};
      const deliveryItem = this.deliveryList.find((item) => {
        return item.id === this.deliveryId;
      });
      datas.sh_delivery_name = deliveryItem.wx_name;
      datas.sh_delivery_id = deliveryItem.phone;
      datas.sh_delivery_uid = deliveryItem.uid;
      // 改派
      if (this.orderData._status_new === 4) {
        response = await deliveryReassignApi(this.orderId, datas);
      } else {
        datas.type = 2;
        datas.delivery_type = 1;
        datas.sh_delivery = this.deliveryId;
        response = await putDelivery({ id: this.orderId, datas });
      }
      if (response.status == 200) {
        this.modal3 = false;
        this.$Message.success(this.orderData._status_new === 4 ? '改派成功' : '派单成功');
        this.$emit('submitFail', !response.data.dump || Array.isArray(response.data.dump) ? '' : response.data.dump.label);
      } else {
        this.$Message.error(response.msg);
      }
    },
    // 确认送达
    deliveryConfirm() {
      const { order_id, delivery_voucher, delivery_voucher_img } = this.orderDatalist.orderInfo;
      this.$modalSure({
        title: '确认送达',
        info: '确认送达后不可撤销，是否确认？',
        url: '/order/confirm/delivery',
        method: 'post',
        ids: {
          order_id,
          delivery_voucher,
          delivery_voucher_img,
        },
      }).then((res) => {
        this.$Message.success(res.msg);
        this.$emit('submitFail');
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    // 重新发单
    orderReissueOrder() {
      orderReissueOrderApi(this.orderData.id, {
        store_id: this.orderData.store_id,
      }).then((res) => {
        this.$Message.success(res.msg);
        this.$emit('submitFail');
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
  },
  computed: {},
};
</script>
<style scoped lang="stylus">
/deep/.delivery-modal {
  .ivu-modal-footer {
    border-top-color: transparent;
  }
}
.order_box .section .item .txtVal{
  max-width 100px;
}
.order_box .head .full .ivu-btn.on {
  color: #c5c8ce !important;
  background-color: #f7f7f7 !important;
  border-color: #dcdee2 !important;
}

.productTime~.productTime {
  margin-top: 10px;
}

.product .title .line2 {
  width: 330px;
}
</style>
<style scoped lang="stylus">
.supplierName {
  margin-top: 5px;
}

.ivu-description-list-title {
  margin-bottom: 16px;
  color: #17233d;
  font-weight: 500;
  font-size: 14px;
}

.logisticsLook {
  font-size: 13px;
  margin-left: 10px;
  color: #1890FF;
  cursor: pointer;
}

.value {
  word-break: break-all;
}

/deep/.ivu-icon-ios-more {
  font-size: 20px;
}

.logistics {
  align-items: cente;
  padding: 10px 0px;

  .logistics_img {
    width: 45px;
    height: 45px;
    margin-right: 12px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .logistics_cent {
    span {
      display: block;
      font-size: 12px;
    }
  }
}

.trees-coadd {
  width: 100%;
  height: 400px;
  border-radius: 4px;
  overflow: hidden;

  .scollhide {
    width: 100%;
    height: 100%;
    overflow: auto;
    margin-left: 18px;
    padding: 10px 0 10px 0;
    box-sizing: border-box;

    .content {
      font-size: 12px;
    }

    .time {
      font-size: 12px;
      color: #2d8cf0;
    }
  }
}

.order_box2 {
  position: absolute;
  z-index: 999999999;
}

.order_box >>> .ivu-modal-header {
  padding: 30px 16px !important;
}

.order_box >>> .ivu-card {
  font-size: 12px !important;
}

.fontColor1 >>> .ivu-description-term {
  color: red !important;
}

.fontColor1 >>> .ivu-description-detail {
  color: red !important;
  padding-bottom: 14px !important;
}

.fontColor2 >>> .ivu-description-detail {
  color: #733AF9 !important;
}

.order_box >>> .ivu-description-term {
  padding-bottom: 10px !important;
}

.order_box >>> .ivu-description-detail {
  padding-bottom: 10px !important;
}

.order_box >>> .ivu-modal-body {
  padding: 0 !important;
}

.fontColor3 >>> .ivu-description-term {
  color: #f1a417 !important;
}

.fontColor3 >>> .ivu-description-detail {
  color: #f1a417 !important;
}

.tabBoxPic {
  width: 50px;
  height: 50px;
  display: inline-block;
  vertical-align: top;
  margin-right: 6px;
}

.tabBox_img {
  width: 100%;
  height: 100%;
  border-radius: 4px;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
    padding: 2px;
  }
}

>>> .order_box {
  .head {
    padding: 30px 35px 25px;

    .full {
      display: flex;

      .iconfont {
        color: #1890FF;

        &.sale-after {
          color: #90ADD5;
        }
      }

      .text {
        align-self: center;
        flex: 1;
        min-width: 0;
        padding-left: 12px;
        border: 0;
        font-size: 13px;
        line-height: 13px;
        color: #606266;

        .title {
          margin-bottom: 10px;
          font-weight: 500;
          font-size: 16px;
          line-height: 16px;
          color: rgba(0, 0, 0, 0.85);
        }
      }

      .ivu-btn {
        margin-left: 12px;

        &:first-child {
          display: inline-block;
          border-color: #1890FF;
          margin-left: 0;
          background-color: #1890FF;
          color: #FFFFFF;
        }

        &:nth-child(2) {
          display: inline-block;
          border-color: #19BE6B;
          background-color: #19BE6B;
          color: #FFFFFF;
        }

        &:nth-child(3) {
          display: inline-block;
        }

        &:focus {
          box-shadow: none;
        }
      }

      .ivu-dropdown {
        margin-left: 12px;

        &:nth-child(n+5) {
          display: inline-block;
        }

        .ivu-btn {
          border-color: #DCDEE2;
          background-color: #FFFFFF;
          color: #515A6E;
        }
      }
    }

    .list {
      display: flex;
      margin-top: 20px;
      overflow: hidden;
      list-style: none;

      .item {
        flex: none;
        width: 195px;
        font-size: 14px;
        line-height: 14px;
        color: rgba(0, 0, 0, 0.85);

        .title {
          margin-bottom: 12px;
          font-size: 13px;
          line-height: 13px;
          color: #666666;
        }

        .value1 {
          color: #F56022;
        }

        .value2 {
          color: #1BBE6B;
        }

        .value3 {
          color: #1890FF;
        }

        .value4 {
          color: #6A7B9D;
        }

        .value5 {
          color: #F5222D;
        }
      }
    }
  }

  .section {
    padding: 25px 0;
    border-bottom: 1px dashed #EEEEEE;

    .title {
      padding-left: 10px;
      border-left: 3px solid #1890FF;
      font-size: 15px;
      line-height: 15px;
      color: #303133;
    }

    .list {
      display: flex;
      flex-wrap: wrap;
      list-style: none;
    }

    .item {
      flex: 0 0 calc((100% / 3));
      display: flex;
      margin-top: 16px;
      font-size: 13px;
    }
  }
}

color #606266 {
  &:nth-child(3n+1) {
    padding-right: 20px;
  }

  &:nth-child(3n+2) {
    padding-right: 10px;
    padding-left: 10px;
  }

  &:nth-child(3n+3) {
    padding-left: 20px;
  }
}

.value {
  flex: 1;

  .image {
    display: inline-block;
    width: 40px;
    height: 40px;
    margin: 0 12px 12px 0;
    vertical-align: middle;
  }

  img {
    width: 100%;
    height: 100%;
  }
}

.product {
  // display flex
  .image {
    width: 50px;
    height: 50px;
    display: inline-block;
    vertical-align: middle;
  }

  img {
    width: 100%;
    height: 100%;
    border-radius: 4px;
  }

  .title {
    width: calc(100% - 63px);
    padding-left: 13px;
    text-align: left;
    display: inline-block;
    vertical-align: middle;
  }
}

>>> .ivu-tabs {
  color: rgba(0, 0, 0, 0.85);

  .ivu-tabs-bar {
    border-bottom: 0;
    margin-bottom: 0;
    background-color: #F5F7FA;
  }

  .ivu-tabs-nav-container {
    font-size: 13px;
  }

  .ivu-tabs-nav-wrap {
    margin-bottom: 0;
  }

  .ivu-tabs-ink-bar {
    display: none;
  }

  .ivu-tabs-tab {
    padding: 7px 19px !important;
    margin-right: 0;
    line-height: 26px;
  }

  .ivu-tabs-tab-active {
    background-color: #FFFFFF;
    color: rgba(0, 0, 0, 0.85);

    &::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 2px;
      background-color: #1890FF;
    }
  }

  .ivu-tabs-tabpane {
    padding: 15px;

    &:first-child {
      padding: 0 25px;
    }
  }
}

>>> .ivu-table {
  .ivu-table-header {
    table {
      border-top: 0 !important;
    }
  }
}
/deep/.table-product-column .ivu-table-cell-slot {
  display: inline-block;
}
/deep/.table-product-column .ivu-table-cell-tree {
  text-align: center;
}
</style>
