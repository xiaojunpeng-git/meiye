<template>
  <Modal v-model="modals" scrollable closable title="商品规格" :mask-closable="false" width="634" class-name="attr-modal">
    <div class="productAttr">
      <div class="header">
        <div class="pictrue">
          <img :src="attr.productSelect.image"/>
        </div>
        <div class='text'>
          <div class="name" :class="productType==6?'line2 h-49':'line1'">{{ attr.productSelect.store_name }}</div>
          <div class="info" v-if="productType!=6">库存 {{ attr.productSelect.stock }}</div>
          <div class="money" :class="productType==6?'mt-24':''">¥<span class="num">{{ attr.productSelect.price }}</span></div>
        </div>
      </div>
      <div class="attr" :class="productType==6?'max-h-188':'flex-1'">
        <div v-if="productType == 5 && attr.productSelect.card_num > 0 && attr.productSelect.card_num_type == 0" class="onlyChoose">
          以下项目只能选择 {{ attr.productSelect.card_num }}个
        </div>
        <div v-if="productType == 5" class="acea-row row-between">
          <div v-for="(item, index) in attr.productAttr" :key="index" class="acea-row row-middle w-284 p-10 mt-16 bg-w111-F9F9F9 rd-8" style="flex: 0 0 calc((100% - 16px)/2);">
            <input
                v-if="attr.productSelect.card_num > 0 && attr.productSelect.card_num_type == 0"
                type="checkbox"
                v-model="attr.productSelect.selectedProduct"
                :value="item.productInfo.id"
                class="selfCheckbox"
                :disabled="isDisabled(item.productInfo.id)"
            >
            <div class="w-48 h-48 rd-8">
              <img :src="item.productInfo.attrInfo.image" class="w-full h-full rd-8">
            </div>
            <div class="flex-1 m-w-0 pl-8">
              <div class="fs-14 text-wlll-303133 line1">{{ item.productInfo.store_name }}</div>
              <div class="acea-row row-middle mt-10 fs-13 text-wlll-909399">
                <div class="flex-1 line1">{{ item.productInfo.attrInfo.suk }}</div>
                <div>x{{ item.write_times }}</div>
              </div>
            </div>
          </div>
        </div>
        <template v-else>
          <div class="list" v-for="(item, indexw) in attr.productAttr" :key="indexw">
            <div class="title">{{ productType==6?'规格':item.attr_name }}</div>
            <div class="listn acea-row" :class="productType==6?'':'on'">
              <div class="item acea-row row-center-wrapper"
                   :class="item.index === itemn.attr ? 'on' : ''"
                   v-for="(itemn, indexn) in item.attr_value" @click="tapAttr(indexw, indexn)"
                   :key="indexn">{{ itemn.attr }}
              </div>
            </div>
          </div>
        </template>
      </div>
      <div v-if="productType==6">
        <!--		<div class="acea-row row-middle mb20 pt-12">-->
        <!--			<span class="fs-16 fw-600 text-wlll-303133">预约信息</span>-->
        <!--			<span class="fs-12 text-wlll-f5222d ml-6">立即下单不消耗可不填写预约信息</span>-->
        <!--		</div>-->
        <Form ref="formValidate" :model="formValidate" :label-width="110" inline>
          <!--			<FormItem label="预约手机号：">-->
          <!--			  <Input v-model="formValidate.phone" placeholder="请输入预约手机号" class="w-170"></Input>-->
          <!--			</FormItem>-->
          <!--			<FormItem label="预约人：">-->
          <!--			  <Input v-model="formValidate.real_name"  placeholder="请输入预约人" class="w-170"></Input>-->
          <!--			</FormItem>-->
          <!--			<FormItem label="预约日期：">-->
          <!--			  <DatePicker v-model="formValidate.reservation_time" type="date" :options="options1" placeholder="请输入预约日期" class="w-170" @on-change='changeData' />-->
          <!--			</FormItem>-->
          <!--			<FormItem label="预约时间：">-->
          <!--			  <Select v-model="formValidate.reservation_time_id" class="w-170">-->
          <!--				<Option v-for="item in reservationOptionalTimeData" :disabled='item.disabled' :value="item.id" :key="item.id">{{ item.show_time }}</Option>-->
          <!--			  </Select>-->
          <!--			</FormItem>-->
          <FormItem label="服务人员：" required>
            <Select v-model="formValidate.service_staff_id" class="w-170">
              <Option v-for="item in staffList" :value="item.value">{{ item.label }}</Option>
            </Select>
          </FormItem>
        </Form>
      </div>
    </div>
    <div slot="footer">
      <div v-if="productType==6" class="acea-row row-center-wrapper">
        <!--      <div @click="goCat(1)" class="w-176 h-46 rd-30px acea-row row-center-wrapper bg-w111-F5F5F5 text-wlll-606266 fs-16 pointer">预约下单</div>-->
        <div @click="goCat(2)" class="w-176 h-46 rd-30px acea-row row-center-wrapper bg-w111-F5F5F5 text-wlll-606266 fs-16 pointer ml-20">添加到购物车</div>
        <div @click="goCat(1)" class="w-176 h-46 rd-30px acea-row row-center-wrapper bg-w111-1890FF text-wlll-FFFFFF fs-16 pointer ml-20">下单并消耗</div>
      </div>
      <Button v-else type="primary" size="large" long :disabled="disabled" class="bnt" @click="goCat()">{{isSkill?'立即购买': '确定' }}</Button>
    </div>
  </Modal>
