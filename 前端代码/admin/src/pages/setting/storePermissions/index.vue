<template>
  <section class="store-permission-page">
    <Card dis-hover>
      <p slot="title">门店端权限规则</p>
      <p>维护门店端功能名称、显示层级、排序和启停；岗位仍在“岗位策略 → 门店端”勾选授权。</p>
      <div class="rule-toolbar">
        <Input v-model.trim="keyword" placeholder="搜索功能名称或权限代码" style="width:280px" clearable />
        <Button :disabled="loading || saving" @click="load">刷新</Button>
        <Button :disabled="loading || saving" @click="collapsedIds = []">展开全部</Button>
        <Button :disabled="loading || saving" @click="collapseAll">收起全部</Button>
        <Button type="primary" :loading="saving" :disabled="loading || !!error" @click="save">保存规则</Button>
      </div>
      <Alert v-if="error" type="error" show-icon>{{ error }}</Alert>
      <p class="rule-hint">停用功能会关闭已有岗位的对应入口和操作，保留原勾选；重新启用后恢复。显示层级不会改变功能本身的业务权限关系。菜单入口的启停控制导航展示，岗位仍按原业务功能授权。</p>
    </Card>
    <div class="rule-scroll">
      <table class="rule-table">
        <thead><tr><th>功能名称</th><th>权限代码</th><th>显示上级</th><th>排序</th><th>状态</th></tr></thead>
        <tbody>
          <template v-for="entry in visibleRows">
          <tr :key="entry.rule.id" :class="{ 'rule-row--root': entry.depth === 0, 'rule-category': entry.rule.grantable === false && !entry.rule.navigation_key }" :data-rule-id="entry.rule.id" :data-depth="entry.depth">
            <td>
              <div class="rule-name" :style="{ paddingLeft: entry.depth * 24 + 'px' }">
                <button v-if="entry.hasChildren" type="button" class="rule-toggle" :aria-label="(isExpanded(entry.rule.id) ? '收起' : '展开') + entry.rule.title" :aria-expanded="isExpanded(entry.rule.id) ? 'true' : 'false'" @click="toggle(entry.rule.id)"><span :class="{ 'is-expanded': isExpanded(entry.rule.id) }">›</span></button>
                <span v-else class="rule-leaf" aria-hidden="true">·</span>
                <Input v-model.trim="entry.rule.title" :maxlength="30" :disabled="saving" />
              </div>
            </td>
            <td>{{ entry.rule.navigation_key ? '菜单入口：' + entry.rule.navigation_key : entry.rule.grantable === false ? '菜单大类' : entry.rule.feature_code }}</td>
            <td><Select v-model="entry.rule.parent_id" :disabled="saving || (entry.rule.grantable === false && !entry.rule.navigation_key)" filterable><Option v-if="entry.rule.grantable === false && !entry.rule.navigation_key" :value="0">顶级</Option><Option v-for="parent in parentOptions(entry.rule.id)" :key="parent.id" :value="parent.id">{{ parent.title }}</Option></Select></td>
            <td><InputNumber v-model="entry.rule.sort" :min="0" :max="99999" :precision="0" :disabled="saving" /></td>
            <td><i-switch v-model="entry.rule.enabled" :true-value="1" :false-value="0" :disabled="saving"><span slot="open">开</span><span slot="close">关</span></i-switch></td>
          </tr>
          </template>
          <tr v-if="!visibleRows.length"><td colspan="5">{{ loading ? '加载中…' : '没有匹配的规则' }}</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
<script>
import request from '@/plugins/request';

