<template>
  <div class="verify-page verify-page--workbench">
    <writeoffWorkbench
      class="batch-workbench"
      :uid="workbenchUid"
      :member="workbenchMember"
      @exit="onWorkbenchExit"
      @member-change="onWorkbenchMemberChange"
      @active-card="onWorkbenchActiveCard"
      @legacy-action="handleWorkbenchLegacy"
      @done="onWorkbenchSuccess"
      @success="onWorkbenchSuccess"
    />
      <Modal
        v-model="legacyDetailVisible"
        title="订单详情"
        width="960"
        :footer-hide="true"
        class-name="verify-legacy-modal"
      >
        <orderDetails
          v-if="legacyDetailVisible && selectOrderData.id"
          :id="selectOrderData.id"
        />
      </Modal>
      <Modal
        v-model="legacyRecordVisible"
        title="核销记录"
        width="960"
        :footer-hide="true"
        class-name="verify-legacy-modal"
      >
        <orderRecord
          v-if="legacyRecordVisible && selectOrderData.id"
          :key="'legacy-rec-' + recordRefreshKey + '-' + selectOrderData.id"
          :id="selectOrderData.id"
        />
      </Modal>
      <!-- 备注 -->
      <order-remark
        ref="remarks"
        :orderId="selectOrderData.id"
        @submitFail="submitFail"
      ></order-remark>
      <orderWriteOff
        ref="writeOff"
        :orderNumId="selectOrderData.order_id"
      ></orderWriteOff>
      <debt-repay
        v-model="debtRepayVisible"
        :row="debtRepayRow"
        :store-name="currentStoreName"
        @pay="onDebtRepayPay"
      />
      <settleDrawer
        ref="settlePay"
        v-model="settleVisible"
        :list="payList"
        :type="payType"
        :hide-yue-pay-option="hideYuePayOption"
        :force-combination-pay="forceCombinationPay"
        :init-combination-info="initCombinationInfo"
        :lock-yue-edit="lockSettleYueEdit"
        :lock-debt-repay-source="lockDebtRepaySource"
        :initial-source="debtRepayInitialSource"
        :money="settleMoney"
        :collection="collection"
        :price-info="priceInfo"
        :has-remark="createOrder.remarkInfo"
        :now-money="verifyUserMoney"
        :submit-data="{}"
        :verify="yueVerify"
        :is-recharge="0"
        :z-index="zIndex"
        @payPrice="payPrice"
        @changeSource="changeSource"
        @numTap="numTap"
        @delNum="delNum"
        @cashBnt="cashBnt"
        @setBudan="setBudan"
        @saveRemark="saveRemark"
        @saveCombinationinfo="saveCombinationinfo"
      />
      <Modal v-model="refundModal" title="撤销订单" width="960" class-name="refund-modal">
        <Form ref="refundForm" :label-width="100" :rules="refundFormRules" :model="refundForm">
          <FormItem label="退款金额：">
            <InputNumber v-model="refundMoney" style="width: 408px;"></InputNumber>
            <div class="refund-tips">
              <div style="color: red">如果是开错单，退款金额输入0</div>
              <div>请注意：退款金额作为记录使用；【退本金】【退赠金】是用于退回用户余额的值</div>
            </div>
          </FormItem>
          <FormItem label="退本金：" required prop="refundBen">
            <Input v-model="refundForm.refundBen" style="width: 408px;" placeholder="请输入退还本金"></Input>
          </FormItem>
          <FormItem label="退赠金：" required prop="refundGive">
            <Input v-model="refundForm.refundGive" style="width: 408px;" placeholder="请输入退还赠金"></Input>
          </FormItem>
          <FormItem label="退款说明：">
            <Input v-model="refund_explain" placeholder="请输入退款说明" style="width: 408px;" />
          </FormItem>
        </Form>
        <div slot="footer">
          <Button @click="refundModal = false">取消</Button>
          <Button type="primary" @click="submitCancelOrder">提交</Button>
        </div>
      </Modal>

      <Modal v-model="writeoffSuccessVisible" title="项目核销成功" width="460" :mask-closable="false">
        <p>已完成本次所选卡项和项目的核销</p>
        <p v-if="lastWriteoffCancels.length" style="margin-top: 8px; color: #808695;">
          本次生成 {{ lastWriteoffCancels.length }} 条核销记录，可直接撤销。
        </p>
        <div slot="footer">
          <Button
            v-if="lastWriteoffCancels.length"
            type="error"
            ghost
            :loading="writeoffCancelLoading"
            @click="cancelLastWriteoffs"
          >撤销本次核销</Button>
          <Button type="primary" @click="closeWriteoffSuccess">完成</Button>
        </div>
      </Modal>

      <memberSet
        ref="memberSet"
        hide-guest
        picker-title="选择受让客户"
        @submitSuccess="onPickTransferUser"
      />

      <addReservation ref="addReservation" />

      <Modal v-model="extendModal" title="卡延期" width="480" class-name="card-op-modal">
        <Form :label-width="100">
          <FormItem label="有效期至：">
            <DatePicker
              v-model="extendDate"
              type="date"
              format="yyyy-MM-dd"
              placeholder="请选择有效期"
              style="width: 100%;"
              :transfer="true"
            />
          </FormItem>
        </Form>
        <p class="card-op-tip">已失效的卡也可延期，延期后立即恢复可用（在剩余次数内）。</p>
        <div slot="footer">
          <Button @click="extendModal = false">取消</Button>
          <Button type="primary" :loading="extendLoading" @click="submitCardExtend">确认延期</Button>
        </div>
      </Modal>
  </div>
</template>

