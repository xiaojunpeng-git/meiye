<template>
    <Modal :value="visible" title="业绩分配" width="868" class-name="recharge-modal"  @on-cancel="clear">
      <Tabs v-model="activeName" v-if="canSy">
        <TabPane
            v-for="(item, index) in chooseList"
            :key="index"
            :label="item.label"
            :name="item.val"
        >
       <Form :label-width="0" v-if="item.val == 'yeji'">
          <FormItem>
          <Button type="primary" size="large" @click="doAdd">添加人员</Button>
          <Button type="danger" size="large" style="margin-left: 20px" @click="doSync" v-if="syncProduct.length > 1">同步到其他项目</Button>
          <span class="price_title">参与分配业绩：{{ yeji.price}}</span>
               <div class="table_out">
                  <div class="head">
                          <div class="body_title">员工</div>
                          <div class="body_title">职位</div>
                          <div class="body_title">职级</div>
<!--                          <div class="body_title">是否点客</div>-->
                          <div class="body_title">业绩</div>
                          <div class="body_title">操作</div>
                  </div>
                  <div class="body">
                        <div class="heng_kuai" v-for="(item,index) in yeji.staffChoose">
                          <div class="body_kuai">{{ item.staff_name }}</div>
                          <div class="body_kuai">{{ item.position_label }}</div>
                          <div class="body_kuai">{{ item.position_level_label }}</div>
