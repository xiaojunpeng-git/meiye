<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form inline :label-width="80" @submit.native.prevent>
        <FormItem label="职位"><Input v-model="tableFrom.name" placeholder="请输入职位名称" /></FormItem>
        <Button type="primary" @click="search">查询</Button>
        <Button type="primary" icon="md-add" style="margin-left:14px" @click="add">添加</Button>
      </Form>
      <Table :columns="columns" :data="tableList" :loading="loading" no-data-text="暂无职位">
        <template slot="status" slot-scope="{ row }"><i-switch v-model="row.status" :true-value="1" :false-value="0" size="large" @on-change="changeStatus(row)"><span slot="open">开启</span><span slot="close">关闭</span></i-switch></template>
        <template slot="salary" slot-scope="{ row }"><a @click="openRules(row)">{{ independent ? '设置工资规则' : (row.levels || '设置') }}</a></template>
        <template slot="categories" slot-scope="{ row }"><a @click="openRules(row)">{{ row.cates || '设置' }}</a></template>
        <template slot="action" slot-scope="{ row, index }"><a @click="edit(row)">编辑</a><a style="margin-left:20px" @click="remove(row,index)">删除</a></template>
      </Table>
      <div class="acea-row row-right page"><Page :total="total" :current="tableFrom.page" :page-size="20" show-total @on-change="pageChange" /></div>
    </Card>
    <Modal v-model="rulesOpen" :title="independent ? '职位工资规则' : '职级配置'" footer-hide scrollable width="1200"><position-level ref="positionLevel" @getList="getList" /></Modal>
  </div>
</template>
<script>
import { positionList, editPosition, positionSetStatus } from '@/api/position';
import positionLevel from '@/components/positionLevel';
/** 职位与工资规则共用原后端入口；只按后端能力切换独立职位模型的标签。 */
export default {
  name: 'PositionSalaryConfiguration', components: { positionLevel },
  data() { return { independent: false, loading: false, total: 0, tableList: [], rulesOpen: false, tableFrom: { page: 1, limit: 20, name: '' } }; },
  computed: {
    columns() { return [
      { title: 'ID', key: 'id', width: 90 }, { title: '职位', key: 'name', minWidth: 150 },
      { title: this.independent ? '工资规则' : '职级配置', slot: 'salary', minWidth: 150 },
      { title: '品项配置', slot: 'categories', minWidth: 200 }, { title: '状态', slot: 'status', width: 110 },
      { title: '操作', slot: 'action', width: 130, fixed: 'right' }
    ]; }
  },
  created() { this.getList(); },
  methods: {
    search() { this.tableFrom.page = 1; this.getList(); },
    pageChange(page) { this.tableFrom.page = page; this.getList(); },
    async getList() {
      this.loading = true;
      try { const { data } = await positionList(this.tableFrom); this.tableList = data.list; this.total = data.count; this.independent = data.independent_positions === true; }
      catch (error) { this.$Message.error(error.msg || '职位读取失败'); }
      finally { this.loading = false; }
    },
    add() { this.$modalForm(editPosition({})).then(this.getList); },
    edit(row) { this.$modalForm(editPosition({ id: row.id })).then(this.getList); },
    async changeStatus(row) {
      try { await positionSetStatus({ id: row.id, status: row.status }); }
      catch (error) { row.status = row.status ? 0 : 1; this.$Message.error(error.msg || '修改失败'); }
    },
    openRules(row) { this.$refs.positionLevel.tableFrom = { page: 1, limit: 20, name: '', position_id: row.id }; this.$refs.positionLevel.getList(); this.rulesOpen = true; },
    remove(row, index) { this.$modalSure({ title: '删除职位', num: index, url: 'position/delPosition/' + row.id, method: 'PUT', ids: '' }).then(this.getList).catch(error => { if (error && error.msg) this.$Message.error(error.msg); }); }
  }
};
</script>
