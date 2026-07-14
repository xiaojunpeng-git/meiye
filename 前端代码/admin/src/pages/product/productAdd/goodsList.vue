<template>
  <div class="goodList">
    <Form
      ref="formValidate"
      :model="formValidate"
      :label-width="labelWidth"
      :label-position="labelPosition"
      inline
      class="tableform"
    >
      <FormItem label="商品分类：">
        <Cascader
          :data="treeSelect"
          placeholder="请选择商品分类"
          change-on-select
          filterable
          class="input-add"
          @on-change="treeSearchs"
        ></Cascader>
      </FormItem>
      <FormItem label="商品标签：">
        <Select
          v-model="formValidate.store_label_id"
          class="input-add"
          clearable
          @on-change="userSearchs"
        >
          <Option v-for="item in labelSelect" :value="item.id" :key="item.id"
            >{{ item.label_name }}
          </Option>
        </Select>
      </FormItem>
      <FormItem label="商品类型：">
        <Select v-model="formValidate.product_type" class="input-add" clearable>
          <Option
            v-for="item in productTypeSelect"
            :value="item.id"
            :key="item.id"
            >{{ item.label_name }}
          </Option>
        </Select>
      </FormItem>
      <FormItem label="商品搜索：">
        <Input
          placeholder="请输入商品名称,关键字,编号"
          v-model="formValidate.store_name"
          class="input-add mr14"
        />
        <Button type="primary" @click="userSearchs()">查询</Button>
      </FormItem>
    </Form>
    <vxe-table
      border="inner"
      ref="tableRef"
      height="500"
      :tree-config="{ children: 'attrValue' }"
      :data="tableList"
      @checkbox-change="checkboxChange"
    >
      <vxe-column type="checkbox" title="选择" tree-node></vxe-column>
      <vxe-column title="商品ID">
        <template v-slot="{ row }">
          {{ row.product_id || row.id }}
        </template>
      </vxe-column>
      <vxe-column title="图片">
        <template v-slot="{ row }">
          <viewer>
            <img v-lazy="row.image" width="36" height="36" />
          </viewer>
        </template>
      </vxe-column>
      <vxe-column title="商品名称">
        <template v-slot="{ row }">
          <div class="line2">{{ row.suk || row.store_name }}</div>
        </template>
      </vxe-column>
      <vxe-column title="商品类型"></vxe-column>
      <vxe-column title="商品分类">
        <template v-slot="{ row }">
          {{ row.cate_name }}
        </template>
      </vxe-column>
    </vxe-table>
    <div class="acea-row row-right page">
      <Page
        :total="total"
        show-elevator
        show-total
        @on-change="pageChange"
        :page-size="formValidate.limit"
      />
    </div>
    <div class="footer" slot="footer">
      <Button
        type="primary"
        size="large"
        :loading="modal_loading"
        long
        @click="ok"
        >提交</Button
      >
    </div>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import { cascaderListApi, changeListApi, allLabelApi } from '@/api/product';
export default {
  data() {
    return {
      //选中商品集合
      selectEquips: [],
      // 选中的id集合
      selectEquipsIds: [],
      labelSelect: [],
      productTypeSelect: [
        {
          id: 0,
          label_name: '产品',
        },
        {
          id: 6,
          label_name: '预约商品',
        },
      ],
      cateIds: [],
      modal_loading: false,
      treeSelect: [],
      formValidate: {
        page: 1,
        limit: 10,
        cate_id: '',
        store_name: '',
        is_new: '',
        store_label_id: '',
        is_card: 1,
        product_type: '',
      },
      total: 0,
      modals: false,
      loading: false,
      grid: {
        xl: 10,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24,
      },
      tableList: [],
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  mounted() {
    this.goodsCategory();
    this.getAllLabelApi();
    this.getList();
  },
  methods: {
    // 判断是否选中
    sortData() {
      if (this.selectEquipsIds.length) {
        this.tableList.forEach((ele) => {
          if (this.selectEquipsIds.includes(ele.id)) ele._checked = true;
        });
      }
    },
    getAllLabelApi() {
      allLabelApi()
        .then((res) => {
          this.labelSelect = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 商品分类；
    goodsCategory() {
      cascaderListApi({
        type: 0,
        relation_id: 0,
      })
        .then((res) => {
          this.treeSelect = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.getList();
    },
    // 列表
    getList() {
      this.loading = true;
      changeListApi(this.formValidate)
        .then(async (res) => {
          let list = res.data.list;
          list.forEach((item) => {
            item.attrValue.forEach((value) => {
              value.cate_name = item.cate_name;
              value.store_name = item.store_name;
              value.write_times = 1;
            });
          });
          this.tableList = list;
          this.total = res.data.count;
          this.sortData();
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    ok() {
      this.$emit('getProductId', this.selectEquips);
    },
    treeSearchs(value) {
      this.cateIds = value;
      this.formValidate.page = 1;
      this.getList();
    },
    // 表格搜索
    userSearchs() {
      this.formValidate.page = 1;
      this.getList();
    },
    checkboxChange({ checked, row }) {
      if (checked) {
        if (row.spec_type === undefined) {
          this.selectEquips.push(row);
        } else {
          row.attrValue.forEach((value) => {
            this.selectEquips.push(value);
          });
        }
      } else {
        if (row.spec_type === undefined) {
          for (let i = 0; i < this.selectEquips.length; i++) {
            if (this.selectEquips[i].id === row.id) {
              this.selectEquips.splice(i, 1);
            }
          }
        } else {
          for (let i = 0; i < this.selectEquips.length; i++) {
            for (let j = 0; j < row.attrValue.length; j++) {
              if (this.selectEquips[i].id === row.attrValue[j].id) {
                this.selectEquips.splice(i, 1);
              }
            }
          }
        }
      }
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-table-header thead tr th {
  padding: 8px 5px;
}

/deep/.ivu-radio-wrapper {
  margin-right: 0 !important;
}

.footer {
  margin: 15px 0;
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

.tableform {
  >>> .ivu-form-item {
    margin-bottom: 16px !important;
  }
}

.btn {
  margin-top: 20px;
  float: right;
}

.mr-20 {
  margin-right: 10px;
}
</style>
