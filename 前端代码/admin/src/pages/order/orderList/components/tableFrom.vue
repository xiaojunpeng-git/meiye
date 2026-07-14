<template>
  <div>
    <Form
      ref="orderData"
      inline
      :model="orderData"
      :label-width="labelWidth"
      :label-position="labelPosition"
      class="tabform"
      @submit.native.prevent
    >
	  <FormItem label="订单号：" prop="search_order_id" label-for="search_order_id">
	    <Input
	      v-model="orderData.search_order_id"
	      placeholder="订单号（含补交单关联）"
	      element-id="search_order_id"
	      clearable
	     class="input-add"
	      @on-change="clearTap"
	    />
	  </FormItem>
	  <FormItem label="商品：" prop="search_product" label-for="search_product">
	    <Input
	      v-model="orderData.search_product"
	      placeholder="商品名称关键词"
	      clearable
	     class="input-add"
	      @on-change="clearTap"
	    />
	  </FormItem>
	  <FormItem label="用户：" prop="search_user" label-for="search_user">
	    <Input
	      v-model="orderData.search_user"
	      placeholder="昵称、手机号、UID、收货人"
	      clearable
	     class="input-add"
	      @on-change="clearTap"
	    />
	  </FormItem>
	  <FormItem label="订单类型：">
	    <Select
	      v-model="orderData.type"
	     class="input-add"
	      clearable
	      @on-change="typeChange"
	      placeholder="全部"
	    >
	      <Option value="0">普通订单</Option>
	      <Option value="1">秒杀订单</Option>
	      <Option value="2">砍价订单</Option>
	      <Option value="3">拼团订单</Option>
	      <Option value="4">积分订单</Option>
	      <Option value="5">套餐订单</Option>
	      <Option value="6">预售订单</Option>
	      <Option value="7">新人订单</Option>
	      <Option value="8">抽奖订单</Option>
	    </Select>
	  </FormItem>
	  <FormItem label="支付类型：">
	    <Select
	      v-model="orderData.pay_type"
	      clearable
	    class="input-add"
	      @on-change="userSearchs"
	      placeholder="全部"
	    >
	      <Option
	        v-for="item in payList"
	        :value="item.val"
	        :key="item.id"
	        >{{ item.label }}</Option
	      >
	    </Select>
	  </FormItem>
    <FormItem label="配送方式：">
	    <Select
	      v-model="orderData.delivery_type"
	     class="input-add"
	      clearable
	      @on-change="deliveryTypeChange"
	      placeholder="全部"
	    >
	      <Option value="1">快递</Option>
	      <Option value="2">商家配送</Option>
	      <Option value="3">UU</Option>
	      <Option value="4">达达</Option>
	      <Option value="5">无需配送</Option>
	      <Option value="6">到店核销</Option>
	    </Select>
	  </FormItem>
    <FormItem label="实付金额：">
      <InputNumber class="w-118 fs-12" placeholder='开始' :max="9999999999" :min="0" :precision='0' v-model="orderData.interval_price_min" />
      ~
      <InputNumber class="w-118 fs-12 mr14" placeholder='结尾' :max="9999999999" :min="0" :precision='0' v-model="orderData.interval_price_max" />
    </FormItem>
    <FormItem label="下单时间：">
	    <DatePicker
	      :editable="false"
	      :clearable="true"
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
    <FormItem label="支付时间：">
      <DatePicker
	      :editable="false"
	      :clearable="true"
	      @on-change="payTimeChange"
	      :value="payTimeVal"
	      format="yyyy/MM/dd HH:mm:ss"
	      type="datetimerange"
	      placement="bottom-start"
	      placeholder="自定义时间"
	      class="input-add"
	      :options="options"
	    ></DatePicker>
	  </FormItem>
    <FormItem label="确认收货时间：">
      <DatePicker
	      :editable="false"
	      :clearable="true"
	      @on-change="takeTimeChange"
	      :value="takeTimeVal"
	      format="yyyy/MM/dd HH:mm:ss"
	      type="datetimerange"
	      placement="bottom-start"
	      placeholder="自定义时间"
	      class="input-add"
	      :options="options"
	    ></DatePicker>
      <Button
        class="ml-14"
        type="primary"
        @click="orderSearch()"
        >查询</Button>
      <Button @click="reset()" class="ml-14">重置</Button>
	  </FormItem>
    </Form>
  </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex'
