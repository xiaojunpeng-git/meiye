<template>
    <Modal :value="visible" title="业绩分配" :width="canSy ? 1100 : 600" :z-index="modalZIndex" :class-name="canSy ? 'recharge-modal recharge-modal-dual' : 'recharge-modal'" @on-cancel="clear">
      <div class="payPage dual-mode" v-if="canSy">
        <div class="dual-search">
          <Input class="searchOut" v-model="staffForm.keyword" ref="rechargeNum" size="large" type="url" @input="getStaff" placeholder="输入关键词搜索员工"/>
        </div>
        <div class="dual-columns">
          <div class="dual-column">
            <div class="section-title">手艺人</div>
            <div class="column-list">
              <div
                :class="getStaffItemClass(item, 'yejiService')"
                v-for="item in staffList"
                :key="'service-' + item.id"
                @click="chooseStaff(item, 'yejiService')"
              >
                <div class="kuai_center">
                  <div class="staff-name">{{ item.staff_name }}</div>
                  <div class="dian-wrap" @click.stop>
                    <input type="checkbox" class="selfCheckbox" v-model="dianAttr" :value="item.id" :disabled="isStaffBusy(item, 'yejiService')" @change="toggleDian(item, 'yejiService')">点客
                  </div>
                </div>
                <div v-if="isStaffBusy(item, 'yejiService')" class="staff-busy-tip">已预约</div>
              </div>
              <div class="empty-tip" v-if="!staffList.length">{{ emptyStaffTip }}</div>
            </div>
          </div>
          <div class="dual-divider"></div>
          <div class="dual-column">
            <div class="section-title">销售</div>
            <div class="column-list">
              <div
                :class="staffIds.includes(item.id) ? 'staff-item choose_kuai' : 'staff-item'"
                v-for="item in staffList"
                :key="'sale-' + item.id"
                @click="chooseStaff(item, 'yeji')"
              >
                <div class="staff-name">{{ item.staff_name }}</div>
              </div>
              <div class="empty-tip" v-if="!staffList.length">{{ emptyStaffTip }}</div>
            </div>
          </div>
        </div>
      </div>
      <div class="payPage" v-else>
        <div class="page_top">
          <div class="btn_out">
            <div class="kuai_type chose_kuai_type" v-if="isShouyi">选择手艺人</div>
            <div class="kuai_type chose_kuai_type" v-else>选择销售</div>
          </div>
          <Input class="searchOut" v-model="staffForm.keyword" ref="rechargeNum" size="large" type="url" @input="getStaff" placeholder="输入关键词" style="margin-top: 16px;"/>
        </div>
        <div class="kuai_out">
          <div :class="getStaffItemClass(item, 'yeji')" v-for="item in staffList" :key="'single-' + item.id" @click="chooseStaff(item, 'yeji')">
            <div v-if="!isShouyi">{{ item.staff_name }}</div>
            <div class="kuai_center" v-else>
              <div>{{ item.staff_name }}</div>
              <div @click.stop>
                <input type="checkbox" class="selfCheckbox" v-model="dianAttr" :value="item.id" :disabled="isStaffBusy(item, 'yeji')" @change="toggleDian(item, 'yeji')">点客
              </div>
            </div>
            <div v-if="isShouyi && isStaffBusy(item, 'yeji')" class="staff-busy-tip">已预约</div>
          </div>
          <div class="empty-tip" v-if="!staffList.length">{{ emptyStaffTip }}</div>
        </div>
      </div>
      <div slot="footer">
        <div class="acea-row row-center-wrapper mt22 yeji-footer-btns">
          <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="hideAdd">取消</Button>
          <Button v-if="showApplyAll" class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="applyToAll">应用全部</Button>
          <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="saveAdd">确认</Button>
        </div>
      </div>
    </Modal>
