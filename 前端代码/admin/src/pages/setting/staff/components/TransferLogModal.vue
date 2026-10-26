<template>
  <Modal
    :value="value"
    title="调店记录"
    width="1000"
    footer-hide
    :mask-closable="false"
    @on-cancel="handleClose"
  >
    <Form inline :model="formData" :label-width="80" @submit.native.prevent>
      <FormItem label="原门店：">
        <Select
          v-model="formData.from_store_id"
          clearable
          filterable
          class="filter-select"
          placeholder="全部"
        >
          <Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
        </Select>
      </FormItem>
      <FormItem label="目标门店：">
        <Select
          v-model="formData.to_store_id"
          clearable
          filterable
          class="filter-select"
          placeholder="全部"
        >
          <Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
        </Select>
      </FormItem>
      <FormItem label="调店时间：">
        <DatePicker
          :editable="false"
          :value="timeVal"
          format="yyyy/MM/dd"
          type="daterange"
          placement="bottom-end"
          placeholder="自定义时间"
          class="filter-select-wide"
          :options="options"
          @on-change="onDateChange"
        />
      </FormItem>
      <FormItem :label-width="0">
        <Button type="primary" @click="handleSearch">查询 <span class="enter-key">↵</span></Button>
      </FormItem>
    </Form>
    <Table
      :columns="columns"
      :data="tableData"
      :loading="loading"
      highlight-row
      no-data-text="暂无调店记录"
    />
    <div class="acea-row row-right page">
      <Page
        :total="total"
        :current="formData.page"
        :page-size="formData.limit"
        show-elevator
        show-total
        @on-change="onPageChange"
      />
    </div>
  </Modal>
</template>

<script>
import { merchantStoreListApi } from '@/api/setting';
import { staffTransferLog } from '@/api/staff';
import timeOptions from '@/utils/timeOptions';

export default {
  name: 'StaffTransferLogModal',
  props: {
    value: {
      type: Boolean,
      default: false,
    },
    staffId: {
      type: Number,
      default: 0,
    },
  },
  data() {
    return {
      options: timeOptions,
      loading: false,
      storeList: [],
      timeVal: [],
      formData: {
        from_store_id: '',
        to_store_id: '',
        data: '',
        page: 1,
        limit: 10,
      },
      tableData: [],
      total: 0,
      columns: [
        { title: '原门店', key: 'from_store_name', minWidth: 120 },
        { title: '目标门店', key: 'to_store_name', minWidth: 120 },
        { title: '原身份', key: 'from_roles_text', minWidth: 140 },
        { title: '新身份', key: 'to_roles_text', minWidth: 140 },
        { title: '调店原因', key: 'reason', minWidth: 120 },
        { title: '操作人', key: 'operator_name', minWidth: 100 },
        { title: '调店时间', key: 'add_time_text', minWidth: 160 },
        { title: '生效时间', key: 'effective_time_text', minWidth: 160 },
      ],
    };
  },
  watch: {
    value(val) {
      if (val && this.staffId) {
        this.resetForm();
        this.loadStores();
        this.getList();
      }
    },
  },
  methods: {
    resetForm() {
      this.formData = {
        from_store_id: '',
        to_store_id: '',
        data: '',
        page: 1,
        limit: 10,
      };
      this.timeVal = [];
    },
    loadStores() {
      if (this.storeList.length) return;
      merchantStoreListApi()
        .then((res) => {
          this.storeList = res.data || [];
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getList() {
      if (!this.staffId) return;
      this.loading = true;
      const params = {
        staff_id: this.staffId,
        page: this.formData.page,
        limit: this.formData.limit,
      };
      if (this.formData.from_store_id) params.from_store_id = this.formData.from_store_id;
      if (this.formData.to_store_id) params.to_store_id = this.formData.to_store_id;
      if (this.formData.data) params.data = this.formData.data;
      staffTransferLog(params)
        .then((res) => {
          this.tableData = res.data.list || [];
          this.total = res.data.count || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleSearch() {
      this.formData.page = 1;
      this.getList();
    },
    onDateChange(date) {
      this.timeVal = date;
      this.formData.data = date && date[0] ? date.join('-') : '';
    },
    onPageChange(page) {
      this.formData.page = page;
      this.getList();
    },
    handleClose() {
      this.resetForm();
      this.$emit('input', false);
    },
  },
};
</script>

<style scoped lang="stylus">
.filter-select
  width 160px

.filter-select-wide
  width 220px

.page
  margin-top 16px

.enter-key
  margin-left 2px
  font-weight 600
</style>
