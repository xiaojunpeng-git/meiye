<template>
  <Modal :value="visible" title="修改余额" width="680" class-name="recharge-modal" @on-cancel="clear">
    <div class="infoData" v-if="userInfo">
      <div class="pictrue">
        <img :src="userInfo.avatar">
      </div>
      <div class="info">
        <div class="attr">
          <div v-if="userInfo.phone" class="item phone">{{ userInfo.phone }}</div>
          <div class="item">余额<span class="num">{{ userInfo.now_money }}</span></div>
          <div class="item">本金<span class="num">{{ userInfo.ben_money }}</span></div>
          <div class="item">赠金<span class="num">{{ userInfo.give_money }}</span></div>
        </div>
      </div>
    </div>
    <Form :label-width="90">
      <FormItem label="修改类型：">
        <Radio-group v-model="changeForm.type">
           <Radio :label='1'>增加</Radio>
           <Radio :label='2'>减少</Radio>
        </Radio-group>
      </FormItem>
      <FormItem label="修改本金：">
        <InputNumber v-model="changeForm.ben_money" :min="0" :max="9999999" placeholder="0.00"></InputNumber>
      </FormItem>
      <FormItem style="margin-top: 20px" label="修改赠金：">
        <InputNumber v-model="changeForm.give_money" :min="0" :max="9999999" placeholder="0.00"></InputNumber>
      </FormItem>
      <FormItem style="margin-top: 20px" label="备注：">
        <Input v-model="changeForm.mark"  placeholder="请输入备注内容"></Input>
      </FormItem>
    </Form>
    <div slot="footer">
      <div class="acea-row row-center-wrapper mt22">
        <Button class="w-176 h-46 fs-16 rd-30px bnt-F5F5F5" @click="clear">取消</Button>
        <Button class="w-176 h-46 fs-16 rd-30px ml20" type="primary" @click="save">提交</Button>
      </div>
    </div>
  </Modal>
</template>
<script>
import { changeMoney } from '@/api/user';
export default {
  name: 'recharge',
  model: {
    prop: 'visible',
    event: 'close'
  },
  components:{

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
      changeForm:{
         ben_money:0,
         give_money:0,
         type:1,  //1增加 2减少
         uid:0,
         mark:'',
      },
      givePrice: 0,
      totalPrice: 0
    }
  },
  watch: {
  },
  created() {

  },
  methods: {
    save() {
      let that=this;
      this.changeForm.uid=this.userInfo.uid;
      changeMoney(this.changeForm).then(res=>{
        that.$emit("changeSuccess",that.userInfo.uid);
        that.$Message.success('修改成功');
        that.clear();
      }).catch(res=>{
        this.$Message.error(res.msg);
      })
    },
    clear() {
      this.changeForm = {
        ben_money:0,
        give_money:0,
        type:1,  //1增加 2减少
        uid:0,
      };
      this.$emit('close', false);
    }
  }
}
</script>

<style lang="stylus" scoped>
.icon-send-num {
  position: absolute;
  top: -8px;
  right: -7px;
  padding: 5px 7px 3px;
  border-radius: 11px;
  background: #FF7700;
  font-size: 14px;
  line-height: 14px;
  color: #FFFFFF;
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
  height: 375px;
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
