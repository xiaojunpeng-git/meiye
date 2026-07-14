<template>
  <div>
    <Card :bordered="false" dis-hover>
      <Button class="mb15" type="primary" @click="modalToggle">添加分类</Button>
      <Table :columns="columns" :data="tableCateList" :loading="loading">
        <template slot-scope="{ row }" slot="action">
          <a @click="onEdit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="onDelete(row.id)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :page-size="limit"
          show-total
          @on-change="onChange"
        />
      </div>
    </Card>
    <Modal
      v-model="modal"
      :mask-closable="false"
      title="房间分类"
      width="540"
      class-name="add-modal-wrap"
      footer-hide
    >
      <Form :label-width="107">
        <FormItem label="房间分类：">
          <Input v-model="name" placeholder="请输入"></Input>
        </FormItem>
      </Form>
      <div class="footer">
        <Button class="mr10" @click="modalToggle">取消</Button>
        <Button type="primary" @click="addTableCate">确认</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { getTableCateList, addTableCate } from '@/api/setting';

export default {
  data() {
    return {
      columns: [
        {
          title: '房间分类',
          key: 'name',
        },
        {
          title: '房间数量',
          key: 'sum',
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
      tableCateList: [],
      total: 100,
      limit: 10,
      page: 1,
      modal: false,
      id: 0,
      name: '',
      loading: false,
    };
  },
  created() {
    this.getTableCateList();
  },
  methods: {
    getTableCateList() {
      this.loading = true;
      getTableCateList({
        page: this.page,
        limit: this.limit,
      }).then((res) => {
        let { data, count } = res.data;
        this.tableCateList = data;
        this.total = count;
        this.loading = false;
      });
    },
    // 分页
    onChange(page) {
      this.page = page;
      this.getTableCateList();
    },
    // 取消、添加分类
    modalToggle() {
      this.id = 0;
      this.name = '';
      this.modal = !this.modal;
    },
    // 编辑
    onEdit(row) {
      this.modal = true;
      this.id = row.id;
      this.name = row.name;
    },
    // 删除
    onDelete(id) {
      this.$modalSure({
        title: '删除房间分类',
        url: `/table/del/cate/${id}`,
        method: 'delete',
        ids: '',
      }).then((res) => {
        this.$Message.success(res.msg);
        this.getTableCateList();
      });
    },
    // 提交分类
    addTableCate() {
      addTableCate(this.id, { name: this.name }).then((res) => {
        this.$Message.success(res.msg);
        this.modal = false;
        this.getTableCateList();
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
.add-modal-wrap {
  .footer {
    text-align: right;
  }
}
</style>