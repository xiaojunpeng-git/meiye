<template>
  <Modal
    v-model="modals"
    scrollable
    title="付款信息"
    width="960px"
  >
     <div v-if="info.type == 1">
           <div class="heng">外部流水号：{{ info.remark_info.water_number }}</div>
           <div class="heng">备注：{{ info.remark_info.remark }}</div>
           <div class="heng" v-if="info.pay_type =='cash' && !isEdit">
                  付款方式：{{ info.pay_type_name }}
             <Button type="primary" style="margin-left: 20px" @click="showSet">修改</Button>
           </div>
           <div class="heng" v-if="info.pay_type =='cash' && isEdit">付款方式：
             <Select v-model="form.cash_choose"
                     class="input-add"
             >
               <Option :value="item.id" v-for="(item,index) in info.cash_types">{{ item.name }}</Option>
             </Select>
             <Button type="primary"  style="margin-left: 20px" @click="saveRemark">保存</Button>
           </div>
     </div>
    <div v-else>
      <Table
          :columns="columns1"
          :data="info.list"
          highlight-row
          row-key="id"
      >
        <template slot-scope="{ row }" slot="linkOrder">
          <span v-if="row.pay_sub_type === 'card_upgrade'">
            {{
              oldOrderNoMap[String(row.upgrade_old_oid || row.old_oid || row.oid || '')] ||
              row.old_order_id ||
              row.upgrade_old_order_id ||
              row.upgrade_old_oid ||
              row.old_oid ||
              row.oid ||
              '-'
            }}
          </span>
          <span v-else>-</span>
        </template>
        <template slot-scope="{ row }" slot="waibu">
            <span>{{ row.remarkInfo.water_number}}</span>
        </template>
        <template slot-scope="{ row }" slot="remark">
          <span>{{ row.remarkInfo.remark}}</span>
        </template>
      </Table>
    </div>
    <div slot="footer">
      <Button @click="cancel">关闭</Button>
    </div>
  </Modal>
</template>

<script>
import { getRemak,saveRemark } from "@/api/yeji";
import { getDataInfo } from "@/api/order";
export default {
  name: "orderMark",
  props: {
    orderId: Number,
    remarkType: {
      default: "",
      type: String,
    }
  },
  data() {
    return {
      isEdit:false,
      info:{},
      oldOrderNoMap: {},
      form:{
         id:0,
         type:0,
         cash_choose:0
      },
      modals: false,
      columns1: [
          {
            title: "付款方式",
             key: "name",
             minWidth: 120,
          },
         {
            title: "付款金额",
             key: "price",
             minWidth: 120,
          },
        {
          title: "关联订单号",
          slot: "linkOrder",
          minWidth: 160,
        },
        {
          title: "外部流水号",
          slot: "waibu",
          minWidth: 120,
        },
        {
          title: "备注",
          slot: "remark",
          minWidth: 200,
        }
        ]
    };
  },
  methods: {
    showSet(){
       this.isEdit=true;
    },
    cancel() {
      this.modals = false;
    },
    getRemark(orderId) {
        getRemak({order_id:orderId,type:this.remarkType}).then((res)=>{
             this.info=res.data
             this.oldOrderNoMap = {};
             this.fillCardUpgradeOrderNos();
             this.form={
               id:orderId,
               type:this.info.the_type,
               cash_choose:this.info.cash_choose
             }
        })
    },
    fillCardUpgradeOrderNos() {
      const list = (this.info && Array.isArray(this.info.list)) ? this.info.list : [];
      const oidSet = {};
      list.forEach((row) => {
        if (row && row.pay_sub_type === 'card_upgrade') {
          const oid = Number(row.upgrade_old_oid || row.old_oid || row.oid || 0);
          if (oid) oidSet[oid] = oid;
        }
      });
      const oids = Object.keys(oidSet).map((k) => Number(k)).filter(Boolean);
      if (!oids.length) return;
      oids.forEach((oid) => {
        getDataInfo(oid).then((res) => {
          const orderNo = res && res.data && res.data.orderInfo ? res.data.orderInfo.order_id : '';
          if (orderNo) this.$set(this.oldOrderNoMap, String(oid), orderNo);
        }).catch(() => {});
      });
    },
    saveRemark(){
      let that=this;
      saveRemark(this.form).then((res)=>{
             that.isEdit=false;
             that.$Message.success( '修改成功！');
             that.$parent.getList();
             that.modals=false;
             that.isEdit=false;
      })
    }
  },
};
</script>

<style scoped>
.heng{
  margin-bottom: 20px;
}
</style>
