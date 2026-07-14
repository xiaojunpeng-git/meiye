<template>
  <!-- 设置-系统表单 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 相关操作 -->
      <Row type="flex">
        <Col v-bind="grid">
          <Button type="primary" @click="add">添加表单</Button>
        </Col>
      </Row>
      <!-- 表单列表 -->
      <Table :columns="columns1" :data="formList" ref="table" class="mt25"
             :loading="loading" highlight-row
             no-userFrom-text="暂无数据"
             no-filtered-userFrom-text="暂无筛选结果">
        <template slot-scope="{ row, index }" slot="action">
          <a v-auth="['setting-system_form-data']" @click="details(row.id)">查看</a>
          <Divider type="vertical" />
          <a @click="edit(row.id)">编辑</a>
          <Divider type="vertical" />
          <a @click="del(row,'删除分组',index)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page :total="total" show-elevator show-total @on-change="pageChange"
              :page-size="listFrom.limit"/>
      </div>
    </Card>
  </div>
</template>
<script>
import { mapState } from 'vuex';
import Setting from '@/setting'
import {systemFormList} from "@/api/setting";
export default {
  name: "form_list",
  data() {
    return {
      roterPre: Setting.roterPre,
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24
      },
      loading: false,
      columns1: [
        {
          title: '页面ID',
          key: 'id',
          width: 80
        },
        {
          title: '模板名称',
          key: 'name',
          minWidth: 150
        },
        {
          title: '添加时间',
          key: 'add_time',
          minWidth: 150
        },
        {
          title: '更新时间',
          key: 'update_time',
          minWidth: 150
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          width: 150,
        }
      ],
      listFrom: {
        page: 1,
        limit: 15
      },
      formList: [],
      total:0
    }
  },
  computed: {
    ...mapState('admin/layout', [
      'isMobile'
    ]),
    labelWidth () {
      return this.isMobile ? undefined : 75;
    },
    labelPosition () {
      return this.isMobile ? 'top' : 'left';
    }
  },
  created () {
    this.getList();
  },
  methods:{
    // 添加
    add () {
      this.$router.push({
        path: `${this.roterPre}/setting/system/create`,
        query: {
          id:0
        }
      })
    },

    // 分组列表
    getList () {
      this.loading = true;
      systemFormList(this.listFrom).then(async res => {
        let data = res.data;
        this.formList = data.list;
        this.total = data.count;
        this.loading = false;
      }).catch(res => {
        this.loading = false;
        this.$Message.error(res.msg);
      })
    },
    pageChange (index) {
      this.listFrom.page = index;
      this.getList();
    },
    //修改
    edit(id){
      this.$router.push({
        path: `${this.roterPre}/setting/system/create`,
        query: {
          id:id
        }
      })
    },
    // 删除
    del (row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/system/form/del/${row.id}`,
        method: 'DELETE',
        ids: ''
      };
      this.$modalSure(delfromData).then((res) => {
        this.$Message.success(res.msg);
        this.formList.splice(num, 1);
        if (!this.formList.length) {
          this.listFrom.page =
              this.listFrom.page == 1 ? 1 : this.listFrom.page - 1;
        }
        this.getList();
      }).catch(res => {
        this.$Message.error(res.msg);
      });
    },
    details(id) {
      this.$router.push({
        path: `${this.roterPre}/setting/system_form/data`,
        query: { id }
      });
    }
  }
}
</script>