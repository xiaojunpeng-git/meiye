<template>
  <div>
    <Form inline :label-width="80" @submit.native.prevent>
      <FormItem v-if="!independent" label="职级"><Input v-model="tableFrom.name" placeholder="请输入职级名称" /></FormItem>
      <Button v-if="!independent" type="primary" @click="search">查询</Button>
      <Button type="primary" icon="md-add" @click="add">添加工资规则</Button>
    </Form>
    <p v-if="independent" class="rule-note">来源规则只读继承；复制配置后保存为本职位规则，其他职位不受影响。</p>
    <Table :columns="columns" :data="tableList" :loading="loading" no-data-text="暂无工资规则">
      <template slot="status" slot-scope="{ row }">
        <span v-if="row.inherited">{{ row.status ? '继承启用' : '继承停用' }}</span>
        <i-switch v-else v-model="row.status" :true-value="1" :false-value="0" size="large" @on-change="changeStatus(row)"><span slot="open">可用</span><span slot="close">禁用</span></i-switch>
      </template>
      <template slot="action" slot-scope="{ row, index }">
        <a @click="edit(row)">{{ row.inherited ? '复制配置' : '编辑' }}</a>
        <a v-if="!row.inherited" style="margin-left:20px" @click="remove(row,index)">删除</a>
      </template>
    </Table>
    <div class="acea-row row-right page"><Page :total="total" :current="tableFrom.page" :page-size="20" show-total @on-change="pageChange" /></div>
  </div>
</template>
<script>
import { positionLevelList, editPositionLevel, positionLevelSetStatus } from '@/api/position';
/** 工资规则弹窗只负责展示和操作；继承判定与独立职位能力由后端提供。 */
export default {
  name: 'PositionSalaryRules',
  data() { return { independent: false, loading: false, total: 0, tableList: [], tableFrom: { page: 1, limit: 20, name: '', position_id: 0 } }; },
  computed: {
    columns() {
      const columns = [
        { title: 'ID', key: 'id', width: 75 }, { title: '类型', key: 'type_label', minWidth: 100 },
        { title: '业绩取值范围', key: 'range_type', minWidth: 120 }, { title: '业绩区间', key: 'range', minWidth: 150 },
        { title: '品项分类', key: 'cates', minWidth: 160 }
      ];
      if (!this.independent) columns.push({ title: '职级', key: 'position_level_label', minWidth: 100 });
      return columns.concat([{ title: '提成比例', key: 'commission', width: 100 }, { title: '状态', slot: 'status', width: 110 }, { title: '操作', slot: 'action', width: 150 }]);
    }
  },
  methods: {
    search() { this.tableFrom.page = 1; this.getList(); },
    pageChange(page) { this.tableFrom.page = page; this.getList(); },
    async getList() {
      this.loading = true;
      try { const { data } = await positionLevelList(this.tableFrom); this.tableList = data.list; this.total = data.count; this.independent = data.independent_positions === true; }
      catch (error) { this.$Message.error(error.msg || '工资规则读取失败'); }
      finally { this.loading = false; }
    },
    saved() { this.getList(); this.$emit('getList'); },
    add() { this.$modalForm(editPositionLevel({ position_id: this.tableFrom.position_id })).then(this.saved); },
    edit(row) { this.$modalForm(editPositionLevel({ id: row.id, position_id: this.tableFrom.position_id })).then(this.saved); },
    async changeStatus(row) {
      try { await positionLevelSetStatus({ id: row.id, status: row.status, expected_version: row.version }); this.saved(); }
      catch (error) { row.status = row.status ? 0 : 1; this.$Message.error(error.msg || '修改失败'); }
    },
    remove(row, index) {
      this.$modalSure({ title: '删除工资规则', num: index, url: 'position/delPositionLevel/' + row.id + (this.independent ? '?expected_version=' + row.version : ''), method: 'PUT', ids: '' }).then(this.saved).catch(error => { if (error && error.msg) this.$Message.error(error.msg); });
    }
  }
};
</script>
<style scoped>
.rule-note { margin: 10px 0; color: #64748b; }
</style>
