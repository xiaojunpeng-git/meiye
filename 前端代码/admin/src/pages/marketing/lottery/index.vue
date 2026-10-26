<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form
        ref="tableFrom"
        inline
        :model="tableFrom"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <FormItem label="活动状态：" clearable>
          <Select
            class="input-add"
            v-model="tableFrom.start"
            placeholder="请选择"
            clearable
            @on-change="userSearch"
          >
            <Option value="0">未开始</Option>
            <Option value="1">进行中</Option>
            <Option value="-1">已结束</Option>
          </Select>
        </FormItem>
        <FormItem label="开启状态：">
          <Select
            class="input-add"
            placeholder="请选择"
            v-model="tableFrom.status"
            clearable
            @on-change="userSearch"
          >
            <Option value="1">开启</Option>
            <Option value="0">关闭</Option>
          </Select>
        </FormItem>
        <FormItem label="活动时间：">
          <DatePicker
            :editable="false"
            @on-change="onTimeChange"
            :value="timeVal"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-start"
            placeholder="请选择"
            class="input-add"
            :options="options"
          ></DatePicker>
        </FormItem>
        <FormItem label="活动信息：">
          <Input
            class="input-add"
            placeholder="请输入活动名称/ID"
            v-model="tableFrom.keyword"
          />
          <Button type="primary" @click="userSearch" class="ml-14">查询</Button>
          <Button class="ml-14" @click="reset">重置</Button>
        </FormItem>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="new_tab">
        <!-- Tab栏切换 -->
        <Tabs @on-click="onTabsClick">
          <TabPane
            v-for="item in headTab"
            :key="item.type"
            :label="item.name"
            :name="item.type"
          />
        </Tabs>
      </div>
      <Button
        v-auth="['admin-marketing-lottery-create']"
        type="primary"
        @click="add"
        >添加抽奖活动</Button
      >
      <Table
        class="ivu-mt"
        :columns="columns1"
        :data="tableList"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="is_fail">
          <Icon
            type="md-checkmark"
            v-if="row.is_fail === 1"
            color="#0092DC"
            size="14"
          />
          <Icon type="md-close" v-else color="#ed5565" size="14" />
        </template>
        <template slot-scope="{ row, index }" slot="image">
          <viewer>
            <div class="tabBox_img">
              <img v-lazy="row.image" />
            </div>
          </viewer>
        </template>

        <template slot-scope="{ row, index }" slot="bargain_min_price">
          <span>{{ row.bargain_min_price }}~{{ row.bargain_max_price }}</span>
        </template>
        <template slot-scope="{ row, index }" slot="status">
          {{ status == 0 ? '开启' : '关闭' }}
        </template>
        <template slot-scope="{ row, index }" slot="time">
          <div>开始：{{ row.start_time || '--' }}</div>
          <div>结束：{{ row.end_time || '--' }}</div>
        </template>
        <template slot-scope="{ row, index }" slot="status">
          <i-switch
            v-model="row.status"
            :value="row.status"
            :true-value="1"
            :false-value="0"
            @on-change="onchangeIsShow(row)"
          >
            <span slot="open">是</span>
            <span slot="close">否</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="getRecording(row)">中奖记录</a>
          <Divider type="vertical" />
          <Dropdown @on-click="changeMenu(row, $event, index)">
            <a href="javascript:void(0)">
              更多
              <Icon type="ios-arrow-down"></Icon>
            </a>
            <DropdownMenu slot="list">
              <DropdownItem name="1" v-if="tableFrom.factor == 1">
                <span
                  class="copy copy-data"
                  v-clipboard="copyLink(row)"
                  v-clipboard:success="handleCopySuccess"
                  >复制链接</span
                >
              </DropdownItem>
              <DropdownItem name="2">删除活动</DropdownItem>
            </DropdownMenu>
          </Dropdown>
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
import { lotteryListApi, lotteryStatusApi } from '@/api/lottery';
import { formatDate } from '@/utils/validate';
import timeOptions from '@/utils/timeOptions';
import Setting from '@/setting';
export default {
  name: 'storeBargain',
  filters: {
    formatDate(time) {
      if (time !== 0) {
        let date = new Date(time * 1000);
        return formatDate(date, 'yyyy-MM-dd hh:mm');
      }
    },
  },
  data() {
    return {
      options: timeOptions,
      roterPre: Setting.roterPre,
      loading: false,
      columns1: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '活动名称',
          key: 'name',
          minWidth: 90,
        },
        {
          title: '抽奖人数',
          key: 'records_total_user',
          minWidth: 100,
        },
        {
          title: '中奖人数',
          key: 'records_wins_user',
          minWidth: 100,
        },
        {
          title: '抽奖次数',
          key: 'records_total_num',
          minWidth: 100,
        },
        {
          title: '中奖次数',
          key: 'records_wins_num',
          minWidth: 100,
        },
        {
          title: '活动状态',
          key: 'lottery_status',
          minWidth: 100,
        },
        {
          title: '是否开启',
          slot: 'status',
          minWidth: 100,
        },
        {
          title: '活动时间',
          slot: 'time',
          minWidth: 160,
        },
        {
          title: '操作',
          slot: 'action',
          minWidth: 160,
        },
      ],
      tableList: [],
      tableFrom: {
        factor: '1',
        start: '',
        status: '',
        time: '',
        keyword: '',
        page: 1,
        limit: 15,
      },
      timeVal: [],
      headTab: [
        {
          name: '积分抽奖',
          type: '1',
        },
        {
          name: '订单支付',
          type: '3',
        },
        {
          name: '订单评价',
          type: '4',
        },
        {
          name: '关注公众号',
          type: '5',
        },
      ],
      total: 0,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'left';
    },
  },
  created() {
    this.getList();
  },
  methods: {
    // 添加
    add() {
      this.$router.push({
        path: `${this.roterPre}/marketing/lottery/create`,
      });
    },
    // 编辑
    edit(row) {
      this.$router.push({
        path: `${this.roterPre}/marketing/lottery/create/${row.id}`,
      });
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `lottery/del/${row.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableList.splice(num, 1);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    //查看抽奖记录
    getRecording(row) {
      this.$router.push({
        path: `${this.roterPre}/marketing/lottery/recording_list`,
        query: {
          id: row.id,
        },
      });
    },
    // 列表
    getList() {
      this.loading = true;
      lotteryListApi(this.tableFrom)
        .then(async (res) => {
          let data = res.data;
          this.tableList = data.list;
          this.total = data.count;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.tableFrom.page = index;
      this.getList();
    },
    // 表格搜索
    userSearch() {
      this.tableFrom.page = 1;
      this.getList();
    },
    // 修改是否显示
    onchangeIsShow(row) {
      let data = {
        id: row.id,
        status: row.status,
      };
      lotteryStatusApi(data)
        .then(async (res) => {
          this.$Message.success(res.msg);
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
          this.getList();
        });
    },
    // 活动时间
    onTimeChange(date) {
      this.timeVal = date;
      this.tableFrom.time = date.some((item) => !item) ? '' : date.join('-');
      this.userSearch();
    },
    onTabsClick(name) {
      this.tableFrom.factor = name;
      this.userSearch();
    },
    // 更多操作
    changeMenu(row, name, index) {
      switch (name) {
        case '1':
          break;
        case '2':
          this.del(row, '删除抽奖', index);
          break;
      }
    },
    // 重置
    reset() {
      const factor = this.tableFrom.factor;
      this.tableFrom = {
        factor,
        start: '',
        status: '',
        time: '',
        keyword: '',
        page: 1,
        limit: 15,
      };
      this.timeVal = [];
      this.getList();
    },
    copyLink(row) {
      return `${window.location.origin}/pages/goods/lottery/grids/index?type=${row.factor}&lottery_id=${row.id}`;
    },
    handleCopySuccess() {
      this.$Message.success('复制成功');
    },
  },
};
</script>

<style scoped lang="stylus">
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

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
</style>
