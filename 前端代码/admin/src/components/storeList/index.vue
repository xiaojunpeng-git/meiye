<template>
  <div class="goodList">
    <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        inline
        class="tabform"
		v-if="region < 1"
    >
      <FormItem label="门店分类：" label-for="pid">
        <Cascader
            :data="treeSelect"
            placeholder="请选择门店分类"
            change-on-select
            filterable
            class="input-add"
            v-model="formValidate.cate_id"
        ></Cascader>
      </FormItem>
      <FormItem label="门店搜索：" label-for="name">
        <Input
            placeholder="请输入门店名称"
            v-model="formValidate.name"
            class="input-add mr14"
        />
        <Button type="primary" @click="userSearchs">搜索</Button>
      </FormItem>
    </Form>
    <Table
        ref="table"
        no-data-text="暂无数据"
        @on-select-all="selectAll"
        @on-select-all-cancel="cancelAll"
        @on-select="TableSelectRow"
        @on-select-cancel="TableSelectCancelRow"
        no-filtered-data-text="暂无筛选结果"
        :columns="columns"
        :data="tableList"
        :loading="loading"
        class="mr-20"
		:class='region < 1?"store":""'
        height="350"
    >
      <template slot-scope="{ row }" slot="image">
        <viewer>
          <div class="tabBox_img">
            <img v-lazy="row.image" />
          </div>
        </viewer>
      </template>
	  <template slot-scope="{ row, index }" slot="action">
		<a @click="delte(row,'删除区域门店',index)" v-auth="['admin-store-region_delete_store']">删除</a>
	  </template>
    </Table>
    <div class="acea-row row-right page">
      <Page
          :total="total"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formValidate.limit"
      />
    </div>
    <div class="footer" slot="footer" v-if="region < 1">
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
import { storeListApi, cascaderList } from '@/api/store';
export default {
  name: 'index',
  props: {
    region: {
      type: Number,
      default: 0
    }
  },
  data() {
    return {
      // 选中商品集合
      selectEquips: [],
      // 选中的id集合
      selectEquipsIds: [],
      modal_loading: false,
      treeSelect: [],
      formValidate: {
        page: 1,
        limit: 10,
        cate_id: [],
        name: ''
      },
      total: 0,
      loading: false,
      grid: {
        xl: 10,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24
      },
      tableList: [],
      columns: [
        {
          type: 'selection',
          width: 60,
          align: 'center'
        },
        {
          title: 'ID',
          key: 'id',
          width: 60
        },
        {
          title: '门店图片',
          slot: 'image',
          minWidth: 80
        },
        {
          title: '门店名称',
          key: 'name',
          minWidth: 80
        },
        {
          title: '门店分类',
          key: 'cate_name',
          minWidth: 80
        },
        {
          title: '联系电话',
          key: 'phone',
          minWidth: 90
        },
        {
          title: '门店地址',
          key: 'address',
          ellipsis: true,
          minWidth: 150
        },
        {
          title: '营业时间',
          key: 'day_time',
          minWidth: 120
        },
        {
          title: '营业状态',
          key: 'status_name',
          minWidth: 80
        }
      ]
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    }
  },
  created() {
	  if (this.region > 0) {
		  const obj = {
			  title: '操作',
			  slot: 'action',
			  width: 60
		  };
		  this.columns.shift();
		  this.columns.push(obj);
	  }
  },
  mounted() {
    this.getList();
    this.goodsCategory();
  },
  methods: {
    // 删除
    delte(row, tit, num) {
      const ids = {
        store_id: row.id
      };
	    const delfromData = {
	        title: tit,
	        num: num,
	        url: `store/region/del_store/${this.region}`,
	        method: 'DELETE',
	        ids: ids
	    };
	    this.$modalSure(delfromData).then((res) => {
	        this.$Message.success(res.msg);
	        this.tableList.splice(num, 1);
        if (!this.tableList.length) {
			  this.formValidate.page =
			    this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
        }
        this.getList();
	    }).catch(err => {
	        this.$Message.error(err.msg);
	    });
    },
    // 判断是否选中
    sortData() {
      if (this.selectEquipsIds.length) {
        this.tableList.forEach(ele => {
          if (this.selectEquipsIds.includes(ele.id)) ele._checked = true;
        });
      }
    },
    // 选中一行
    TableSelectRow(selection, row) {
      if (!this.selectEquipsIds.includes(row.id)) {
        this.selectEquipsIds.push(row.id);
        this.selectEquips.push(row);
      }
    },
    // 取消选中一行
    TableSelectCancelRow(selection, row) {
      var _index = this.selectEquipsIds.indexOf(row.id);
      if (_index != -1) {
        this.selectEquipsIds.splice(_index, 1);
        this.selectEquips.splice(_index, 1);
      }
    },
    // 选中所有
    selectAll() {
      for (let i = this.tableList.length - 1; i >= 0; i--) {
        this.TableSelectRow(null, this.tableList[i]);
      }
    },
    // 取消选中所有
    cancelAll() {
      for (let i = this.tableList.length - 1; i >= 0; i--) {
        this.TableSelectCancelRow(null, this.tableList[i]);
      }
    },
    handleSelectAll() {
      this.$refs.table.selectAll(false);
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.getList();
    },
    // 门店分类
    goodsCategory() {
      cascaderList(1).then(res => {
        this.treeSelect = res.data;
      }).catch(res => {
        this.$Message.error(res.msg);
      });
    },
    // 列表
    getList() {
      this.loading = true;
	  if (this.region == -1) {
		  this.formValidate.is_region = 0;
	  }
	  if (this.region > 0) {
		  this.formValidate.region_id = this.region;
	  }
      storeListApi(this.formValidate).then(res => {
        this.tableList = res.data.list;
        this.total = res.data.count;
        this.sortData();
        this.loading = false;
      }).catch(err => {
        this.loading = false;
        this.$Message.error(err.msg);
      });
    },
    ok() {
      const images = [];
      this.selectEquips.forEach(function(item) {
        const imageObject = {
          image: item.image,
          product_id: item.id,
          store_name: item.store_name,
          temp_id: item.temp_id
        };
        images.push(imageObject);
      });
      if (images.length > 0) {
        this.$emit('getStoreId', this.selectEquips);
      } else {
        this.$Message.warning('请先选择商品');
      }
    },
    // 表格搜索
    userSearchs() {
      this.formValidate.page = 1;
      this.getList();
    }
  }
};
</script>

<style scoped lang="stylus">
.store /deep/.ivu-table-header thead tr th{
  padding: 8px 5px;
}
/deep/.ivu-table th, /deep/.ivu-table td{
	padding: 0 5px 0 0;
}
/deep/.ivu-table td:nth-of-type(1){
	padding-left: 16px !important;
}
/deep/.ivu-radio-wrapper{
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

.tabform {
  >>> .ivu-form-item {
    margin-bottom: 16px !important;
  }
}

.btn {
  margin-top: 20px;
  float: right;
}

.goodList {

}
.mr-20{
  margin-right:10px;
}
</style>