</template>

<script>
import { getReservationStaffList, formatReservationStaffOptions } from "@/api/reservation";
export default {
  name: 'productAttr',
  props: {
    attr: {
      type: Object,
      default: () => {
      }
    },
    isCart: {
      type: Number,
      value: 0
    },
    disabled: {
      type: Boolean,
      value: false
    },
    isSkill: {
      type: Boolean,
      value: false
    }
  },
  data() {
    return {
      options1: {
        disabledDate (date) {
          return date && date.valueOf() < Date.now() - 86400000;
        }
      },
      modals: false,
      productType: 0,
      reservationTimeData:[],
      reservationOptionalTimeData:[],
      formValidate:{
        phone:'',
        selectedProduct:[],
        real_name:'',
        reservation_time:'',
        reservation_time_id:0,
        service_staff_id:''
      },
      staffList:[],
    }
  },
  created() {
    let attr= this.attr;
    this.allStaffList();
  },
  watch: {
    reservationTimeData(newVal) {
      this.timeData();
    }
  },
  methods: {
    isDisabled(id) {
      // 条件1：已选中数量 ≥ 最大限制  条件2：当前选项未被选中 → 禁用
      let max=this.attr.productSelect.card_num;
      var len=this.attr.productSelect.selectedProduct.length;
      if(max > 0 && this.attr.productSelect.card_num_type == 0){
        return len >= max && !this.attr.productSelect.selectedProduct.includes(id);
      }else{
        return false;
      }
    },
    timeData(){
      const now = new Date();
      const currentHour = now.getHours();
      const currentMinute = now.getMinutes();
      const currentTotalMinutes = currentHour * 60 + currentMinute; // 转换为总分钟数
      const updatedArray = this.reservationTimeData.map(item => {
        item.disabled = false;
        const [startHour, startMinute] = item.start.split(":").map(Number);
        const startTotalMinutes = startHour * 60 + startMinute;
        // 如果当前时间 > start 时间，则设置 disabled = true
        if (currentTotalMinutes > startTotalMinutes) {
          return { ...item, disabled: true };
        }
        return item;
      });
      this.reservationOptionalTimeData = updatedArray
    },
    changeData(e){
      const currentDate = new Date();
      currentDate.setHours(0, 0, 0, 0); // 清除时分秒毫秒
      const targetDate = new Date(e);
      targetDate.setHours(0, 0, 0, 0); // 清除时分秒毫秒
      if(targetDate>currentDate){
        this.reservationOptionalTimeData = this.reservationTimeData
      }else{
        this.timeData();
      }
    },
    allStaffList(){
      getReservationStaffList().then(res=>{
        this.staffList = formatReservationStaffOptions(res.data.list || []);
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    goCat: function (num) {
      if(num==1){
        // if(!/^1(3|4|5|7|8|9|6)\d{9}$/.test(this.formValidate.phone)){
        // 	return this.$Message.error('请输入正确的预约手机号');
        // }
        if(!this.formValidate.reservation_time && this.formValidate.reservation_time_id){
          return this.$Message.error('请选择预约日期');
        }
        // if(!this.formValidate.reservation_time_id){
        // 	return this.$Message.error('请选择预约时间');
        // }
        if(!this.formValidate.service_staff_id){
          return this.$Message.error('必须选择服务人员');
        }
      }
      var len=this.attr.productSelect.selectedProduct.length;
      const isGiftProject = String(this.attr.productSelect.store_name || '') === '赠送项目';
      if (isGiftProject) {
        if (!len) {
          return this.$Message.error('请选择赠送哪些项目');
        }
      } else if(len < this.attr.productSelect.card_num && this.attr.productSelect.card_num > 0 && this.attr.productSelect.card_num_type == 0){
        return this.$Message.error('必须选择'+this.attr.productSelect.card_num+'个项目！');
      }
      if(!isGiftProject && this.attr.productSelect.card_num > 0 && this.attr.productSelect.card_num < len && this.attr.productSelect.card_num_type == 0){
        return this.$Message.error('最多只能选择'+this.attr.productSelect.card_num+'个项目');
      }
      this.formValidate.selectedProduct=this.attr.productSelect.selectedProduct;
      this.$emit('goCat', this.isCart, this.formValidate, num);
    },
    tapAttr: function (indexw, indexn) {
      let that = this;
      that.$emit("attrVal", {
        indexw: indexw,
        indexn: indexn
      });
      this.$set(this.attr.productAttr[indexw], 'index', this.attr.productAttr[indexw].attr_values[indexn]);
      let value = that
          .getCheckedValue()
          .join(",");
      that.$emit("ChangeAttr", value);
    },
    //获取被选中属性；
    getCheckedValue: function () {
      let productAttr = this.attr.productAttr;
      let value = [];
      for (let i = 0; i < productAttr.length; i++) {
        for (let j = 0; j < productAttr[i].attr_values.length; j++) {
          if (productAttr[i].index === productAttr[i].attr_values[j]) {
            value.push(productAttr[i].attr_values[j]);
          }
        }
      }
      return value;
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
}
.onlyChoose{
  font-size: 16px;
  margin-top: 20px;
  color: #000000;
  font-weight: bold;
}
/deep/.ivu-form-item{
  margin-bottom: 20px;
}
/deep/.ivu-form .ivu-form-item-label{
  color: #606266;
}
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #eee;
}

::-webkit-scrollbar {
  width: 4px !important;
  /*对垂直流动条有效*/
}

/deep/.attr-modal {
.ivu-modal-content {
  border-radius: 10px;
}

.ivu-modal-header {
  padding: 14px 25px;
}

.ivu-modal-header-inner {
  font-size: 18px !important;
  color: rgba(0,0,0,0.85);
}

.ivu-modal-body {
  display: flex;
  height: 510px;
  padding: 20px 25px 0;
}

.ivu-modal-footer {
  padding: 17px 25px;
  border-top: 0;

.bnt {
  height: 46px;
  border-color: #1890FF;
  border-radius: 23px;
  background: #1890FF;
  font-weight: 500;
  font-size: 16px !important;
}
}
}

.productAttr {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
.header {
  display: flex;
}

.pictrue {
  width: 116px;
  height: 116px;

img {
  width: 100%;
  height: 100%;
  border-radius 10px;
}
}

.text {
  flex: 1;
  display: flex;
  flex-direction column;
  min-width: 0;
  padding: 4px 0 4px 18px;
}

.attr {
  overflow-x: hidden;
}

.list {
.title {
  color: rgba(0,0,0,0.85);
  font-size: 16px;
  font-weight: bold;
  line-height: 18px;
  padding: 20px 0;
}

.listn {
&.on{
   margin: 0 -20px -16px 0;
 }
.item {
  line-height:18px;
  background: #F5F5F5;
  border-radius: 18px;
  color: #666666;
  font-size 14px;
  margin: 0 20px 16px 0;
  cursor: pointer;
  padding: 9px 14px;

&.on {
   background: #1890FF;
   color: #fff;
 }
}
}
}

.name {
  color: rgba(0,0,0,0.85);
  font-size: 18px;
}

.info {
  flex: 1;
  font-size: 13px;
  color: #999999;
  margin: 12px 0 0;
}

.money {
  font-size: 18px;
  color: #F5222D;
  font-weight: bold;

.num {
  font-size: 22px;
}
}
}
</style>
