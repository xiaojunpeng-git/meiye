<template>
  <div style="width: 100%">
    <Modal
      v-model="modals"
      scrollable
      footer-hide
      closable
      title="账单详情"
      :mask-closable="false"
      @on-cancel="cancel"
      width="950"
    >
      <Form
        ref="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        class="tabform"
        @submit.native.prevent
      >
        <Row type="flex" :gutter="24">
          <Col v-bind="grid">
            <FormItem label="选择供应商：" label-for="status1">
              <Select
                v-model="formValidate.supplier_id"
                placeholder="请选择"
                clearable
                element-id="status1"
                @on-change="searchs"
              >
                <Option
                  :value="item.id"
                  v-for="item in supplierList"
                  :key="item.id"
                  >{{ item.supplier_name }}</Option
                >
              </Select>
            </FormItem>
          </Col>
          <Col v-bind="grid">
            <FormItem label="订单搜索：" label-for="status1">
              <Input
                v-model="formValidate.keyword"
                placeholder="请输入交易单号/交易人"
                class="input"
              ></Input>
            </FormItem>
          </Col>
          <Col>
            <div class="search" @click="searchs">搜索</div>
          </Col>
          <Col>
            <div class="reset" @click="reset">重置</div>
          </Col>
        </Row>
      </Form>
      <!-- <Divider dashed/> -->
      <Table
        :columns="columns"
        :data="tabList"
        ref="table"
        :loading="loading"
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
        class="table"
      >
        <template slot-scope="{ row, index }" slot="number">
          <span v-if="row.pm == 0" class="colorgreen">- {{ row.number }}</span>
          <span v-if="row.pm == 1" class="colorred">+ {{ row.number }}</span>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="formValidate.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formValidate.limit"
        />
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  supplierFfundRecordInfoApi,
  getSupplierList,
} from '@/api/supplier';
import { mapState } from 'vuex';
export default {
  name: 'commissionDetails',
  props: {
    takeTime: {
      type: Boolean,
      default: false
    }
  },
  data() {
    let columns = [
      {
        title: '交易单号',
        key: 'order_id',
        minWidth: 80,
      },
      {
        title: '关联订单',
        key: 'link_id',
        minWidth: 80,
      },
      {
        title: '交易时间',
        key: 'trade_time',
        minWidth: 150,
      },
      {
        title: '完成时间',
        key: 'take_time',
        minWidth: 150,
      },
      {
        title: '交易金额',
        slot: 'number',
        minWidth: 80,
      },
      {
        title: '交易人',
        key: 'user_nickname',
        ellipsis: true,
        minWidth: 80,
      },
      {
        title: '交易类型',
        key: 'type_name',
        minWidth: 80,
      },
      {
        title: '支付方式',
        key: 'pay_type_name',
        minWidth: 80,
      },
      {
        title: '备注',
        key: 'remark',
        minWidth: 120,
      },
    ];
    if (!this.takeTime) {
      columns.forEach((item, index) => {
        if (item.key == 'take_time') {
          columns.splice(index, 1);
        }
      });
    }
    return {
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      modals: false,
      detailsData: {},
      Ids: 0,
      loading: false,
      supplierList: [],
      ids: '',
      formValidate: {
        ids: '',
        supplier_id: '',
        keyword: '',
        page: 1,
        limit: 10,
      },
      total: 0,
      columns,
      tabList: [],
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 90;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'left';
    },
  },
  mounted() {
    this.getSupplierList();
  },
  methods: {
    getSupplierList() {
      getSupplierList().then((res) => {
        this.supplierList = res.data;
      });
    },
    searchs() {
      this.formValidate.page = 1;
      this.getList(this.ids);
    },
    // 时间
    onchangeTime(e) {
      this.formValidate.start_time = e[0];
      this.formValidate.end_time = e[1];
    },
    // 列表
    getList(id) {
      this.ids = id;
      this.formValidate.ids = id;
      this.loading = true;
      supplierFfundRecordInfoApi(this.formValidate)
        .then(async (res) => {
          let data = res.data;
          this.tabList = data.list;
          this.total = data.count;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.getList(this.ids);
    },
    reset() {
      this.formValidate = {
        ids: this.ids,
        supplier_id: '',
        keyword: '',
        data: '',
        page: 1,
        limit: 10,
      };
      this.getList(this.ids);
    },
    // 关闭按钮
    cancel() {
      this.formValidate = {
        ids: '',
        supplier_id: '',
        keyword: '',
        data: '',
        page: 1,
        limit: 10,
      };
    },
  },
};
</script>

<style lang="less" scoped>
.colorred {
  color: #ff5722;
}
.colorgreen {
  color: #009688;
}
.search {
  width: 86px;
  height: 32px;
  background: #1890ff;
  border-radius: 4px;
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: #ffffff;
  cursor: pointer;
}
.reset {
  width: 86px;
  height: 32px;
  border-radius: 4px;
  border: 1px solid rgba(151, 151, 151, 0.36);
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: rgba(0, 0, 0, 0.85);
  cursor: pointer;
}
.table {
  .ivu-table-default {
    overflow-y: auto;
    max-height: 350px;
  }
}
.dashboard-workplace {
  &-header {
    &-avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      margin-right: 16px;
      font-weight: 600;
    }

    &-tip {
      width: 82%;
      display: inline-block;
      vertical-align: middle;

      &-title {
        font-size: 13px;
        color: #000000;
        margin-bottom: 12px;
      }

      &-desc {
        &-sp {
          width: 33.33%;
          color: #17233d;
          font-size: 12px;
          display: inline-block;
        }
      }
    }

    &-extra {
      .ivu-col {
        p {
          text-align: right;
        }

        p:first-child {
          span:first-child {
            margin-right: 4px;
          }

          span:last-child {
            color: #808695;
          }
        }

        p:last-child {
          font-size: 22px;
        }
      }
    }
  }
}
</style>
