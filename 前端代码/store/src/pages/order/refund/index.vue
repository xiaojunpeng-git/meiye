<template>
  <div>
    <!-- <div class="i-layout-page-header"> -->
      <!-- <PageHeader
        class="product_tabs"
        :title="$route.meta.title"
        hidden-breadcrumb
      ></PageHeader> -->
    <!-- </div> -->
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
        <Form
        ref="pagination"
        :model="pagination"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
         <Row type="flex" class="mt10">
        <Col class="ivu-text-left mr">
          <FormItem label="订单状态：">
            <Select
                v-model="pagination.refund_type"
                style="width: 250px"
				clearable
                @on-change="selectChange2"
              >
                <Option
                  v-for="(item, index) in num"
                    :key="index"
                  :value="index"
                  >{{ item.name }}</Option
                >
              </Select>
            <!-- <RadioGroup
              v-model="pagination.refund_type"
              type="button"
              @on-change="selectChange2(pagination.refund_type)"
            >
              <Radio v-for="(item, index) in num" :key="index" :label="index"
                >{{ item.name }} {{ '(' + item.num + ')' }}</Radio
              >
            </RadioGroup> -->
          </FormItem>
        </Col>
          <Col class="ml15 mr">
            <FormItem label="退款时间：">
              <DatePicker
                :editable="false"
                @on-change="onchangeTime"
                :value="timeVal"
                format="yyyy/MM/dd"
                type="daterange"
                placement="bottom-start"
                placeholder="自定义时间"
                style="width: 250px"
                :options="options"
              ></DatePicker>
            </FormItem>
          </Col>
          <Col class="ivu-text-left mr">
          <FormItem label="售后原因：">
            <Select
                v-model="refund_reason"
                style="width: 250px"
				        clearable
                @on-change="refundReasonChange"
              >
                <Option
                  v-for="(item, index) in refundReasonList"
                    :key="index"
                  :value="index"
                  >{{ item }}</Option
                >
              </Select>
          </FormItem>
        </Col>
            <Col  class="ivu-text-left ml15">
            <FormItem label="订单搜索：" label-for="title">
              <Input
               
                enter-button
                v-model="pagination.order_id"
                placeholder="请输入订单号"
                 style="width: 250px"
               
              />
         <Button type="primary" class="ml10 search"  @click="orderSearch">搜索</Button>
            </FormItem>
            </Col>

        </Row>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
    
      <Table
        :columns="thead"
        :data="tbody"
        ref="table"
        class="mt10"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="order_id">
          <span v-text="row.order_id" style="display: block"></span>
          <span
            v-show="row.is_del === 1 && row.delete_time == null"
            style="color: #ed4014; display: block"
            >用户已删除</span
          >
        </template>
        <template slot-scope="{ row, index }" slot="user">
          <div>用户名：{{ row.nickname }}</div>
          <div>用户ID：{{ row.uid }}</div>
        </template>
        <template slot-scope="{ row }" slot="apply_type">
          <Tag color="blue" size="medium" v-if="row.apply_type == 1">仅退款</Tag>
          <Tag color="blue" size="medium" v-if="row.apply_type == 2">退货退款(快递退回)</Tag>
          <Tag color="blue" size="medium" v-if="row.apply_type == 3">退货退款(到店退货)</Tag>
          <Tag color="blue" size="medium" v-if="row.apply_type == 4">商家主动退款</Tag>
        </template>
        <template slot-scope="{ row }" slot="refund_type">
          <Tag color="blue" size="medium" v-if="[0, 1, 2].includes(row.refund_type)">待处理</Tag>
          <Tag color="red" size="medium" v-if="row.refund_type == 3">拒绝退款</Tag>
          <Tag color="blue" size="medium" v-if="row.refund_type == 4">商品待退货</Tag>
          <Tag color="blue" size="medium" v-if="row.refund_type == 5">退货待收货</Tag>
          <Tag color="green" size="medium" v-if="row.refund_type == 6">已退款</Tag>
        </template>
        <template slot-scope="{ row, index }" slot="nickname">
          <div>
            {{ row.uid ? row.nickname : '游客'
            }}<span style="color: #ed4014" v-if="row.delete_time != null">
              (已注销)</span
            >
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="info">
          <div class="tabBox" v-for="(val, i) in row._info" :key="i">
            <div class="tabBox_img" v-viewer>
              <img
                v-lazy="
                  val.cart_info.productInfo.attrInfo
                    ? val.cart_info.productInfo.attrInfo.image
                    : val.cart_info.productInfo.image
                "
              />
            </div>
            <span class="tabBox_tit"
              >{{ val.cart_info.productInfo.store_name + ' | '
              }}{{
                val.cart_info.productInfo.attrInfo
                  ? val.cart_info.productInfo.attrInfo.suk
                  : ''
              }}</span
            >
            <span class="tabBox_pice">{{
              '￥' + val.cart_info.truePrice + ' x ' + val.cart_info.cart_num
            }}</span>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="order_info">
          <div>订单金额：￥{{ row.pay_price }}</div>
          <div>付款方式：{{ row.pay_type_name }}</div>
          <div>
            订单状态：<span v-html="row.status_name.status_name"></span>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="statusName">
          <div v-html="row.refund_reason" class="pt5"></div>
          <div v-html="row.refund_explain" class="pt5"></div>
          <div class="pictrue-box">
            <div
              v-viewer
              v-if="row.refund_img"
              v-for="(item, index) in row.refund_img || []"
              :key="index"
            >
              <img class="pictrue mr10" v-lazy="item" :src="item" />
            </div>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="statusGoodName">
          <div v-html="row.refund_goods_explain" class="pt5"></div>
          <div class="pictrue-box">
            <div
              v-viewer
              v-if="row.refund_goods_img"
              v-for="(item, index) in row.refund_goods_img || []"
              :key="index"
            >
              <img class="pictrue mr10" v-lazy="item" :src="item" />
            </div>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <!--          <a @click="edit(row)" v-if="row._status === 1">编辑</a>-->
          <!--          <a-->
          <!--            @click="sendOrder(row)"-->
          <!--            v-if="-->
          <!--              row._status === 2 && row.shipping_type === 1 && !row.pinkStatus-->
          <!--            "-->
          <!--            >发送货</a-->
          <!--          >-->
          <!--          <a-->
          <!--            @click="sendOrder(row)"-->
          <!--            v-if="-->
          <!--              row._status === 2 &&-->
          <!--              row.shipping_type === 1 &&-->
          <!--              row.pinkStatus === 2-->
          <!--            "-->
          <!--            >发送货</a-->
          <!--          >-->
          <!--          <a @click="delivery(row)" v-if="row._status === 4">配送信息</a>-->
          <!--          <a-->
          <!--            @click="bindWrite(row)"-->
          <!--            v-if="-->
          <!--              row.shipping_type == 2 &&-->
          <!--              row.status == 0 &&-->
          <!--              row.paid == 1 &&-->
          <!--              row.refund_status === 0-->
          <!--            "-->
          <!--            >立即核销</a-->
          <!--          >-->
          <!--          <Divider-->
          <!--            type="vertical"-->
          <!--            v-if="-->
          <!--              row._status === 2 &&-->
          <!--              row.shipping_type === 1 &&-->
          <!--              row.pinkStatus === 2-->
          <!--            "-->
          <!--          />-->
          <!--          <Divider-->
          <!--            type="vertical"-->
          <!--            v-if="-->
          <!--              row._status === 1 ||-->
          <!--              (row._status === 2 && !row.pinkStatus) ||-->
          <!--              row._status === 4 ||-->
          <!--              (row.shipping_type == 2 &&-->
          <!--                row.status == 0 &&-->
          <!--                row.paid == 1 &&-->
          <!--                row.refund_status === 0)-->
          <!--            "-->
          <!--          />-->
          <!-- <a
            @click="changeMenu(row, '5')"
            v-show="
              [1, 2, 5].includes(row.refund_type) &&
              (parseFloat(row.pay_price) > parseFloat(row.refunded_price) ||
                row.pay_price == 0)
            "
            >{{ row.refund_type == 2 ? '立即退货' : '立即退款' }}</a
          >
          <Divider
            type="vertical"
            v-show="
              [1, 2, 5].includes(row.refund_type) &&
              (parseFloat(row.pay_price) > parseFloat(row.refunded_price) ||
                row.pay_price == 0)
            "
          /> -->
          <a
              @click="changeMenu(row, '5')"
              v-show="
              (row.apply_type == 1 || row.refund_type == 5 || (row.refund_type == 4 && row.apply_type == 3)) &&
              ![3, 6].includes(row.refund_type) &&
              (parseFloat(row.pay_price) > parseFloat(row.refunded_price) || row.pay_price == 0)
            "
          >立即退款</a
          >
          <Divider
              type="vertical"
              v-show="
              (row.apply_type == 1 || row.refund_type == 5 || (row.refund_type == 4 && row.apply_type == 3)) &&
              ![3, 6].includes(row.refund_type) &&
              (parseFloat(row.pay_price) > parseFloat(row.refunded_price) || row.pay_price == 0)
            "
          />
          <a
              @click="changeMenu(row, '55')"
              v-show="
              [2, 3].includes(row.apply_type) && [0, 1, 2].includes(row.refund_type)"
          >同意退货</a
          >
          <Divider
              type="vertical"
              v-show="[2, 3].includes(row.apply_type) && [0, 1, 2].includes(row.refund_type)"
          />
          <a @click="changeMenu(row, '2')">订单详情</a>
          <!-- <Dropdown @on-click="changeMenu(row, $event)">
            <a href="javascript:void(0)"
              >更多
              <Icon type="ios-arrow-down"></Icon>
            </a>
            <DropdownMenu slot="list">
              <DropdownItem name="2">订单详情</DropdownItem>
              <DropdownItem name="3">订单记录</DropdownItem>
              <DropdownItem name="4">售后备注</DropdownItem>
              <DropdownItem
                name="5"
                v-show="
                  [1, 2, 5].includes(row.refund_type) &&
                  (parseFloat(row.pay_price) > parseFloat(row.refunded_price) ||
                    row.pay_price == 0)
                "
                >{{
                  row.refund_type == 2 ? "立即退货" : "立即退款"
                }}</DropdownItem
              >
              <DropdownItem name="7" v-show="[1, 2].includes(row.refund_type)"
                >不退款</DropdownItem
              >
            </DropdownMenu>
          </Dropdown> -->
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="pagination.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="pagination.limit"
        />
      </div>
    </Card>
    <!-- 编辑 退款 退积分 不退款-->
    <edit-from
      ref="edits"
      :FromData="FromData"
      @submitFail="submitFail"
    ></edit-from>
    <!-- 详情 -->
    <details-from
      ref="detailss"
      :orderDatalist="orderDatalist"
      :orderId="orderId"
      :rowActive="rowActive"
    ></details-from>
    <!-- 备注 -->
    <order-remark
      ref="remarks"
      remarkType="refund"
      :orderId="orderId"
      @submitFail="submitFail"
    ></order-remark>
    <!-- 记录 -->
    <order-record ref="record"></order-record>
    <Modal
      v-model="refundModal"
      title="手动退款"
      width="960"
      class-name="refund-modal"
      @on-visible-change="visibleChange"
    >
      <Form ref="formValidateRefund" :label-width="100" :rules="refundBalanceFormRules" :model="formValidateRefund">
        <FormItem label="卡项情况：" v-if="rowActive.type == 11 && benefitsInfo && Object.keys(benefitsInfo).length">
          <Card dis-hover>
            <div slot="title" class="acea-row row-middle">
              <div class="flex-1">{{ benefitsInfo.card_name }}</div>
              <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
              <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
              <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} -
                {{ benefitsInfo.write_end | timeFormat }}
              </div>
            </div>
            <div class="acea-row flex-wrap">
              <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
              <div class="flex-33">数量：1</div>
              <div class="flex-33">剩余金额：￥{{ remainingPrice }}</div>
              <div class="flex-33">已核销：{{ writeTimes - writeSurplusTimes }}/{{ writeTimes }}</div>
              <div class="flex-33">
                卡项权益：
                <Poptip placement="bottom" width="300">
                  <div class="cup text-wlll-1890FF">查看</div>
                  <div slot="content">
                    <div v-for="item in cardBenefits" :key="item.id" class="acea-row row-middle pt-4 pb-4 fs-12">
                      <div class="flex-1 min-w-0 pr-8 white-space-normal line2">{{
                          item.cart_info.productInfo.store_name
                        }}{{ item.cart_info.productInfo.attrInfo.suk }}
                      </div>
                      <div>{{ item.write_times }}次（已使用{{ item.write_times - item.write_surplus_times }}次）</div>
                    </div>
                  </div>
                </Poptip>
              </div>
            </div>
          </Card>
        </FormItem>
        <FormItem label="基础信息：" v-if="rowActive.product_type == 4 && benefitsInfo && Object.keys(benefitsInfo).length">
          <Card dis-hover>
            <div slot="title" class="acea-row row-middle">
              <div class="flex-1">{{ benefitsInfo.card_name }}</div>
              <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
              <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
              <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} -
                {{ benefitsInfo.write_end | timeFormat }}
              </div>
            </div>
            <div class="flex flex-wrap">
              <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
              <div class="flex-33">总次数：{{ writeTimes }}</div>
              <div class="flex-33">已核销次数：{{ writeTimes - writeSurplusTimes }}</div>
              <div class="flex-33">剩余次数：{{ writeSurplusTimes }}</div>
              <div class="flex-33">剩余金额：￥{{ remainingPrice }}</div>
            </div>
          </Card>
        </FormItem>
        <FormItem label="退款金额：">
          <InputNumber v-model="refundMoney" class="w-408"></InputNumber>
          <div class="refund-tips" v-if="rowActive.pay_type == 'combination' || rowActive.pay_type == 'yue'">
            <div style="color: red">如果是开错单，退款金额输入0</div>
            <div>请注意：退款金额作为记录使用；【退本金】【退赠金】是用于退回用户余额的值</div>
          </div>
        </FormItem>
        <FormItem v-if="showRefundBalanceInputs" label="退本金：" required prop="refundBen">
          <Input v-model="formValidateRefund.refundBen" class="w-408" placeholder="请输入退还本金"></Input>
        </FormItem>
        <FormItem v-if="showRefundBalanceInputs" label="退赠金：" required prop="refundGive">
          <Input v-model="formValidateRefund.refundGive" class="w-408" placeholder="请输入退还赠金"></Input>
        </FormItem>
        <FormItem label="退款说明：">
          <Input v-model="refund_explain" placeholder="请输入退款说明" class="w-408"/>
        </FormItem>
        <FormItem v-if="showReturnCouponRefundOption" label="优惠券：">
          <RadioGroup v-model="returnCoupon">
            <Radio :label="1">退回优惠券给用户</Radio>
            <Radio :label="0">不退回</Radio>
          </RadioGroup>
          <div class="tips">该订单使用了优惠券；选择「退回」将把用户该张券恢复为未使用。</div>
        </FormItem>
        <!-- 售后合并退款走 merge_refund_id，与订单列表「撤销」拆单接口不同，此处不展示分单表格 -->
        <FormItem label="售后入库：" v-if="orderDatalist && orderDatalist.orderInfo && orderDatalist.orderInfo.status >= 1">
          <RadioGroup v-model="stockInType">
            <Radio :label="0">暂不入库</Radio>
            <Radio :label="1">入良品库</Radio>
            <Radio :label="2">入残次品库</Radio>
          </RadioGroup>
          <div class="tips">选择售后商品是否需要执行入库操作，若需存入不同仓库，请于入库管理模块中操作退货入库。</div>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="cancelRefundModal">取消</Button>
        <Button type="primary" @click="putOpenRefundSubmit">提交</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex'
