<template>
  <div>
    <Modal
      :value="visible"
      title="业绩分配"
      width="868"
      :z-index="modalZIndex"
      transfer
      class-name="recharge-modal"
      @on-cancel="clear"
    >
      <Form :label-width="0">
        <FormItem>
          <Button type="primary" size="large" @click="doAdd">添加人员</Button>
          <span class="price_title" v-if="isLaborYejiType">参与分配劳动业绩：{{ yeji.price }}</span>
          <span class="price_title" v-else>参与分配现金业绩：{{ displayCashYejiPrice }}</span>
          <span class="price_title" v-if="showDeductCardYeji">参与分配扣卡业绩：{{ yeji.balance_price != null ? yeji.balance_price : 0 }}</span>
          <div class="table_out">
                  <div class="head">
                          <div class="body_title yeji-table-cell">员工</div>
                          <div class="body_title yeji-table-cell">职位</div>
                          <div class="body_title yeji-table-cell">职级</div>
                          <div class="body_title yeji-table-cell" v-if="yeji.type == 3">是否点客</div>
                          <div class="body_title yeji-table-cell" v-if="isLaborYejiType">劳动业绩</div>
                          <div class="body_title yeji-table-cell" v-else>现金业绩</div>
                          <div class="body_title yeji-table-cell" v-if="showDeductCardYeji">扣卡业绩</div>
                          <div class="body_title yeji-table-cell">操作</div>
                  </div>
                  <div class="body">
                        <div class="heng_kuai" v-for="(item,index) in yeji.staffChoose">
                          <div class="body_kuai yeji-table-cell">{{ item.staff_name }}</div>
                          <div class="body_kuai yeji-table-cell">{{ item.position_label }}</div>
                          <div class="body_kuai yeji-table-cell">{{ item.position_level_label }}</div>
                          <div class="body_kuai yeji-table-cell" v-if="yeji.type == 3">
                            <Switch size="large" v-model="item.is_dian" :false-value="0" :true-value="1">
                              <span slot="open" :true-value="1">是</span>
                              <span slot="close" :false-value="0">否</span>
                            </Switch>
                          </div>
                          <div class="body_kuai yeji-table-cell">
                             <InputNumber style="width: 80%" v-model="item.yeji" :min="0" :max="9999999" :precision="isLaborYejiType ? 2 : 0" :placeholder="isLaborYejiType ? '0.00' : '0'"></InputNumber>
                          </div>
                          <div class="body_kuai yeji-table-cell" v-if="showDeductCardYeji">
                             <InputNumber style="width: 80%" v-model="item.deduct_card_yeji" :min="0" :max="9999999" :precision="2" placeholder="0.00"></InputNumber>
                          </div>
                          <div class="body_kuai yeji-table-cell">
                               <div class="shanchu" @click="delYeji(index)">删除</div>
                          </div>
                        </div>
                  </div>
          </div>
        </FormItem>
      </Form>
      <div slot="footer">
        <div class="acea-row row-center-wrapper mt22">
          <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="clear">取消</Button>
          <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="save">提交</Button>
        </div>
      </div>
    </Modal>
    <Modal
      v-model="showAdd"
      title="分配业绩"
      width="500"
      :z-index="staffModalZIndex"
      transfer
      class="modalPay"
    >
      <div class="payPage">
        <Input class="searchOut" v-model="staffForm.keyword" ref="rechargeNum" size="large" type="url" @input="getStaff" placeholder="输入关键词" style="margin-top: 16px;"/>
        <div class="kuai_out">
          <div :class="staffIds.includes(item.id)?'kuai choose_kuai':'kuai'" v-for="(item,index) in staffList" :key="item.id" @click="chooseStaff(item)">{{ item.staff_name }}</div>
        </div>
      </div>
      <div slot="footer">
        <div class="acea-row row-center-wrapper mt22">
          <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="hideAdd">取消</Button>
          <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="saveAdd">确认</Button>
        </div>
      </div>
    </Modal>
  </div>
