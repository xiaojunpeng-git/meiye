<template>
    <Modal :value="visible" title="赠送品项/优惠劵" width="868" class-name="recharge-modal"  @on-cancel="clear">
      <Form style="margin-top: 15px" :label-width="0">
        <FormItem>
          <Button type="primary" size="large" @click="addProduct">添加品项</Button>
          <Button size="large" style="margin-left: 20px" @click="addCoupon">添加优惠劵</Button>
          <div class="table_out">
            <div class="head">
              <div class="body_title">类型</div>
              <div class="body_title" style="width: 30%">名称</div>
              <div class="body_title">数量</div>
              <div class="body_title" style="width: 30%">生效时间</div>
              <div class="body_title">有效天数</div>
              <div class="body_title">结束时间</div>
              <div class="body_title">操作</div>
            </div>
            <div class="body">
              <div class="heng_kuai" v-for="(row,index) in sendAll.product">
                <div class="body_kuai">
                  <span v-if="row.product_type==0">产品</span>
                  <span v-if="row.product_type==5">卡项</span>
                  <span v-if="row.product_type==6">项目</span>
                </div>
                <div class="body_kuai" style="font-size: 12px;width: 30%;white-space: nowrap;overflow: hidden;text-overflow: ellipsis">{{ row.store_name }}</div>
                <div class="body_kuai">
                      <InputNumber  style="width: 80%"  v-model="row.num" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
                </div>
                <div class="body_kuai"  style="width: 30%">
                          ——
<!--                  <DatePicker :clearable="false" :transfer='true' v-model="row.begin_time_label" type="date"-->
<!--                              placeholder="选择日期" @on-change='changeBeginProduct($event,row,index)' style="width: 100%"/>-->
                </div>
                <div class="body_kuai">
                          ——
<!--                  <InputNumber @input="handleInput($event,row)" style="width: 80%"  v-model="row.write_days" :min="1" :max="9999999" placeholder="0.00"></InputNumber>-->
                </div>
                <div class="body_kuai">
                            ——
<!--                       <span v-if="row.end_time">{{ row.end_time_label }}</span>-->
<!--                       <span v-else>不限制</span>-->
                </div>
                <div class="body_kuai">
                  <div class="shanchu" @click="delSendProduct(index)">删除</div>
                </div>
              </div>
              <div class="heng_kuai" v-for="(row,index) in sendAll.coupon">
                <div class="body_kuai">
                        劵
                </div>
                <div class="body_kuai" style="font-size: 12px;width: 30%;white-space: nowrap;overflow: hidden;text-overflow: ellipsis">{{ row.store_name }}</div>
                <div class="body_kuai">
                      <InputNumber  style="width: 80%"  v-model="row.num" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
                </div>
                <div class="body_kuai" style="width: 30%">
                  <DatePicker :clearable="false" :transfer='true' v-model="row.begin_time_label" type="date"
                              placeholder="选择日期" @on-change='changeBeginCoupon($event,row,index)' style="width: 100%"/>
                </div>
                <div class="body_kuai">
                  <InputNumber @input="handleInput($event,row)" style="width: 80%"  v-model="row.write_days" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
                </div>
                <div class="body_kuai">
                       <span v-if="row.end_time_label">{{ row.end_time_label }}</span>
                       <span v-else>未设置</span>
                </div>
                <div class="body_kuai">
                  <div class="shanchu" @click="delSendCoupon(index,row)">删除</div>
                </div>
              </div>
            </div>
          </div>
        </FormItem>
      </Form>
      <div slot="footer">
        <div class="acea-row row-center-wrapper mt22">
          <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="clear">关闭</Button>
          <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="save">提交</Button>
        </div>
      </div>
      <Modal v-model="goodsModal" title="商品列表" footerHide scrollable width="900">
        <goods-attr :goodsType="1"  ref="goodSattr" v-if="goodsModal" @getProductId="getAtterId"></goods-attr>
     </Modal>
      <coupon-list
          ref="couponTemplates"
          @nameId="nameId"
          :couponids="formValidate.coupon_ids"
          :updateIds="formValidate.coupon_ids"
          :updateName="formValidate.couponName"
      ></coupon-list>
    </Modal>