import {
  orderRefundList,
  getRefundDataInfo,
  getnoRefund,
  refundIntegral,
  getDistribution,
  refundReason,
  orderBenefits,
  putOpenRefund,
} from '@/api/order'
import { getRemak } from '@/api/yeji'
import dayjs from 'dayjs'
import editFrom from '@/components/from/from'
import detailsFrom from '../orderList/components/orderDetails'
import orderRemark from '../orderList/components/orderRemark'
import orderRecord from '../orderList/components/orderRecord'
export default {
  components: { editFrom, detailsFrom, orderRemark, orderRecord },
  filters: {
    timeFormat: (value) => dayjs(value * 1000).format('YYYY-MM-DD HH:mm'),
  },
  data() {
    return {
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      thead: [
        {
          title: '订单号',
          align: 'center',
          slot: 'order_id',
          minWidth: 150,
        },
        {
          title: '用户信息',
          slot: 'nickname',
          minWidth: 130,
        },
        {
          title: '商品信息',
          slot: 'info',
          minWidth: 300,
        },
        {
          title: '实际支付',
          key: 'pay_price',
          minWidth: 70,
        },
        {
          title: '发起退款时间',
          key: 'add_time',
          minWidth: 100,
        },
        {
          title: '订单状态',
          slot: 'refund_type',
          minWidth: 100,
        },
        {
          title: '退款信息',
          slot: 'statusName',
          minWidth: 100,
        },
        {
          title: '退货信息',
          slot: 'statusGoodName',
          minWidth: 100,
        },
        {
          title: '售后备注',
          key: 'remark',
          minWidth: 80,
        },
        {
          title: '操作',
          slot: 'action',
          // fixed: "right",
          minWidth: 150,
          align: 'center',
        },
      ],
      tbody: [],
      num: [],
      orderDatalist: null,
      loading: false,
      FromData: null,
      total: 0,
      orderId: 0,
      animal: 1,
      pagination: {
        page: 1,
        limit: 15,
        order_id: '',
        time: '',
        refund_type: 'all',
      },
      options: {
        shortcuts: [
          {
            text: '今天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                new Date(
                  new Date().getFullYear(),
                  new Date().getMonth(),
                  new Date().getDate()
                )
              )
              return [start, end]
            },
          },
          {
            text: '昨天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 1
                  )
                )
              )
              end.setTime(
                end.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 1
                  )
                )
              )
              return [start, end]
            },
          },
          {
            text: '最近7天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 6
                  )
                )
              )
              return [start, end]
            },
          },
          {
            text: '最近30天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 29
                  )
                )
              )
              return [start, end]
            },
          },
		  {
		    text: "上月",
		    value() {
		      const end = new Date();
		      const start = new Date();
		  	const day = new Date(start.getFullYear(), start.getMonth(), 0).getDate();
		      start.setTime(
		        start.setTime(
		          new Date(new Date().getFullYear(), new Date().getMonth()-1, 1)
		        )
		      );
		  	end.setTime(
		  	  end.setTime(
		  	    new Date(new Date().getFullYear(), new Date().getMonth()-1, day)
		  	  )
		  	);
		      return [start, end];
		    },
		  },
          {
            text: '本月',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(new Date().getFullYear(), new Date().getMonth(), 1)
                )
              )
              return [start, end]
            },
          },
          {
            text: '本年',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(new Date(new Date().getFullYear(), 0, 1))
              )
              return [start, end]
            },
          },
        ],
      },
      timeVal: [],
      modal: false,
      qrcode: null,
      name: '',
      spin: false,
      rowActive: {},
      refundReasonList: [],
      refund_reason: -1,
      benefitsInfo: {},
      remarkType: 1,
      storeOrderIdForOpenRefund: 0,
      refundModal: false,
      refundMoney: 0,
      formValidateRefund: {
        refundBen: '0',
        refundGive: '0',
      },
      refund_explain: '',
      writeSurplusTimes: 0,
      writeTimes: 0,
      cardBenefits: [],
      remainingPrice: 0,
      stockInType: 0,
      returnCoupon: 1,
    }
  },
  watch: {
    refundMoney(value) {
      this.$nextTick(() => {
        if (typeof value != 'number') return
        if (parseFloat(value) == parseInt(value)) return
        if (value.toString().length - (value.toString().indexOf('.') + 1) > 2) {
          this.refundMoney = Number(value.toFixed(2))
        }
      })
    },
  },
  computed: {
    ...mapState('order', ['orderChartType']),
    // ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 75
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
    showRefundBalanceInputs() {
      const orderType = Number((this.orderDatalist && this.orderDatalist.orderInfo && this.orderDatalist.orderInfo.order_type) || this.rowActive.order_type || 0)
      return orderType === 0 || this.rowActive.pay_type === 'yue' || this.rowActive.pay_type === 'combination'
    },
    showReturnCouponRefundOption() {
      const oi = this.orderDatalist && this.orderDatalist.orderInfo
      if (!oi) return false
      const cid = Number(oi.coupon_id || 0)
      const cp = Number(oi.coupon_price || 0)
      return cid > 0 && cp > 0
    },
    refundBalanceFormRules() {
      if (!this.showRefundBalanceInputs) {
        return {}
      }
      return {
        refundBen: [{ required: true, message: '请输入本金', trigger: 'blur' }],
        refundGive: [{ required: true, message: '请输入赠金', trigger: 'blur' }],
      }
    },
  },
  created() {
    this.refundReason();
    this.getOrderList()
  },
  methods: {
    onchangeCode(e) {
      this.animal = e
      this.qrcodeShow()
    },
    // 具体日期搜索()；
    onchangeTime(e) {
      this.pagination.page = 1
      this.timeVal = e
      this.pagination.time = this.timeVal[0] ? this.timeVal.join('-') : ''
      this.getOrderList()
    },
    // 操作
    changeMenu(row, name) {
      this.orderId = row.id
      switch (name) {
        case '1':
          this.delfromData = {
            title: '修改立即支付',
            url: `/order/pay_offline/${row.id}`,
            method: 'post',
            ids: '',
          }
          this.$modalSure(this.delfromData)
            .then((res) => {
              this.$Message.success(res.msg)
              this.getOrderList()
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
          // this.modalTitleSs = '修改立即支付';
          break
        case '2':
          this.rowActive = row
          this.getData(row.id)
          break
        case '3':
          this.$refs.record.modals = true
          this.$refs.record.getList(row.store_order_id)
          break
        case '4':
          this.$refs.remarks.modals = true
          this.$refs.remarks.formValidate.remark = row.remark
          break
        case '5':
          this.getRefundData(row.id, row.refund_type, row)
          break
        case '55':
          this.getRefundGoodsData(row.id, row.refund_type)
          break
        case '6':
          this.getRefundIntegral(row.id)
          break
        case '7':
          this.getNoRefundData(row.id)
          break
        case '8':
          this.delfromData = {
            title: '修改确认收货',
            url: `/order/take/${row.id}`,
            method: 'put',
            ids: '',
          }
          this.$modalSure(this.delfromData)
            .then((res) => {
              this.$Message.success(res.msg)
              this.getOrderList()
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
          // this.modalTitleSs = '修改确认收货';
          break
        case '10':
          this.delfromData = {
            title: '立即打印订单',
            info: '您确认打印此订单吗?',
            url: `/order/print/${row.id}`,
            method: 'get',
            ids: '',
          }
          this.$modalSure(this.delfromData)
            .then((res) => {
              this.$Message.success(res.msg)
              this.$emit('changeGetTabs')
              this.getOrderList()
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
          break
        case '11':
          this.delfromData = {
            title: '立即打印电子面单',
            info: '您确认打印此电子面单吗?',
            url: `/order/order_dump/${row.id}`,
            method: 'get',
            ids: '',
          }
          this.$modalSure(this.delfromData)
            .then((res) => {
              this.$Message.success(res.msg)
              this.getOrderList()
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
          break
        default:
          this.delfromData = {
            title: '删除订单',
            url: `/order/del/${row.id}`,
            method: 'DELETE',
            ids: '',
          }
          // this.modalTitleSs = '删除订单';
          this.delOrder(row, this.delfromData)
      }
    },
    getOrderBenefits(row) {
      return orderBenefits(row.store_order_id).then((res) => {
        const d = res.data || {}
        this.benefitsInfo = d
        this.writeSurplusTimes = d.write_surplus_times
        this.writeTimes = d.write_times
        this.remainingPrice = d.remaining_price
        this.refundMoney = d.remaining_price
        this.cardBenefits = d.cart_info || []
      })
    },
    // 获取退款表单数据
    getRefundData(id, refund_type, row) {
      if (refund_type == 2) {
        this.delfromData = {
          title: '立即退货',
          url: `/refund/agree/${id}`,
          method: 'get',
        }
        this.$modalSure(this.delfromData)
          .then((res) => {
            this.$Message.success(res.msg)
            this.getOrderList()
            this.getData(this.orderId, 1)
          })
          .catch((res) => {
            this.$Message.error(res.msg)
          })
      } else {
        this.openManualRefundModal(row)
      }
    },
    openManualRefundModal(row) {
      const needBenefits =
        row.type == 11 ||
        (row.cartInfo && row.cartInfo[0] && row.cartInfo[0].product_type == 4)
      const benefitsP = needBenefits
        ? this.getOrderBenefits(row)
        : Promise.resolve().then(() => {
            this.benefitsInfo = {}
            this.writeSurplusTimes = 0
            this.writeTimes = 0
            this.remainingPrice = 0
            this.cardBenefits = []
          })
      benefitsP
        .then(() => getRefundDataInfo(row.id))
        .then((res) => {
          this.orderDatalist = res.data
          this.storeOrderIdForOpenRefund = row.store_order_id
          this.formValidateRefund = { refundBen: '0', refundGive: '0' }
          if (row.pay_type === 'combination') {
            try {
              getRemak({ order_id: row.store_order_id, type: this.remarkType })
                .then((r) => {
                  const info = r && r.data ? r.data : {}
                  const list = Array.isArray(info.list) ? info.list : []
                  let yueSum = 0
                  list.forEach((it) => {
                    const activePay = Number(it.activePay || it.active_pay || 0)
                    const subType = it.pay_sub_type || it.paySubType || ''
                    if (activePay === 3 && subType !== 'card_upgrade') {
                      const p = Number(it.price || 0)
                      if (!isNaN(p)) yueSum += p
                    }
                  })
                  this.formValidateRefund.refundBen = String(Number(yueSum.toFixed(2)))
                })
                .catch(() => {})
            } catch (e) {}
          }
          this.rowActive = { ...row }
          if (row.cartInfo && row.cartInfo[0]) {
            this.rowActive.product_type = row.cartInfo[0].product_type
          }
          const oi = res.data.orderInfo
          if (oi && oi.pay_type != null && oi.pay_type !== '') {
            this.rowActive.pay_type = oi.pay_type
          }
          this.refund_explain = ''
          this.stockInType = 0
          this.returnCoupon = 1
          this.getOnlyRefundDataFromRow(row, res.data.orderInfo)
          this.refundModal = true
        })
        .catch((res) => {
          this.$Message.error(res.msg || '加载失败')
        })
    },
    visibleChange(visible) {
      this.stockInType = 0
      if (!visible) this.returnCoupon = 1
    },
    cancelRefundModal() {
      this.refundModal = false
    },
    extractRefundCartLines(row, orderInfo) {
      const out = []
      if (orderInfo && Array.isArray(orderInfo.cartInfo) && orderInfo.cartInfo.length) {
        orderInfo.cartInfo.forEach((c) => {
          if (!c.is_gift) out.push(c)
        })
        return out
      }
      const src = row._info || []
      Object.values(src).forEach((pack) => {
        if (pack.cart_info && !pack.cart_info.is_gift) {
          out.push(pack.cart_info)
        }
      })
      return out
    },
    getOnlyRefundDataFromRow(row, orderInfo) {
      const cartInfo = this.extractRefundCartLines(row, orderInfo)
      let total = 0
      cartInfo.forEach((value) => {
        const cartNum = Number(value.cart_num) || 0
        const refundedNum = Number(value.refund_num) || 0
        const refundNum = Math.max(0, cartNum - refundedNum)
        const lineRefundTotal = parseFloat(value.refund_price)
        let unitPrice
        if (
          !isNaN(lineRefundTotal) &&
          lineRefundTotal >= 0 &&
          cartNum > 0
        ) {
          unitPrice = parseFloat(this.$computes.Div(lineRefundTotal, cartNum))
        } else {
          unitPrice = Number(value.truePrice)
        }
        if (isNaN(unitPrice)) unitPrice = 0
        value.refundPrice = unitPrice
        value.refundNum = refundNum
        value._disabled = !refundNum
        const sub = this.$computes.Mul(unitPrice, refundNum)
        total = this.$computes.Add(total, isNaN(sub) ? 0 : sub)
      })
      if (isNaN(total) || !cartInfo.length) {
        const req = parseFloat(row.refund_price)
        const done = parseFloat(row.refunded_price || 0)
        if (!isNaN(req)) {
          total = Math.max(0, req - (isNaN(done) ? 0 : done))
        }
      }
      if (isNaN(total)) total = 0
      this.refundMoney = Number(Number(total).toFixed(2))
    },
    putOpenRefundSubmit() {
      if (this.showRefundBalanceInputs) {
        this.$refs['formValidateRefund'].validate((valid) => {
          if (valid) {
            const ben = parseFloat(this.formValidateRefund.refundBen) || 0
            const give = parseFloat(this.formValidateRefund.refundGive) || 0
            const needConfirm = this.rowActive.pay_type == 'yue' || this.rowActive.pay_type == 'combination' || ben > 0 || give > 0
            if (needConfirm) {
              this.$Modal.confirm({
                title: '操作退款',
                content:
                  '您本次退款的本金【' +
                  this.formValidateRefund.refundBen +
                  '】元和赠金【' +
                  this.formValidateRefund.refundGive +
                  '】元，是否确认退回用户余额？',
                okText: '确认退款',
                cancelText: '取消操作',
                onOk: () => {
                  this.doOpenRefund()
                },
              })
            } else {
              this.doOpenRefund()
            }
          } else {
            this.$Message.warning('请填写本金和赠金！')
          }
        })
      } else {
        this.doOpenRefund()
      }
    },
    doOpenRefund() {
      const data = {
        id: this.storeOrderIdForOpenRefund,
        merge_refund_id: this.orderId,
        refund_price: this.refundMoney,
        refund_ben: this.showRefundBalanceInputs ? this.formValidateRefund.refundBen : '0',
        refund_give: this.showRefundBalanceInputs ? this.formValidateRefund.refundGive : '0',
        type: 1,
        is_split_order: 0,
        refund_explain: this.refund_explain,
        stock_in_type: this.stockInType,
        return_coupon: this.showReturnCouponRefundOption ? this.returnCoupon : 1,
      }
      putOpenRefund(data)
        .then((res) => {
          this.$Message.success(res.msg)
          this.refundModal = false
          this.getOrderList()
          this.getData(this.orderId, 1)
          this.$emit('changeGetTabs')
        })
        .catch((err) => {
          this.$Message.error(err.msg)
        })
    },
    //同意退货
    getRefundGoodsData(id) {
      this.delfromData = {
        title: '是否立即退货',
        url: `/refund/agree/${id}`,
        method: 'get',
      }
      this.$modalSure(this.delfromData)
          .then((res) => {
            this.$Message.success(res.msg)
            this.getOrderList()
            this.getData(this.orderId, 1)
          })
          .catch((res) => {
            this.$Message.error(res.msg)
          })
    },
    // 获取退积分表单数据
    getRefundIntegral(id) {
      refundIntegral(id)
        .then(async (res) => {
          this.FromData = res.data
          this.$refs.edits.modals = true
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },

    // 获取详情表单数据
    getData(id, type) {
      getRefundDataInfo(id)
        .then(async (res) => {
          if (!type) {
            this.$refs.detailss.modals = true
          }
          this.$refs.detailss.activeName = 'detail'
          if (this.rowActive.type == 11) {
            res.data.orderInfo.cartInfo[0]._loading = false;
            res.data.orderInfo.cartInfo[0].children = [];
          }
          this.orderDatalist = res.data
          // if (this.orderDatalist.orderInfo.refund_reason_wap_img) {
          //   try {
          //     this.orderDatalist.orderInfo.refund_reason_wap_img = JSON.parse(
          //       this.orderDatalist.orderInfo.refund_reason_wap_img
          //     );
          //   } catch (e) {
          //     this.orderDatalist.orderInfo.refund_reason_wap_img = [];
          //   }
          // }
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },
    // 删除单条订单
    delOrder(row, data) {
      if (row.is_del === 1) {
        this.$modalSure(data)
          .then((res) => {
            this.$Message.success(res.msg)
            this.getOrderList()
          })
          .catch((res) => {
            this.$Message.error(res.msg)
          })
      } else {
        const title = '错误！'
        const content =
          '<p>您选择的的订单存在用户未删除的订单，无法删除用户未删除的订单！</p>'
        this.$Modal.error({
          title: title,
          content: content,
        })
      }
    },
    // 修改成功
    submitFail() {
      this.getOrderList()
      this.getData(this.orderId, 1)
    },
    // 订单选择状态
    selectChange2(tab) {
      if (typeof tab === 'undefined') {
        this.pagination.refund_type = 'all';
      }
      this.pagination.page = 1
      this.getOrderList()
    },
    // 不退款表单数据
    getNoRefundData(id) {
      this.$modalForm(getnoRefund(id)).then(() => {
        this.getOrderList()
        this.getData(this.orderId, 1)
        this.$emit('changeGetTabs')
      })
    },
    // 订单列表
    getOrderList() {
      this.loading = true
      orderRefundList(this.pagination)
        .then((res) => {
          this.loading = false
          const { count, list, num } = res.data
          this.total = count
          this.tbody = list
    //       num.forEach((item, index) => {
    //  num[index]=( (Object.assign({}, item, { value: index })))
             
    //       })
          this.num = num
          list.forEach((item) => {
            if (item.id == this.orderId) {
              this.rowActive = item
            }
          })
        })
        .catch((err) => {
          this.loading = false
          this.$Message.error(err.msg)
        })
    },
    // 分页
    pageChange(index) {
      this.pagination.page = index
      this.getOrderList()
    },
    nameSearch() {
      this.pagination.page = 1
      this.getOrderList()
    },
    // 订单搜索
    orderSearch() {
      this.pagination.page = 1
      this.getOrderList()
    },
    // 配送信息表单数据
    delivery(row) {
      getDistribution(row.id)
        .then(async (res) => {
          this.FromData = res.data
          this.$refs.edits.modals = true
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },
    // 获取退款原因列表
    refundReason() {
      refundReason().then(res => {
        const { data } = res;
        this.refundReasonList = data;
      });
    },
    // 退款原因改变事件处理函数
    refundReasonChange(value) {
      this.pagination.refund_reason = value === undefined ? "" : this.refundReasonList[value];
      this.pagination.page = 1;
      this.getOrderList();
    }
  },
}
</script>

<style lang="stylus" scoped>
	/deep/.ivu-select-selected-value {
	font-size: 12px !important;}
.code {
  position: relative;
}

.QRpic {
  width: 180px;
  height: 259px;

  img {
    width: 100%;
    height: 100%;
  }
}
.search {
width: 86px;
  height: 32px;
  }
.tabBox {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;

  .tabBox_img {
    width: 36px;
    height: 36px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .tabBox_tit {
    width: 60%;
    font-size: 12px !important;
    margin: 0 2px 0 10px;
    letter-spacing: 1px;
    padding: 5px 0;
    box-sizing: border-box;
  }
}

.pictrue-box {
  display: flex;
  align-item: center;
}

.pictrue {
  width: 25px;
  height: 25px;
}

.w-408 {
  width: 408px;
}

.refund-tips {
  margin-top: 8px;
  font-size: 12px;
  line-height: 1.5;
}
</style>

<style lang="stylus">
>>> .refund-modal {
  .ivu-input-number-controls-outside {
    width: 105px;
    height: 28px;
    border: 0;
    border-radius: 0;
    background-color: transparent;
    font-size: 16px;
    line-height: 28px;
    box-shadow: none;
  }
  .ivu-input-number-controls-outside:focus {
    box-shadow: none;
  }
  .ivu-input-number-controls-outside-btn {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    background-color: #1890FF;
    line-height: 28px;
    color: #FFFFFF;
  }
  .ivu-input-number-input-wrap {
    height: 28px;
  }
  .ivu-input-number-controls-outside .ivu-input-number-input {
    height: 28px;
    background-color: transparent;
    text-align: center;
    line-height: 28px;
  }
  .tips {
    padding: 12px 0 23px;
    font-size: 12px;
    line-height: 14px;
    color: #999999;
  }
  .ivu-modal-footer {
    padding-bottom: 30px;
    border: 0;
    text-align: center;
  }
  .ivu-modal-footer button + button {
    margin-left: 20px;
  }
  .ivu-btn {
    height: 46px;
    padding: 0 71px;
    border-color: #F5F5F5;
    border-radius: 23px;
    background-color: #F5F5F5;
    font-size: 16px !important;
    color: #666666;
  }
  .ivu-btn-primary {
    border-color: #1890FF;
    background-color: #1890FF;
    color: #FFFFFF;
  }
}
</style>