<script>
import orderList from "@/components/orderList";
import goodsList from "@/pages/hang/components/goodsList";
import userOrder from "./components/userOrder";
import writeoffWorkbench from "./components/writeoffWorkbench";
import orderDetails from "./components/orderDetails";
import orderRecord from "@/components/orderRecord";
import orderRemark from "@/components/orderRemark";
import orderWriteOff from "./components/orderWriteOff";
import debtRepay from '@/components/debtRepay';
import settleDrawer from '@/components/settleDrawer';
import util from '@/libs/util';
import filterModal from "@/components/filterModal";
import memberSet from "@/pages/cashier/components/memberSet";
import addReservation from "@/pages/reservation/components/addReservation";

import { getVerifyList, putWriteUpdate, putWriteoffCancel, orderWriteForm, getPrice, openRefund, orderBenefits, cardTransfer, cardExtend } from "@/api/order";
import { debtOrderItemsApi, debtRepayPayApi } from '@/api/debt';
import Setting from '@/setting';

export default {
  components: {
    orderList,
    goodsList,
    userOrder,
    writeoffWorkbench,
    orderDetails,
    orderRemark,
    orderRecord,
    orderWriteOff,
    debtRepay,
    settleDrawer,
    filterModal,
    memberSet,
    addReservation,
  },
  data() {
    return {
      workbenchUid: 0,
      workbenchMember: null,
      pageMode: 'batch',
      legacyDetailVisible: false,
      legacyRecordVisible: false,
      orderId: 0,
      orderListData: [],
      tabs: ["商品信息", "订单详情", "订单记录"],
      sle: 0,
      filterModal: false,
      userFrom: {
        keyword: "",
        page: 1,
        limit: 9,
      },
      dataList: [],
      orderData: {
        keyword: "",
        type: "",
        search_type:2,
        status: "5",
        time: "",
        staff_id: "",
        real_name: "",
        page: 1,
        limit: 10,
      },
      cha:0,
      timer: null,
      yuNum:0,
      is_card_num:0,
      budan:0,
      order_time:'',
      selectOrderData: {},
      orderInfoData: {},
      count: 0,
      orderStatusList: [],
      selectOrderDatas: [],
      currentPage: 1,
      refundModal: false,
      refundMoney: 0,
      refund_explain: '',
      refundForm: {
        refundBen: '0',
        refundGive: '0',
      },
      refundFormRules: {
        refundBen: [{ required: true, message: '请输入本金', trigger: 'blur' }],
        refundGive: [{ required: true, message: '请输入赠金', trigger: 'blur' }],
      },
      transferToUid: 0,
      transferToUser: {},
      transferLoading: false,
      extendModal: false,
      extendDate: '',
      extendLoading: false,
      verifySubmitting: false,
      writeoffSuccessVisible: false,
      writeoffCancelLoading: false,
      lastWriteoffCancels: [],
      recordRefreshKey: 0,
      debtLimitMsg: '你当前订单还有欠款，可用次数已用完，是否去还款？',
      fullDebtLimitMsg: '你当前订单还有欠款，可用次数已用完，是否去还款？',
      debtRepayVisible: false,
      debtRepayRow: {},
      isDebtRepay: 0,
      lockDebtRepaySource: false,
      debtRepayInitialSource: 0,
      debtRepayData: {},
      settleVisible: false,
      settleMoney: 0,
      collection: 0,
      collectionArray: [],
      payType: '',
      payNum: '',
      cashBntLoading: false,
      yueVerify: false,
      zIndex: 9999,
      hideYuePayOption: false,
      forceCombinationPay: false,
      lockSettleYueEdit: false,
      initCombinationInfo: [],
      priceInfo: {
        is_cashier_yue_pay_verify: false,
      },
      createOrder: {
        combination_info: [],
        remarkInfo: {
          water_number: '',
          remark: '',
        },
        cash_choose: 0,
        source: 0,
        is_budan: 0,
        budan_time: '',
        auth_code: '',
        userCode: '',
        pay_type: '',
      },
      payList: [
        { id: 1, label: '微信/支付宝', value: '', status: true, icon: '', num: 3 },
        { id: 2, label: '现金收款', value: 'cash', status: true, icon: 'iconicon_cash', num: 3 },
        { id: 3, label: '余额收款', value: 'yue', status: true, icon: 'icona-icon_yue', num: 3 },
        { id: 4, label: '组合收款', value: 'combination', status: true, icon: 'iconicon_cash', num: 4 },
      ],
    };
  },
  computed: {
    currentStoreName() {
      return util.cookies.get('pageTitle') || '';
    },
    verifyUserMoney() {
      return Number((this.selectOrderData && this.selectOrderData.now_money) || 0);
    },
    staffInfoId() {
      try {
        const info = JSON.parse(sessionStorage.getItem('staffInfo') || '{}');
        return Number(info.id || 0);
      } catch (e) {
        return 0;
      }
    },
    canCancelOrder() {
      const row = this.selectOrderData || {};
      return row.order_id && row.paid === 1 && row.refund_status === 0 && row.refund_type !== 6;
    },
    canOpenReservation() {
      const row = this.selectOrderData || {};
      return row.order_id && row.uid;
    },
    canCardOps() {
      const row = this.selectOrderData || {};
      if (!row.id || row.paid !== 1 || row.refund_status !== 0 || row.refund_type === 6) {
        return false;
      }
      return row.product_type === 4 || row.product_type === 5;
    },
    canShowWriteoff() {
      const row = this.selectOrderData || {};
      if (!row.order_id || row.paid !== 1 || row.refund_type === 6) {
        return false;
      }
      if (!row.status || row.status === 5) {
        return true;
      }
      if (row.is_yx === 1 && (Number(this.cha) > 0 || Number(this.yuNum) > 0)) {
        return true;
      }
      return false;
    },
    batchUid() {
      return Number(this.workbenchUid || (this.selectOrderData && this.selectOrderData.uid) || 0);
    },
    batchMember() {
      if (this.workbenchMember && this.workbenchMember.uid) {
        return this.workbenchMember;
      }
      const row = this.selectOrderData || {};
      if (!row.uid) return null;
      return {
        uid: Number(row.uid),
        real_name: row.real_name || '',
        nickname: row.nickname || '',
        phone: row.phone || row.user_phone || '',
        avatar: row.avatar || '',
      };
    },
  },
  watch: {
    'orderData.keyword'(value) {
      this.orderData.status = value ? '' : '5';
    }
  },
  created() {
    const data = this.$route.query && Object.keys(this.$route.query).length
      ? this.$route.query
      : this.getUrlParams();
    const uid = Number(data.uid || 0);
    if (uid > 0) {
      this.workbenchUid = uid;
      this.workbenchMember = {
        uid,
        phone: data.phone || '',
        nickname: data.nickname || '',
        real_name: data.real_name || '',
      };
      this.currentPage = 2;
      this.pageMode = 'batch';
      return;
    }
    // 菜单进入：直接空状态工作台，不查旧会员列表、不默认会员
    this.workbenchUid = 0;
    this.workbenchMember = null;
    this.currentPage = 2;
    this.pageMode = 'batch';
  },
  methods: {
    syncWorkbenchRouteQuery(member) {
      const query = member && member.uid
        ? {
            uid: member.uid,
            phone: member.phone || '',
            nickname: member.nickname || '',
            real_name: member.real_name || '',
          }
        : {};
      this.$router.replace({
        path: `${Setting.roterPre}/verify/index`,
        query,
      }).catch(() => {});
    },
    onWorkbenchMemberChange(member) {
      if (!member || !member.uid) return;
      this.workbenchUid = Number(member.uid);
      this.workbenchMember = { ...member };
      this.selectOrderData = {
        ...(this.selectOrderData || {}),
        uid: this.workbenchUid,
        phone: member.phone || '',
        nickname: member.nickname || '',
        real_name: member.real_name || '',
      };
      this.syncWorkbenchRouteQuery(this.workbenchMember);
    },
    onWorkbenchActiveCard(card) {
      if (!card) return;
      this.selectOrderData = {
        ...(this.selectOrderData || {}),
        id: card.oid || this.selectOrderData.id,
        order_id: card.order_id || this.selectOrderData.order_id,
        uid: card.uid || this.workbenchUid,
      };
    },
    onWorkbenchExit() {
      this.$router.push({
        path: `${Setting.roterPre}/cashier/index`,
      }).catch(() => {
        this.$router.back();
      });
    },
    changeBudanTime(e){
      this.order_time=e;
    },
    chooseType(type){
        this.orderListData=[];
        this.orderData.search_type=type;
        this.orderData.page = 1;
        this.getVerifyList();
    },
    getUrlParams() {
      // 获取 URL 中的查询字符串（? 后面的部分）
      const search = window.location.search;
      // 初始化 URLSearchParams
      const params = new URLSearchParams(search);
      // 返回参数对象
      const result = {};
      for (const [key, value] of params) {
        result[key] = value;
      }
      return result;
    },
    addPage() {
      if (this.orderListData.length < this.count) this.orderData.page++;
      this.getVerifyList();
    },
    search() {
      if (this.currentPage == 1) {
        this.currentPage = 2;
      }
      this.orderListData = [];
      this.selectOrderData = {};
      this.orderData.page = 1;
      this.sle = 0;
      this.pageMode = 'batch';
      this.getVerifyList();
    },
    //搜索
    searchList(data) {
      this.filterModal = false;
      this.orderData = { ...this.orderData, ...data };
      this.search();
    },
    // 设置备注
    remarks() {
      this.$refs.remarks.modals = true;
      this.$refs.remarks.formValidate.remark = this.selectOrderData.remark;
    },
    clearSelectedMember() {
      this.workbenchUid = 0;
      this.workbenchMember = null;
      this.selectOrderData = {};
      this.legacyDetailVisible = false;
      this.legacyRecordVisible = false;
      this.syncWorkbenchRouteQuery(null);
    },
    openWorkbench() {
      // 已统一为工作台主入口，保留方法兼容旧调用
    },
    switchPageMode() {
      // 已统一为项目核销工作台，保留方法兼容旧调用
      this.pageMode = 'batch';
    },
    handleWorkbenchLegacy(actionKey) {
      const payload = typeof actionKey === 'string' ? { key: actionKey } : (actionKey || {});
      const key = payload.key;
      if (!key) return;
      if (payload.oid) {
        this.selectOrderData = {
          ...(this.selectOrderData || {}),
          id: payload.oid,
          order_id: payload.order_id || '',
          uid: payload.uid || this.workbenchUid,
        };
      }
      if (key === 'order_detail') {
        if (!this.selectOrderData || !this.selectOrderData.id) {
          return this.$Message.warning('请先选中一张卡后再查看详情');
        }
        this.legacyDetailVisible = true;
        return;
      }
      if (key === 'writeoff_record') {
        if (!this.selectOrderData || !this.selectOrderData.id) {
          return this.$Message.warning('请先在左侧选中卡项订单后再查看核销记录');
        }
        this.legacyRecordVisible = true;
        return;
      }
      if (key === 'cancel') {
        if (!this.canCancelOrder) {
          return this.$Message.warning('当前订单不可撤销，请先在左侧选中可撤销订单');
        }
        return this.openCancelOrder();
      }
      if (key === 'reservation') {
        if (!this.canOpenReservation) {
          return this.$Message.warning('请先在左侧选中会员订单后再预约');
        }
        return this.openReservation();
      }
      if (key === 'transfer') {
        if (!this.canCardOps) {
          return this.$Message.warning('请先在左侧选中可转让的卡项订单');
        }
        return this.openTransferModal();
      }
      if (key === 'extend') {
        if (!this.canCardOps) {
          return this.$Message.warning('请先在左侧选中可延期的卡项订单');
        }
        return this.openExtendModal();
      }
      if (key === 'remark') {
        if (!this.selectOrderData || !this.selectOrderData.id) {
          return this.$Message.warning('请先在左侧选中订单后再备注');
        }
        return this.remarks();
      }
      if (key === 'print') {
        if (!this.selectOrderData || !this.selectOrderData.id) {
          return this.$Message.warning('请先在左侧选中订单后再打印');
        }
        return this.point();
      }
    },
    onWorkbenchSuccess() {
      // 工作台自行 reloadOptions；此处仅刷新更多操作依赖的当前订单上下文
      if (this.selectOrderData && this.selectOrderData.id) {
        this.refreshCurrentOrder();
        this.getPrice();
        this.recordRefreshKey += 1;
      }
    },
    openCancelOrder() {
      const row = this.selectOrderData;
      if (!row || !row.id) return;
      this.refundForm = { refundBen: '0', refundGive: '0' };
      this.refund_explain = '';
      if (row.type == 11 || row.product_type == 4) {
        orderBenefits(row.id).then((res) => {
          this.refundMoney = res.data.remaining_price;
        });
      } else {
        this.refundMoney = parseFloat(row.pay_price || 0);
      }
      this.refundModal = true;
    },
    openReservation() {
      const row = this.selectOrderData || {};
      if (!row.uid) {
        return this.$Message.warning('当前订单无会员，无法预约');
      }
      const user = {
        uid: row.uid,
        real_name: row.real_name || row.nickname || '',
        nickname: row.nickname || '',
        phone: row.phone || row.user_phone || '',
        avatar: row.avatar || '',
      };
      this.$refs.addReservation.openWithMember(user);
    },
    submitCancelOrder() {
      this.$refs.refundForm.validate((valid) => {
        if (!valid) {
          this.$Message.warning('请填写本金和赠金！');
          return;
        }
        const ben = parseFloat(this.refundForm.refundBen) || 0;
        const give = parseFloat(this.refundForm.refundGive) || 0;
        const doRefund = () => {
          openRefund(this.selectOrderData.id, {
            refund_price: this.refundMoney,
            refund_ben: this.refundForm.refundBen,
            refund_give: this.refundForm.refundGive,
            type: 1,
            is_split_order: 0,
            refund_explain: this.refund_explain,
            stock_in_type: 0,
          }).then((res) => {
            this.$Message.success(res.msg);
            this.refundModal = false;
            this.orderListData = [];
            this.selectOrderData = {};
            this.orderData.page = 1;
            this.getVerifyList();
          }).catch((err) => {
            this.$Message.error(err.msg);
          });
        };
        if (ben > 0 || give > 0) {
          this.$Modal.confirm({
            title: '操作退款',
            content: '您本次退款的本金【' + this.refundForm.refundBen + '】元和赠金【' + this.refundForm.refundGive + '】元，是否确认退回用户余额？',
            okText: '确认退款',
            cancelText: '取消操作',
            onOk: doRefund,
          });
        } else {
          doRefund();
        }
      });
    },
    transferUserName(user) {
      if (!user) return '游客';
      const real = String(user.real_name || '').trim();
      if (real) return real;
      const nick = String(user.nickname || '').trim();
      if (nick) return nick;
      const phone = String(user.phone || '').trim();
      if (phone) return phone;
      return '游客';
    },
    openTransferModal() {
      this.transferToUid = 0;
      this.transferToUser = {};
      this.$refs.memberSet.currentid = 0;
      this.$refs.memberSet.modal4 = true;
      this.$refs.memberSet.searchUser();
    },
    onPickTransferUser(user) {
      if (!user || !user.uid) {
        return this.$Message.warning('请选择受让客户');
      }
      this.transferToUser = user;
      this.transferToUid = user.uid;
      this.$Modal.confirm({
        title: '确认转让',
        content: `确定将卡转让给 ${this.transferUserName(user)}（ID: ${user.uid}）吗？转让后订单归属将变更为该客户。`,
        onOk: () => {
          this.submitCardTransfer();
        },
      });
    },
    submitCardTransfer() {
      if (!this.selectOrderData.id) return;
      if (!this.transferToUid) {
        return this.$Message.warning('请选择受让客户');
      }
      this.transferLoading = true;
      cardTransfer({
        id: this.selectOrderData.id,
        to_uid: this.transferToUid,
      }).then((res) => {
        this.$Message.success(res.msg || '转让成功');
        this.recordRefreshKey += 1;
        this.refreshCurrentOrder();
      }).catch((err) => {
        this.$Message.error(err.msg || '转让失败');
      }).finally(() => {
        this.transferLoading = false;
      });
    },
    openExtendModal() {
      this.extendDate = '';
      this.extendModal = true;
    },
    formatExtendDate(val) {
      if (!val) return '';
      if (typeof val === 'string') return val.slice(0, 10);
      const d = val instanceof Date ? val : new Date(val);
      const y = d.getFullYear();
      const m = d.getMonth() + 1;
      const day = d.getDate();
      return y + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    },
    submitCardExtend() {
      if (!this.selectOrderData.id) return;
      const writeEnd = this.formatExtendDate(this.extendDate);
      if (!writeEnd) {
        return this.$Message.warning('请选择有效期');
      }
      this.extendLoading = true;
      cardExtend({
        id: this.selectOrderData.id,
        write_end: writeEnd,
      }).then((res) => {
        this.$Message.success(res.msg || '延期成功');
        this.extendModal = false;
        this.recordRefreshKey += 1;
        this.refreshCurrentOrder();
      }).catch((err) => {
        this.$Message.error(err.msg || '延期失败');
      }).finally(() => {
        this.extendLoading = false;
      });
    },
    refreshCurrentOrder() {
      const currentId = this.selectOrderData.id;
      this.orderListData = [];
      this.orderData.page = 1;
      getVerifyList(this.orderData).then((res) => {
        if (!res || !res.data) {
          return;
        }
        const list = Array.isArray(res.data.data) ? res.data.data : [];
        this.orderListData = list.map((item) => {
          const infoArr = [];
          for (const key in (item._info || {})) {
            infoArr.push(item._info[key]);
          }
          this.$set(item, '_infoData', infoArr);
          return item;
        });
        this.count = res.data.count || 0;
        const found = this.orderListData.find((item) => item.id === currentId);
        this.selectOrderData = found || this.orderListData[0] || {};
        if (this.selectOrderData.id) {
          this.getPrice();
        }
      }).catch((err) => {
        this.$Message.error((err && (err.msg || err.message)) || '刷新卡项失败，请重试');
      });
    },
    // 备注修改成功
    submitFail(remark) {
      this.selectOrderData.remark=remark;
    },
    changeNum(){
        this.$refs.userOrder.isChange=!this.$refs.userOrder.isChange;
    },
    point() {
      this.delfromData = {
        title: "立即打印订单",
        info: "您确认打印此订单吗?",
        url: `/order/print/${this.selectOrderData.id}`,
        method: "get",
        ids: "",
      };
      this.$modalSure(this.delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    selectData(data) {
      this.selectOrderDatas = data;
    },
    // 立即消耗
    getVerifyData() {
      if (this.verifySubmitting) return;
      const userOrder = this.$refs.userOrder;
      if (!userOrder) return;
      const writeOffData = userOrder.writeOffData || [];
      const selectedItems = writeOffData.filter((item) => item._checked);
      const debtBlocked = userOrder.isDebtWriteoffExhausted();
      if (debtBlocked && (!selectedItems.length || userOrder.checkDebtWriteoffBlocked(selectedItems))) {
        this.showDebtRepayConfirm(userOrder.isFullDebtOrder);
        return;
      }
      if (!selectedItems.length) {
        return this.$Message.error('请选择要消耗的商品');
      }
      if (userOrder.checkDebtWriteoffBlocked(selectedItems)) {
        this.showDebtRepayConfirm(userOrder.isFullDebtOrder);
        return;
      }
      let cart_ids = selectedItems.map((item) => {
        return {
          cart_id: item.cart_id,
          cart_num: item.value,
          service_object: item.service_object || '本人',
        };
      });
      // 仅提交本次实际核销的非赠送项目；前端过滤不替代服务端对订单、门店和员工的校验。
      const selectedByCartId = new Map(selectedItems.map((item) => [String(item.cart_id), item]));
      const syncAll = (userOrder.syncAll || [])
        .filter((item) => {
          const selectedItem = selectedByCartId.get(String(item.cart_id));
          // 替换生成权益 cart_id 可能为 rpl* 字符串，不能用 Number()>0 判断
          const cartKey = String((selectedItem && selectedItem.cart_id) || '').trim();
          return selectedItem
            && userOrder.isWritableProjectRow(selectedItem)
            && cartKey !== ''
            && cartKey !== '0';
        })
        .map((item) => {
          const selectedItem = selectedByCartId.get(String(item.cart_id));
          const value = Number(selectedItem.value || 0);
          const oncePrice = Number(item.once_price || 0);
          // once_price 为耗卡业绩单价；commission 按此分摊，writeoff_amount 由后端覆盖
          const price = Math.round((oncePrice * value + Number.EPSILON) * 100) / 100;
          const staffChoose = JSON.parse(JSON.stringify(item.staffChoose || []));
          return {
            ...item,
            value,
            price,
            staffChoose: staffChoose.length
              ? userOrder.buildEqualStaffAllocation(staffChoose, price)
              : [],
          };
        });
      // 可核销项目必须先分配手艺人，禁止只写核销记录却不落人员业绩。
      const missingStaff = selectedItems.some((item) => {
        const cartKey = String((item && item.cart_id) || '').trim();
        if (!userOrder.isWritableProjectRow(item) || cartKey === '' || cartKey === '0') {
          return false;
        }
        const syncItem = syncAll.find((row) => String(row.cart_id) === String(item.cart_id));
        return !(syncItem && Array.isArray(syncItem.staffChoose) && syncItem.staffChoose.length);
      });
      if (missingStaff) {
        return this.$Message.error('请先为核销项目选择手艺人');
      }
      if(this.selectOrderData.product_type == 4){
        this.$modalForm(orderWriteForm(this.selectOrderData.id, { cart_num: this.selectOrderDatas[0].value,is_budan:this.budan,budan_time:this.order_time })).then((res) => {
          this.$Message.success(res.msg);
          this.orderListData = [];
          this.selectOrderDatas=[];
          this.getVerifyList();
          this.selectOrderData.status = 2;
        });
      }else{
        this.verifySubmitting = true;
        let data={
          cart_ids:cart_ids,
          is_budan:this.budan,
          budan_time:this.order_time,
          syncAll
        }
        var selectOrderData=this.selectOrderData;
        let that=this;
        putWriteUpdate(this.selectOrderData.id,data)
            .then((res) => {
              that.verifySubmitting = false;
              that.selectOrderDatas = [];
              const payload = (res && res.data) || {};
              const writeoffs = Array.isArray(payload.writeoffs) ? payload.writeoffs : [];
              that.lastWriteoffCancels = writeoffs.filter((row) => Number(row.sub_order_id) > 0);
              that.writeoffSuccessVisible = true;
              if (that.$refs.userOrder) {
                that.$refs.userOrder.getWriteOff({ oid: that.selectOrderData.id }, true);
              }
              clearTimeout(that.timer);
              that.timer = setTimeout(() => {
                that.getPrice();
              }, 1000);
            })
            .catch((err) => {
              that.verifySubmitting = false;
              const msg = err.msg || '';
              if (msg === this.debtLimitMsg || msg === this.fullDebtLimitMsg) {
                this.showDebtRepayConfirm(msg === this.fullDebtLimitMsg);
                return;
              }
              this.$Message.error(msg);
            });
      }
    },
    closeWriteoffSuccess() {
      this.writeoffSuccessVisible = false;
      this.writeoffCancelLoading = false;
    },
    async cancelLastWriteoffs() {
      const rows = (this.lastWriteoffCancels || []).filter((row) => Number(row.sub_order_id) > 0);
      if (!rows.length || this.writeoffCancelLoading) return;
      this.writeoffCancelLoading = true;
      try {
        for (let i = 0; i < rows.length; i++) {
          await putWriteoffCancel(rows[i].sub_order_id, { remarks: '核销页成功弹窗撤销' });
        }
        this.$Message.success('撤销本次核销成功');
        this.lastWriteoffCancels = [];
        this.writeoffSuccessVisible = false;
        if (this.$refs.userOrder && this.selectOrderData && this.selectOrderData.id) {
          this.$refs.userOrder.getWriteOff({ oid: this.selectOrderData.id }, true);
        }
        this.getPrice();
        this.recordRefreshKey += 1;
      } catch (err) {
        this.$Message.error((err && (err.msg || err.message)) || '撤销核销未成功');
      } finally {
        this.writeoffCancelLoading = false;
      }
    },
    openDebtRepay() {
      const orderId = Number(this.selectOrderData.id || 0);
      if (!orderId) return;
      debtOrderItemsApi(orderId).then((res) => {
        const data = res.data || {};
        const items = (data.items || []).filter((item) => Number(item.pending_debt || 0) > 0);
        if (!items.length) {
          this.$Message.warning('暂无待还欠款');
          return;
        }
        const pendingItems = items;
        const debtItemId = pendingItems.length === 1
          ? Number(pendingItems[0].debt_item_id || pendingItems[0].id || 0)
          : 0;
        const targetItem = pendingItems.length === 1 ? pendingItems[0] : null;
        const pendingDebt = targetItem
          ? Number(targetItem.pending_debt || 0)
          : pendingItems.reduce((sum, item) => sum + Number(item.pending_debt || 0), 0);
        this.debtRepayRow = {
          debt_id: Number(data.debt_id || 0),
          debt_item_id: debtItemId,
          pending_debt: pendingDebt,
          order_sn: data.order_sn || this.selectOrderData.order_id || '',
          original_source: Number((targetItem && targetItem.original_source) || items[0].original_source || 0),
          product_id: Number((targetItem && targetItem.product_id) || 0),
          cart_id: Number((targetItem && (targetItem.cart_info_id || targetItem.cart_id)) || 0),
        };
        this.debtRepayVisible = true;
      }).catch((err) => {
        this.$Message.error(err.msg || '加载欠款失败');
      });
    },
    onDebtRepayPay(data) {
      this.openDebtRepaySettle(data);
    },
    openDebtRepaySettle(data) {
      this.isDebtRepay = 1;
      this.debtRepayData = { ...data };
      this.lockDebtRepaySource = true;
      this.debtRepayInitialSource = Number(data.original_source || 0);
      this.createOrder.source = this.debtRepayInitialSource;
      this.hideYuePayOption = false;
      this.forceCombinationPay = false;
      this.lockSettleYueEdit = false;
      this.initCombinationInfo = [];
      this.settleMoney = data.repay_amount;
      this.collection = data.repay_amount;
      this.payList.forEach((value, index, arr) => {
        value.status = true;
        value.num = 3;
        const uid = Number(this.selectOrderData.uid || 0);
        if (!uid) {
          value.num = 2;
          if (value.value === 'yue') value.status = false;
        }
        if (value.status && (!index || !arr[index - 1].status)) {
          this.payType = value.value;
          this.createOrder.pay_type = value.value;
        }
      });
      this.yueVerify = !!this.priceInfo.is_cashier_yue_pay_verify;
      this.settleVisible = true;
      this.$nextTick(() => {
        const settle = this.$refs.settlePay;
        if (settle) {
          settle.combinationPay = 0;
          settle.combination_info = [];
          settle.activePay = 0;
          settle.payLabel = '请选择支付方式';
          settle.source = 0;
        }
      });
    },
    debtRepaySubmit(payNum) {
      if (this.payType === 'cash') {
        if (parseFloat(this.settleMoney) > parseFloat(this.collection)) {
          return this.$Message.error('您付款金额不足');
        }
      }
      const combo = this.createOrder.combination_info || [];
      const payType = combo.length ? 'combination' : this.payType;
      const payload = {
        debt_id: this.debtRepayData.debt_id,
        debt_item_id: this.debtRepayData.debt_item_id,
        repay_amount: this.debtRepayData.repay_amount,
        pay_type: payType,
        source: this.createOrder.source || this.debtRepayInitialSource || 0,
        cash_choose: this.createOrder.cash_choose || 0,
        remark_info: this.createOrder.remarkInfo || {},
        budan_time: this.createOrder.budan_time || '',
        combination_info: combo,
        user_code: payType === 'yue' ? payNum : (this.createOrder.userCode || ''),
        auth_code: payType === '' ? payNum : (this.createOrder.auth_code || payNum || ''),
        is_budan: this.createOrder.is_budan || 0,
        setYejiAll: this.debtRepayData.setYejiAll || [],
      };
      debtRepayPayApi(payload).then((res) => {
        this.payNum = '';
        if (res.data.status === 'SUCCESS') {
          this.isDebtRepay = 0;
          this.lockDebtRepaySource = false;
          this.debtRepayInitialSource = 0;
          this.debtRepayData = {};
          this.settleVisible = false;
          this.$Message.success(res.data.message || '还款成功');
          this.onDebtRepaid();
        } else if (res.data.status === 'PAY_ING') {
          this.$Message.warning(res.data.message || '等待支付');
        } else {
          this.$Message.error(res.data.message || '还款失败');
        }
      }).catch((err) => {
        this.$Message.error(err.msg || '还款失败');
      });
    },
    cashBnt(payNum) {
      if (this.cashBntLoading) return;
      this.cashBntLoading = true;
      if (this.payType === 'yue') {
        this.createOrder.userCode = payNum;
      } else if (this.payType === '') {
        this.createOrder.auth_code = payNum;
      }
      if (this.isDebtRepay) {
        this.debtRepaySubmit(payNum);
      }
      setTimeout(() => {
        this.cashBntLoading = false;
      }, 1000);
    },
    changeSource(data) {
      this.createOrder.source = data.source;
    },
    payPrice(data) {
      this.payType = data.type;
      this.createOrder.auth_code = '';
      this.createOrder.userCode = '';
      this.createOrder.cash_choose = data.cashChoose;
      this.createOrder.pay_type = data.type;
      this.collection = data.type === 'cash' ? this.settleMoney : 0;
    },
    numTap(item) {
      if (this.collectionArray.join('') <= 9999999) {
        this.collectionArray.push(item);
      }
      this.collection = this.collectionArray.join('') || 0;
    },
    delNum(type) {
      if (type === -1) {
        this.collectionArray = [];
      } else {
        this.collectionArray.pop();
      }
      this.collection = this.collectionArray.length ? this.collectionArray.join('') : 0;
    },
    saveRemark(remarkInfo) {
      this.createOrder.remarkInfo = remarkInfo;
    },
    saveCombinationinfo(info) {
      this.createOrder.combination_info = info;
    },
    setBudan(data) {
      this.createOrder.is_budan = data.is_budan;
      this.createOrder.budan_time = data.budan_time;
    },
    showDebtRepayConfirm(isFullDebt = false) {
      this.$Modal.confirm({
        title: '提示',
        content: isFullDebt ? this.fullDebtLimitMsg : this.debtLimitMsg,
        okText: '是',
        cancelText: '否',
        onOk: () => {
          this.openDebtRepay();
        },
      });
    },
    onDebtRepaid() {
      if (this.$refs.userOrder && this.selectOrderData.id) {
        this.$refs.userOrder.getWriteOff({ oid: this.selectOrderData.id }, true);
        this.getPrice();
      }
    },
    selectOrder(data) {
      this.is_card_num = 0;
      this.selectOrderData = data;
      this.getPrice();
    },
    getPrice() {
      if (!this.selectOrderData.id) return;
      getPrice({ id: this.selectOrderData.id }).then((res) => {
        this.cha = res.data.cha;
        this.yuNum = res.data.yu_num;
        this.is_card_num = res.data.is_card_num;
      });
    },
    tabClick(index) {
      this.sle = index;
      switch (index) {
        case 1:
          break;
      }
    },

    // 消耗列表
    getVerifyList() {
      if(this.loading){
        return true;
      }
      this.loading=true;
      getVerifyList(this.orderData)
        .then((res) => {
          if (!res || !res.data) {
            return;
          }
          const list = Array.isArray(res.data.data) ? res.data.data : [];
          const mapped = list.map((item) => {
            let infoArr = [];
            for (let key in (item._info || {})) {
              let obj = item._info[key];
              infoArr.push(obj);
            }
            this.$set(item, "_infoData", infoArr);
            return item;
          });
          this.orderListData = this.orderListData.concat(mapped);
          this.count = res.data.count || 0;
          // 前置消耗页仅展示查询结果，不自动进入工作台；须用户点击会员卡
        })
        .catch((err) => {
          this.$Message.error((err && (err.msg || err.message)) || '查询超时，请稍后重试');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    onSearch() {
      if (this.orderData.keyword) {
        this.currentPage = 2;
        this.search();
      }
    },
    goBack() {
      this.pageMode = 'batch';
      this.currentPage = 1;
      this.orderData.keyword = '';
      this.orderData.type = '';
      this.orderData.status = '';
      this.orderData.time = '';
      this.orderData.staff_id = '';
      this.orderData.real_name = '';
      this.orderData.page = 1;
      this.filterModal = false;
    },
    goAll() {
      this.orderData.keyword = '';
      this.search();
    }
  },
};
</script>
<style lang="stylus" scoped>
.verify-page--workbench
  /* 顶栏约 66px；必须锁死可视高度，否则项目列表撑高后底栏被 overflow 裁切 */
  height calc(100vh - 66px)
  max-height calc(100vh - 66px)
  min-height 0
  display flex
  flex-direction column
  overflow hidden
  .batch-workbench
    flex 1
    min-height 0
    width 100%
    height 100%
    overflow hidden

.handle-title {
  margin-right: 10px;
  font-size: 14px;
  font-weight: 400;
  color: #323233;
  line-height: 20px;
}
.combine-pay-switch {
  display: flex;
  justify-content: center;
  align-items: center;
}
.order_time_out{
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.order_time{
  color: #8558fa;
}
.qukuai{
  width: 110px;
  height: 40px;
  background: #f7f8fa;
  border-radius: 4px;
  margin: 0px 0px 16px 25px;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  cursor: pointer;
  font-weight: bold;
  font-size: 14px;
  line-height: 20px;
}
.qukuai_out {
  display: flex;
  flex-wrap: wrap;
}
.left-hint {
  margin: 8px 16px 4px;
  font-size: 12px;
  color: #8c8c8c;
  line-height: 1.5;
}
.qukuai-active{
  border: 1px solid #1890ff;
  background-color: #fff;
  color: #1890ff;
}
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #ccc;
}

::-webkit-scrollbar {
  width: 0px !important;
  /* 对垂直流动条有效 */
}

.order {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  padding: 20px;
  background: #F5F5F5;

  &.order--selecting {
    .left {
      flex: 1;
      width: auto;
      max-width: 720px;
      margin: 0 auto;
    }
  }

  &.order--workbench {
    padding: 12px 16px 16px;

    .order-data--full {
      flex: 1;
      width: 100%;
      margin-left: 0;
    }
  }

  .left {
    display: flex;
    flex-direction: column;
    width: 460px;
    border-radius: 20px;
    background: #FFFFFF;

    .content {
      height: 100%;
    }

    .left-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 24px;

      .ivu-btn {
        padding: 0 6px 0 3px;
        font-size: 14px !important;
        color: #606266;
		height: 27px;
		line-height: 27px;
      }

	  /deep/.ivu-icon{
		  vertical-align: -1px;
	  }

      .title {
        display: flex;
        align-items: center;
        font-weight: 600;
        font-size: 20px;
        color: #303133;

        .line {
          margin: 0 7px;
          font-weight: 400;
          font-size: 16px;
          color: #606266;
        }
      }

      .sx {
        color: #666666;
        cursor: pointer;
        font-size: 14px;

        .ios-funnel-outline {
          font-weight: bold;
          font-size: 12px;
        }
      }
    }

    .order-box {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      font-size: 18px;

      .search {
        padding: 0 24px 16px;

        /deep/.ivu-input {
          padding-left: 14px;
          border-color: #DDDDDD;
          border-radius: 20px 0 0 20px;

          &:focus {
            border-color: #1890FF;
          }
        }

        /deep/.ivu-input-search {
          border-radius: 0px 20px 20px 0px;
          background: #1890FF !important;
          font-size: 14px;
          line-height: normal;
        }
      }

      .order-list {
        flex: 1;
      }
    }
  }

  .order-data {
    flex: 1;
    display: flex;
    flex-direction: column;
    margin-left: 20px;
    min-width: 0;
    min-height: 0;

    &.order-data--full {
      margin-left: 0;
    }

    .page-mode-tabs {
      flex: 0 0 auto;
      display: flex;
      gap: 8px;
      padding: 12px 16px;
      margin-bottom: 0;
      background: #fff;
      border-radius: 20px 20px 0 0;

      button {
        height: 36px;
        padding: 0 18px;
        border: 1px solid #e8eaed;
        border-radius: 18px;
        color: #606266;
        background: #f5f7fa;
        cursor: pointer;
        font-size: 14px;
      }

      button.active {
        color: #1890ff;
        border-color: #91caff;
        background: #eaf4ff;
        font-weight: 600;
      }
    }

    .batch-workbench {
      flex: 1;
      min-height: 0;
      width: 100%;
      border-radius: 20px;
      overflow: hidden;
      background: #f5f7fa;
    }

    .content {
      flex: 1;
      padding: 24px;
      border-radius: 0 20px 0 0;
      background: #FFFFFF;
      overflow-x: hidden;
    }

    .border-radius {
      border-radius: 20px 20px 0 0;
    }

    .header {
      display: flex;
      background: #FFFFFF;
      font-size: 18px;
      align-items: stretch;
      position: relative;

      .box {
        flex: 1;
        background: #F5F5F5;
      }

      .item {
        cursor: pointer;
        background-color: #F5F5F5;
        transition: all 0.1s;
      }

      .item-wrap {
        padding: 16px 29px;
        border-radius: 20px 20px 0px 0px;
      }

      .sel {
        color: rgba(0, 0, 0, 0.85);
        font-weight: 500;

        .item-wrap {
          background: #FFFFFF;
        }
      }

      .neighbor-left {
        border-bottom-right-radius: 20px;
      }

      .neighbor-right {
        border-bottom-left-radius: 20px;
      }
    }

    .orders {
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
      // max-height: calc(100% - 53px);
    }
  }
}

.footer {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 12px;
  padding: 16px 24px 22px;
  border-radius: 0 0 20px 20px;
  background: #FFFFFF;
  box-shadow: 0 -1px 11px 0 rgba(0,0,0,0.06);

  .footer-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
  }

  .footer-info {
    width: 100%;
  }

  .footer-left {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 24px;
    font-size: 15px;
    line-height: 1.6;

    .clerk {
      color: #000;
      font-weight: bold;
    }

    .pay {
      color: #333333;
    }

    .num {
      font-size: 20px;
      color: #F5222D;
      font-weight: bold;
      margin-right: 8px;
    }
  }

  .footer-right {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 12px;
    flex: 1;
    min-width: 0;

    .btn {
      display flex;
      justify-content: center;
      align-items: center;
      min-width: 100px;
      height: 44px;
      padding: 0 18px;
      border-radius: 22px;
      background: #F2F3F5;
      cursor: pointer;
      font-size: 16px;
      color: rgba(0,0,0,0.85);
      flex-shrink: 0;

      +.btn {
        margin-left: 0;
      }
    }

    .pay {
      color: #FFFFFF;
      background: #FF7700;
    }
    .is-disabled {
      opacity: .6;
      cursor: not-allowed;
    }
    .cancel-btn {
      color: #FFFFFF;
      background: #F5222D;
    }
    .reservation-btn {
      color: #FFFFFF;
      background: #1890FF;
    }
    .card-op-btn {
      color: #FFFFFF;
      background: #8B5CF6;
    }
    .red_btn {
      color: #FFFFFF;
      background: red;
    }
  }
}
.card-op-tip
  margin-bottom 12px
  font-size 13px
  color #666
.card-op-selected
  margin-top 12px
  padding 10px 12px
  background #f5f0ff
  border-radius 6px
  color #333
  font-size 14px
.refund-tips
  margin-top 8px
  line-height 1.6
  color #666
/deep/.page1  {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;

  .title {
    text-align: center;
    font-weight: 500;
    font-size: 24px;
    color: #303133;
  }

  .ivu-input-wrapper {
    width: 640px;
    margin: 32px auto 35px;
  }

  .ivu-input {
    height: 50px;
    padding-left: 20px;
    border-color: #1890FF;
    border-radius: 25px 0 0 25px;
    font-size: 16px !important;
  }

  .ivu-input-search {
    padding: 0 39px !important;
    border-color: #1890FF !important;
    border-radius: 0 25px 25px 0;
    background: #1890FF !important;
    font-size: 16px;
  }

  .btn {
    text-align: center;
  }

  .ivu-btn {
    font-size: 15px !important;
    color: #1890FF;

    &:focus {
      box-shadow: none;
    }
  }
}
</style>