</template>
<script>
import couponList from '@/components/couponChoose';
import goodsAttr from '@/components/goodsAttr';
export default {
  name: 'send',
  components: {
    goodsAttr,
    couponList
  },
  props: {
    visible: {
      type: Boolean,
      default: false
    },
    sendAll:{
       type:Object
    }
  },
  data() {
    return {
      goodsModal:false,
      formValidate:{
        coupon_ids:[],
        couponName:[],
      }
    }
  },
  watch: {

  },
  created() {

  },
  methods: {    // 添加优惠券
    addCoupon() {
      this.formValidate={
        coupon_ids:[],
        couponName:[],
      }
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    //对象数组去重；
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    nameId(id, names) {
      // this.formValidate.coupon_ids = id;
      // this.formValidate.couponName = this.unique(names);
      let that=this;
      names.forEach(data=> {
        if(data.begin_time === 0 || !data.begin_time){
            data.begin_time=that.todayDateSecondTimestamp();
        }
        if(data.end_time > 0 && data.begin_time > 0 && data.write_days === 0){
          data.write_days=that.diffDays(data.end_time,data.begin_time);
        }
        if(data.write_days === 0){
            data.write_days=1;
        }
        if(data.write_days > 0){
          data.end_time=that.getEnd(data.begin_time,data.write_days);
        }
        data.begin_time_label='未设置';
        if(data.begin_time > 0){
             data.begin_time_label=that.formatSecondToDate(data.begin_time);
        }
        data.end_time_label='未设置'
        if(data.end_time > 0){
             data.end_time_label=that.formatSecondToDate(data.end_time);
         }
        that.sendAll['coupon'].push(data)
      });
    },
    handleClose(name) {
      let index = this.formValidate.couponName.indexOf(name);
      this.formValidate.couponName.splice(index, 1);
      this.formValidate.coupon_ids.splice(index, 1);
    },
    handleInput(value,row){
       if(value == 0) {
         row.end_time=0;
       }else{
         row.end_time = this.getEnd(row.begin_time, value);
         row.end_time_label = this.formatSecondToDate(row.end_time);
       }
    },
    changeBeginCoupon(value,row,index){
      row.begin_time_label=value;
      row.begin_time= Math.floor(new Date(value).getTime() / 1000);
      row.end_time = this.getEnd(row.begin_time, row.write_days);
      row.end_time_label = this.formatSecondToDate(row.end_time);
      let coupon=this.sendAll.coupon;
      coupon[index]=row;
      this.sendAll.coupon=[];
      this.sendAll.coupon=coupon;
    },
    changeBeginProduct(value,row,index){
      row.begin_time_label=value;
      row.begin_time= Math.floor(new Date(value).getTime() / 1000);
      row.end_time = this.getEnd(row.begin_time, row.write_days);
      row.end_time_label = this.formatSecondToDate(row.end_time);
      let product=this.sendAll.product;
      product[index]=row;
      this.sendAll.product=[];
      this.sendAll.product=product;
    },
    delSendProduct(index){
         this.sendAll.product.splice(index, 1);
    },
    delSendCoupon(index,row){
         this.handleClose(row);
         this.sendAll.coupon.splice(index, 1);
    },
    addProduct(){
       this.goodsModal=true;
    },
    diffDays(begin,end) {
      // 定义一天的毫秒数
      const ONE_DAY = 24 * 60 * 60 * 1000;
      // 校验时间戳有效性
      if (!begin || !end) {
        return 0; // 无效时间戳返回0
      }
      // 计算天数差（取绝对值避免负数，取整忽略不足1天的部分）
      var diffTime = Math.abs(begin - end);
      var diffDays = Math.floor(diffTime / ONE_DAY);
      return diffDays;
    },
    todayDateSecondTimestamp() {
      const now = new Date();
      // 1. 构造当天0点的Date对象（时分秒默认0）
      const todayZero = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      // 2. 转换为毫秒级时间戳后，除以1000得到秒级时间戳
      // 3. 取整确保是整数（避免浮点误差）
      return Math.floor(todayZero.getTime() / 1000);
    },
    formatSecondToDate(timestamp, format = 'YYYY-MM-DD') {
    // 边界校验：仅处理数字类型的秒级时间戳（10位）
    const timeNum = Number(timestamp);
    if (!timestamp || isNaN(timeNum) || timeNum.toString().length !== 10) {
      return '--'; // 非秒级时间戳返回占位符
    }

    // 秒级转毫秒级，生成Date对象
    const date = new Date(timeNum * 1000);
    if (date.toString() === 'Invalid Date') {
      return '--';
    }

    // 提取年月日（补0处理）
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

     // 仅替换年月日占位符，无需处理时分秒
     return format.replace('YYYY', year).replace('MM', month).replace('DD', day);
    },
    getEnd(startDate, days = 2) {
      // 1. 校验入参：确保是有效的秒级时间戳（10位数字）
      const secTimestamp = Number(startDate);
      if (isNaN(secTimestamp) || secTimestamp.toString().length !== 10) {
        console.error('startDate必须是10位的秒级时间戳！');
        return 0; // 无效入参返回0
      }

      // 2. 核心处理：秒级转毫秒级，创建Date对象
      const msTimestamp = secTimestamp * 1000; // 秒级→毫秒级
      const targetDate = new Date(msTimestamp);

      // 3. 校验日期是否有效（避免极端错误的时间戳）
      if (targetDate.toString() === 'Invalid Date') {
        console.error('秒级时间戳对应的日期无效！');
        return 0;
      }

      // 4. 计算往后推days天的日期
      targetDate.setDate(targetDate.getDate() + days);

      // 5. 转回秒级时间戳并返回
      return Math.floor(targetDate.getTime() / 1000);
    },
    getAtterId(selectCardData) {
      this.goodsModal = false;
      // const uniqueSet = new Set(this.sendAll['product'].map((item) => item.id));
      // const newCardData = selectCardData.filter((item) => !uniqueSet.has(item.id));
      let result=[];
      let that=this;
      selectCardData.forEach(res=>{
        var data={
          id:res.id,
          product_type:res.product_type,
          store_name:res.store_name,
          num:1,
          begin_time:res.attrValue[0].write_start || 0,
          write_days:res.attrValue[0].write_days || 1,
          end_time:res.attrValue[0].write_start || 0,
        };
        if(data.begin_time === 0 || !data.begin_time){
            data.begin_time=that.todayDateSecondTimestamp();
        }
        if(data.end_time > 0 && data.begin_time > 0 && data.write_days === 0){
              data.write_days=that.diffDays(data.end_time,data.begin_time);
        }
        if(data.write_days === 0){
          data.write_days=1;
        }
        if(data.write_days > 0){
             data.end_time=that.getEnd(data.begin_time,data.write_days);
        }
        data.begin_time_label='未设置';
        if(data.begin_time > 0){
           data.begin_time_label=that.formatSecondToDate(data.begin_time);
        }
        data.end_time_label='未设置'
        if(data.end_time > 0){
          data.end_time_label=that.formatSecondToDate(data.end_time);
        }
        that.sendAll['product'].push(data)
      })
    },
    clear() {
      this.$emit('closeSend');
    },
    save() {
      this.$emit('closeSend');
    },
  }
}
</script>

<style lang="stylus" scoped>
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
    padding: 0px 25px 30px 25px;
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
