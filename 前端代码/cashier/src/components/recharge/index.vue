<template>
  <Modal :value="visible" title="充值" width="868" class-name="recharge-modal" @on-cancel="clear">
    <div class="infoData" v-if="userInfo">
      <div class="pictrue">
        <img :src="userInfo.avatar">
      </div>
      <div class="info">
        <div class="name">{{ userInfo.nickname }}<span v-if="userInfo.vip_name">{{ userInfo.vip_name }}</span></div>
        <div class="attr">
          <div v-if="userInfo.phone" class="item phone">{{ userInfo.phone }}</div>
          <div class="item">余额<span class="num">{{ userInfo.now_money }}</span></div>
          <div class="item">积分<span class="num">{{ userInfo.integral }}</span></div>
          <div v-if="userInfo.is_money_level" class="item">付费会员到期：<span class="time">{{ userInfo.is_ever_level ? '永久会员' : userInfo.overdue_time || '已过期' }}</span></div>
        </div>
      </div>
    </div>
    <Form :label-width="90">
      <FormItem label="充值方式：">
        <RadioGroup v-model="chooseKuai" type="button" button-style="solid" @on-change="changeTabs">
          <Radio :label="0">充值套餐</Radio>
          <Radio :label="1">自定义充值</Radio>
          <Radio :label="2">销售</Radio>
        </RadioGroup>
      </FormItem>
      <FormItem v-if="chooseKuai == 0">
        <RadioGroup v-model="activeId" class="radio-border-group" @on-change="onQuotaChange">
          <Radio v-for="item in moneyList" :key="item.id" :label="item.id" border>
            <div class="money">￥<span class="num">{{ item.price }}</span></div>
            <div>额外赠送：￥ {{ item.give_money }}</div>
          </Radio>
        </RadioGroup>
      </FormItem>
      <FormItem v-if="chooseKuai == 1">
        <FormItem label="充值金额：">
            <InputNumber v-model="payPrice" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
         </FormItem>
        <FormItem style="margin-top: 20px" label="赠送金额：">
          <InputNumber v-model="rechargeData.give_money" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
        </FormItem>
      </FormItem>
      <FormItem v-if="chooseKuai == 2">
        <Button type="primary" size="large" @click="doAdd">添加人员</Button>
        <div class="table_out">
          <div class="head">
            <div class="body_title">员工</div>
            <div class="body_title">职位</div>
            <div class="body_title">职级</div>
            <div class="body_title">业绩</div>
            <div class="body_title">操作</div>
          </div>
          <div class="body">
            <div class="heng_kuai" v-for="(item,index) in rechargeData.staffChoose">
              <div class="body_kuai">{{ item.staff_name }}</div>
              <div class="body_kuai">{{ item.position_label }}</div>
              <div class="body_kuai">{{ item.position_level_label }}</div>
              <div class="body_kuai">
                <InputNumber style="width: 80%"  v-model="item.yeji" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
              </div>
              <div class="body_kuai">
                <div class="shanchu" @click="delYeji(index)">删除</div>
              </div>
            </div>
          </div>
        </div>
      </FormItem>
      <FormItem v-if="cashierDebtPayEnabled" label="欠款：">
        <span class="debt-pay-btn" @click="toggleDebtPay">欠款</span>
        <div v-if="show_debt_pay" class="debt-pay-input-wrap">
          <Input
            v-model="rechargeData.debt_pay_amount"
            style="width: 200px"
            placeholder="输入欠款金额"
            :maxlength="11"
            inputmode="decimal"
            @on-change="onDebtPayChange"
            @on-input="onDebtPayChange"
            @input.native="onDebtPayChange"
          />
        </div>
      </FormItem>
    </Form>
    <div slot="footer">
      <div class="acea-row row-center-wrapper mt22">
        <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="clear">取消</Button>
        <Button
          class="w-176 h-46 fs-16 rd-30px ml20 btn-gendan"
          :class="{ 'gendan-selected': rechargeData.gendan_staff_id > 0 }"
          @click="openGendanModal"
        >{{ rechargeData.gendan_staff_name || '跟单' }}</Button>
        <Button class="w-176 h-46 fs-16 rd-30px ml20" style="position: relative;color:#ffffff;background-color: #19be6b" @click="showSend" v-if="cashierOperatorGiftEnabled">
          赠送
          <div class="icon-send-num" v-if="sendNum > 0">
            {{ sendNum }}
          </div>
        </Button>
        <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="save">提交</Button>
      </div>
    </div>
    <Modal v-model="showAdd"  title="分配业绩"  width='500' class="modalPay">
      <div class="payPage">
        <Input class="searchOut" v-model="staffForm.keyword" ref="rechargeNum" size="large" type="url" @input="getStaff" placeholder="输入关键词" style="margin-top: 16px;"/>
        <div class="kuai_out">
          <div :class="staffIds.includes(item.id)?'kuai choose_kuai':'kuai'" v-for="(item,index) in staffList" @click="chooseStaff(item)">{{ item.staff_name }}</div>
        </div>
      </div>
      <div slot="footer">
        <div class="acea-row row-center-wrapper mt22">
          <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="hideAdd">取消</Button>
          <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="saveAdd">确认</Button>
        </div>
      </div>
    </Modal>
    <send :sendAll="rechargeData.sendAll"  @closeSend="closeSend" :visible="sendVisible" ref="send"></send>
    <gendan-staff
      :visible="gendanVisible"
      :value="rechargeData.gendan_staff_id"
      :staff-name="rechargeData.gendan_staff_name"
      @confirm="onGendanConfirm"
      @close="gendanVisible = false"
    ></gendan-staff>
  </Modal>
