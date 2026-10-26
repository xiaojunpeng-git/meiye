<template>
<!-- 门店-门店订单 -->
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
       <Form
          ref="orderData"
          :model="orderData"
          :label-width="labelWidth"
          :label-position="labelPosition"
          class="tabform"
          inline
          @submit.native.prevent
        >
         <FormItem label="选择门店：">
            <Select
              v-model="orderData.store_id"
              clearable
              filterable
              @on-change="orderSearch"
             class="input-add"
            >
              <Option v-for="item in staffData" :value="item.id" :key="item.id"
                >{{ item.name }}
              </Option>
            </Select>
          </FormItem>
         <FormItem label="订单号：" label-for="search_order_id">
           <Input
               placeholder="订单号（含补交单关联）"
               v-model="orderData.search_order_id"
               class="input-add"
           />
         </FormItem>
         <FormItem label="核销订单号：" label-for="search_verify_code">
           <Input
               placeholder="核销子单订单号（含自动核销）"
               v-model="orderData.search_verify_code"
               class="input-add"
           />
         </FormItem>
         <FormItem label="商品：" label-for="search_product">
           <Input
               placeholder="商品名称关键词"
               v-model="orderData.search_product"
               class="input-add"
           />
         </FormItem>
         <FormItem label="用户：" label-for="search_user">
           <Input
               placeholder="昵称、手机号、UID、收货人"
               v-model="orderData.search_user"
               class="input-add"
           />
         </FormItem>
         <FormItem label="下单时间：">
           <DatePicker
               :editable="false"
               @on-change="onchangeTime"
               :value="timeVal"
               format="yyyy/MM/dd"
               type="daterange"
               placement="bottom-start"
               placeholder="自定义时间"
               class="input-add"
               :options="options"
           ></DatePicker>
         </FormItem>
         <FormItem label="订单类型：">
           <Select v-model="orderData.link_type"
                   multiple
                   class="input-add"
                   clearable
                   @on-change="orderSearch"
           >
             <Option value="0">普通订单</Option>
             <Option value="1">充值订单</Option>
             <Option value="2">核销订单</Option>
           </Select>
         </FormItem>
         <FormItem label="来源：">
           <Select v-model="orderData.source"
                   multiple
                   class="input-add"
                   clearable
                   @on-change="orderSearch"
           >
             <Option :value="item.id" v-for="(item,index) in source">{{ item.name }}</Option>
           </Select>
         </FormItem>
         <FormItem label="付款方式：">
           <Select v-model="orderData.pay_type"
                   multiple
                   class="input-add"
                   clearable
                   @on-change="orderSearch"
           >
             <Option :value="item.id" v-for="(item,index) in pay_type_select">{{ item.name }}</Option>
           </Select>
         </FormItem>
         <FormItem label="组合明细：" v-if="showCombinationActivePay">
           <Select
               v-model="orderData.combination_detail"
               class="input-add"
               clearable
               placeholder="全部"
               @on-change="orderSearch"
           >
             <Option value="1">微信</Option>
             <Option
                 v-for="item in cash_type"
                 :key="'comb-cash-' + item.id"
                 :value="'2|' + item.id"
             >{{ item.name }}</Option>
             <Option value="3|balance">余额支付</Option>
             <Option value="3|card_upgrade">旧卡升级</Option>
           </Select>
         </FormItem>
         <FormItem label="现金类型：">
           <Select v-model="orderData.cash_choose"
                   multiple
                   class="input-add"
                   clearable
                   @on-change="orderSearch"
           >
             <Option :value="item.id" v-for="(item,index) in cash_type">{{ item.name }}</Option>
           </Select>
         </FormItem>
         <FormItem label="商品类型：">
           <Select
               v-model="orderData.product_type"
               class="input-add"
               clearable
               placeholder="请选择"
               @on-change="orderSearch"
           >
             <Option value="0">商品订单</Option>
             <Option value="6">项目订单</Option>
             <Option value="5">卡项订单</Option>
           </Select>
         </FormItem>
         <FormItem label="是否跨店：">
           <Select
               v-model="orderData.is_kuadian"
               class="input-add"
               clearable
               placeholder="请选择"
               @on-change="orderSearch"
           >
             <Option value="">全部</Option>
             <Option value="1">是</Option>
           </Select>
         </FormItem>
         <div>
         <FormItem
             label="销售人员："
             label-for="yeji_staff"
         >
           <Input
               placeholder="销售人员"
               v-model="orderData.yeji_staff"
               class="input-add"
           />
         </FormItem>
         <FormItem
             label="手艺人员："
             label-for="yeji_shouyi"
         >
           <Input
               placeholder="手艺人(模糊)"
               v-model="orderData.yeji_shouyi"
               class="input-add"
           />
         </FormItem>
           <Button type="primary" @click="orderSearch()" class="ml-14">查询</Button>
           <Button @click="reset()" class="ml-14">重置</Button>
         </div>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt">
      <!-- Tab栏 + 隐藏字段 -->
      <div class="order-list-toolbar">
        <div class="new_tab order-list-tabs">
		<Tabs v-model="orderData.status" @on-click="orderSearch">
		  <TabPane :label="'全部'" name=" "/>
		  <TabPane :label="'待支付('+(orderChartType.unpaid || 0)+')'" name="0"/>
		  <TabPane :label="'待发货('+(orderChartType.unshipped || 0)+')'" name="1"/>
		  <TabPane :label="'待核销('+(orderChartType.write_off || 0)+')'" name="5"/>
		  <TabPane :label="'待收货'" name="2"/>
		  <TabPane :label="'待评价'" name="3"/>
		  <TabPane :label="'已核销'" name="6"/>
		  <TabPane :label="'已完成'" name="4"/>
		  <TabPane :label="'已退款'" name="-2"/>
		</Tabs>
        </div>
        <Poptip placement="bottom-end" width="420" transfer trigger="click" class="field-toggle-wrap">
          <a class="field-toggle-link">隐藏字段</a>
          <div slot="content" class="field-toggle-panel">
            <CheckboxGroup v-model="columnVisibleKeys" @on-change="saveColumnVisible">
              <Checkbox
                v-for="item in columnOptions"
                :key="item.key"
                :label="item.key"
                class="field-toggle-item"
              >{{ item.label }}</Checkbox>
            </CheckboxGroup>
          </div>
        </Poptip>
      </div>
      <div class="flex-between-center">
	  <div>
		<Tooltip
		  content="请勾选要导出的订单；表头全选选「所有页」则按当前筛选条件导出"
		  :disabled="!!checkUidList.length || isAll == 1"
		>
		  <Button
		    type="primary"
		    v-auth="['admin-store-store_order-export']"
		    size="default"
		    :disabled="!checkUidList.length && isAll == 0"
		    @click="exports"
		    >导出订单</Button
		  >
		</Tooltip>