</template>
<script>
import {staffallList,saveYeji} from '@/api/yeji';
export default {
  name: 'recharge',
  props: {
    syncProduct: {
      type: Array,
      default: []
     },
     yeji:{
      type: Object,
      default: () => {
      }
    },
    staffIds:{
      type: Array
    },
    visible: {
      type: Boolean,
      default: false
    },
    modalZIndex: {
      type: Number,
      default: 1200
    },
    storeId: {
      type: [Number, String],
      default: 0,
    },
  },
  data() {
    return {
      modal: false,
      timer: null,
      showAdd:false,
      currentTab: 0,
      chooseKuai: 0,
      moneyList: [],
      staffList: [],
      staffForm:{
        keyword:'',
        store_id:''
      },
      active: 0,
      activeId: 0,
      modalPay: false,
      payNum: '',
      payPrice: 1,
      givePrice: 0,
      totalPrice: 0
    }
  },
  watch: {
    visible(val) {
      if (val) {
        this.syncStaffStoreId();
        this.$nextTick(() => this.normalizeYejiNumbers());
      }
    },
    showAdd(val) {
      if (val) {
        this.syncStaffStoreId();
        this.getStaff();
      }
    },
    storeId() {
      this.syncStaffStoreId();
    },
  },
  computed: {
    /** 仅普通产品（product_type=0）在门店订单详情中展示扣卡业绩相关 UI，数据仍保留 */
    showDeductCardYeji() {
      return this.yeji && Number(this.yeji.type) === 2 && Number(this.yeji.product_type) === 0;
    },
    /** type=3 核销/手艺人：劳动业绩；其余为现金业绩（兼容接口返回字符串 '3'） */
    isLaborYejiType() {
      const t = this.yeji != null ? this.yeji.type : null;
      return t === 3 || t === '3';
    },
    displayCashYejiPrice() {
      return Math.floor(Number(this.yeji && this.yeji.price) || 0);
    },
    staffModalZIndex() {
      return this.modalZIndex + 100;
    },
  },
  created() {
    let that = this;
  },
  methods: {
    normalizeYejiNumbers() {
      if (!this.yeji) return;
      if (this.yeji.price != null && typeof this.yeji.price !== 'number') {
        this.$set(this.yeji, 'price', Number(this.yeji.price) || 0);
      }
      (this.yeji.staffChoose || []).forEach((item, idx) => {
        if (typeof item.yeji !== 'number') {
          this.$set(this.yeji.staffChoose[idx], 'yeji', Number(item.yeji) || 0);
        }
        if (item.deduct_card_yeji != null && typeof item.deduct_card_yeji !== 'number') {
          this.$set(this.yeji.staffChoose[idx], 'deduct_card_yeji', Number(item.deduct_card_yeji) || 0);
        }
      });
    },
    refreshStaffChooseAmounts() {
      const staffChoose = this.yeji.staffChoose || [];
      const len = staffChoose.length;
      if (len <= 0) return;
      const total = this.isLaborYejiType
        ? Math.round((Number(this.yeji.price) || 0) * 100)
        : Math.floor(Number(this.yeji.price) || 0);
      const number = Math.floor(total / len);
      const yu = total % len;
      staffChoose.forEach((item, idx) => {
        if (this.isLaborYejiType) {
          item.yeji = (number + (idx === len - 1 ? yu : 0)) / 100;
        } else {
          item.yeji = number + (idx === len - 1 ? yu : 0);
        }
      });
      const bal = Number(this.yeji.balance_price) || 0;
      if (bal > 0 && this.showDeductCardYeji) {
        const t = Math.round(bal * 100);
        const nb = Math.floor(t / len);
        const yub = t % len;
        staffChoose.forEach((item, idx) => {
          item.deduct_card_yeji = (nb + (idx === len - 1 ? yub : 0)) / 100;
        });
      } else {
        staffChoose.forEach((item) => {
          item.deduct_card_yeji = 0;
        });
      }
    },
    delYeji(index){
      this.staffIds.splice(index, 1);
      this.yeji.staffChoose.splice(index, 1);
      this.refreshStaffChooseAmounts();
    },
    chooseStaff(item){
      const index = this.staffIds.indexOf(item.id);
      if (index > -1) {
        this.staffIds.splice(index, 1);
        this.yeji.staffChoose.splice(index, 1);
      }else{
        this.staffIds.push(item.id);
        let attr={
          'staff_id':item.id,
          'staff_name':item.staff_name,
          'position_label':item.position_label,
          'position':item.position,
          'position_level':item.position_level,
          'position_level_label':item.position_level_label,
          'yeji':0,
          'deduct_card_yeji':0,
          'is_dian':0,
        };
        this.yeji.staffChoose.push(attr);
      }
    },
    getStaff(){
      this.syncStaffStoreId();
      const params = {
        keyword: this.staffForm.keyword || '',
        name: this.staffForm.keyword || '',
        store_id: this.staffForm.store_id || this.storeId || '',
      };
      staffallList(params).then(res => {
        this.staffList = res.data || [];
      }).catch(() => {
        this.staffList = [];
      });
    },
    saveAdd(){
      if (this.staffIds.length <= 0) {
        return;
      }
      this.refreshStaffChooseAmounts();
      this.showAdd = false;
    },
    hideAdd(){
      this.showAdd=false;
    },
    deepClone(obj) {
     return JSON.parse(JSON.stringify(obj));
   },
   doSync(){
      let that=this;
      let totalYeji=0;
      this.yeji.staffChoose.forEach(function (item){
        totalYeji=Number(totalYeji)+Number(item.yeji);
      })
      if(totalYeji > this.yeji.price){
        this.$Message.error("累计业绩不能大于总金额！");
        return false;
      }
      this.$Modal.confirm({
        title: '同步业绩',
        content: '确认后，业绩将同步到该订单其他项目，是否继续同步?',
        onOk: () => {
            that.$emit('sureSync',this.yeji);
            that.$Message.success("同步成功！");
          }
      });
    },
    syncStaffStoreId() {
      const storeId = Number(this.storeId || this.staffForm.store_id || 0);
      if (storeId > 0) {
        this.staffForm.store_id = storeId;
      }
    },
    doAdd(){
      this.syncStaffStoreId();
      this.getStaff();
      this.showAdd = true;
    },
    clear() {
      this.$emit('closeYeji');
    },
    yuePayClear() {
      this.$Message.destroy()
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null
      }
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
      }
    },
    activeMoney(index, item) {
      this.active = index;
    },
    save() {
      if (!this.yeji.staffChoose || !this.yeji.staffChoose.length) {
        this.$Message.warning('请先添加人员');
        return;
      }
      let totalYeji = 0;
      let totalDeduct = 0;
      this.yeji.staffChoose.forEach(function (item) {
        totalYeji = Number(totalYeji) + Number(item.yeji || 0);
        totalDeduct = Number(totalDeduct) + Number(item.deduct_card_yeji || 0);
      });
      const maxPrice = this.isLaborYejiType
        ? (Number(this.yeji.price) || 0)
        : Math.floor(Number(this.yeji.price) || 0);
      if (totalYeji > maxPrice) {
        const label = this.isLaborYejiType ? '劳动业绩' : '现金业绩';
        this.$Message.error(`累计${label}不能大于参与分配业绩（${maxPrice}）`);
        return;
      }
      if (this.showDeductCardYeji) {
        const maxBal = Number(this.yeji.balance_price) || 0;
        if (totalDeduct > maxBal) {
          this.$Message.error(`累计扣卡业绩不能大于参与分配扣卡业绩（${maxBal}）`);
          return;
        }
      }
      if(this.yeji.link_id && this.yeji.link_id > 0) {
        saveYeji(this.yeji).then((res) => {
          this.$Message.success("设置成功！");
          this.$emit('closeYeji');
        }).catch(error=>{
          this.$Message.error(error.msg)
        })
      }else{
        this.$emit('doChoose',this.yeji);
      }
    }
  }
}
</script>

<style lang="stylus" scoped>
.w-176{
  width: 176px;
}
.bnt-F5F5F5
{
  background-color: #f5f5f5 !important;
  border: .006472rem solid #f5f5f5 !important;
}
.price_title{
  color: red;
  font-weight: bold;
  margin-left: 20px;
}
.shanchu{
  font-size: 15px;
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
   text-align: center;
  font-weight: bold;
  font-size: 16px;
}
.body_kuai{
   height: 50px;
   line-height: 50px;
   border-right: 1px solid rgb(191 191 191);
   border-bottom: 1px solid rgb(191 191 191);
   text-align: center;
  font-size: 15px;
}
.yeji-table-cell{
  flex: 1;
  min-width: 72px;
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
    width: 185px;
    padding: 15px 10px 15px 20px;
    border: 1px solid #eee;
    display: inline-block;
    text-align: left;
    cursor: pointer;
    margin-left: 20px;
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
</style>