import {
  putWrite,
  storeOrderApi,
  handBatchDelivery,
  otherBatchDelivery,
  exportExpressList,
} from '@/api/order'
import { staffListInfo } from '@/api/store'
import { getSupplierList } from '@/api/supplier'
import autoSend from '../handle/autoSend'
import queueList from '../handle/queueList'
import Setting from '@/setting'
import util from '@/libs/util'
import timeOptions from '@/utils/timeOptions'
export default {
  name: 'table_from',
  components: {
    autoSend,
    queueList,
  },
  props: ['formSelection', 'isAll', 'orderDataStatus'],
  data() {
    const codeNum = (rule, value, callback) => {
      if (!value) {
        return callback(new Error('请填写核销码'))
      }
      // 模拟异步验证效果
      if (!Number.isInteger(value)) {
        callback(new Error('请填写12位数字'))
      } else {
        const reg = /\b\d{12}\b/
        if (!reg.test(value)) {
          callback(new Error('请填写12位数字'))
        } else {
          callback()
        }
      }
    }
    return {
      currentTab: '-1',
      grid: {
        xl: 7,
        lg: 12,
        md: 24,
        sm: 24,
        xs: 24,
      },
      // 搜索条件
      orderData: {
        status: '',
        data: '',
        search_order_id: '',
        search_product: '',
        search_user: '',
        field_key: '',
        pay_type: '',
        type:'', // 订单类型
        store_id: '',
        supplier_id: '',
        pay_time: '',
        take_time: '',
        delivery_type: '',
        interval_price_min: '',
        interval_price_max: '',
              },
      modalTitleSs: '',
      statusType: '',
      time: '',
      value2: [],
      isDelIdList: [],
      writeOffRules: {
        code: [{ validator: codeNum, trigger: 'blur', required: true }],
      },
      writeOffFrom: {
        code: '',
        confirm: 0,
      },
      staffData: [], // 门店
      supplierName: [], // 供应商
      modals2: false,
      timeVal: [],
      takeTimeVal: [],
      payTimeVal: [],
      options: timeOptions,
      payList: [
        { label: '全部', val: '' },
        { label: '微信支付', val: '1' },
        { label: '支付宝支付', val: '4' },
        { label: '余额支付', val: '2' },
        { label: '线下支付', val: '3' },
      ],
      manualModal: false,
      uploadAction: `${Setting.apiBaseURL}/file/upload/1`,
      uploadHeaders: {},
      file: '',
      autoModal: false,
      isShow: false,
      recordModal: false,
      sendOutValue: '',
      exportListOn: 0,
      fileList: [],
    }
  },
  mounted() {

  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    ...mapState('admin/order', [
      'orderChartType',
      'isDels',
      'delIdList',
      'orderType',
    ]),
    labelWidth() {
      return this.isMobile ? undefined : 96
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
    today() {
      const end = new Date()
      const start = new Date()
      var datetimeStart =
        start.getFullYear() +
        '/' +
        (start.getMonth() + 1) +
        '/' +
        start.getDate()
      var datetimeEnd =
        end.getFullYear() + '/' + (end.getMonth() + 1) + '/' + end.getDate()
      return [datetimeStart, datetimeEnd]
    },
  },
  watch: {
    $route() {
      if (this.$route.fullPath === '/order/list?status=1') {
        this.getPath()
      }
    },
    orderDataStatus(value) {
      this.selectChange2(value)
    },
    'orderData.interval_price_min'(val) {
      if (val === '') {
        this.orderData.interval_price_min = ''
      }
      this.getOrderIntervalPriceMin(val);
    },
    'orderData.interval_price_max'(val) {
      if (val === '') {
        this.orderData.interval_price_max = ''
      }
      this.getOrderIntervalPriceMax(val);
    },
  },
  created() {
    this.staffList()
    this.getSupplierList()
    if (this.$route.fullPath === '/order/list?status=1') {
      this.getPath()
    }
    this.$parent.$emit('add')
  },
  methods: {
    ...mapMutations('admin/order', [
      'getOrderStatus',
      'getOrderType',
      'getOrderTime',
      'getOrderNum',
      'getOrderSearchProduct',
      'getOrderSearchUser',
      'getfieldKey',
      'getSupplier_id',
      'getStore_id',
      'getType_id',
      'getOrderDeliveryType',
      'getOrderTakeTime',
      'getOrderPayTime',
      'getOrderIntervalPriceMin',
      'getOrderIntervalPriceMax',
          ]),
    getPath() {
      this.orderData.status = this.$route.query.status.toString()
      this.getOrderStatus(this.orderData.status)
      this.$emit('getList', 1)
      this.$emit('order-data', this.orderData)
    },
    clearTap(){
      this.getOrderNum(this.orderData.search_order_id)
      this.getOrderSearchProduct(this.orderData.search_product)
      this.getOrderSearchUser(this.orderData.search_user)
      this.getfieldKey('')
      this.$emit('order-data', this.orderData)
    },
	reset(){
		this.orderData = {
			status: '',
			data: '',
			search_order_id: '',
			search_product: '',
			search_user: '',
			field_key: '',
			pay_type: '',
			delivery_type:'',
			type:'', // 订单类型
			store_id: '',
			supplier_id: '',
			interval_price_min:'',
			interval_price_max:''
		}
		this.timeVal = [];
		this.payTimeVal = [];
		this.takeTimeVal = [];
		this.getOrderNum('')
		this.getOrderSearchProduct('')
		this.getOrderSearchUser('')
		this.getType_id('')
		this.getOrderType('')
		this.getOrderDeliveryType('');
		this.getOrderTime('')
		this.getOrderTakeTime('');
		this.getOrderPayTime('');
		this.$emit('getList', 1)
		this.$emit('order-data', this.orderData)
	},
    // 具体日期
    onchangeTime(e) {
      if (e[1].slice(-8) === '00:00:00') {
        e[1] = e[1].slice(0, -8) + '23:59:59'
        this.timeVal = e
      } else {
        this.timeVal = e
      }
      this.orderData.data = this.timeVal[0] ? this.timeVal.join('-') : ''
      this.getOrderTime(this.orderData.data)
      this.$emit('getList', 1)
      this.$emit('order-data', this.orderData)
    },
    // 选择时间
    selectChange(tab) {
      this.$store.dispatch('admin/order/getOrderTabs', { data: tab })
      this.orderData.data = tab
      this.getOrderTime(this.orderData.data)
      this.timeVal = []
      this.$emit('getList')
      this.$emit('order-data', this.orderData)
    },

    // 订单选择状态
    selectChange2(tab) {
      this.orderData.status = tab;
      this.getOrderStatus(tab)
      this.$emit('getList', 1)
      this.$emit('order-data', this.orderData)
    },

    // 订单类型选择
    typeChange(tab) {
      this.getType_id(tab)
      this.$emit('getList', 1)
      this.$emit('order-data', this.orderData)
    },

        // 门店
    storeChange(tab) {
      this.getStore_id(tab)
      this.$emit('getList', 1)
      this.$emit('order-data', this.orderData)
    },

        // 供应商选择
    supplierChange(tab) {
      this.getSupplier_id(tab)
      this.$emit('getList', 1)
    },

    userSearchs(type) {
      this.getOrderType(type)
      this.$emit('getList', 1)
    },

    // 时间状态
    timeChange(time) {
      this.getOrderTime(time)
      this.$emit('getList')
    },

    // 门店列表
    staffList() {
      let data = {
        page: 0,
        limit: 0,
      }
      staffListInfo()
        .then((res) => {
          this.staffData = res.data
        })
        .catch((err) => {
          this.$Message.error(err.msg)
        })
    },

    // 获取供应商内容
    getSupplierList() {
      getSupplierList()
        .then(async (res) => {
          this.supplierName = res.data
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },

    // 订单/商品/用户搜索（与后端 search_order_id、search_product、search_user 对应）
    orderSearch() {
      this.getOrderNum(this.orderData.search_order_id)
      this.getOrderSearchProduct(this.orderData.search_product)
      this.getOrderSearchUser(this.orderData.search_user)
      this.getfieldKey('')
      this.$emit('getList', 1)
    },

    // 点击订单类型
    onClickTab() {
      this.$emit('onChangeType', this.currentTab)
    },

    // 批量删除
    delAll() {
      if (this.delIdList.length === 0) {
        this.$Message.error('请先选择删除的订单！')
      } else {
        if (this.isDels) {
          this.delIdList.filter((item) => {
            this.isDelIdList.push(item.id)
          })
          let idss = {
            ids: this.isDelIdList,
            all: this.isAll,
            where: this.orderData,
          }
          let delfromData = {
            title: '删除订单',
            url: `/order/dels`,
            method: 'post',
            ids: idss,
          }
          this.$modalSure(delfromData)
            .then((res) => {
              this.$Message.success(res.msg)
              this.tabList()
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
      }
    },
    handleSubmit() {
      this.$emit('on-submit', this.data)
    },

    // 刷新
    Refresh() {
      this.$emit('getList')
    },
    //
    handleReset() {
      this.$refs.form.resetFields()
      this.$emit('on-reset')
    },
    queuemModal() {
      this.$refs.queue.modal = true
    },
    // 配送方式改变
    deliveryTypeChange(delivery_type) {
      this.getOrderDeliveryType(delivery_type);
      this.$emit('getList', 1);
    },
    takeTimeChange(take_time) {
      take_time[1] = take_time[1].replace('00:00:00', '23:59:59');
      this.takeTimeVal = take_time;
      this.orderData.take_time = this.takeTimeVal[0] ? this.takeTimeVal.join('-'):'';
      this.getOrderTakeTime(this.orderData.take_time);
      this.$emit('getList', 1);
    },
    payTimeChange(pay_time) {
      pay_time[1] = pay_time[1].replace('00:00:00', '23:59:59');
      this.payTimeVal = pay_time;
      this.orderData.pay_time = this.payTimeVal[0] ? this.payTimeVal.join('-') :'';
      this.getOrderPayTime(this.orderData.pay_time);
      this.$emit('getList', 1);
    },
  },
}
</script>

<style scoped lang="stylus">
.tab_data >>> .ivu-form-item-content {
  margin-left: 0 !important;
}

.table_box >>> .ivu-divider-horizontal {
  margin-top: 0px !important;
}

.tabform {
  margin-bottom: 10px;
}

.Refresh {
  font-size: 12px;
  color: #1890FF;
  cursor: pointer;
}

.order-wrapper {
  margin-top: 10px;
  padding: 10px;
  border: 1px solid #ddd;

  .title {
    font-size: 16px;
  }

  .order-box {
    margin-top: 10px;
    border: 1px solid #ddd;

    .item {
      display: flex;
      align-items: center;
      border-bottom: 1px solid #ddd;

      &:last-child {
        border-bottom: 0;
      }

      .label {
        width: 100px;
        padding: 10px 0 10px 10px;
        border-right: 1px solid #ddd;
      }

      .con {
        flex: 1;
        padding: 10px 0 10px 10px;
      }
    }
  }
}

.manual-modal {
  display: flex;
  align-items: center;
}

@media screen and (max-width: 1100px) {
  .caozuo {
    margin-top: 20px;
  }
}
</style>
