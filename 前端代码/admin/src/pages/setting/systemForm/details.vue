<template>
  <div>
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title" class="acea-row row-middle">
          <router-link :to="{ path: `${roterPre}/setting/system_form` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span v-text="$route.meta.title" class="mr20 ml16"></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 查询条件 -->
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
		inline
        @submit.native.prevent
      >
        <FormItem label="时间筛选：">
          <DatePicker
            :editable="false"
            :clearable="true"
            @on-change="onchangeTime"
            :value="timeVal"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-start"
            placeholder="自定义时间"
            class="input-add"
            :options="options"
          ></DatePicker>
        </FormItem>
        <FormItem label="商品信息：">
          <Input  v-model="formValidate.store_name" placeholder="请输入商品名称/ID" class="input-add mr14"/>
        </FormItem>
        <FormItem label="用户信息：">
          <Input  v-model="formValidate.user_name" placeholder="请输入用户名称/手机号" class="input-add mr14"/>
		  <Button type="primary" class="mr14" @click="searchs">查询</Button>
		  <Button type="primary" @click="systemFormExport">导出</Button>
        </FormItem>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Table :columns="columns" :data="tableData"></Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formValidate.limit"
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import { systemFormData, systemFormDataExport } from '@/api/system';
import timeOptions from '@/utils/timeOptions';
import exportExcel from '@/utils/newToExcel.js';
import Setting from '@/setting'
export default {
  data() {
    return {
      roterPre: Setting.roterPre,
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      formValidate: {
        data: 'thirtyday',
        store_name: '',
        user_name: '',
        page: 1,
        limit: 20,
      },
      columns: [
        {
          title: '模板名称',
          key: 'system_form_name',
          width: 130,
        },
		{
		  title: '应用商品',
		  key: 'productname',
		  width: 150,
		  render: (h, params) => {
		    return h('div', `${params.row.product_name}/${params.row.product_id}`);
		  },
		},
        {
          title: '用户名称/ID',
          key: 'nickname',
          width: 130,
          render: (h, params) => {
            return h('div', `${params.row.nickname}/${params.row.uid}`);
          },
        },
        {
          title: '用户手机号',
          key: 'phone',
          width: 130,
        },
        {
          title: '模板内容',
          key: 'content',
          tooltip: true,
          minWidth: 80,
        },
		{
		  title: '订单编号',
		  key: 'order_id',
		  width: 180,
		},
        {
          title: '创建时间',
          key: 'add_time',
          width: 130,
        },
      ],
      tableData: [],
      total: 0,
      timeVal: [],
      options: timeOptions,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.systemFormData();
  },
  methods: {
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.formValidate.data = this.timeVal[0] ? this.timeVal.join('-') : '';
      if (e[0] == '') {
        this.formValidate.data = 'thirtyday';
      }
      this.formValidate.page = 1;
      this.systemFormData();
    },
    searchs(){
      this.formValidate.page = 1;
      this.systemFormData();
    },
    systemFormData() {
      systemFormData(this.$route.query.id, this.formValidate).then((res) => {
        for (const row of res.data.list) {
          row.content = '';
          for (const item of row.value) {
            row.content += `${item.title}：${item.value}；`;
          }
        }
        this.tableData = res.data.list;
        this.total = res.data.count;
      });
    },
    systemFormDataExport(formValidate) {
      return new Promise((resolve) => {
        systemFormDataExport(this.$route.query.id, formValidate).then((res) => {
          resolve(res.data);
        });
      });
    },
    async systemFormExport() {
      let formValidate = { ...this.formValidate, page: 1 };
      let headers = [];
      let filenames = '';
      let filekeys = [];
      let sheetData = [];
      for (let i = 0; i < formValidate.page; i++) {
        let result = await this.systemFormDataExport(formValidate);
        let { header, filename, filekey } = result;
        if (!result.export.length) {
          break;
        }
        if (header.length) {
          headers = header;
        }
        if (filename) {
          filenames = filename;
        }
        if (filekey.length) {
          filekeys = filekey;
        }
        sheetData = sheetData.concat(result.export);
        formValidate.page++;
      }
      for (const row of sheetData) {
        row.content = '';
        for (const item of row.form_data) {
          row.content += `${item.title}：${item.value}；`;
        }
        row.form_data = row.content;
      }
      exportExcel(headers, filekeys, filenames, sheetData);
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.systemFormData();
    },
  },
};
</script>

<style scoped lang="stylus">
	/deep/.ivu-table td{
		padding-left: 0 !important;
	}
	/deep/.ivu-table td:nth-of-type(1){
		padding-left: 16px !important;
	}
</style>