<template>
  <Modal
    v-model="modals"
    scrollable
    title="修改来源"
    width="500px"
  >
     <div>
           <div class="heng">来源：
             <Select v-model="form.source" class="input-add">
                 <Option :value="item.id" v-for="(item,index) in source">
                       {{ item.name }}
                 </Option>
             </Select>
           </div>
     </div>
    <div slot="footer">
      <Button type="primary"  style="margin-left: 20px" @click="saveRemark">保存</Button>
      <Button @click="cancel">关闭</Button>
    </div>
  </Modal>
</template>

<script>
import { saveSource } from "@/api/yeji";
import { getCash } from "@/api/order";
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
      form:{
         id:0,
         source:0
      },
      source:[],
      modals: false,
      columns1: []
    };
  },
  methods: {
    showSet(){
       this.isEdit=true;
    },
    cancel() {
      this.modals = false;
    },
    showSource(row) {
      getCash().then((res)=>{
        this.form={
             id:row.id,
             source:row.source
        }
        this.source=res.data.source;
      })
    },
    saveRemark(){
      let that=this;
      saveSource(this.form).then((res)=>{
             that.isEdit=false;
             that.$Message.success( '修改成功！');
             // that.getRemark(that.form.id);
             that.$parent.getList();
             that.modals=false;
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
