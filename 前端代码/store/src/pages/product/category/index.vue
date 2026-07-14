<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Button class="mr20" type="primary" @click="addCategory">添加分类</Button>
      <vxe-table
        :data="tableData"
        ref="xTable"
        class="ivu-mt"
        highlight-hover-row
        :loading="loading"
        header-row-class-name="false"
        row-id="id"
        :tree-config="{
          lazy: true,
          children: 'children',
          hasChild: 'children',
          loadMethod: loadChildrenMethod,
          reserve: true,
        }"
      >
        <vxe-table-column
          field="id"
          title="ID"
          tooltip
          width="80"
        ></vxe-table-column>
        <vxe-table-column
          field="cate_name"
          tree-node
          title="分类名称"
          min-width="250"
        ></vxe-table-column>
        <vxe-table-column field="pic" title="分类图标" min-width="100">
          <template v-slot="{ row }">
            <viewer>
              <div class="tabBox_img">
                <img v-lazy="row.pic" />
              </div>
            </viewer>
          </template>
        </vxe-table-column>
        <vxe-table-column
          field="sort"
          title="排序"
          min-width="100"
          tooltip="true"
        ></vxe-table-column>
        <vxe-table-column field="is_show" title="状态" min-width="120">
          <template v-slot="{ row }">
            <i-switch
              v-model="row.is_show"
              :value="row.is_show"
              :true-value="1"
              :false-value="0"
              @on-change="onchangeIsShow(row)"
              size="large"
            >
              <span slot="open">显示</span>
              <span slot="close">隐藏</span>
            </i-switch>
          </template>
        </vxe-table-column>
        <vxe-table-column field="date" title="操作" width="250" align="left">
          <template v-slot="{ row, index }">
            <a @click="edit(row)">编辑</a>
            <Divider type="vertical" />
            <a @click="del(row, '删除商品分类', index)">删除</a>
          </template>
        </vxe-table-column>
      </vxe-table>
    </Card>
  </div>
</template>

<script>
import {
  productCategory,
  categorySetShowApi,
  productCategoryEdit,
  productCategoryCreate,
} from '@/api/product.js';

export default {
  data() {
    return {
      tableData: [],
      loading: false,
    };
  },
  created() {
    this.productCategory();
  },
  methods: {
    productCategory() {
      productCategory({ pid: 0 }).then((res) => {
        this.tableData = res.data.list;
      });
    },
    // 添加分类
    addCategory() {
      this.$modalForm(productCategoryCreate()).then(() => {
        this.productCategory();
      });
    },
    // 编辑分类
    edit(row) {
      this.$modalForm(productCategoryEdit(row.id)).then(() => {
        this.productCategory();
      });
    },
    // 删除分类
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `product/category/${row.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.productCategory();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    loadChildrenMethod({ row }) {
      return new Promise((resolve, reject) => {
        productCategory({ pid: row.id }).then((res) => {
          let arr = res.data.list;
          resolve(arr);
        });
      });
    },
    // 切换分类状态
    onchangeIsShow(row) {
      categorySetShowApi(row.id, row.is_show)
        .then(async (res) => {
          this.$Message.success(res.msg);
          // this.artFrom.pid = 0;
          this.productCategory();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
  },
};
</script>

<style lang="less" scoped>
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