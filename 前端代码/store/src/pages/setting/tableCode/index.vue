<template>
  <div>
    <Card :bordered="false" dis-hover>
      <Row>
        <Col span="20">
          <Form ref="form" :model="formData" :label-width="110">
            <FormItem label="房间开关：">
              <i-switch
                v-model="formData.store_code_switch"
                true-value="1"
                false-value="0"
                size="large"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
            <FormItem label="房间位：">
              <Table class="mb15" :columns="columns" :data="tableSeatsList">
                <template slot-scope="{ row }" slot="action">
                  <a @click="onEdit(row)">编辑</a>
                  <Divider type="vertical" />
                  <a @click="onDelete(row.id)">删除</a>
                </template>
              </Table>
              <Button type="primary" @click="modalToggle">添加</Button>
            </FormItem>
          </Form>
        </Col>
      </Row>
      <div class="footer acea-row row-center-wrapper">
        <Button type="primary" class="ml20" @click="handleSubmit('form')">提交</Button>
      </div>
    </Card>
    <Modal
      v-model="modal"
      :mask-closable="false"
      title="房间位"
      width="540"
      class-name="add-modal-wrap"
      footer-hide
    >
      <Form :label-width="107">
        <FormItem label="房间位数：">
          <InputNumber v-model="number" :min="1"></InputNumber>
        </FormItem>
      </Form>
      <div class="footer">
        <Button class="mr10" @click="modalToggle">取消</Button>
        <Button type="primary" @click="addSeats">确认</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { getTableSeatsList, getConfig, submitConfig, addSeats } from '@/api/setting';

export default {
  data() {
    return {
      columns: [
        {
          title: '房间位数',
          key: 'number',
        },
        {
          title: '创建时间',
          key: 'add_time',
        },
        {
          title: '操作',
          slot: 'action',
          align: 'center',
        },
      ],
      tableSeatsList: [],
      modal: false,
      formData: {
        store_code_switch: '1',
        store_checkout_method: '2',
        store_number_diners_window: '1',
      },
      id: 0,
      number: 1,
    };
  },
  created() {
    this.getConfig();
    this.getTableSeatsList();
  },
  methods: {
    getConfig() {
      getConfig('store_table_code').then((res) => {
        if (res.data.constructor.name === 'Array') {
          return;
        }
        this.formData = res.data;
      });
    },
    getTableSeatsList() {
      getTableSeatsList().then((res) => {
        this.tableSeatsList = res.data;
      });
    },
    onEdit(row) {
      this.modal = true;
      this.id = row.id;
      this.number = row.number;
    },
    onDelete(id) {
      this.$modalSure({
        title: '删除房间位数',
        url: `/table/del/seats/${id}`,
        method: 'delete',
        ids: '',
      }).then((res) => {
        this.$Message.success(res.msg);
        this.getTableSeatsList();
      });
    },
    modalToggle() {
      this.id = 0;
      this.number = 1;
      this.modal = !this.modal;
    },
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          submitConfig('store_table_code', this.formData).then((res) => {
            this.$Message.success(res.msg);
          });
        }
      });
    },
    addSeats() {
      addSeats(this.id, { number: this.number }).then((res) => {
        this.$Message.success(res.msg);
        this.modal = false;
        this.getTableSeatsList();
      });
    },
  },
};
</script>

<style lang="less" scoped>
/deep/.ivu-modal-content {
  border-radius: 10px;
}
/deep/.ivu-modal-header {
  border-radius: 10px 10px 0 0;
  background-color: #ffffff;
}
/deep/.ivu-modal-body {
  padding: 28px 31px 31px 0;
}
/deep/.ivu-input-number {
  width: 100%;
}
.add-modal-wrap {
  .footer {
    height: auto;
    margin-top: 0;
    box-shadow: none;
    text-align: right;
  }
}
.footer{
  width: 100%;
  height: 50px;
  box-shadow: 0px -2px 4px 0px rgba(0, 0, 0, 0.05);
  margin-top: 50px;
}
/deep/.ivu-card-body {
  padding-bottom: 0;
}
</style>
