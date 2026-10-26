<template>
  <div>
    <div class="table_list">
       <div
         class="table_kuai"
         v-for="(item, index) in tables"
         :key="item.id || index"
         :title="item.name"
         @click="look(item)"
       >
         <span class="table_kuai_text">{{ item.name }}</span>
       </div>
    </div>
     <Modal v-model="positionLevelModals" :title="title" footerHide  scrollable width="90%" @on-cancel="cancelTable">
            <salaryTableInfo ref="salaryTable"></salaryTableInfo>
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import util from "@/libs/util";
import Setting from "@/setting";
import { getTable } from "@/api/salary_table";
import salaryTableInfo from "./components/salary_table_info";
import editFrom from '@/components/from/from';
export default {
  name: "index",
  components:{
      salaryTableInfo,
      editFrom
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    ...mapState("admin/userLevel", ["categoryId"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "left";
    },
  },
  data() {
    return {
       positionLevelModals:false,
       title:'',
       tables:[]
    };
  },
  created() {
    this.showTable();
  },
  methods: {
    cancelTable(){
      this.positionLevelModals=false;
    },
    look(row){
        this.title=row.name;
        this.positionLevelModals=true;
        this.$nextTick(() => {
          this.$refs.salaryTable.tableFrom.table_ids = row.id;
          this.$refs.salaryTable.tableType = row.type;
          this.$refs.salaryTable.begin();
        });
    },
    showTable(){
      getTable().then(res=>{
             this.tables=res.data;
       })
     }
  },
};
</script>

<style scoped lang="stylus">
.table_list
  display grid
  grid-template-columns repeat(auto-fill, minmax(180px, 1fr))
  gap 20px

.table_kuai
  display flex
  align-items center
  justify-content center
  min-height 100px
  padding 12px 16px
  background-color #ffffff
  border-radius 10px
  cursor pointer
  font-size 20px
  box-sizing border-box
  &:hover
    background-color #57a3f3
    color #ffffff

.table_kuai_text
  display -webkit-box
  -webkit-box-orient vertical
  -webkit-line-clamp 2
  overflow hidden
  text-align center
  line-height 1.4
  word-break break-word
</style>
