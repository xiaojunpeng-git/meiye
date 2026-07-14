<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="formData"
          :model="formData"
          :label-width="labelWidth"
          :label-position="labelPosition"
          class="tabform"
          inline
          @submit.native.prevent
        >
          <FormItem label="订单编号：">
            <Input
              placeholder="订单编号"
              v-model="formData.order_id"
              class="input-add"
            />
          </FormItem>
          <FormItem label="下单门店：">
            <Select
              v-model="formData.ordering_store_id"
              clearable
              filterable
              @on-change="searchHandle"
              class="input-add"
            >
              <Option v-for="item in storeList" :value="item.id" :key="item.id"
                >{{ item.name }}
              </Option>
            </Select>
          </FormItem>
          <FormItem label="核销人员：">
            <Input
              placeholder="请输入人员昵称"
              v-model="formData.staff"
              class="input-add"
            />
          </FormItem>
          <FormItem label="商品名称：">
            <Input
              placeholder="商品名称"
              v-model="formData.product_name"
              class="input-add"
              clearable
            />
          </FormItem>
          <FormItem label="手艺人：">
            <Input
              placeholder="手艺人姓名"
              v-model="formData.yeji_staff"
              class="input-add"
              clearable
            />
          </FormItem>
          <FormItem label="核销时间：">
            <DatePicker
              :editable="false"
              @on-change="dateChange"
              :value="timeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="自定义时间"
              class="input-add"
              :options="options"
            ></DatePicker>
            <Button type="primary" @click="searchHandle" class="ml-14"
              >查询</Button
            >
            <Button @click="exports" class="ml-14">导出</Button>
            <Button @click="reset" class="ml-14">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Table
        :columns="columns"
        :data="tableData"
        ref="table"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="action">
          <a @click="doYeji(row)">业绩分配</a>
        </template>
        <template slot-scope="{ row }" slot="user">
          <a @click="showUserInfo(row)" v-if="row.user">{{
               row.user.real_name
          }}</a>
          <span
            style="color: #ed4014"
            v-if="!row.user || row.user.delete_time != null"
          >
            (已注销)</span
          >
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="formData.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formData.limit"
        />
      </div>
    </Card>
    <!-- 用户信息 -->
    <user-details ref="userDetails" fromType="order"></user-details>
    <yeji :syncProduct="syncProduct" :yeji="setYeji" :staffIds="staffIds" @doChoose="doChoose" @closeYeji="closeYeji" :visible="yejiVisible" ref="yeji"></yeji>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import Setting from '@/setting'
import timeOptions from '@/utils/timeOptions';
import { staffListInfo, writeoffRecords,exportWriteoffRecords } from '@/api/store';
import { getYeji } from '@/api/yeji';
import userDetails from '@/pages/user/list/handle/userDetails';
import yeji from '@/components/yeji';
import exportExcel from "@/utils/newToExcel.js";
export default {
  components: {
    userDetails,
    yeji
  },
  data() {
    return {
     staffIds:[],
      setYeji:{
        link_id:0,
        price:0,
        goods_id:0,
        type:2,
        staffChoose:[]
      },
      syncProduct:[],
      yejiVisible: false,
      roterPre: Setting.roterPre,
      options: timeOptions,
      formData: {
        page: 1,
        limit: 10,
        order_id: '',
        ordering_store_id: '',
        staff: '',
        product_name: '',
        yeji_staff: '',
        data: '',
      },
      storeList: [],
      timeVal: [],
      columns: [
        {
          title: 'ID',
          key: 'id',
          minWidth: 80,
        },
        {
          title: '订单号',
          key: 'order_id',
          minWidth: 150,
        },
        {
          title: '客户名称',
          slot: 'user',
          minWidth: 100,
        },
        {
          title: '手机号',
          key: 'phone',
          minWidth: 100,
        },
        {
          title: '商品名称',
          key: 'product_name',
          minWidth: 150,
        },
        {
          title: '商品分类',
          key: 'cate_name',
          minWidth: 150,
        },
        {
          title: '手艺人',
          key: 'yeji_staff',
          minWidth: 150,
          className: 'writeoff-yeji-staff-col',
        },
        {
          title: '核销数量',
          key: 'writeoff_num',
          minWidth: 100,
        },
        {
          title: '核销金额',
          key: 'writeoff_price',
          minWidth: 150,
        },
        {
          title: '下单门店',
          key: 'ordering_store',
          minWidth: 150,
        },
        {
          title: '核销门店',
          key: 'write_off_store',
          minWidth: 150,
        },
        {
          title: '核销人员',
          key: 'staff_name',
          minWidth: 150,
        },
        {
          title: '核销时间',
          key: 'add_time',
          minWidth: 150,
        }
      ],
      tableData: [],
      loading: false,
      total: 0,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.getStoreList();
    this.getList();
  },
  methods: {
    doYeji(row){
      this.setYeji.staffChoose=[];
      this.staffIds=[];
      let that=this;
      getYeji({link_id:row.id,type:3,goods_id:row.product_id}).then((res)=>{
        if(res.data) {
          that.setYeji = res.data;
          res.data.staffChoose.forEach(function (item){
            that.staffIds.push(item.staff_id);
          })
        }
        that.$refs.yeji.staffForm.store_id=row.store_id
        that.$refs.yeji.getStaff();
        that.yejiVisible=true;
      })
    },
    doChoose(yeji){
      let hasAdd=false;
      let that=this;
      this.closeYeji();
    },
    closeYeji(){
      this.yejiVisible=false;
    },
    getStoreList() {
      staffListInfo().then((res) => {
        this.storeList = res.data;
      });
    },
    getList() {
      writeoffRecords(this.formData).then((res) => {
        const { count, list } = res.data;
        this.total = count;
        this.tableData = list;
      });
    },
    dateChange(date) {
      this.timeVal = date;
      this.formData.data = date.join('-');
    },
    pageChange(page) {
      this.formData.page = page;
      this.getList();
    },
    searchHandle() {
      this.formData.page = 1;
      this.getList();
    },
    async exports() {
      let excelData=this.formData;
      let [th, filekey, data, fileName] = [[], [], [], '']
      excelData.page = 1;
      delete excelData.limit;
      for (let i = 0; i < excelData.page; i++) {
        let lebData = await this.getExcelData(excelData)
        if (!lebData.export.length) {
          break;
        }
        if (!fileName) {
          fileName = lebData.filename
        }
        if (!filekey.length) {
          filekey = lebData.filekey
        }
        if (!th.length) {
          th = lebData.header
        }
        data = data.concat(lebData.export)
        excelData.page++
      }
      exportExcel(th, filekey, fileName, data)
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        exportWriteoffRecords(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    reset() {
      this.formData.page = 1;
      this.formData.order_id = '';
      this.formData.ordering_store_id = '';
      this.formData.staff = '';
      this.formData.product_name = '';
      this.formData.yeji_staff = '';
      this.formData.data = '';
      this.timeVal = [];
      this.getList();
    },
    handeYeji(row){
      router.push({
        path: this.roterPre + "/order/yeji",
        query: { link_id: row.id,type:'3'}
      })
    },
    showUserInfo(row) {
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.activeName = 'info';
      this.$refs.userDetails.getDetails(row.uid);
    },
  },
};
</script>

<style lang="stylus" scoped>
/deep/ .writeoff-yeji-staff-col
  height auto !important
  .ivu-table-cell
    white-space normal
    word-break break-all
    line-height 1.5
    height auto !important
    padding-top 8px
    padding-bottom 8px
</style>