</template>
<script>
import {staffallList} from '@/api/user';
import { saveYeji } from "@api/order";
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
    yejiService:{
      type: Object,
      default: () => {
      }
    },
    staffIds:{
      type: Array
    },
    staffIdsService:{
      type: Array
    },
    visible: {
      type: Boolean,
      default: false
    },
    canSy: {
      type: Boolean,
      default: false
    },
    isSale: {
      type: Boolean,
      default: false
    },
    isShouyi: {
      type: Boolean,
      default: false
    },
    showApplyAll: {
      type: Boolean,
      default: true
    },
    modalZIndex: {
      type: Number,
      default: 1000,
    },
    disabledStaffIds: {
      type: Array,
      default: () => []
    },
    reservationStaffQuery: {
      type: Object,
      default: () => ({})
    }
  },
  data() {
    return {
      dianAttr:[],
      chooseList: [
        { val: "yejiService", label: "手艺人" },
        { val: "yeji", label: "销售" },
      ],
      activeName: "yeji",
      modal: false,
      timer: null,
      showAdd:false,
      currentTab: 0,
      chooseKuai: 0,
      moneyList: [],
      staffList: [],
      staffForm:{
        keyword:''
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
      if (val && this.isShouyi) {
        this.getStaff();
      }
    },
    reservationStaffQuery: {
      deep: true,
      handler() {
        if (this.visible && this.isShouyi) {
          this.getStaff();
        }
      }
    }
  },
  computed: {
    useReservationStaffList() {
      if (!this.isShouyi) {
        return false;
      }
      const q = this.reservationStaffQuery || {};
      return !!(q.service_date && (q.reservation_start || q.service_time));
    },
    emptyStaffTip() {
      if (!this.isShouyi || !this.useReservationStaffList) {
        return '暂无匹配员工';
      }
      const q = this.reservationStaffQuery || {};
      if (!q.service_date || !(q.reservation_start || q.service_time)) {
        return '请先选择预约时间';
      }
      return '该时段暂无排班手艺人';
    }
  },
  created() {
    this.getStaff();
    let that = this;
  },
  methods: {
    delYeji(index,type){
      if(type == 1) {
        this.staffIds.splice(index, 1);
        this.yeji.staffChoose.splice(index, 1);
      }else{
        this.staffIdsService.splice(index, 1);
        this.yejiService.staffChoose.splice(index, 1);
      }
    },
    toggleDian(item, type) {
      if (this.isStaffBusy(item, type)) {
        return;
      }
      const activeName = type || this.activeName;
      const isService = activeName === 'yejiService';
      const ids = isService ? this.staffIdsService : this.staffIds;
      const staffChoose = isService ? this.yejiService.staffChoose : this.yeji.staffChoose;
      const index = ids.indexOf(item.id);
      const isDian = this.dianAttr.indexOf(item.id) > -1 ? 1 : 0;
      if (index > -1) {
        this.$set(staffChoose[index], 'is_dian', isDian);
        return;
      }
      if (isService) {
        this.staffIdsService.push(item.id);
        this.yejiService.staffChoose.push({
          staff_id: item.id,
          staff_name: item.staff_name,
          position_label: item.position_label,
          position: item.position,
          position_level: item.position_level,
          position_level_label: item.position_level_label,
          yeji: 0,
          is_dian: isDian,
        });
      } else {
        this.staffIds.push(item.id);
        this.yeji.staffChoose.push({
          staff_id: item.id,
          staff_name: item.staff_name,
          position_label: item.position_label,
          position: item.position,
          position_level: item.position_level,
          position_level_label: item.position_level_label,
          yeji: 0,
          deduct_card_yeji: 0,
          is_dian: isDian,
        });
      }
    },
    isStaffBusy(item, type) {
      const staffId = Number(item.id);
      const busyIds = (this.disabledStaffIds || []).map((id) => Number(id));
      if (!busyIds.includes(staffId)) {
        return false;
      }
      const ids = (type === 'yejiService' ? this.staffIdsService : this.staffIds).map((id) => Number(id));
      return !ids.includes(staffId);
    },
    getStaffItemClass(item, type) {
      const ids = type === 'yejiService' ? this.staffIdsService : this.staffIds;
      const selected = ids.includes(item.id);
      const busy = this.isStaffBusy(item, type);
      if (type === 'yejiService') {
        return {
          'staff-item': true,
          choose_kuai: selected,
          'staff-item--disabled': busy,
        };
      }
      return {
        kuai: true,
        choose_kuai: selected,
        'kuai--disabled': busy,
      };
    },
    chooseStaff(item, type){
      if (this.useReservationStaffList && !this.isReservationStaffSelectable(item)) {
        return;
      }
      let activeName = type || this.activeName;
      const ids = activeName === 'yejiService' ? this.staffIdsService : this.staffIds;
      const isSelected = ids.includes(item.id);
      const busyIds = (this.disabledStaffIds || []).map((id) => Number(id));
      if (busyIds.includes(Number(item.id)) && !isSelected) {
        return;
      }
      if(activeName == 'yejiService') {
        const index = this.staffIdsService.indexOf(item.id);
        if (index > -1) {
          this.staffIdsService.splice(index, 1);
          this.yejiService.staffChoose.splice(index, 1);
          const dianIndex = this.dianAttr.indexOf(item.id);
          if (dianIndex > -1) {
            this.dianAttr.splice(dianIndex, 1);
          }
        } else {
          this.staffIdsService.push(item.id);
          let isDian=0;
          const indexDian = this.dianAttr.indexOf(item.id);
          if (indexDian > -1) {
             isDian=1;
          }
          let attr = {
            'staff_id': item.id,
            'staff_name': item.staff_name,
            'position_label': item.position_label,
            'position': item.position,
            'position_level': item.position_level,
            'position_level_label': item.position_level_label,
            'yeji': 0,
            'is_dian': isDian
          };
          this.yejiService.staffChoose.push(attr);
        }
      }else{
        let isDian=0;
        const indexDian = this.dianAttr.indexOf(item.id);
        if (indexDian > -1 && this.isShouyi) {
            isDian=1;
        }
        const index = this.staffIds.indexOf(item.id);
        if (index > -1) {
          this.staffIds.splice(index, 1);
          this.yeji.staffChoose.splice(index, 1);
          const dianIndex = this.dianAttr.indexOf(item.id);
          if (dianIndex > -1) {
            this.dianAttr.splice(dianIndex, 1);
          }
        } else {
          this.staffIds.push(item.id);
          let attr = {
            'staff_id': item.id,
            'staff_name': item.staff_name,
            'position_label': item.position_label,
            'position': item.position,
            'position_level': item.position_level,
            'position_level_label': item.position_level_label,
            'yeji': 0,
            'deduct_card_yeji': 0,
            'is_dian': isDian,
          };
          this.yeji.staffChoose.push(attr);
        }
      }
    },
    getStaff(){
      const params = Object.assign({}, this.staffForm);
      if (this.useReservationStaffList) {
        params.for_reservation = 1;
        const q = this.reservationStaffQuery || {};
        params.service_date = q.service_date || '';
        params.service_time = q.reservation_start || q.service_time || '';
        params.service_duration = Number(q.service_duration || 0);
        params.exclude_reservation_id = Number(q.exclude_reservation_id || 0);
      }
      staffallList(params).then(res => {
        const list = res.data || [];
        this.staffList = this.useReservationStaffList
          ? list.filter((item) => this.isReservationStaffSelectable(item))
          : list;
      }).catch(() => {
        this.staffList = [];
      });
    },
    isReservationStaffSelectable(item) {
      if (!item || !Number(item.id)) return false;
      if (!String(item.staff_name || '').trim()) return false;
      if (Number(item.service_available) === 0) return false;
      return true;
    },
    allocateYejiService() {
      var len = this.staffIdsService.length;
      if (len <= 0) return;
      var total = Math.round((Number(this.yejiService.price) || 0) * 100);
      var number = Math.floor(total / len);
      var yu = total % len;
      this.yejiService.staffChoose.forEach(function (item, idx) {
        item.yeji = (number + (idx === len - 1 ? yu : 0)) / 100;
      });
    },
    allocateYeji() {
      var len = this.staffIds.length;
      if (len <= 0) return;
      var number = Math.floor(this.yeji.price / len);
      this.yeji.staffChoose.forEach(function (item) {
        item.yeji = number;
      });
      var yu = this.yeji.price % len;
      if (yu > 0) {
        this.yeji.staffChoose[len - 1].yeji = Number(this.yeji.staffChoose[len - 1].yeji) + yu;
      }
      var bal = Number(this.yeji.balance_price) || 0;
      if (this.yeji.type == 2 && bal > 0) {
        var t = Math.round(bal * 100);
        var nb = Math.floor(t / len);
        var yub = t % len;
        this.yeji.staffChoose.forEach(function (item, idx) {
          item.deduct_card_yeji = (nb + (idx === len - 1 ? yub : 0)) / 100;
        });
      } else if (this.yeji.type == 2) {
        this.yeji.staffChoose.forEach(function (item) {
          item.deduct_card_yeji = 0;
        });
      }
    },
    saveAdd(){
      if (this.canSy) {
        this.allocateYejiService();
        this.allocateYeji();
      } else if (this.activeName == 'yejiService') {
        this.allocateYejiService();
      } else {
        if (!this.staffIds.length) {
          if (this.isShouyi) {
            this.yeji.staffChoose = [];
            this.showAdd = false;
            this.save();
            return;
          }
          this.showAdd = false;
          return;
        }
        this.allocateYeji();
      }
      this.showAdd = false;
      this.save();
    },
    hideAdd(){
      this.showAdd=false;
      this.$emit('closeYeji');
    },
    applyToAll() {
      if (this.canSy) {
        const hasService = this.staffIdsService.length > 0;
        const hasSale = this.staffIds.length > 0;
        if (!hasService && !hasSale) {
          this.$Message.warning('请先选择手艺人或销售人员');
          return;
        }
        this.$emit('applyAll', {
          mode: 'both',
          serviceStaffChoose: hasService ? this.deepClone(this.yejiService.staffChoose) : [],
          saleStaffChoose: hasSale ? this.deepClone(this.yeji.staffChoose) : [],
        });
        return;
      }
      if (this.activeName === 'yejiService') {
        if (!this.staffIdsService.length) {
          this.$Message.warning('请先选择手艺人');
          return;
        }
        this.$emit('applyAll', {
          mode: 'yejiService',
          staffChoose: this.deepClone(this.yejiService.staffChoose),
          staffIds: [...this.staffIdsService],
        });
      } else {
        if (!this.staffIds.length) {
          this.$Message.warning('请先选择销售人员');
          return;
        }
        this.$emit('applyAll', {
          mode: 'yeji',
          staffChoose: this.deepClone(this.yeji.staffChoose),
          staffIds: [...this.staffIds],
        });
      }
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
    doAdd(){
       this.showAdd=true;
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
      let totalYeji=0;
      this.yeji.staffChoose.forEach(function (item){
        totalYeji=Number(totalYeji)+Number(item.yeji);
      })
      if(this.yeji.link_id && this.yeji.link_id > 0) {
        saveYeji(this.yeji).then((res) => {
          this.$Message.success("设置成功！");
          this.$emit('closeYeji');
        });
      }else{
        this.$emit('doChoose',this.yeji);
        if(this.canSy) {
          this.$emit('doChooseService',this.yejiService);
        }
      }
    }
  }
}
</script>

<style lang="stylus" scoped>
.selfCheckbox{
  -webkit-appearance: auto !important;
  -moz-appearance: auto !important;
  appearance: auto !important;
  width: 20px;
  height: 20px;
  margin-right: 10px;
  cursor: pointer;
  vertical-align: sub;
}
.page_top{
  position: absolute;
  top: 0px;
  background: #ffffff;
  width: 100%;
  height: 120px;
  border-bottom: 1px solid #eee;
}
.payPage.dual-mode {
  display: flex;
  flex-direction: column;
  height: 100%;
  text-align: left;
  .dual-search {
    flex-shrink: 0;
    padding-bottom: 12px;
    border-bottom: 1px solid #eee;
  }
  .dual-columns {
    display: flex;
    flex: 1;
    min-height: 0;
    margin-top: 12px;
    gap: 0;
  }
  .dual-column {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 0 8px;
  }
  .dual-divider {
    width: 1px;
    background: #e8e8e8;
    flex-shrink: 0;
    margin: 0 4px;
  }
  .section-title {
    flex-shrink: 0;
    font-size: 18px;
    font-weight: bold;
    color: #333;
    text-align: center;
    padding: 10px 0 12px;
    border-bottom: 2px solid #1890ff;
    margin-bottom: 8px;
  }
  .column-list {
    flex: 1;
    overflow-y: auto;
    padding: 4px 4px 12px;
    display: flex;
    flex-wrap: wrap;
    align-content: flex-start;
    gap: 10px;
    &::-webkit-scrollbar {
      width: 6px;
    }
    &::-webkit-scrollbar-thumb {
      background: #d9d9d9;
      border-radius: 3px;
    }
    &::-webkit-scrollbar-thumb:hover {
      background: #bfbfbf;
    }
  }
  .staff-item {
    width: calc((100% - 10px) / 2);
    padding: 12px 10px;
    border: 1px solid #eee;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.2s;
    box-sizing: border-box;
    &:hover {
      border-color: #91caff;
    }
    .kuai_center {
      display: flex;
      justify-content: space-between;
      align-items: center;
      width: 100%;
      flex-wrap: nowrap;
    }
    .staff-name {
      flex: 1;
      min-width: 0;
      font-size: 14px;
      color: #333;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .dian-wrap {
      flex-shrink: 0;
      font-size: 13px;
      color: #666;
      white-space: nowrap;
      margin-left: 6px;
    }
  }
  .empty-tip {
    width: 100%;
    text-align: center;
    color: #999;
    padding: 40px 0;
    font-size: 14px;
  }
}
.btn_out{
  display: flex;
  margin-top: 10px;
}
.chose_kuai_type{
   background-color:#1890ff;
   color: #fff;
}
.kuai_type{
   border: 1px solid #eee;
   height: 40px;
   line-height: 40px;
   width: 100px;
   text-align: center;
   border-radius: 5px;
   margin-right: 10px;
  cursor: pointer;
}
.price_title{
  color: red;
  font-weight: bold;
  margin-left: 20px;
}
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
.kuai--disabled,
.staff-item--disabled {
  background: #f1f3f6 !important;
  color: #bbb !important;
  border-color: #e8e8e8 !important;
  cursor: not-allowed !important;
}
.staff-busy-tip {
  margin-top: 6px;
  font-size: 12px;
  color: #c0c4cc;
}
.kuai_center{
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
}
.kuai_out{
   padding-top: 120px;
   height: 460px;
   overflow-y: scroll;
   width: 100%;
   text-align: left;
  padding-bottom: 20px;
}
.kuai{
    margin-top: 15px;
    width: 48%;
    padding: 15px 10px 15px 20px;
    border: 1px solid #eee;
    display: inline-block;
    text-align: left;
    cursor: pointer;
}
.kuai:nth-child(odd) {
  margin-right: 4%;
}
/deep/ .ivu-tabs-nav-scroll {
  padding:0px !important;
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
  position: relative;
  text-align: center;
  /deep/ .ivu-input {
    width: 100% !important;
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

.yeji-footer-btns {
  flex-wrap nowrap;
  gap 10px;
  justify-content center;
  .w-176 {
    width 165px;
  }
  .ml20 {
    margin-left 0 !important;
  }
  /deep/ .ivu-btn-primary {
    color #fff !important;
  }
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
    padding: 0px 25px 30px 25px;
    overflow-x: hidden;
  }
}
/deep/.recharge-modal-dual {
  .ivu-modal {
    top: 40px;
  }
  .ivu-modal-body {
    height: 620px;
    padding: 16px 24px 20px 24px;
  }
}
/deep/.recharge-modal {

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
