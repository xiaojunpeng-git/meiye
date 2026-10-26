<template>
  <Card :bordered="false" dis-hover>
    <Tabs v-model="tableParams.type" @on-click="tabsChange">
      <TabPane label="连续签到奖励" name="0"></TabPane>
      <TabPane label="累积签到奖励" name="1"></TabPane>
    </Tabs>
    <Button type="primary" @click="add"
      >添加{{ tableParams.type === '0' ? '连续' : '累积' }}签到奖励</Button
    >
    <Table :columns="columns" :data="rewards" class="mt20">
      <template slot-scope="{ row }" slot="type">
        {{ tableParams.type === '0' ? '连续' : '累积' }}签到{{ row.days }}天奖励
      </template>
      <template slot-scope="{ row, index }" slot="action">
        <a @click="edit(row)">编辑</a>
        <Divider type="vertical" />
        <a
          @click="
            remove(
              row,
              `删除${row.type == '0' ? '连续' : '累积'}签到奖励`,
              index
            )
          "
          >删除</a
        >
      </template>
    </Table>
    <div class="acea-row row-right page">
      <Page
        :total="total"
        show-elevator
        show-total
        @on-change="pageChange"
        :page-size="tableParams.limit"
      />
    </div>
  </Card>
</template>

<script>
import { signRewards, addRewards, editRewards } from '@/api/marketing';

export default {
  data() {
    return {
      columns: [
        {
          title: '类型',
          slot: 'type',
        },
        {
          title: '天数',
          key: 'days',
        },
        {
          title: '奖励积分',
          key: 'point',
        },
        {
          title: '奖励经验',
          key: 'exp',
        },
        {
          title: '操作',
          slot: 'action',
        },
      ],
      rewards: [],
      total: 0,
      tableParams: {
        type: '0',
        page: 1,
        limit: 15,
      },
    };
  },
  created() {
    this.signRewards();
  },
  methods: {
    // 获取签到奖励
    signRewards() {
      signRewards(this.tableParams).then((res) => {
        this.rewards = res.data.list;
        this.total = res.data.count;
      });
    },
    // 切换类型
    tabsChange() {
      this.tableParams.page = 1;
      this.signRewards();
    },
    // 分页
    pageChange(page) {
      this.tableParams.page = page;
      this.signRewards();
    },
    // 添加
    add() {
      this.$modalForm(addRewards({ type: this.tableParams.type })).then(() =>
        this.signRewards()
      );
    },
    // 编辑
    edit(row) {
      this.$modalForm(editRewards(row.id)).then(() => this.signRewards());
    },
    // 删除
    remove(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `/setting/sign/del_rewards/${row.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.signRewards();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
  },
};
</script>