// 功能身份及接口由源码登记；这里只提交完整元数据，不能通过编辑名称创建任意业务接口。
export default {
  name: 'StorePermissions',
  data() { return { rules: [], revision: 0, keyword: '', collapsedIds: [], loading: false, saving: false, error: '' }; },
  computed: {
    visibleRows() {
      // 展示投影引用完整文档中的原节点；折叠、搜索和排序都不能改变保存内容。
      const word = this.keyword.toLowerCase();
      const result = [];
      const byParent = new Map();
      this.rules.forEach(rule => {
        if (!byParent.has(rule.parent_id)) byParent.set(rule.parent_id, []);
        byParent.get(rule.parent_id).push(rule);
      });
      byParent.forEach(children => children.sort((a, b) => a.sort - b.sort || a.id - b.id));
      const matches = rule => (rule.title + rule.feature_code + (rule.navigation_key || '')).toLowerCase().includes(word);
      const containsMatch = rule => matches(rule) || (byParent.get(rule.id) || []).some(containsMatch);
      const walk = (parent, depth, parentMatched) => {
        (byParent.get(parent) || []).forEach(rule => {
          if (word && !parentMatched && !containsMatch(rule)) return;
          const children = byParent.get(rule.id) || [];
          result.push({ rule, depth, hasChildren: children.length > 0 });
          if (word || !this.collapsedIds.includes(rule.id)) walk(rule.id, depth + 1, parentMatched || !!word && matches(rule));
        });
      };
      walk(0, 0, false);
      return result;
    }
  },
  created() { this.load(); },
  methods: {
    isExpanded(id) { return !!this.keyword || !this.collapsedIds.includes(id); },
    toggle(id) {
      if (this.keyword) return;
      this.collapsedIds = this.collapsedIds.includes(id) ? this.collapsedIds.filter(value => value !== id) : [...this.collapsedIds, id];
    },
    collapseAll() { this.collapsedIds = this.rules.map(rule => rule.id); },
    // 排除自身和所有显示后代，服务端仍对完整树做循环检查。
    parentOptions(id) {
      const excluded = new Set([id]);
      let changed = true;
      while (changed) {
        changed = false;
        this.rules.forEach(row => { if (excluded.has(row.parent_id) && !excluded.has(row.id)) { excluded.add(row.id); changed = true; } });
      }
      return this.rules.filter(row => !excluded.has(row.id) && !row.navigation_key);
    },
    async load() {
      this.loading = true; this.error = '';
      try { const response = await request({ url: '/setting/store-permissions', method: 'get' }); this.rules = response.data.rules; this.revision = response.data.revision; this.collapseAll(); }
      catch (error) { this.error = error.msg || error.message || '权限规则加载失败'; }
      finally { this.loading = false; }
    },
    async save() {
      if (this.saving) return;
      this.saving = true;
      try {
        const response = await request({ url: '/setting/store-permissions', method: 'post', data: { revision: this.revision, rules: this.rules } });
        this.rules = response.data.rules; this.revision = response.data.revision; this.$Message.success('保存成功');
      } catch (error) { this.$Message.error(error.msg || error.message || '保存失败，请刷新后重试'); }
      finally { this.saving = false; }
    }
  }
};
</script>
<style scoped>
.store-permission-page{height:calc(100vh - 116px);display:flex;flex-direction:column;gap:12px;min-height:0}
.rule-toolbar{display:flex;gap:10px;margin:14px 0}.rule-hint{color:#68758a;font-size:12px}
.rule-scroll{flex:1;min-height:0;overflow:auto;background:#fff}.rule-table{width:100%;min-width:960px;border-collapse:collapse}
.rule-table th{position:sticky;top:0;background:#f4f7fb;z-index:1;text-align:left}.rule-table th,.rule-table td{padding:12px;border-bottom:1px solid #edf0f4}
.rule-table td:first-child{width:280px;min-width:240px}
.rule-category td{background:#edf3fb;color:#315575;font-weight:600;border-top:1px solid #dce5f1}.rule-name{display:flex;align-items:center;gap:6px}.rule-name>.ivu-input-wrapper{flex:1;min-width:100px}
.rule-toggle,.rule-leaf{width:22px;flex:0 0 22px;display:inline-flex;align-items:center;justify-content:center}
.rule-toggle{height:26px;padding:0;border:0;border-radius:4px;background:transparent;color:#52657e;cursor:pointer}
.rule-toggle:hover{background:#e5edf8}.rule-toggle:focus-visible{outline:2px solid #2d8cf0}
.rule-toggle span{font-size:24px;line-height:1;transition:transform .15s}.rule-toggle .is-expanded{transform:rotate(90deg)}
.rule-leaf{color:#9aa9bd;font-size:20px}.rule-row--root{background:#fafcff}.rule-table td:nth-child(3){width:220px}
</style>