<!--		<Button-->
<!--		  v-auth="['admin-store-store_order-writeoff']"-->
<!--		  class="ml-10"-->
<!--		  size="default"-->
<!--		  @click="writeOff"-->
<!--		  >订单核销</Button-->
<!--		>-->
      <Button
          class="ml-10"
          size="default"
          @click="userCardMove"
      >用户卡项导入</Button
      >
	  </div>
        <router-link :to="`${roterPre}/system/import/record/user_card`">导入用户卡项记录</router-link>
      </div>
      <!-- 订单卡片列表 -->
      <store-order-card-list
        :list="tableList"
        :loading="loading"
        :col-visible="colVisible"
        :check-uid-list="checkUidList"
        :is-all="isAll"
        :total="total"
        @all-pages="allPages"
        @check-item="checkboxItem"
        @order-detail="(row) => changeMenu(row, '2')"
        @user-info="showUserInfo"
        @yeji="doYeji"
        @remark="showRemark"
        @gendan="showGendan"
        @debt-detail="openOrderDebtDetail"
      >
        <template #action="{ row }">
          <a
            @click="sendOrder(row)"
            v-if="
              (row._status === 2 || row._status === 8 || row.status === 4) &&
              (row.pinkStatus === null || row.pinkStatus === 2) &&
              row.delete_time == null &&
              row.store_delivery_type !== 2
            "
          >发送货</a>
          <a
            @click="openModal3(row)"
            v-if="
              (row._status === 2 || row._status === 4) &&
              (row.pinkStatus === null || row.pinkStatus === 2) &&
              row.delete_time == null &&
              row.store_delivery_type === 2
            "
          >{{ row._status === 2 ? '派单' : '改派' }}</a>
          <Divider
            type="vertical"
            v-if="
              (row._status === 2 || row._status === 4) &&
              (row.pinkStatus === null || row.pinkStatus === 2) &&
              row.delete_time == null &&
              row.store_delivery_type === 2"
          />
          <a
            @click="orderReissueOrder(row)"
            v-if="row.store_delivery_type == 2 && row._status == 2 && row.status_name.is_reissue_order == 1"
          >重新发单</a>
          <a v-if="row.order_type != 2" @click="changeMenu(row, '2')">详情</a>
          <a v-if="row.order_type == 2 && row.refund_status == 0" class="action-link-gap" @click="changeMenu(row, '14')">撤销</a>
          <a v-if="row.order_type == 0 && row.refund_status == 0" class="action-link-gap" @click="changeMenu(row, '5')">撤销</a>
          <a v-if="row.order_type == 1 && row.refund_status == 0" class="action-link-gap" @click="changeMenu(row, '555')">撤销</a>
        </template>
      </store-order-card-list>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="orderData.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="orderData.limit"
        />
      </div>
    </Card>
    <!-- 用户详情-->
    <user-details ref="userDetails" fromType="order"></user-details>
    <order-debt-detail ref="orderDebtDetail" />
    <!-- 编辑 配送信息表单数据 退款 退积分 不退款-->
    <edit-from
      ref="edits"
      :FromData="FromData"
      @submitFail="submitFail"
    ></edit-from>
    <!-- 订单详情 -->
	<details-from
	  ref="detailss"
	  :orderDatalist="orderDatalist"
	  :orderId="orderId"
	  :row-active="rowActive"
	  :openErp="openErp"
	  :formType="1"
	  :distHide='1'
    @submitFail="submitFail"
	></details-from>
    <!-- 备注 -->
    <order-remark
      ref="remarks_bak"
      :orderId="orderId"
      @submitFail="submitFail"
      currentTab="5"
    ></order-remark>
    <order-remark
      ref="remarks"
      :orderId="orderId"
      @submitFail="submitFail"
    ></order-remark>
    <!-- 记录 -->
    <order-record ref="record"></order-record>
    <!-- 发送货 -->
    <order-send
      ref="send"
      :orderId="orderId"
      :status="status"
      :pay_type="pay_type"
      :row-active="rowActive"
      @submitFail="submitFail"
    >
    </order-send>
	<!--订单核销模态框-->
	<Modal
	  v-model="modals2"
	  title="订单核销"
	  class="paymentFooter"
	  scrollable
	  width="400"
	  class-name="vertical-center-modal"
	>
	  <Form
	    ref="writeOffFrom"
	    :model="writeOffFrom"
	    :rules="writeOffRules"
	    :label-position="labelPosition"
	    class="tabform"
	    @submit.native.prevent
	  >
	    <FormItem prop="code" label-for="code">
	      <Input
	        search
	        enter-button="验证"
	        style="width: 100%"
	        type="text"
	        placeholder="请输入12位核销码"
	        @on-search="search('writeOffFrom')"
	        v-model.number="writeOffFrom.code"
	        number
	      />
	    </FormItem>
	  </Form>
	  <div slot="footer">
	    <Button type="primary" @click="ok">立即核销</Button>
	    <Button @click="del('writeOffFrom')">取消</Button>
	  </div>
	</Modal>
	<Modal v-model="refundModal" title="手动退款" width="960" class-name="refund-modal" @on-visible-change="visibleChange">
	  <Form ref="formValidate" :label-width="100" :rules="refundFormRules" :model="formValidate">
      <FormItem label="卡项情况：" v-if="rowActive.type == 11 && benefitsInfo">
        <Card dis-hover>
          <div slot="title" class="flex-y-center">
            <div class="flex-1">{{ benefitsInfo.card_name }}</div>
            <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
            <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
            <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} - {{ benefitsInfo.write_end | timeFormat }}</div>
          </div>
          <div class="flex flex-wrap">
            <div class="flex-33">购卡实付金额：￥{{ benefitsInfo.pay_price }}</div>
            <div class="flex-33">数量：1</div>
            <div class="flex-33">剩余金额：￥{{ remainingPrice }}</div>
            <div class="flex-33">已核销：{{ writeTimes - writeSurplusTimes }}/{{ writeTimes }}</div>
            <div class="flex-33">
              卡项权益：
              <Poptip placement="bottom" width="300">
                <div class="cup text-wlll-1890FF">查看</div>
                <div slot="content">
                  <div v-for="item in cardBenefits" :key="item.id" class="flex-y-center pt-4 pb-4 fs-12">
                    <div class="flex-1 min-w-0 pr-8 white-space-normal line2">{{
                        item.cart_info.productInfo.store_name
                      }}<template v-if="orderListSpecSuk(item)"> | {{ orderListSpecSuk(item) }}</template></div>
                    <div>{{ item.write_times }}次（已使用{{ item.write_times - item.write_surplus_times }}次）</div>
                  </div>
                </div>
              </Poptip>
            </div>
          </div>
        </Card>
	    </FormItem>
		<FormItem label="基础信息：" v-if="rowActive.product_type == 4 && benefitsInfo">
		  <Card dis-hover>
		    <div slot="title" class="flex-y-center">
		      <div class="flex-1">{{ benefitsInfo.card_name }}</div>
		      <div v-if="benefitsInfo.write_valid == 1">永久有效</div>
		      <div v-else-if="benefitsInfo.write_valid == 2">购买后{{ benefitsInfo.write_days }}天有效</div>
		      <div v-else-if="benefitsInfo.write_valid == 3">{{ benefitsInfo.write_start | timeFormat }} - {{ benefitsInfo.write_end | timeFormat }}</div>
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
	      <div class="refund-tips" v-if="showRefundBalanceInputs">
	        <div style="color: red">如果是开错单，退款金额输入0</div>
	        <div>请注意：退款金额作为记录使用；【退本金】【退赠金】是用于退回用户余额的值</div>
	      </div>
	    </FormItem>
	    <FormItem v-if="showRefundBalanceInputs" label="退本金：" required prop="refundBen">
	      <Input v-model="formValidate.refundBen" class="w-408" placeholder="请输入退还本金"></Input>
	    </FormItem>
	    <FormItem v-if="showRefundBalanceInputs" label="退赠金：" required prop="refundGive">
	      <Input v-model="formValidate.refundGive" class="w-408" placeholder="请输入退还赠金"></Input>
	    </FormItem>
		<FormItem label="退款说明：">
		  <Input v-model="refund_explain" placeholder="请输入退款说明" class="w-408"/>
		</FormItem>
	    <FormItem v-if="showReturnCouponOption" label="优惠券：">
	      <RadioGroup v-model="returnCoupon">
	        <Radio :label="1">退回优惠券给用户</Radio>
	        <Radio :label="0">不退回</Radio>
	      </RadioGroup>
	      <div class="tips">该订单使用了优惠券；选择「退回」将把用户优惠券恢复为未使用。</div>
	    </FormItem>
	    <FormItem v-if="refundProductNum > 1" label="分单退款：">
	      <i-switch v-model="is_split_order" :true-value="1" :false-value="0" size="large">
	        <span slot="open">开启</span>
	        <span slot="close">关闭</span>
	      </i-switch>
	      <div class="tips">可选择表格中的商品单独退款，退款后且不能撤回，请谨慎操作！</div>
	      <Table v-show="is_split_order" ref="refundTable" max-height="500" :columns="refundColumns" :data="refundProduct" @on-selection-change="refundSelectionChange">
	        <template slot-scope="{ row }" slot="product">
	          <div class="image-wrap" v-viewer><img :src="row.productInfo.attrInfo.image" class="image"></div>
	          <div class="title">{{ row.productInfo.store_name }}</div>
	        </template>
	        <template slot-scope="{ row }" slot="action">
	          <InputNumber v-model="row.refundNum" :max="row.cart_num - row.refund_num" :min="1" :precision="0" controls-outside @on-change="refundNumChange(row)"></InputNumber>
	        </template>
	      </Table>
	    </FormItem>
		<FormItem label="售后入库：" v-if="orderDatalist && orderDatalist.orderInfo.status>=1">
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
	    <Button type="primary" @click="putOpenRefund">提交</Button>
	  </div>
	</Modal>
    <Modal
        v-model="importCardShow"
        scrollable
        :mask-closable="false"
        title="用户卡项导入"
        footer-hide
        width="900"
    >
      <userCardImport v-if="importCardShow" @close="importCardShow = false"></userCardImport>
    </Modal>
	<changePrice ref="changePrice" @submitSuccess='submitSuccess'></changePrice>
	<orderWriteOff
	  ref="writeOff"
	  :orderNumId="orderNumId"
	  @submitSuccess="submitSuccess(1)"
	></orderWriteOff>
    <remarkInfo ref="remarkInfo" :orderId="orderId" :remarkType="remarkType"></remarkInfo>
    <changeGendan ref="changeGendan"></changeGendan>
    <yeji
      :syncProduct="syncProduct"
      :yeji="setYeji"
      :staffIds="staffIds"
      @closeYeji="closeYeji"
      :visible="yejiVisible"
      ref="yeji"
    ></yeji>
  </div>