</template>
<script>
import {userRechargelApi, userSaveApi, checkOrderApi,staffallList} from '@/api/user';
import send from '@/components/send';
import gendanStaff from '@/components/gendanStaff';
export default {
  name: 'recharge',
  model: {
    prop: 'visible',
    event: 'close'
  },
  components:{
      send,
      gendanStaff
  },
  props: {
    visible: {
      type: Boolean,
      default: false
    },
    userInfo: {
      type: Object,
      default: () => {
      }
    }
  },
  data() {
    return {
      modal: false,
      sendVisible: false,
      gendanVisible: false,
      timer: null,
      showAdd:false,
      currentTab: 0,
      chooseKuai: 0,
      moneyList: [],
      staffList: [],
      staffIds:[],
      staffForm:{
        keyword:''
      },
      active: 0,
      activeId: 0,
      modalPay: false,
      payNum: '',
      payPrice: 1,
      show_debt_pay: false,
      sendNum:0,
      cashierOperatorGiftSwitch: 1,
      cashierDebtPaySwitch: 0,
      rechargeData: {
        sendAll: {
          'product':[],
          'coupon':[]
        },
        uid: 0,
        price: '',
        rechar_id: 0,
        give_money: 0,
        pay_type: 3,
        auth_code: '',
        staffChoose:[],
        is_gendan: 0,
        gendan_staff_id: 0,
        gendan_staff_name: '',
        debt_pay_amount: '',
      },
      givePrice: 0,
      totalPrice: 0
    }
  },
  computed: {
    cashierOperatorGiftEnabled() {
      const v = this.cashierOperatorGiftSwitch;
      return v === undefined || v === null || v === '' ? true : Number(v) === 1;
    },
    cashierDebtPayEnabled() {
      return Number(this.cashierDebtPaySwitch) === 1;
    },
  },
  watch: {
    visible(val) {
      if (val) {
        this.getList(true);
      }
    },
    activeId() {
      this.onQuotaChange();
    },
    modal(n) {
      if (!n) {
        return
      }
      let that = this;
      document.onkeydown = function (e) {
        if (e.which == 13) {
          if (that.payNum) {
            that.rechargeData.auth_code = that.payNum;
            that.confirm();
          }
        }
      };
    }
  },
  created() {
    this.getList();
    this.getStaff();
    let that = this;
    //扫码枪扫码，针对纯数字
    // document.onkeydown = function(e) {
    // 	if (e.which == 13) {
    // 		if (that.payNum) {
    // 			that.rechargeData.auth_code = that.payNum;
    // 			console.log('klklk');
    // 			that.confirm();
    // 		}
    // 	}
    // };
  },
  methods: {
    resetSendAll() {
      this.rechargeData.sendAll = {
        product: [],
        coupon: []
      };
      this.sendNum = 0;
    },
    onQuotaChange() {
      if (this.chooseKuai !== 0) return;
      this.resetSendAll();
      this.applySendPreset();
    },
    normalizePresetCoupon(row) {
      const item = JSON.parse(JSON.stringify(row || {}));
      item.num = item.num || 1;
      item.write_days = item.write_days || 1;
      if (!item.begin_time) {
        item.begin_time = this.todayDateSecondTimestamp();
      }
      if (item.write_days > 0) {
        item.end_time = this.getEnd(item.begin_time, item.write_days);
      }
      item.begin_time_label = item.begin_time > 0
        ? this.formatSecondToDate(item.begin_time)
        : '未设置';
      item.end_time_label = item.end_time > 0
        ? this.formatSecondToDate(item.end_time)
        : '未设置';
      return item;
    },
    normalizePresetProduct(row) {
      const item = JSON.parse(JSON.stringify(row || {}));
      item.num = item.num || 1;
      return item;
    },
    applySendPreset() {
      if (this.chooseKuai !== 0) return;
      const result = this.moneyList.find(item => String(item.id) === String(this.activeId));
      if (!result || !result.send_config) return;
      const preset = result.send_config;
      this.rechargeData.sendAll = {
        product: (preset.product || []).map(row => this.normalizePresetProduct(row)),
        coupon: (preset.coupon || []).map(row => this.normalizePresetCoupon(row))
      };
      this.updateSendNum();
    },
    todayDateSecondTimestamp() {
      const now = new Date();
      const todayZero = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      return Math.floor(todayZero.getTime() / 1000);
    },
    formatSecondToDate(timestamp) {
      const timeNum = Number(timestamp);
      if (!timestamp || isNaN(timeNum) || timeNum.toString().length !== 10) {
        return '--';
      }
      const date = new Date(timeNum * 1000);
      if (date.toString() === 'Invalid Date') {
        return '--';
      }
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    },
    getEnd(startDate, days = 1) {
      const secTimestamp = Number(startDate);
      if (isNaN(secTimestamp) || secTimestamp.toString().length !== 10) {
        return 0;
      }
      const targetDate = new Date(secTimestamp * 1000);
      if (targetDate.toString() === 'Invalid Date') {
        return 0;
      }
      targetDate.setDate(targetDate.getDate() + Number(days));
      return Math.floor(targetDate.getTime() / 1000);
    },
    updateSendNum() {
      let num = 0;
      this.rechargeData.sendAll.product.forEach(res => {
        num += Number(res.num) || 0;
      });
      this.rechargeData.sendAll.coupon.forEach(res => {
        num += Number(res.num) || 0;
      });
      this.sendNum = num;
    },
    closeSend(){
      this.sendVisible=false;
      this.updateSendNum();
    },
    delYeji(index){
      this.staffIds.splice(index, 1);
      this.rechargeData.staffChoose.splice(index, 1);
    },
    chooseStaff(item){
      const index = this.staffIds.indexOf(item.id);
      if (index > -1) {
        this.staffIds.splice(index, 1);
        this.rechargeData.staffChoose.splice(index, 1);
      }else{
        this.staffIds.push(item.id);
        let attr={
          'staff_id':item.id,
          'staff_name':item.staff_name,
          'position_label':item.position_label,
          'position':item.position,
          'position_level':item.position_level,
          'position_level_label':item.position_level_label,
          'yeji':0
        };
        this.rechargeData.staffChoose.push(attr);
      }
    },
    getStaff(){
      staffallList(this.staffForm).then(res => {
        this.staffList = res.data
      })
    },
    getPrice(){
      let price=0;
      let givePrice=0;
      let totalPrice=0;
      if (this.currentTab == 1) {
        price = this.payPrice;
      } else {
        let result = this.moneyList.find(item => item.id == this.activeId);
        if (result) {
          price = result.price;
          givePrice = result.give_money;
        }
      }
      // totalPrice = this.$computes.Add(price,givePrice);
      return price;
    },
    saveAdd(){
      var len=this.staffIds.length;
      let price=this.getPrice();
      var number=Math.floor(price/ len);
      this.rechargeData.staffChoose.forEach(function (item){
        item.yeji=number;
      })
      var yu=price % len;
      if(len > 0 && yu > 0){
        this.rechargeData.staffChoose[len-1].yeji= this.rechargeData.staffChoose[len-1].yeji+yu;
      }
      this.showAdd=false;
    },
    hideAdd(){
      this.showAdd=false;
    },
    doAdd(){
      this.showAdd=true;
    },
    clear() {
      this.payPrice = 0;
      this.currentTab = 0;
      this.show_debt_pay = false;
      this.rechargeData.debt_pay_amount = '';
      this.$emit('close', false);
    },
    toggleDebtPay() {
      this.show_debt_pay = !this.show_debt_pay;
      if (!this.show_debt_pay) {
        this.rechargeData.debt_pay_amount = '';
      }
    },
    getRechargePrice() {
      if (this.chooseKuai === 1) {
        return Number(this.payPrice || 0);
      }
      const result = this.moneyList.find(item => item.id == this.activeId);
      return result ? Number(result.price || 0) : 0;
    },
    onDebtPayChange() {
      const max = this.getRechargePrice();
      let val = String(this.rechargeData.debt_pay_amount || '').replace(/[^\d.]/g, '');
      const parts = val.split('.');
      if (parts.length > 2) {
        val = parts[0] + '.' + parts.slice(1).join('');
      }
      if (parts[1] && parts[1].length > 2) {
        val = parts[0] + '.' + parts[1].slice(0, 2);
      }
      let num = Number(val || 0);
      if (max > 0 && num > max) {
        num = max;
        val = String(max);
      }
      this.rechargeData.debt_pay_amount = val;
    },
    yuePayClear() {
      this.$Message.destroy()
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null
      }
    },
    //扫码枪扫码，针对带有字母的
    inputSaoMa(e) {
      // setTimeout定时器的作用是，等待扫码枪输入完，拿到完整的二维码信息，再调接口（扫码枪输入速度大概8~20毫秒，手动输速度大概是80毫秒），否则拿不到完整的二维信息。
      // let val = e
      // if (val === '') return false
      // clearTimeout(this.endTimeout)
      // this.endTimeout = null
      // this.endTimeout = setTimeout(() => {
      // 	if (this.payNum === val) {
      // 		clearTimeout(this.endTimeout)
      // 		if (val) {
      // 			this.rechargeData.auth_code = val;
      // 			this.confirm();
      // 		}
      // 	}
      // }, 500)
    },
    changeTabs(e) {
      if(e < 2){
        this.currentTab=e;
      }
      if (e == 0) {
        this.active = 0;
        if (this.moneyList.length) {
          this.activeId = this.moneyList[0].id;
        }
        this.onQuotaChange();
      }
      if (e == 1) {
        this.resetSendAll();
      }
      if(e == 2){
        var len=this.staffIds.length;
        let price=this.getPrice();
        var number=Math.floor(price/ len);
        this.rechargeData.staffChoose.forEach(function (item){
          item.yeji=number;
        })
        var yu=price % len;
        if(len > 0 && yu > 0){
          this.rechargeData.staffChoose[len-1].yeji= this.rechargeData.staffChoose[len-1].yeji+yu;
        }
      }
    },
    getList(preserveActiveId = false) {
      return userRechargelApi().then(res => {
        this.moneyList = res.data.recharge_quota || [];
        if (res.data.cashier_operator_gift_switch !== undefined) {
          this.cashierOperatorGiftSwitch = res.data.cashier_operator_gift_switch;
        }
        if (res.data.cashier_debt_pay_switch !== undefined) {
          this.cashierDebtPaySwitch = res.data.cashier_debt_pay_switch;
        }
        if (this.moneyList.length) {
          const exists = this.moneyList.some(item => String(item.id) === String(this.activeId));
          if (!preserveActiveId || !this.activeId || !exists) {
            this.activeId = this.moneyList[0].id;
          }
        }
        if (this.visible && this.chooseKuai === 0) {
          this.resetSendAll();
          this.applySendPreset();
        }
        return res;
      });
    },
    activeMoney(index, item) {
      this.active = index;
    },
    showSend(){
      this.applySendPreset();
      this.sendVisible = true;
    },
    openGendanModal() {
      this.gendanVisible = true;
    },
    onGendanConfirm(data) {
      this.rechargeData.gendan_staff_id = data.gendan_staff_id || 0;
      this.rechargeData.gendan_staff_name = data.gendan_staff_name || '';
      this.rechargeData.is_gendan = data.is_gendan || 0;
    },
    save() {
      // this.modalPay = true;
      // this.$nextTick(() => {
      //   this.$refs.rechargeNum.focus();
      // })
      if (this.currentTab == 1) {
        this.rechargeData.rechar_id = 0;
        this.givePrice = this.rechargeData.give_money;
        this.rechargeData.price = this.payPrice;
        //限制比例
        let maxper=this.userInfo.recharge_per;
        if(maxper && maxper > 0){
             let per=this.givePrice/this.rechargeData.price;
             let max=maxper*100;
             if(per > maxper){
               this.$Message.error('赠送金额占比不能超过'+max+"%");
               return false;
             }
        }
      } else {
        let result = this.moneyList.find(item => item.id == this.activeId);
        if (result) {
          this.rechargeData.price = result.price;
          this.rechargeData.rechar_id = result.id;
          this.givePrice = result.give_money;
        }
      }
      this.totalPrice = this.$computes.Add(this.rechargeData.price, this.givePrice);
      let totalYeji=0;
      this.rechargeData.staffChoose.forEach(function (item){
        totalYeji=totalYeji+item.yeji;
      })
      if(totalYeji > this.rechargeData.price){
        this.$Message.error("累计业绩不能大于总金额！");
        return false;
      }
      const debtAmt = this.show_debt_pay ? Number(this.rechargeData.debt_pay_amount || 0) : 0;
      if (debtAmt > 0 && debtAmt > Number(this.rechargeData.price || 0)) {
        this.$Message.error('欠款金额不能超过充值金额');
        return false;
      }
      this.rechargeData.debt_pay_amount = debtAmt > 0 ? debtAmt : 0;
      if (!this.cashierOperatorGiftEnabled) {
        this.applySendPreset();
      }
      this.$emit('recharge', this.rechargeData);
    },
    confirm() {
      this.rechargeData.uid = this.userInfo.uid;
      userSaveApi(this.rechargeData).then(res => {
        this.payNum = '';
        let status = res.data.status;
        let orderId = res.data.data.order_id;
        switch (status) {
          case 'SUCCESS':
            this.$Message.success('支付成功');
            this.modalPay = false;
            this.modal = false;
            this.$emit("getSuccess", this.totalPrice);
            break;
          case 'PAY_ING':
            let msg = this.$Message.loading({
              content: '等待支付中...',
              duration: 0
            });
            this.checkOrderTime(orderId, msg);
            break;
          default:
            this.$Message.warning('支付失败');
            break;
        }
      }).catch(err => {
        this.payNum = '';
        this.$Message.error(err.msg)
      })
    },
    checkOrderTime(orderId, msg) {
      let that = this;
      let num = 1;
      let timer = this.timer = setInterval(function () {
        that.checkOrder(orderId, timer, msg);
        num++;
        if (num >= 60) {
          clearInterval(timer);
          msg();
          that.$Message.success("支付失败");
          // that.modalPay = false;
          // that.modal = false;
        }
      }, 1000)
    },
    checkOrder(orderId, timer, msg) {
      checkOrderApi(1, {order_id: orderId}).then(res => {
        if (res.data.status == true) {
          msg();
          this.$Message.success("支付成功");
          this.$emit("getSuccess", this.totalPrice);
          this.modalPay = false;
          this.modal = false;
          clearInterval(timer);
        }
      }).catch(err => {
        msg();
        this.$Message.error(err.msg)
      })
    }
  }
}
</script>

