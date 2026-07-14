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
		 <FormItem label="订单类型：">
		   <Select
		     v-model="orderData.type"
		     class="input-add"
		     clearable
		     placeholder="请选择"
		 	 @on-change="orderSearch"
		   >
		      <Option value="0">普通订单</Option>
		       <Option value="107">配送订单</Option>
		    	 <Option value="12">预约订单</Option>
		    	 <Option value="11">卡项订单</Option>
		       <Option value="1">秒杀订单</Option>
		        <Option value="2">砍价订单</Option>
	 	       <Option value="3">拼团订单</Option>
	  	     <Option value="4">积分订单</Option>
	 	       <Option value="5">套餐订单</Option>
		       <Option value="6">预售订单</Option>
		       <Option value="7">新人订单</Option>
		       <Option value="8">抽奖订单</Option>
		       <Option value="9">拼单订单</Option>
		       <Option value="10">桌码订单</Option>
		   </Select>
		 </FormItem>
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
		  <FormItem label="订单来源：">
		    <Select v-model="orderData.order_type"
			  class="input-add"
			  clearable
			  @on-change="orderSearch"
			  >
		      <Option value="106">收银台订单</Option>
		      <Option value="108">线上订单</Option>
		    </Select>
		  </FormItem>
		  <FormItem label="订单号：" label-for="search_order_id">
		    <Input
		      placeholder="仅匹配订单号（模糊）"
		      v-model="orderData.search_order_id"
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
          <FormItem label="创建时间：">
            <DatePicker
              :editable="false"
              @on-change="onchangeTime"
              :value="timeVal"
              format="yyyy/MM/dd HH:mm:ss"
              type="datetimerange"
              placement="bottom-start"
              placeholder="自定义时间"
              class="input-add"
              :options="options"
            ></DatePicker>
          </FormItem>
		  <FormItem label="商品类型：">
		    <Select v-model="orderData.product_type"
		  	  class="input-add"
		  	  clearable
		  	  @on-change="orderSearch"
		  	  >
		      <Option value="0">产品</Option>
			   <Option value="4">次卡商品</Option>
		      <Option value="5">卡项</Option>
			    <Option value="6">预约商品</Option>
		    </Select>
			<Button type="primary" @click="orderSearch()" class="ml-14">查询</Button>
			<Button @click="reset()" class="ml-14">重置</Button>
		  </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt">
      <!-- Tab栏切换 -->
      <div class="new_tab">
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
      <div class="flex-between-center">
	  <div>
		<Button
		  type="primary"
		  v-auth="['admin-store-store_order-export']"
		  size="default"
		  @click="exports"
		  >导出订单</Button
		>
		<Button
		  v-auth="['admin-store-store_order-writeoff']"
		  class="ml-10"
		  size="default"
		  @click="writeOff"
		  >订单核销</Button
		>
      <Button
          class="ml-10"
          size="default"
          @click="userCardMove"
      >用户卡项导入</Button
      >
	  </div>
        <router-link :to="`${roterPre}/system/import/record/user_card`">导入用户卡项记录</router-link>
      </div>
      <!-- 表格 -->
	  <vxe-table
	      ref="xTable"
	      class="mt25"
	      :loading="loading"
	      row-id="id"
	      :expand-config="{accordion: true}"
	      :checkbox-config="{reserve: true}"
	      @checkbox-all="checkboxAll"
	      @checkbox-change="checkboxItem"
	      :data="tableList">
	    <vxe-column type="" width="0"></vxe-column>
	    <vxe-column type="expand" width="35">
	      <template #content="{ row }">
	        <div class="tdinfo">
	          <Row class="expand-row">
	            <Col span="6">
	              <span class="expand-key">商品总价：</span>
	              <span class="expand-value" v-text="row.total_price"></span>
	            </Col>
              <Col span="6">
	              <span class="expand-key">推广人：</span>
	              <span class="expand-value" v-text="row.spread_nickname?row.spread_nickname:'无'"></span>
	            </Col>
              <Col span="6">
	              <span class="expand-key">配送方式：</span>
	              <span class="expand-value">
                  <!-- UU/达达/商家自配（跑腿名称  13022223333） -->
                </span>
	            </Col>
              <Col span="6">
	              <span class="expand-key">用户备注：</span>
	              <span class="expand-value" v-text="row.mark?row.mark:'无'"></span>
	            </Col>
	          </Row>
	          <Row class="expand-row">
	            <Col span="6">
	              <span class="expand-key">商家备注：</span>
	              <span class="expand-value" v-text="row.remark?row.remark:'无'"></span>
	            </Col>
              <Col span="6">
	              <span class="expand-key">下单时间：</span>
	              <span class="expand-value" v-text="row.add_time"></span>
	            </Col>
              <Col span="6">
	              <span class="expand-key">支付时间：</span>
	              <span class="expand-value"></span>
	            </Col>
              <Col span="6">
	              <span class="expand-key">确认收货时间：</span>
	              <span class="expand-value"></span>
	            </Col>
	          </Row>
	        </div>
	      </template>
	    </vxe-column>
	    <vxe-column type="checkbox" width="80">
	      <template #header>
	        <div>
	          <Dropdown transfer @on-click="allPages">
	            <a href="javascript:void(0)" class="acea-row row-middle">
	              <span>全选({{isAll==1?(total-checkUidList.length):checkUidList.length}})</span>
	              <Icon type="ios-arrow-down"></Icon>
	            </a>
	            <template #list>
	              <DropdownMenu>
	                <DropdownItem name="0">当前页</DropdownItem>
	                <DropdownItem name="1">所有页</DropdownItem>
	              </DropdownMenu>
	            </template>
	          </Dropdown>
	        </div>
	      </template>
	    </vxe-column>
      <vxe-column field="add_time" title="下单时间" min-width="150"></vxe-column>
      <vxe-column field="order_id" title="订单号" min-width="175">
        <template v-slot="{ row }">
          <Tooltip
              :transfer="true"
              theme="dark"
              max-width="300"
              :delay="600"
              content="用户已删除"
              v-if="row.is_del === 1 && row.delete_time == null"
          >
            <span style="color: #ed4014; display: block">{{ row.order_id }}</span>
          </Tooltip>
          <span
              @click="changeMenu(row, '2')"
              v-else
              style="color: #2d8cf0; display: block; cursor: pointer"
          >{{ row.order_id }}</span>
        </template>
      </vxe-column>
      <vxe-column field="nickname" title="用户信息" min-width="130">
        <template v-slot="{ row }">
          <a @click="showUserInfo(row)" v-if="row.uid">{{ row.nickname }} /{{ row.uid }}</a>
          <span v-else>游客<span class="ml5">/{{ row.uid }}</span></span>
          <div style="color: #ed4014" v-if="row.delete_time != null">(已注销)</div>
        </template>
      </vxe-column>
      <vxe-column field="info" title="商品信息" min-width="330">
        <template v-slot="{ row }">
          <Tooltip :transfer="true" theme="dark" max-width="300" :delay="600">
            <div class="tabBox" v-for="(val, i) in row._info" :key="i">
              <div class="tabBox_img" v-viewer>
                <img v-lazy="val.cart_info.productInfo.attrInfo? val.cart_info.productInfo.attrInfo.image: val.cart_info.productInfo.image" />
              </div>
              <span class="tabBox_tit line1">
	            <span class="font-color-red" v-if="val.cart_info.is_gift"
              >赠品</span>

	            {{ val.cart_info.productInfo.store_name + ' | ' }}
	            {{val.cart_info.productInfo.attrInfo?val.cart_info.productInfo.attrInfo.suk: ''}} </span>
            </div>
            <div slot="content">
              <div v-for="(val, i) in row._info" :key="i">
                <p class="font-color-red" v-if="val.cart_info.is_gift">赠品</p>
                <p>{{ val.cart_info.productInfo.store_name }}</p>
                <p> {{ val.cart_info.productInfo.attrInfo? val.cart_info.productInfo.attrInfo.suk: ''}}</p>
                <p class="tabBox_pice">{{'￥' + val.cart_info.sum_price +' x ' + val.cart_info.cart_num }} </p>
              </div>
            </div>
          </Tooltip>
        </template>
      </vxe-column>
      <vxe-column field="pay_price" title="实际支付" align="center" min-width="70">
        <template v-slot="{ row }">
          <span>{{ row.paid > 0 ? row.pay_price : 0 }}</span>
        </template>
      </vxe-column>
      <vxe-column field="source_name" title="来源" min-width="100">
        <template v-slot="{ row }">
          <span>{{ row.source_name }}</span>
        </template>
      </vxe-column>
      <vxe-column field="store_name" title="门店名称" min-width="150"></vxe-column>
      <vxe-column field="id" title="订单ID" min-width="80"></vxe-column>
	    <vxe-column field="pink_name" title="订单类型" min-width="120"></vxe-column>
	    <vxe-column field="pay_type_name" title="支付类型" min-width="100">
	      <template v-slot="{ row }">
	        <a @click="showRemark(row)" v-if="row.pay_type == 'combination' || row.pay_type == 'cash'">{{ row.pay_type_name }}</a>
	        <span v-else>{{ row.pay_type_name }}</span>
	      </template>
	    </vxe-column>
		<vxe-column field="clerk_name" title="店员" min-width="150"></vxe-column>
		<vxe-column field="statusName" title="订单状态" min-width="100">
	      <template v-slot="{ row }">
	        <Tag color="default" size="medium" v-show="row.status == 3">{{
	            row.status_name.status_name
	          }}</Tag>
	        <Tag color="orange" size="medium" v-show="row.status == 4">{{
	            row.status_name.status_name
	          }}</Tag>
	        <Tag
	            color="orange"
	            size="medium"
	            v-show="row.status == 1 || row.status == 2 || row.status == 5"
	        >{{ row.status_name.status_name }}</Tag>
	        <Tag color="red" size="medium" v-show="row.status == 0">{{
	            row.status_name.status_name
	          }}</Tag>
	        <Tag
	            color="orange"
	            size="medium"
	            v-if="!row.is_all_refund && row.refund.length"
	        >部分退款中</Tag
	        >
	        <Tag
	            color="orange"
	            size="medium"
	            v-if="row.is_all_refund && row.refund.length && row.refund_type != 6"
	        >退款中</Tag
	        >
	        <div class="pictrue-box" size="medium" v-if="row.status_name.pics">
	          <div
	              v-viewer
	              v-for="(item, index) in row.status_name.pics || []"
	              :key="index"
	          >
	            <img class="pictrue mr10" v-lazy="item" :src="item" />
	          </div>
	        </div>
	      </template>
	    </vxe-column>
	    <vxe-column field="action" title="操作" width="100" fixed="right">
	      <template v-slot="{ row }">
	        <a @click="changeMenu(row, '2')">详情</a>
	      </template>
	    </vxe-column>
	  </vxe-table>
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
	  <Form :label-width="100">
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
                    <div class="flex-1 min-w-0 pr-8 white-space-normal line2">{{ item.cart_info.productInfo.store_name }}{{item.cart_info.productInfo.attrInfo.suk }}</div>
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
	    <FormItem label="退款金额：" required>
	      <InputNumber v-model="refundMoney" class="w-408"></InputNumber>
	    </FormItem>
		<FormItem label="退款说明：">
		  <Input v-model="refund_explain" placeholder="请输入退款说明" class="w-408"/>
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
    <remarkInfo ref="remarkInfo" :orderId="orderId" :remarkType="1"></remarkInfo>
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
import userCardImport from "./handle/userCardImport.vue";
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
  orderWriteForm
} from "@/api/order";
import { erpConfig } from "@/api/erp";
import timeOptions from "@/utils/timeOptions";
import Setting from '@/setting'
import exportExcel from '@/utils/newToExcel.js'
import printJS from 'print-js';
import dayjs from "dayjs";
export default {
  name: "index",
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
    remarkInfo
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
      roterPre: Setting.roterPre,
	  openErp:false,
      distshow: false, //分配的弹窗
      delfromData: {},
      pay_type: "",
      status: 0, //发货状态判断
      FromData: null,
      orderDatalist: null,
      orderId: 0,
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
        time: "",
        real_name: "",
        search_order_id: "",
        search_product: "",
        search_user: "",
        store_id: "",
        order_type: "",
		product_type:''
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
	        return h('div', params.row.productInfo.attrInfo.suk);
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
	}
  },
  created() {
	this.getErpConfig();
    this.staffList();
    this.getList();
  },
  mounted() {},
  watch:{
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
  },
  methods: {
    showRemark(row){
      this.orderId = row.id
      this.$refs.remarkInfo.modals=true;
      this.$refs.remarkInfo.getRemark(row.id);
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
		this.orderData.search_product = '';
		this.orderData.search_user = '';
		this.orderData.store_id = '';
		this.orderData.order_type = '';
		this.orderData.product_type = '';
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
	checkboxAll(){
	  // 获取选中当前值
	  let obj2 = this.$refs.xTable.getCheckboxRecords(true);
	  // 获取之前选中值
	  let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
	  if(this.isAll == 0 && this.checkUidList.length <= obj.length && !this.isCheckBox){
	  	 obj = [];
	  }
	  obj = obj.concat(obj2);
	  let ids = [];
	  obj.forEach((item)=>{
	    ids.push(parseInt(item.id))
	  })
	  this.checkUidList = ids;
	  if(!obj2.length){
	    this.isCheckBox = false;
	  }
	},
	checkboxItem(e){
	  let id = parseInt(e.rowid);
	  let index = this.checkUidList.indexOf(id);
	  if(index !== -1){
	    this.checkUidList = this.checkUidList.filter((item)=> item !== id);
	  }else{
	    this.checkUidList.push(id);
	  }
	},
	allPages(e){
	  this.isAll = e;
	  if(e==0){
	    this.$refs.xTable.toggleAllCheckboxRow();
	  }else{
	    if(!this.isCheckBox){
	      this.$refs.xTable.setAllCheckboxRow(true);
	      this.isCheckBox = true;
	      this.isAll = 1;
	    }else{
	      this.$refs.xTable.setAllCheckboxRow(false);
	      this.isCheckBox = false;
	      this.isAll = 0;
	    }
	    this.checkUidList = []
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
	  }
	},
	cancelRefundModal() {
	  this.refundModal = false;
	},
	changeMenu(row, name, num) {
	  this.orderId = row.id
	  this.orderConId = row.pid > 0 ? row.pid : row.id
	  this.orderConNum = num
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
		  if (row.type == 11 || row.product_type == 4) {
		    this.getOrderBenefits()
		  }
	      this.getOnlyrefundData(row.id, row.refund_type, row)
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
    const cartInfo = rowActive.cart_info;
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
	  let data = {
	    id: this.orderId,
	    refund_price: this.refundMoney,
	    type: 1,
	    is_split_order: this.is_split_order,
		refund_explain: this.refund_explain,
		stock_in_type: this.stockInType
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
	    this.getData(this.orderDatalist.orderInfo.id);
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
    // 获取详情表单数据
    getData(id, type) {
      getDataInfo(id)
        .then(async (res) => {
          if (!type) {
            this.$refs.detailss.modals = true;
          }
          this.$refs.detailss.activeName = "detail";
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
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.activeName = "info";
      this.$refs.userDetails.getDetails(row.uid);
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
		  this.$nextTick(function(){
		    if (this.isAll == 1) {
		      if(this.isCheckBox){
				let flag = false;
				data.data.forEach(item=>{
					this.checkUidList.forEach(j=>{
						if(item.id == j){
							flag = true;
						}
					})
				})
				if(!flag){
					this.$refs.xTable.setAllCheckboxRow(true);
				}
		      }else{
		        this.$refs.xTable.setAllCheckboxRow(false);
		      }
		    }else{
				let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
				if(!this.checkUidList.length || this.checkUidList.length <= obj.length){
				  this.$refs.xTable.setAllCheckboxRow(false);
				}
			}
		  })
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
	  this.$refs.xTable.setAllCheckboxRow(false);
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
	  let [th, filekey, data, fileName] = [[], [], [], '']
	  let excelData = {
	    ...this.orderData,
      page: 1,
	    export_type: 0,
	    ids: this.checkUidList.join(),
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
	  for (let j = 0; j < data.length; j++) {
	    let goodsList = data[j].goods_name.split('\n')
	    for (let k = 0; k < goodsList.length; k++) {
	      let row = {...data[j]}
	      row.goods_name = goodsList[k]
	      if (k) {
	        for (const key in row) {
	          if (Object.hasOwnProperty.call(row, key)) {
	            if (key !== 'goods_name') {
	              row[key] = null
	            }
	          }
	        }
	      }
	      sheetData.push(row)
	    }
	  }
	  exportExcel(th, filekey, fileName, sheetData)
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
</style>