<!--                          <div class="body_kuai">-->
<!--                            <Switch size="large" v-model="item.is_dian" :false-value="0" :true-value="1">-->
<!--                              <span slot="open" :true-value="1">是</span>-->
<!--                              <span slot="close" :false-value="0">否</span>-->
<!--                            </Switch>-->
<!--                          </div>-->
                          <div class="body_kuai">
                             <InputNumber style="width: 80%"  v-model="item.yeji" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
                          </div>
                          <div class="body_kuai">
                               <div class="shanchu" @click="delYeji(index,1)">删除</div>
                          </div>
                        </div>
                  </div>
          </div>
           </FormItem>
          </Form>
          <Form :label-width="0" v-if="item.val == 'yejiService'">
            <FormItem>
              <Button type="primary" size="large" @click="doAdd">添加人员</Button>
              <Button type="danger" size="large" style="margin-left: 20px" @click="doSync" v-if="syncProduct.length > 1">同步到其他项目</Button>
              <span class="price_title">参与分配业绩：{{ yejiService.price}}</span>
              <div class="table_out">
                <div class="head">
                  <div class="body_title">员工</div>
                  <div class="body_title">职位</div>
                  <div class="body_title">职级</div>
                  <div class="body_title">是否点客</div>
                  <div class="body_title">业绩</div>
                  <div class="body_title">操作</div>
                </div>
                <div class="body">
                  <div class="heng_kuai" v-for="(item,index) in yejiService.staffChoose">
                    <div class="body_kuai">{{ item.staff_name }}</div>
                    <div class="body_kuai">{{ item.position_label }}</div>
                    <div class="body_kuai">{{ item.position_level_label }}</div>
                    <div class="body_kuai">
                      <Switch size="large" v-model="item.is_dian" :false-value="0" :true-value="1">
                        <span slot="open" :true-value="1">是</span>
                        <span slot="close" :false-value="0">否</span>
                      </Switch>
                    </div>
                    <div class="body_kuai">
                      <InputNumber style="width: 80%"  v-model="item.yeji" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
                    </div>
                    <div class="body_kuai">
                      <div class="shanchu" @click="delYeji(index,2)">删除</div>
                    </div>
                  </div>
                </div>
              </div>
            </FormItem>
          </Form>
        </TabPane>
      </Tabs>
      <Form style="margin-top: 15px" :label-width="0" v-else>
        <FormItem>
          <Button type="primary" size="large" @click="doAdd">添加人员</Button>
          <Button type="danger" size="large" style="margin-left: 20px" @click="doSync" v-if="syncProduct.length > 1">同步到其他项目</Button>
          <span class="price_title">参与分配业绩：{{ yeji.price}}</span>
          <div class="table_out">
            <div class="head">
              <div class="body_title">员工</div>
              <div class="body_title">职位</div>
              <div class="body_title">职级</div>
              <div class="body_title" v-if="!isSale">是否点客</div>
              <div class="body_title">业绩</div>
              <div class="body_title">操作</div>
            </div>
            <div class="body">
              <div class="heng_kuai" v-for="(item,index) in yeji.staffChoose">
                <div class="body_kuai">{{ item.staff_name }}</div>
                <div class="body_kuai">{{ item.position_label }}</div>
                <div class="body_kuai">{{ item.position_level_label }}</div>
                <div class="body_kuai" v-if="!isSale">
                  <Switch size="large" v-model="item.is_dian" :false-value="0" :true-value="1">
                    <span slot="open" :true-value="1">是</span>
                    <span slot="close" :false-value="0">否</span>
                  </Switch>
                </div>
                <div class="body_kuai">
                  <InputNumber style="width: 80%"  v-model="item.yeji" :min="1" :max="9999999" placeholder="0.00"></InputNumber>
                </div>
                <div class="body_kuai">
                  <div class="shanchu" @click="delYeji(index,1)">删除</div>
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
      <Modal v-model="showAdd"  title="分配业绩"  width='500' class="modalPay">
        <div class="payPage" v-if="activeName == 'yejiService'">
          <Input class="searchOut" v-model="staffForm.keyword" ref="rechargeNum" size="large" type="url" @input="getStaff" placeholder="输入关键词" style="margin-top: 16px;"/>
          <div class="kuai_out">
                <div :class="staffIdsService.includes(item.id)?'kuai choose_kuai':'kuai'" v-for="(item,index) in staffList" @click="chooseStaff(item)">{{ item.staff_name }}</div>
          </div>
        </div>
        <div class="payPage" v-else>
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
    }
  },
  data() {
    return {
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
    chooseStaff(item){
      let activeName=this.activeName;
      if(activeName == 'yejiService') {
        const index = this.staffIdsService.indexOf(item.id);
        if (index > -1) {
          this.staffIdsService.splice(index, 1);
          this.yejiService.staffChoose.splice(index, 1);
        } else {
          this.staffIdsService.push(item.id);
          let attr = {
            'staff_id': item.id,
            'staff_name': item.staff_name,
            'position_label': item.position_label,
            'position': item.position,
            'position_level': item.position_level,
            'position_level_label': item.position_level_label,
            'yeji': 0,
            'is_dian': 0,
          };
          this.yejiService.staffChoose.push(attr);
        }
      }else{
        const index = this.staffIds.indexOf(item.id);
        if (index > -1) {
          this.staffIds.splice(index, 1);
          this.yeji.staffChoose.splice(index, 1);
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
            'is_dian': 0,
          };
          this.yeji.staffChoose.push(attr);
        }
      }
    },
    getStaff(){
      staffallList(this.staffForm).then(res => {
        this.staffList = res.data
      })
    },
    saveAdd(){
      let activeName=this.activeName;
      if(activeName == 'yejiService') {
        var len = this.staffIdsService.length;
        var number = Math.floor(this.yejiService.price / len);
        this.yejiService.staffChoose.forEach(function (item) {
            item.yeji = number;
        })
        var yu = this.yejiService.price % len;
        if (len > 0 && yu > 0) {
          this.yejiService.staffChoose[len - 1].yeji = Number(this.yejiService.staffChoose[len - 1].yeji) + yu;
        }
      }else{
        var len = this.staffIds.length;
        var number = Math.floor(this.yeji.price / len);
        this.yeji.staffChoose.forEach(function (item) {
          item.yeji = number;
        })
        var yu = this.yeji.price % len;
        if (len > 0 && yu > 0) {
          this.yeji.staffChoose[len - 1].yeji = Number(this.yeji.staffChoose[len - 1].yeji) + yu;
        }
      }
       this.showAdd=false;
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
      // if(totalYeji > this.yeji.price){
      //   this.$Message.error("累计销售业绩不能大于总金额！");
      //   return false;
      // }
      // if(this.canSy) {
      //   totalYeji = 0;
      //   this.yejiService.staffChoose.forEach(function (item) {
      //     totalYeji = Number(totalYeji) + Number(item.yeji);
      //   })
      //   if (totalYeji > this.yejiService.price) {
      //     this.$Message.error("累计劳动业绩不能大于总分配业绩！");
      //     return false;
      //   }
      // }
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