<style lang="stylus" scoped>
.btn-gendan
  color #ffffff !important
  background-color #2d8cf0 !important
  border-color #2d8cf0 !important
.gendan-selected
  background-color #1c7ed6 !important
  border-color #1c7ed6 !important
  color #ffffff !important
.icon-send-num
  position: absolute;
  top: -8px;
  right: -7px;
  padding: 5px 7px 3px;
  border-radius: 11px;
  background: #FF7700;
  font-size: 14px;
  line-height: 14px;
  color: #FFFFFF;
.shanchu{
  font-size: 0.097087rem;
  color: #1890ff;
  cursor: pointer;
}
.table_out{
  border-top: 1px solid rgb(191 191 191);
  border-left: 1px solid rgb(191 191 191);
  margin-top: 20px;
}
.heng_kuai{
  display: flex;
  width: 100%;
}
.head{
  display: flex;
  background-color: #efefef;
  width: 100%;
  border-bottom: 1px solid rgb(191 191 191);
}
.body_title{
  height: 50px;
  line-height: 50px;
  border-right: 1px solid rgb(191 191 191);
  width: 25%;
  text-align: center;
  font-weight: bold;
  font-size: 16px;
}
.body_kuai{
  height: 50px;
  line-height: 50px;
  border-right: 1px solid rgb(191 191 191);
  border-bottom: 1px solid rgb(191 191 191);
  width: 25%;
  text-align: center;
  font-size: 15px;
}
.choose_kuai{
  background-color: #409eff54;
}
.kuai_out{
  height: 400px;
  overflow-y: scroll;
  width: 100%;
  padding: 16px;
  text-align: left;
}
.kuai_out::-webkit-scrollbar {
  width: 0; /* 隐藏滚动栏宽度 */
  height: 0; /* 横向滚动时隐藏 */
}
/* 可选：自定义滚动栏（若不想完全隐藏，仅美化） */
.kuai_out::-webkit-scrollbar-thumb {
  background: transparent; /* 滚动条滑块透明 */
}
.kuai{
  margin-top: 15px;
  width: 1.20rem;
  padding: 15px 10px 15px 20px;
  border: 1px solid #eee;
  display: inline-block;
  text-align: left;
  cursor: pointer;
  margin-left: 0.14rem;
}
/deep/ .ivu-tabs-nav-scroll {
  padding 16px 24px 0 24px !important;
}


