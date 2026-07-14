<template>
  <div>
    <Card :bordered="false" dis-hover>
      <div class="mb15">
        <Button class="mr10" type="primary" @click="modalToggle"
          >添加房间</Button
        >
        <Button
          :disabled="!QRCodeSelected.length"
          type="primary"
          @click="onBatchDownload"
          >批量下载</Button
        >
      </div>
      <Table
        :columns="columns"
        :data="tableQRCodeList"
        :loading="loading"
        @on-selection-change="onSelectionChange"
      >
        <template slot-scope="{ row }" slot="qrcode">
          <viewer>
            <div class="tabBox_img">
              <img v-lazy="row.qrcode" />
            </div>
          </viewer>
        </template>
        <template slot-scope="{ row }" slot="is_using">
          <i-switch v-model="row.is_using" :true-value="1" :false-value="0" size="large" @on-change="isUsingChange(row.id, $event)">
            <span slot="open">启用</span>
            <span slot="close">停用</span>
          </i-switch>
        </template>
        <template slot-scope="{ row }" slot="category">
          <div>{{ row.category.name }}</div>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="onEdit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="onDownload(row)">下载</a>
          <Divider type="vertical" />
          <a @click="onDelete(row.id)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="page"
          :page-size="limit"
          show-total
          @on-change="onChange"
        />
      </div>
    </Card>
    <Modal
      v-model="modal"
      :mask-closable="false"
      :title="`${id ? '编辑' : '新增'}房间号`"
      width="540"
      class-name="add-modal-wrap"
      footer-hide
    >
      <Form :model="formValidate" :label-width="96">
        <FormItem label="房间类型：">
          <Select v-model="formValidate.cate_id">
            <Option
              v-for="item in tableCateList"
              :key="item.id"
              :value="item.id"
              >{{ item.name }}</Option
            >
          </Select>
        </FormItem>
        <FormItem label="房间位：">
          <Select v-model="formValidate.seat_num">
            <Option
              v-for="item in tableSeatsList"
              :key="item.id"
              :value="item.number"
              >{{ item.number }}</Option
            >
          </Select>
        </FormItem>
        <FormItem label="房位名称：">
          <Input
            v-model="formValidate.remarks"
            type="input"
            maxlength="120"
            placeholder="请输入房位名称"
          ></Input>
        </FormItem>
      </Form>
      <div class="footer">
        <Button class="mr10" @click="modalToggle">取消</Button>
        <Button class="mr10" @click="addTableQRCode(1)">保存并启用</Button>
        <Button type="primary" @click="addTableQRCode(0)">仅保存</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  getTableQRCodeList,
  addTableQRCode,
  getTableCateList,
  getTableSeatsList,
  updateTableUsing,
} from '@/api/setting';

export default {
  data() {
    return {
      columns: [
        {
          type: 'selection',
          width: 60,
          align: 'center',
        },
        {
          title: '房间编号',
          key: 'table_number',
        },
        {
          title: '房位名称',
          key: 'remarks',
        },
        {
          title: '二维码',
          slot: 'qrcode',
        },
        {
          title: '关联分类',
          slot: 'category',
        },
        {
          title: '创建时间',
          key: 'add_time',
        },
        {
          title: '状态',
          slot: 'is_using',
        },
        {
          title: '操作',
          slot: 'action',
          align: 'center',
        },
      ],
      tableQRCodeList: [],
      total: 0,
      limit: 10,
      page: 1,
      modal: false,
      tableCateList: [],
      tableSeatsList: [],
      formValidate: {
        cate_id: 0,
        seat_num: 0,
        number: [],
        is_using: 0,
        remarks: '',
      },
      id: 0,
      loading: false,
      QRCodeSelected: [],
    };
  },
  created() {
    this.getTableQRCodeList();
    this.getTableCateList();
    this.getTableSeatsList();
  },
  methods: {
    onSelectionChange(selection) {
      this.QRCodeSelected = selection;
    },
    getTableQRCodeList() {
      this.loading = true;
      getTableQRCodeList({
        page: this.page,
        limit: this.limit,
      }).then((res) => {
        this.loading = false;
        this.tableQRCodeList = res.data.list;
        this.total = res.data.count;
      });
    },
    getTableCateList() {
      getTableCateList().then((res) => {
        this.tableCateList = res.data.data;
      });
    },
    getTableSeatsList() {
      getTableSeatsList().then((res) => {
        this.tableSeatsList = res.data;
      });
    },
    onChange(page) {
      this.page = page;
      this.getTableQRCodeList();
    },
    onEdit(row) {
      let { cate_id, seat_num, is_using, remarks, id } = row;
      this.modal = true;
      this.formValidate = { cate_id, seat_num, is_using, remarks, number: [] };
      this.id = id;
    },
    onDownload(row) {
      if (typeof row.qrcode != 'string' || !row.qrcode) {
        return this.$Message.error('无二维码图片链接');
      }
      new Promise((resolve) => {
        const image = new Image();
        image.crossOrigin = 'anonymous';
        image.src = row.qrcode;
        image.onload = () => {
          resolve(image);
        };
      }).then((image) => {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        canvas.width = image.width;
        canvas.height = image.height;
        context.drawImage(image, 0, 0, image.width, image.height);
        let url = canvas.toDataURL('image/jpeg');
        let a = document.createElement('a');
        let event = new MouseEvent('click');
        a.download = row.table_number;
        a.href = url;
        a.dispatchEvent(event);
      });
    },
    onDelete(id) {
      this.$modalSure({
        title: '删除房间',
        url: `/table/del/qrcode/${id}`,
        method: 'delete',
        ids: '',
      }).then((res) => {
        this.$Message.success(res.msg);
        if (this.page > 1 && this.tableQRCodeList.length == 1) {
          this.page--;
        }
        this.getTableQRCodeList();
      });
    },
    onBatchDownload() {
      if (!this.QRCodeSelected[0].qrcode) {
        return this.$Message.error('无二维码图片链接');
      }
      this.QRCodeSelected.forEach(this.onDownload);
    },
    modalToggle() {
      this.modal = !this.modal;
      this.formValidate = {
        cate_id: 0,
        seat_num: 0,
        number: [],
        is_using: 0,
        remarks: '',
      };
      this.id = 0;
    },
    addTableQRCode(is_using) {
      if (!this.formValidate.cate_id) {
        return this.$Message.error('请选择房间类型');
      }
      if (!this.formValidate.seat_num) {
        return this.$Message.error('请选择房间位');
      }
      if (!this.formValidate.remarks) {
        return this.$Message.error('请输入房位名称');
      }
      let formValidate = { ...this.formValidate };
      formValidate.is_using = is_using;
      addTableQRCode(this.id, formValidate)
        .then((res) => {
          this.$Message.success(res.msg);
          this.modal = false;
          this.getTableQRCodeList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    isUsingChange(id, is_using) {
      updateTableUsing(id, { is_using }).then(res => {
        this.$Message.success(res.msg);
        this.getTableQRCodeList();
      }).catch(res => {
        this.$Message.error(res.msg);
      });
    }
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
  padding: 30px 25px 29px 0;
}
.ivu-modal-wrap {
  .footer {
    text-align: right;
  }
}
.tabBox_img {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
  }
}
</style>