</template>

<script>
import { mapState } from "vuex";
import userDetails from "@/pages/user/list/handle/userDetails";
import editFrom from "@/components/from/from";
import orderSend from "@/pages/order/orderList/handle/orderSend";
import detailsFrom from "@/pages/order/orderList/handle/orderDetails";
import orderRecord from "@/pages/order/orderList/handle/orderRecord";
import orderRemark from "@/pages/order/orderList/handle/orderRemark";
import changePrice from '@/pages/order/orderList/handle/changePrice.vue';
import orderWriteOff from './components/orderWriteOff';
import remarkInfo from '@/components/yeji/orderRemarkInfo';
import changeGendan from '@/components/yeji/changeGendan';
import yeji from '@/components/yeji';
import userCardImport from "./handle/userCardImport.vue";
import storeOrderCardList from "./components/storeOrderCardList.vue";
import orderDebtDetail from "@/components/orderDebtDetail";
import { getYeji } from '@/api/yeji';
import { refundEditApi } from '@/api/finance';
import {
  orderList,
  orderChart,
  orderHeader,
  getOrdeDatas, //编辑表单数据
  orderExport,
  staffListInfo,
} from "@/api/store";
import {
  storeOrderApi,
  putWrite,
  putOpenRefund,
  getDistribution,
  writeUpdate,
  getDataInfo,
  orderBenefits,
  orderWriteForm,
  getCash
} from "@/api/order";
import { erpConfig } from "@/api/erp";
import timeOptions from "@/utils/timeOptions";
import Setting from '@/setting'
import exportExcel from '@/utils/newToExcel.js'
import printJS from 'print-js';
import dayjs from "dayjs";
export default {
  name: "store_order",
  components: {
    userDetails,
    editFrom,
    detailsFrom,
    orderRecord,
    orderRemark,
    orderSend,
  	changePrice,
	  orderWriteOff,
    userCardImport,
    remarkInfo,
    changeGendan,
    yeji,
    storeOrderCardList,
    orderDebtDetail,
  },
  filters: {
    timeFormat: (value) => dayjs(value * 1000).format("YYYY-MM-DD HH:mm"),
  },
  data() {
	const codeNum = (rule, value, callback) => {
	  if (!value) {
	    return callback(new Error('请填写核销码'))
	  }
	  // 模拟异步验证效果
	  if (!Number.isInteger(value)) {
	    callback(new Error('请填写12位数字'))
	  } else {
	    // const reg = /[0-9]{12}/;
	    const reg = /\b\d{12}\b/
	    if (!reg.test(value)) {
	      callback(new Error('请填写12位数字'))
	    } else {
	      callback()
	    }
	  }
	}
    return {
      remarkType:1,
      roterPre: Setting.roterPre,
	  openErp:false,
      yejiVisible: false,
      staffIds: [],
      syncProduct: [],
      setYeji: {
        link_id: 0,
        cart_id: 0,
        price: 0,
        goods_id: 0,
        type: 2,
        staffChoose: [],
      },
      distshow: false, //分配的弹窗
      delfromData: {},
      pay_type: "",
      status: 0, //发货状态判断
      FromData: null,
      orderDatalist: null,
      orderId: 0,
      cash_type:[],
      source:[],
      pay_type_select:[],
      staffData: [],
      orderChartType: {},
      options: timeOptions,
      timeVal: [],
      // 订单搜索条件
      orderData: {
        page: 1,
        limit: 10,
        type: '',
        status: "",
        is_kuadian:'',
        date_range: "",
        yeji_staff: "",
        yeji_shouyi: "",
        real_name: "",
        search_order_id: "",
        search_verify_code: "",
        search_product: "",
        search_user: "",
        store_id: "",
        order_type: "",
        cash_choose:'',
        link_type: "",
        product_type:'',
        combination_detail: '',
        active_pay: '',
        pay_sub_type: '',
        combination_cash_choose: '',
      },
      tableList: [],
      total: 0,
      loading: false,
      rowActive: {},
	  isAll: 0,
	  checkUidList: [],
	  isCheckBox:false,
	  modals2: false,
	  writeOffRules: {
	    code: [{ validator: codeNum, trigger: 'blur', required: true }],
	  },
	  writeOffFrom: {
	    code: '',
	    confirm: 0,
	  },
	  refundModal: false,
	  refundColumns: [
	    {
	      type: 'selection',
	      width: 60,
	      align: 'center'
	    },
	    {
	      title: '商品信息',
	      width: 210,
	      slot: 'product'
	    },
	    {
	      title: '规格',
	      render: (h, params) => {
	        const raw = params.row.productInfo && params.row.productInfo.attrInfo ? params.row.productInfo.attrInfo.suk : '';
	        const suk = raw === undefined || raw === null ? '' : String(raw).replace(/^\s+|\s+$/g, '').replace(/^　+|　+$/g, '');
	        return h('div', (suk && suk !== '默认' && suk !== '默认规格') ? suk : '');
	      }
	    },
	    {
	      title: '售价',
	      render: (h, params) => {
	        return h('div', params.row.productInfo.attrInfo.price);
	      }
	    },
	    {
	      title: '实付金额',
	      key: 'pay_price'
	    },
	    {
	      title: '总数',
	      key: 'cart_num'
	    },
	    {
	      title: '退款数量',
	      slot: 'action',
	      width: 160,
	    }
	  ],
	  refundProduct: [],
	  refundSelection: [],
	  refundMoney: 0,
	  formValidate: {
	    refundBen: '0',
	    refundGive: '0',
	  },
	  returnCoupon: 1,
    importCardShow: false,
	  is_split_order: 0,
	  refund_explain:'',
	  orderConNum: 0,
	  orderConId: 0,
	  writeSurplusTimes: 0,
	  writeTimes: 0,
	  remainingPrice: 0,
	  cardBenefits: [],
	  stockInType: 0,
	  orderNumId: '',
	  benefitsInfo:{},
      columnOptions: [
        { key: 'product', label: '商品' },
        { key: 'price', label: '单价' },
        { key: 'qty', label: '数量' },
        { key: 'coupon_deduct', label: '优惠券抵扣' },
        { key: 'craft_staff', label: '手艺人' },
        { key: 'sales_staff', label: '销售人' },
        { key: 'customer', label: '客户' },
        { key: 'amount', label: '金额' },
        { key: 'source', label: '来源' },
        { key: 'store', label: '下单门店' },
        { key: 'status', label: '状态' },
        { key: 'action', label: '操作' },
      ],
      columnVisibleKeys: ['product', 'price', 'qty', 'coupon_deduct', 'craft_staff', 'sales_staff', 'customer', 'amount', 'source', 'store', 'status', 'action'],
    }
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
	refundProductNum() {
	  return this.refundProduct.reduce((total, { refundNum }) => (total + refundNum), 0);
	},
    showRefundBalanceInputs() {
      return Number(this.rowActive.order_type || 0) === 0;
    },
    showReturnCouponOption() {
      const r = this.rowActive || {};
      if (Number(r.coupon_id || 0) > 0 || Number(r.coupon_price || 0) > 0) {
        return true;
      }
      const cartItems = (this.refundProduct && this.refundProduct.length)
        ? this.refundProduct
        : (r._info
          ? Object.values(r._info).map((v) => v.cart_info || v)
          : (Array.isArray(r.cart_info) ? r.cart_info : (r.cart_info ? [r.cart_info] : [])));
      return cartItems.some((item) => {
        if (!item || item.is_gift) return false;
        return Number(item.coupon_id || 0) > 0 || Number(item.coupon_price || 0) > 0;
      });
    },
    refundFormRules() {
      if (!this.showRefundBalanceInputs) {
        return {};
      }
      return {
        refundBen: [{ required: true, message: '请输入本金', trigger: 'blur' }],
        refundGive: [{ required: true, message: '请输入赠金', trigger: 'blur' }],
      };
    },
    colVisible() {
      const visible = {};
      this.columnOptions.forEach(({ key }) => {
        visible[key] = this.columnVisibleKeys.includes(key);
      });
      return visible;
    },
    showCombinationActivePay() {
      const v = this.orderData.pay_type;
      if (!v) return false;
      if (Array.isArray(v)) return v.includes('combination');
      return String(v) === 'combination';
    }
  },
  created() {
    this.loadColumnVisible();
    this.applyRouteQuery();
    this.getCash()
	  this.getErpConfig();
    this.staffList();
  },
  mounted() {},
  watch:{
    '$route'(to, from) {
      if (to.name !== 'store_order' || to.fullPath === from.fullPath) return;
      this.applyRouteQuery();
      this.getCash();
    },
	refundSelection: {
	  handler(value) {
	    this.refundMoney = value.reduce((total, { refundPrice, refundNum }) => {
	      return this.$computes.Add(total, this.$computes.Mul(refundPrice, refundNum));
	    }, 0);
	  },
	  deep: true
	},
	is_split_order(value) {
	  this.$nextTick(() => {
	    this.$refs.refundTable.selectAll(!!value);
	  });
	},
	refundMoney(value) {
	  this.$nextTick(() => {
	    if (typeof value != 'number') {
	      return;
	    }
	    if (parseFloat(value) == parseInt(value)) {
	      return;
	    }
	    if (value.toString().length - (value.toString().indexOf('.') + 1) > 2) {
	      this.refundMoney = Number(value.toFixed(2));
	    }
	  });
	},
    'orderData.pay_type': {
      handler() {
        if (!this.showCombinationActivePay) {
          this.orderData.combination_detail = '';
          this.syncCombinationFilter();
        }
      },
      deep: true
    }
  },
  methods: {
    applyRouteQuery() {
      const { storeId, payType, dateRange } = this.$route.query;
      const hasReportQuery = storeId || payType || dateRange;
      if (!hasReportQuery) return;

      // 从门店对账单跳转：只保留路由传入的筛选，清空其他搜索条件
      Object.assign(this.orderData, {
        page: 1,
        limit: 10,
        type: '',
        status: ' ',
        is_kuadian: '',
        date_range: '',
        yeji_staff: '',
        yeji_shouyi: '',
        real_name: '',
        search_order_id: '',
        search_verify_code: '',
        search_product: '',
        search_user: '',
        store_id: '',
        order_type: '',
        cash_choose: '',
        link_type: '',
        product_type: '',
        pay_type: '',
        source: '',
        time: '',
        combination_detail: '',
        active_pay: '',
        pay_sub_type: '',
        combination_cash_choose: '',
      });
      this.timeVal = [];
      this.isAll = 0;
      this.isCheckBox = false;
      this.checkUidList = [];

      if (storeId) {
        this.orderData.store_id = Number(storeId);
      }
      if (dateRange) {
        this.orderData.time = dateRange;
        this.timeVal = dateRange.split('-');
      }
    },
    syncCombinationFilter() {
      this.orderData.active_pay = '';
      this.orderData.pay_sub_type = '';
      this.orderData.combination_cash_choose = '';
      const detail = this.orderData.combination_detail;
      if (!detail || !this.showCombinationActivePay) return;
      const parts = String(detail).split('|');
      this.orderData.active_pay = parts[0];
      if (parts[0] === '3' && parts[1]) {
        this.orderData.pay_sub_type = parts[1];
      } else if (parts[0] === '2' && parts[1]) {
        this.orderData.combination_cash_choose = parts[1];
      }
    },
    loadColumnVisible() {
      try {
        const saved = localStorage.getItem('admin_store_order_field_visible');
        if (saved) {
          const keys = JSON.parse(saved);
          if (Array.isArray(keys) && keys.length) {
            this.columnVisibleKeys = this.normalizeColumnVisibleKeys(keys);
          }
        }
      } catch (e) {}
    },
    normalizeColumnVisibleKeys(keys) {
      if (!Array.isArray(keys) || !keys.length) {
        return this.columnVisibleKeys;
      }
      if (keys.includes('staff') && !keys.includes('craft_staff')) {
        keys = keys.filter((key) => key !== 'staff');
        keys.splice(Math.min(3, keys.length), 0, 'craft_staff', 'sales_staff');
      }
      const allowed = this.columnOptions.map((item) => item.key);
      let result = allowed.filter((key) => keys.includes(key));
      if (!result.includes('source')) {
        const storeIdx = result.indexOf('store');
        if (storeIdx >= 0) {
          result.splice(storeIdx, 0, 'source');
        } else {
          result.push('source');
        }
      }
      return result;
    },
    saveColumnVisible(keys) {
      this.columnVisibleKeys = keys;
      try {
        localStorage.setItem('admin_store_order_field_visible', JSON.stringify(keys));
      } catch (e) {}
    },
    /** 订单列表商品行：有效规格文案（空/默认/纯空白不传，避免出现「 | | 」） */
    orderListSpecSuk(val) {
      try {
        const a = val && val.cart_info && val.cart_info.productInfo && val.cart_info.productInfo.attrInfo
        if (!a || a.suk === undefined || a.suk === null) return ''
        const t = String(a.suk).replace(/^\s+|\s+$/g, '').replace(/^　+|　+$/g, '')
        if (!t || t === '默认' || t === '默认规格') return ''
        return t
      } catch (e) {
        return ''
      }
    },
    getCash(){
      getCash().then(res=>{
        this.cash_type=res.data.cash_type;
        this.pay_type_select=res.data.pay_type;
        this.source=res.data.source;
        const payType = this.$route.query.payType || '';
        // 仅在对账单跳转时根据 payType 设置，普通切页返回保留用户已选筛选
        if (payType !== '') {
          this.orderData.pay_type = ['cash','combination'];
          this.orderData.cash_choose = '';
          this.cash_type.forEach((item) => {
            if (payType === item.name) {
              this.orderData.cash_choose = [item.id];
            }
          });
        }
        this.getList();
      })
    },
    showRemark(row){
      this.orderId = row.id
      this.$refs.remarkInfo.modals=true;
      this.$refs.remarkInfo.isEdit = false;
      this.$refs.remarkInfo.getRemark(row.id);
    },
    showGendan(row) {
      this.$refs.changeGendan.modals = true;
      this.$refs.changeGendan.showGendan(row);
    },
    closeYeji() {
      this.yejiVisible = false;
      this.getList();
    },
    doYeji(row, staffKind) {
      this.setYeji.staffChoose = [];
      this.staffIds = [];
      let that = this;
      let type = '';
      if (row.order_type == 1) type = 1;
      if (row.order_type == 2) type = 3;
      if (row.order_type == 0) type = 2;
      if (type == 2) {
        this.rowActive = row;
        this.orderId = row.id;
        this.orderConId = row.pid > 0 ? row.pid : row.id;
        let activeTab = 'product';
        if (staffKind === 'craft') {
          activeTab = Number(row.shipping_type) === 2 ? 'writeOff' : 'product';
        }
        this.getData(row.id, null, activeTab);
        return;
      }
      getYeji({ link_id: row.link_id, type: type, goods_id: 0, price: row.pay_price }).then((res) => {
        if (res.data) {
          that.setYeji = res.data;
          res.data.staffChoose.forEach(function (item) {
            that.staffIds.push(item.staff_id);
          });
        }
        that.$refs.yeji.staffForm.store_id = row.store_id;
        that.$refs.yeji.getStaff();
        that.yejiVisible = true;
      });
    },
    userCardMove() {
      this.importCardShow = !this.importCardShow;
    },
	reset(){
		this.orderData.page = 1;
		this.orderData.type = '';
		this.orderData.time = '';
		this.orderData.real_name = '';
		this.orderData.search_order_id = '';
		this.orderData.search_verify_code = '';
		this.orderData.search_product = '';
		this.orderData.search_user = '';
		this.orderData.store_id = '';
		this.orderData.order_type = '';
		this.orderData.product_type = '';
		this.orderData.yeji_staff = '';
		this.orderData.yeji_shouyi = '';
		this.orderData.combination_detail = '';
		this.orderData.active_pay = '';
		this.orderData.pay_sub_type = '';
		this.orderData.combination_cash_choose = '';
		this.timeVal = [],
		this.getList();
	},
	// 核销订单
	bindWrite(row) {
	  if (row.total_num > 1 || row.product_type == 5 || row.product_type == 4) {
	    this.orderNumId = row.order_id
	    this.$refs.writeOff.modals = true
	    this.$refs.writeOff.getWriteOff({ oid: row.id })
	  } else {
	    if(row.product_type == 4){
	      this.$modalForm(orderWriteForm(row.id)).then((res) => {
	        this.$Message.success(res.msg)
	        this.getList()
			this.$refs.detailss.modals = false;
	        if (this.$refs.detailss.modals) {
	          this.getData(this.orderId, 1)
	        }
	      });
	    }else{
	      this.singleWrite(row)
	    }
	  }
	},
	// 单个核销
	singleWrite(row) {
	  let self = this
	  this.$Modal.confirm({
	    title: '提示',
	    content: '确定要核销该订单吗？',
	    cancelText: '取消',
	    closable: true,
	    maskClosable: true,
	    onOk: function () {
	      writeUpdate(row.order_id)
	        .then((res) => {
	          self.$Message.success(res.msg)
	          self.getList()
			  self.$refs.detailss.modals = false;
	          if (self.$refs.detailss.modals) {
	            self.getData(row.id, 1)
	          }
	        })
	        .catch((err) => {
	          self.$Message.error(err.msg)
	        })
	    },
	    onCancel: () => {},
	  })
	},
	// 订单改价
	edit(row) {
	  this.$refs.changePrice.id = row.id;
	  this.$refs.changePrice.ordeUpdateInfo(row.id);
	  this.$refs.changePrice.priceModals = true;
	},
	submitSuccess(num){
	   // if (res.data.status === false) {
	   //   return this.$authLapse(res.data)
	   // }
	   // this.$authLapse(res.data)
	   // this.FromData = res.data
	   // this.$refs.edits.modals = true
	   if(num==1){
		 this.$refs.detailss.modals = false;
	   }
	   this.getList()
	   if (this.$refs.detailss.modals) {
	     this.getData(this.orderId, 1)
	   }
	},
	checkboxItem(e) {
	  const id = parseInt(e.rowid);
	  const checked = e.checked;
	  const index = this.checkUidList.indexOf(id);
	  if (this.isAll === 1) {
	    if (checked) {
	      if (index !== -1) {
	        this.checkUidList = this.checkUidList.filter((item) => item !== id);
	      }
	    } else if (index === -1) {
	      this.checkUidList.push(id);
	    }
	  } else if (checked) {
	    if (index === -1) {
	      this.checkUidList.push(id);
	    }
	  } else if (index !== -1) {
	    this.checkUidList = this.checkUidList.filter((item) => item !== id);
	  }
	},
	allPages(e) {
	  this.isAll = parseInt(e);
	  if (this.isAll === 0) {
	    this.tableList.forEach((item) => {
	      const id = parseInt(item.id);
	      if (!this.checkUidList.includes(id)) {
	        this.checkUidList.push(id);
	      }
	    });
	  } else if (!this.isCheckBox) {
	    this.isCheckBox = true;
	    this.isAll = 1;
	    this.checkUidList = [];
	  } else {
	    this.isCheckBox = false;
	    this.isAll = 0;
	    this.checkUidList = [];
	  }
	},
	getErpConfig(){
		erpConfig().then(res=>{
			this.openErp = res.data.open_erp;
		}).catch(err=>{
			this.$Message.error(err.msg);
		})
	},
	visibleChange(visible) {
	  this.is_split_order = 0;
	  this.stockInType = 0;
	  if (!visible) {
	    this.refundSelection = [];
	    this.returnCoupon = 1;
	  }
	},
	cancelRefundModal() {
	  this.refundModal = false;
	},
	changeMenu(row, name, num) {
	  this.orderId = row.id
	  this.orderConId = row.pid > 0 ? row.pid : row.id
	  this.orderConNum = num
	  this.formValidate = { refundBen: '0', refundGive: '0' };
	  switch (name) {
	    case '1':
	      this.delfromData = {
	        title: '确认收款',
	        url: `/order/pay_offline/${row.id}`,
	        method: 'post',
	        ids: '',
	      }
	      this.$modalSure(this.delfromData)
	        .then((res) => {
	          this.$Message.success(res.msg)
	          this.getData(row.id, 1)
	          this.getList()
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	      break
		case '2':
		  this.rowActive = row
		  this.getData(row.id)
		  break
		case '3':
		  this.$refs.record.modals = true
		  this.$refs.record.getList(row.id)
		  break
	    case '4':
	      this.$refs.remarks.formValidate.remark = row.remark
	      this.$refs.remarks.modals = true
	      break
	    case '5':
		  this.rowActive = row;
		  if (row.type == 11 || row.product_type == 4) {
		    this.getOrderBenefits()
		  }
	      this.getOnlyrefundData(row.id, row.refund_type, row)
	      break
	    case '555':
	      this.$modalForm(refundEditApi(row.link_id)).then(() => this.getList())
	      break
	    case '8':
		  if (row.refund.length){
			  return this.$Message.error("该订单有售后处理中，请先处理售后申请");
		  }
	      this.delfromData = {
	        title: '修改确认收货',
	        url: `/order/take/${row.id}`,
	        method: 'put',
	        ids: '',
	      }
	      this.$modalSure(this.delfromData)
	        .then((res) => {
	          this.$Message.success(res.msg)
	          this.getList()
	          if (num) {
	            this.$refs.detailss.getSplitOrder(row.pid)
	          } else {
	            this.getData(row.id, 1)
	          }
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
	          this.getList()
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
	          this.getList()
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	      break
		case '12':
		  let pathInfo = this.$router.resolve({
		    path: this.roterPre + '/supplier/order/distribution',
		    query: {
		      id: row.id,
		      status: 2,
		    },
		  })
		  window.open(pathInfo.href, '_blank')
		  break
		case '13':
		  this.printImg(row.kuaidi_label);
		  break;
	    case '14':
	      this.$refs.remarks_bak.modals = true
	      this.$refs.remarks_bak.formValidate.remark = row.back_reason || ''
	      break
	    default:
	      this.delfromData = {
	        title: '删除订单',
	        url: `/order/del/${row.id}`,
	        method: 'DELETE',
	        ids: '',
	      }
	      this.delOrder(row, this.delfromData)
	  }
	},
	//修改增加打印方法
	printImg(url) {
	  printJS({
	    printable: url,
	    type: 'image',
	    documentTitle: '快递信息',
	    style: `img{
	      width: 100%;
	      height: 476px;
	    }`,
	  });
	},
	// 删除单条订单
	delOrder(row, data) {
	  if (row.is_del === 1) {
	    this.$modalSure(data)
	      .then((res) => {
	        this.$Message.success(res.msg)
	        this.getList()
	        this.$refs.detailss.modals = false
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
	// 仅退款
	getOnlyrefundData(id, refund_type, rowActive) {
    this.rowActive = rowActive;
    this.returnCoupon = 1;
    const cartInfo = [];
    if (rowActive._info) {
      Object.values(rowActive._info).forEach((value) => {
        if (!value.cart_info.is_gift) {
          cartInfo.push(value.cart_info);
        }
      });
    } else if (rowActive.cart_info) {
      const list = Array.isArray(rowActive.cart_info) ? rowActive.cart_info : [rowActive.cart_info];
      list.forEach((value) => {
        if (!value.is_gift) {
          cartInfo.push(value);
        }
      });
    }
    cartInfo.forEach((value) => {
      value.refundPrice = this.$computes.Div(value.refund_price, value.cart_num);
      value.refundNum = value.cart_num - value.refund_num;
      value._disabled = !value.refundNum;
    });
    this.refundProduct = cartInfo;
    this.refundSelection = cartInfo;
    this.refundModal = true;
	},
	putOpenRefund() {
	  if (this.showRefundBalanceInputs) {
	    this.$refs.formValidate.validate((valid) => {
	      if (valid) {
	        const ben = parseFloat(this.formValidate.refundBen) || 0;
	        const give = parseFloat(this.formValidate.refundGive) || 0;
	        const needConfirm = this.rowActive.pay_type == 'yue' || this.rowActive.pay_type == 'combination' || ben > 0 || give > 0;
	        if (needConfirm) {
	          this.$Modal.confirm({
	            title: '操作退款',
	            content: '您本次退款的本金【' + this.formValidate.refundBen + '】元和赠金【' + this.formValidate.refundGive + '】元，是否确认退回用户余额？',
	            okText: '确认退款',
	            cancelText: '取消操作',
	            onOk: () => {
	              this.doOpenRefund();
	            },
	          });
	        } else {
	          this.doOpenRefund();
	        }
	      } else {
	        this.$Message.warning('请填写本金和赠金！');
	      }
	    });
	  } else {
	    this.doOpenRefund();
	  }
	},
	doOpenRefund() {
	  let data = {
	    id: this.orderId,
	    refund_price: this.refundMoney,
	    refund_ben: this.showRefundBalanceInputs ? this.formValidate.refundBen : '0',
	    refund_give: this.showRefundBalanceInputs ? this.formValidate.refundGive : '0',
	    type: 1,
	    is_split_order: this.is_split_order,
		refund_explain: this.refund_explain,
		stock_in_type: this.stockInType,
	    return_coupon: this.showReturnCouponOption ? this.returnCoupon : 1,
	  };
	  if (this.is_split_order) {
	    if (!this.refundSelection.length) {
	      return this.$Message.error('请选择需要退款的商品');
	    }
	    data.cart_ids = this.refundSelection.map(({ id, refundNum }) => ({
	      cart_id: id,
	      cart_num: refundNum
	    }));
	  }
	  putOpenRefund(data).then(res => {
	    this.$Message.success(res.msg);
	    this.refundModal = false;
		this.getList();
	    if (this.orderDatalist && this.orderDatalist.orderInfo) {
	      this.getData(this.orderDatalist.orderInfo.id);
	    }
	  }).catch(err => {
	    this.$Message.error(err.msg);
	  });
	},
	refundSelectionChange(selection) {
	  this.refundSelection = selection;
	},
	refundNumChange({ id, refundNum }) {
	  let result = this.refundSelection.find(item => item.id === id);
	  if (result) {
	    result.refundNum = refundNum;
	  }
	},
    // 获取详情表单数据；activeTab：product|detail|record 等，默认 product
    getData(id, type, activeTab) {
      getDataInfo(id)
        .then(async (res) => {
          if (!type) {
            this.$refs.detailss.modals = true;
          }
          this.$refs.detailss.activeName = activeTab || 'product';
          if (res.data.orderInfo.type == 11) {
            res.data.orderInfo.cartInfo[0]._loading = false;
            res.data.orderInfo.cartInfo[0].children = [];
          }
          this.orderDatalist = res.data;
          if (this.orderDatalist.orderInfo.refund_reason_wap_img) {
            try {
              this.orderDatalist.orderInfo.refund_reason_wap_img = JSON.parse(
                this.orderDatalist.orderInfo.refund_reason_wap_img
              );
            } catch (e) {
              this.orderDatalist.orderInfo.refund_reason_wap_img = [];
            }
          }
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 修改成功(编辑只有未支付时出现)
	submitFail() {
	  this.status = 0
	  this.getList()
	  if (this.orderConNum != 1) {
	    this.getData(this.orderId, 1)
	  } else {
	    this.$refs.detailss.getSplitOrder(this.orderConId)
	  }
	},
    // 发送货
    sendOrder(row, num) {
	  this.orderConId = row.pid
	  this.orderConNum = num
      this.$store.commit("admin/order/setSplitOrder", row.total_num);
      this.$refs.send.modals = true;
	  this.$refs.send.activeRow = row;
      this.orderId = row.id;
      this.status = row._status;
      this.pay_type = row.pay_type;
      this.$refs.send.getList();
      this.$refs.send.getDeliveryList();
      this.$nextTick((e) => {
        this.$refs.send.getCartInfo(row._status, row.id);
      });
    },
	// 配送信息表单数据
	delivery(row, num) {
	  getDistribution(row.id)
	    .then(async (res) => {
	      this.orderConNum = num
	      this.orderConId = row.pid
	      this.FromData = res.data
	      this.$refs.edits.modals = true
	      if (num != 1) {
	        this.getData(this.orderId, 1)
	      }
	    })
	    .catch((res) => {
	      this.$Message.error(res.msg)
	    })
	},
    // 详情
    showUserInfo(row) {
      const uid = Number(row && row.uid);
      if (!uid) return;
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.activeName = "info";
      this.$refs.userDetails.getDetails(uid);
    },
    openOrderDebtDetail(row) {
      if (this.$refs.orderDebtDetail) {
        this.$refs.orderDebtDetail.open(row);
      }
    },
    // 店员列表
    staffList() {
      let data = {
        page: 0,
        limit: 0,
      };
      staffListInfo()
        .then((res) => {
          this.staffData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 订单头部数据
    getChart() {
      orderChart(this.orderData)
        .then((res) => {
          this.orderChartType = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 订单列表
    getList() {
      this.syncCombinationFilter();
      this.loading = true;
      this.tableList = [];
      orderList(this.orderData)
        .then((res) => {
          let data = res.data;
          data.data.forEach((item) => {
            if (item.id == this.orderId) {
              this.rowActive = item;
            }
          });
          this.tableList = data.data;
          this.total = data.count;
          this.loading = false;
		  this.getChart();
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    pageChange(index) {
      this.orderData.page = index;
      this.getList();
    },
    // 搜索
    orderSearch() {
      this.orderData.page = 1;
	  this.isAll = 0;
	  this.isCheckBox = false;
	  this.checkUidList = [];
	  this.getList();
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.orderData.time = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.orderData.page = 1;
      if (!e[0]) {
        this.orderData.time = "";
      }
      this.getList();
    },
	// 订单核销
	writeOff() {
	  this.modals2 = true
	},
	// 验证
	search(name) {
	  this.$refs[name].validate((valid) => {
	    if (valid) {
	      this.writeOffFrom.confirm = 0
	      putWrite(this.writeOffFrom)
	        .then(async (res) => {
	          if (res.status === 200) {
	            this.$Message.success(res.msg)
	          } else {
	            this.$Message.error(res.msg)
	          }
	        })
	        .catch((res) => {
	          this.$Message.error(res.msg)
	        })
	    } else {
	      this.$Message.error('请填写正确的核销码')
	    }
	  })
	},
	// 订单核销
	ok() {
	  if (!this.writeOffFrom.code) {
	    this.$Message.warning('请先验证订单！')
	  } else {
	    this.writeOffFrom.confirm = 1
	    putWrite(this.writeOffFrom)
	      .then(async (res) => {
	        if (res.status === 200) {
	          this.$Message.success(res.msg)
	          this.modals2 = false
	          this.$refs[name].resetFields()
			  this.getList();
	        } else {
	          this.$Message.error(res.msg)
	        }
	      })
	      .catch((res) => {
	        this.$Message.error(res.msg)
	      })
	  }
	},
	del(name) {
	  this.modals2 = false
	  this.writeOffFrom.confirm = 0
	  this.$refs[name].resetFields()
	},
	async exports(value) {
	  if (!this.checkUidList.length && this.isAll == 0) {
	    return this.$Message.warning('请勾选要导出的订单')
	  }
	  this.syncCombinationFilter();
	  let [th, filekey, data, fileName] = [[], [], [], '']
	  let excelData = {
	    ...this.orderData,
      page: 1,
	    export_type: 0,
	    ids: this.isAll == 1 ? '' : this.checkUidList.join(),
	    plat_type: 1,
	  }
	  for (let i = 0; i < excelData.page; i++) {
	    let lebData = await this.downOrderData(excelData)
	    if (!lebData.export.length) {
	      break;
	    }
	    if (!fileName) {
	      fileName = lebData.filename
	    }
	    if (!filekey.length) {
	      filekey = lebData.filekey
	    }
	    if (!th.length) {
	      th = lebData.header
	    }
	    data = data.concat(lebData.export)
	    excelData.page++
	  }
	  let sheetData = []
	  // for (let j = 0; j < data.length; j++) {
	  //   let goodsList = data[j].goods_name.split('\n')
	  //   for (let k = 0; k < goodsList.length; k++) {
	  //     let row = {...data[j]}
	  //     row.goods_name = goodsList[k]
	  //     if (k) {
	  //       for (const key in row) {
	  //         if (Object.hasOwnProperty.call(row, key)) {
	  //           if (key !== 'goods_name') {
	  //             row[key] = null
	  //           }
	  //         }
	  //       }
	  //     }
	  //     sheetData.push(row)
	  //   }
	  // }
	  exportExcel(th, filekey, fileName, data)
	},
	downOrderData(excelData) {
	  return new Promise((resolve, reject) => {
	    storeOrderApi(excelData).then((res) => {
	      return resolve(res.data)
	    }).catch(err=>{
			this.$Message.error(err.msg)
		})
	  })
	},
  getOrderBenefits() {
    orderBenefits(this.orderId).then((res) => {
	  this.benefitsInfo = res.data;
      this.writeSurplusTimes = res.data.write_surplus_times;
      this.writeTimes = res.data.write_times;
      this.remainingPrice = res.data.remaining_price;
	  this.refundMoney = res.data.remaining_price;
      this.cardBenefits = res.data.cart_info;
    });
  }
  }
};
</script>

<style scoped lang="stylus">
/deep/.ivu-dropdown-item{
  font-size: 12px!important;
}
/deep/.vxe-table--render-default .vxe-cell{
  font-size: 12px;
}
.tdinfo{
  margin-left: 75px;
  margin-top: 16px;
}
.expand-row{
  margin-bottom: 16px;
  font-size: 12px;
}
.ivu-tag-orange{
  color #fa8c16;
}
img {
  height: 36px;
  display: block;
}

.tabBox {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;

  .tabBox_img {
    width: 30px;
    height: 30px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .tabBox_tit {
    width: 290px;
    height: 30px;
    line-height: 30px;
    font-size: 12px !important;
    margin: 0 2px 0 10px;
    letter-spacing: 1px;
    box-sizing: border-box;
  }
}

.tabBox +.tabBox {
  margin-top: 5px;
}

.vertical-center-modal {
  display: flex;
  align-items: center;
  justify-content: center;
}

/deep/.select-item:hover {
  background-color: #f3f3f3;
}

/deep/.select-on {
  display: block;
}

/deep/.select-item.on {
  /* background: #f3f3f3; */
}

.pictrue-box {
  display: flex;
  align-item: center;
}

.pictrue {
  width: 25px;
  height: 25px;
}

.trip {
  color: orange;
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
.order-list-toolbar
  display flex
  align-items flex-start
  justify-content space-between
  .order-list-tabs
    flex 1
    min-width 0
  .field-toggle-wrap
    flex-shrink 0
    padding-top 8px
    margin-left 16px
  .field-toggle-link
    color #7c6bae
    font-size 13px
    cursor pointer
    white-space nowrap
.field-toggle-panel
  padding 4px 0
  >>> .ivu-checkbox-group
    display flex
    flex-wrap wrap
  .field-toggle-item
    width 33.33%
    margin-right 0
    margin-bottom 12px
    font-size 13px
.flex-between-center
  margin-bottom 12px
>>> .ivu-table-fixed-body {
  background-color: #f8f8f9;
}
>>>.ivu-table th {
  overflow: visible;
}

>>>.refund-modal {
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

  .ivu-input-number-controls-outside-btn i {
    font-weight: bold;
  }

  .ivu-input-number-controls-outside-btn:hover i {
    color: inherit;
  }

  .ivu-input-number-controls-outside-btn-disabled, .ivu-input-number-controls-outside-btn-disabled:hover {
    background-color: #F5F5F5;
  }

  .ivu-input-number-controls-outside-btn-disabled i, .ivu-input-number-controls-outside-btn-disabled:hover i {
    color: rgba(0, 0, 0, 0.85);
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

  .ivu-btn:focus {
    box-shadow: none;
  }

  .ivu-btn-primary {
    border-color: #1890FF;
    background-color: #1890FF;
    color: #FFFFFF;
  }

  .ivu-form .ivu-form-item-label {
    font-size: 13px !important;
  }

  .ivu-table {
    font-size: 14px !important;
    line-height: 20px;
  }

  .image-wrap {
    float: left;
  }

  .image {
    width: 46px;
    height: 46px;
  }

  .title {
    margin-left: 52px;
  }
}

.action-link-gap {
  margin-left: 10px;
}
</style>