/deep/ .ivu-input-number-large input {
  height 56px !important;
  line-height 56px !important;
}

/deep/ .ivu-input-number-large {
  height 56px !important;
}

/deep/ .ivu-input-number-large .ivu-input-number-input-wrap {
  height 56px !important;
}

/deep/ .ivu-input-number-large .ivu-input-number-handler {
  height 28px;
}

/deep/ .ivu-input-number-large .ivu-input-number-handler-up-inner {
  top 6px;
}

/deep/ .ivu-input-number-large .ivu-input-number-handler-down-inner {
  bottom 6px;
}

.payPage {
  text-align: center;
/deep/ .ivu-input {
  width: 394px !important;
  text-align: center;
}

.header {
  margin: 35px 0 3px 0;
}

.process {
  width 394px;
  height 158px;
  border: 1px dashed #D8D8D8;
  border-top: 1px dashed #fff;
  margin: -1px auto 0 auto;

&.on {
   border-top: 1px dashed #D8D8D8;
   margin-top: 20px;

.list {
  padding-left 14px !important
}
}

.list {
  padding 6px 10px 0 3px;

.item {
  font-size 12px;
  color #666;
  width: 24%;
.name {
  color #333;
  font-size 13px;
  font-weight bold;
}
}
}

.pictrue {
  width 362px;
  height 68px;
  margin 24px auto 0 auto;

img {
  width 100%;
  height 100%;
}
}
}

.pictrue {
  width: 18px;
  height: 18px;

img {
  width: 100%;
  height: 100%;
}
margin-right 7px
}

.text {
  color: rgba(0, 0, 0, 0.45);
  font-size: 14px;
}

.money {
  font-size: 18px;
  color: rgba(0, 0, 0, 0.85);

.num {
  font-size: 32px;
  margin-left: 5px;
}
}

.tip {
  width: 310px;
  height: 26px;
  background: rgba(255, 126, 0, 0.1);
  border-radius: 13px;
  font-size: 13px;
  color: #FF7E00;
  margin: 10px auto 0 auto;

.icon {
  font-size: 16px;
  margin-right: 5px;
}
}

.bnt {
  width: 394px;
  height 38px;
  margin: 28px 0 15px 0;
}
}

