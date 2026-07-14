<template>
  <!-- 社区话题列表 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="tableFrom"
          :model="tableFrom"
          :label-width="labelWidth"
          :label-position="labelPosition"
          inline
          @submit.native.prevent
        >
          <FormItem label="话题名称：" label-for="name">
            <Input
              placeholder="请输入话题名称"
              v-model="tableFrom.name"
              class="input-add"
            />
          </FormItem>
          <FormItem label="推荐状态：">
            <Select
              v-model="tableFrom.is_recommend"
              clearable
              class="input-add"
              @on-change="tableSearchs"
            >
              <Option value="1">开启</Option>
              <Option value="0">关闭</Option>
            </Select>
          </FormItem>
          <FormItem label="显示状态：">
            <Select
              v-model="tableFrom.status"
              clearable
              class="input-add mr14"
              @on-change="tableSearchs"
            >
              <Option value="1">开启</Option>
              <Option value="0">关闭</Option>
            </Select>
            <Button type="primary" @click="tableSearchs" class="mr14"
              >查询</Button
            >
            <Button @click="reset">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Button type="primary" @click="add" class="mr10">添加社区话题</Button>
      <Table
        :columns="columns"
        :data="tableList"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
        class="ivu-mt"
      >
        <template slot-scope="{ row }" slot="is_recommend">
          <i-switch
            v-model="row.is_recommend"
            :true-value="1"
            :false-value="0"
            @on-change="onchangeIsRecommend(row)"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row }" slot="status">
          <i-switch
            v-model="row.status"
            :true-value="1"
            :false-value="0"
            @on-change="onchangeIsShow(row)"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="del(row, '删除社区话题', index)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="tableFrom.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="tableFrom.limit"
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  topicListApi,
  topicSave,
  topicSetHotApi,
  topicSetStatusApi,
} from '@/api/community';
export default {
  name: 'topic',
  data() {
    return {
      tableFrom: {
        page: 1,
        limit: 15,
        name: '',
        is_recommend: '',
        status: '',
      },
      loading: false,
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '话题名称',
          key: 'name',
          minWidth: 200,
        },
        {
          title: '文章数',
          key: 'community_num',
          minWidth: 100,
        },
        {
          title: '创建时间',
          key: 'add_time',
          minWidth: 200,
        },
        {
          title: '是否推荐',
          slot: 'is_recommend',
          minWidth: 150,
        },
        {
          title: '是否显示',
          slot: 'status',
          minWidth: 150,
        },
        {
          title: '排序',
          key: 'sort',
          minWidth: 80,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          width: 100,
        },
      ],
      tableList: [],
      grid: {
        xl: 7,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24,
      },
      total: 0,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.getList();
  },
  methods: {
    // 列表
    getList() {
      this.loading = true;
      topicListApi(this.tableFrom)
        .then((res) => {
          let data = res.data;
          this.tableList = data.list;
          this.total = res.data.count;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    // 表格搜索
    tableSearchs() {
      this.tableFrom.page = 1;
      this.getList();
    },
    // 重置
    reset() {
      this.tableFrom.name = '';
      this.tableFrom.is_recommend = '';
      this.tableFrom.status = '';
      this.tableFrom.page = 1;
      this.getList();
    },
    // 添加
    add() {
      this.$modalForm(topicSave(0)).then(() => this.getList());
    },
    // 编辑
    edit(row) {
      this.$modalForm(topicSave(row.id)).then(() => this.getList());
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/community/topic/del/${row.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableList.splice(num, 1);
          if (!this.tableList.length) {
            this.tableFrom.page =
              this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
          }
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.tableFrom.page = index;
      this.getList();
    },
    // 修改是否推荐
    onchangeIsRecommend(row) {
      let data = {
        id: row.id,
        hot: row.is_recommend,
      };
      topicSetHotApi(data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 修改是否显示
    onchangeIsShow(row) {
      let data = {
        id: row.id,
        status: row.status,
      };
      topicSetStatusApi(data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.line2 {
  max-height: 36px;
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