.button {
  width: 535px;
  height: 36px;
  background: #1890FF;
  margin-top 28px;
  margin-bottom 8px;
}

.discount {
  padding 0 24px;

.custom {
  margin-top 24px;

.inputNum {
  width: 100%;
}

.tip {
  font-size: 13px;
  color: rgba(153, 153, 153, 0.85);
  margin-top 15px;
}
}

.infoData {
.pictrue {
  width 32px;
  height 32px;
  border-radius 50%;

img {
  width 100%;
  height 100%;
  border-radius 50%;
}
}

.info {
  font-size: 14px;
  font-weight: 400;
  color: rgba(0, 0, 0, 0.85);

span {
  padding: 0 8px;

& ~ span {
    border-left: 1px solid #DDDDDD;
  }
}
}
}

.list {
display flex
flex-wrap wrap
.item {
  width 165px;
  height 90px;
  border-radius: 7px;
  border: 1px solid #DADFE6;
  color: rgba(0, 0, 0, 0.5);
  font-size 12px;
  text-align center;
  padding 8px 0;
  margin-right 20px;
  margin-top 20px;
  cursor pointer;

&:nth-child(3n) {
   margin-right 0;
 }

.money {
  color: #0091FF;
  font-size 30px;
}

&:hover {
   background-color #0091FF;
   color #fff;

.money {
  color #fff;
}
}

&.on {
   background-color #0091FF;
   color #fff;

.money {
  color #fff;
}
}
}
}
}
/deep/.recharge-modal {
.ivu-modal-content {
  border-radius: 10px;
}

.ivu-modal-body {
  height: 475px;
  padding: 30px 25px;
  overflow-x: hidden;
}

.ivu-modal-body::-webkit-scrollbar {
  display: none;
}

.radio-border-group {
  margin: 0 -20px -20px 0;
}

.infoData {
  display: flex;
  align-items: center;
  margin-bottom: 30px;

.pictrue {
  width: 60px;
  height: 60px;
}

img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
}

.info {
  flex: 1;
  margin-left: 14px;
}

.name {
  display: flex;
  align-items: center;
  font-weight: 600;
  font-size: 18px;
  color: #303133;

span {
  display: inline-block;
  padding: 2px 5px;
  border: 1px solid #1890FF;
  border-radius: 3px;
  margin-left 6px;
  font-weight: 400;
  font-size: 12px;
  line-height: 12px;
  color: #1890FF;
}
}

.tag {
  padding: 2px 5px;
  border: 1px solid #1890FF;
  border-radius: 3px;
  margin-left 6px;
  vertical-align: middle;
  font-weight: 400;
  font-size: 12px;
  color: #1890FF;
}

.attr {
  display: flex;
  align-items: center;
  margin-top: 8px;
  font-size: 12px;
  color: #303133;
}

.item + .item {
  margin-left 20px;
}

.phone {
  font-size: 14px;
}

.num {
  margin-left 4px;
  font-weight: 600;
  font-size: 17px;
  color: #F5222D;
}

.time {
  font-size: 14px;
  color: #1890FF;
}
}

.ivu-form-item:last-child {
  margin-bottom: 0;
}

.ivu-radio-border {
  display: inline-flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  width: 200px;
  height: 110px;
  border-color: #1890FF;
  border-radius: 10px;
  margin: 0 20px 20px 0;
  box-shadow: 0 0 14px 0 rgba(0,84,161,0.18);
  font-size: 14px !important;
  color: #909399;

.ivu-radio {
  display: none;
}

.money {
  font-weight: 500;
  font-size: 16px;
  color: #1890FF;
}

.num {
  font-size: 24px;
}

&.ivu-radio-wrapper-checked {
   background: #1890FF;
   color: #FFFFFF;

.money {
  color: #FFFFFF;
}
}
}

.ivu-input-number {
  width: 460px;
  height: 36px;
}

.ivu-modal-footer {
  padding: 30px 25px;

.ivu-btn {
  height: 50px;
  border-radius: 25px;
  background: #1890FF;
  font-size: 16px !important;
}
}
}
.debt-pay-btn
  display inline-block
  padding 4px 12px
  margin-right 12px
  color #1890ff
  border 1px solid #1890ff
  border-radius 4px
  cursor pointer
  font-size 14px
.debt-pay-input-wrap
  display inline-block
  vertical-align middle
</style>
