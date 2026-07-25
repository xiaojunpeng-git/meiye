<template>
  <div class="fcpe">
    <div class="fcpe-tabs" role="tablist">
      <button
        v-for="tab in visibleTabs"
        :key="tab.key"
        type="button"
        role="tab"
        class="fcpe-tab"
        :class="{ active: current === tab.key, locked: tab.locked || !isEntryOn(tab.key) }"
        :aria-selected="current === tab.key ? 'true' : 'false'"
        :disabled="disabled"
        @click="current = tab.key"
      >
        <span class="fcpe-tab-label">{{ tab.label }}</span>
        <span class="fcpe-tab-count">{{ counts[tab.key] || 0 }}</span>
      </button>
    </div>

    <div v-if="showEntrySwitch" class="fcpe-entry">
      <div class="fcpe-entry-main">
        <span class="fcpe-entry-label">{{ currentLabel }}入口</span>
        <label
          class="fcpe-entry-switch"
          :class="{
            'is-on': isEntryOn(current),
            'is-disabled': disabled || isChannelHardLocked(current),
          }"
        >
          <input
            class="fcpe-entry-input"
            type="checkbox"
            :checked="isEntryOn(current)"
            :disabled="disabled || isChannelHardLocked(current)"
            @change="onEntryToggle(current, $event.target.checked)"
          />
          <span class="fcpe-entry-track" aria-hidden="true"><span class="fcpe-entry-thumb" /></span>
          <span class="fcpe-entry-text">{{ isEntryOn(current) ? '开启' : '关闭' }}</span>
        </label>
      </div>
      <p class="fcpe-entry-hint">
        {{ isEntryOn(current) ? '入口已开启，可配置本端功能权限（可不勾选具体功能）。' : '入口关闭时，该端不可用，权限树已锁定。' }}
      </p>
    </div>

    <div v-if="showPlatformTip" class="fcpe-banner">
      平台后台权限只在总部后台生效，门店员工不能看到或使用。
    </div>

    <div class="fcpe-toolbar">
      <div class="fcpe-search">
        <input
          v-model.trim="keyword"
          type="search"
          :placeholder="'搜索' + currentLabel + '权限'"
          :disabled="disabled || isCurrentLocked"
          @keyup.enter.prevent
        />
      </div>
      <div class="fcpe-actions">
        <button type="button" class="fcpe-link" :disabled="disabled || isCurrentLocked" @click="expandAll(true)">展开</button>
        <button type="button" class="fcpe-link" :disabled="disabled || isCurrentLocked" @click="expandAll(false)">收起</button>
        <button type="button" class="fcpe-link" :disabled="disabled || isCurrentLocked" @click="selectAll(true)">全选</button>
        <button type="button" class="fcpe-link" :disabled="disabled || isCurrentLocked" @click="selectAll(false)">取消全选</button>
      </div>
      <div class="fcpe-selected">已选择 {{ counts[current] || 0 }} 项</div>
    </div>

    <div class="fcpe-tree-wrap" ref="treeWrap">
      <div v-if="isCurrentLocked" class="fcpe-locked">
        <p v-if="isChannelHardLocked(current)">平台后台权限只在总部后台生效，门店员工不能看到或使用。</p>
        <p v-else>该端入口已关闭。开启入口后，才可配置本端功能权限。</p>
      </div>
      <Tree
        v-else-if="treeReady"
        class="fcpe-tree"
        :key="current + '-' + treeEpoch"
        :data="displayTree"
        show-checkbox
        :ref="'tree_' + current"
        @on-check-change="onCheckChange"
      />
      <div v-else class="fcpe-locked"><p>权限菜单加载中…</p></div>
    </div>
  </div>
</template>

<script>
function cloneMenus(list) {
  return JSON.parse(JSON.stringify(list || []));
}

function markChecked(nodes, idSet) {
  (nodes || []).forEach((n) => {
    const id = Number(n.id);
    n.checked = idSet.has(id);
    n.selected = false;
    if (n.children && n.children.length) {
      markChecked(n.children, idSet);
    }
  });
}

function setExpand(nodes, expand) {
  (nodes || []).forEach((n) => {
    n.expand = !!expand;
    if (n.children && n.children.length) setExpand(n.children, expand);
  });
}

function setChecked(nodes, checked) {
  (nodes || []).forEach((n) => {
    n.checked = !!checked;
    if (n.children && n.children.length) setChecked(n.children, checked);
  });
}

function filterTree(nodes, keyword) {
  const kw = String(keyword || '').trim().toLowerCase();
  if (!kw) return cloneMenus(nodes);
  const walk = (list) => {
    const out = [];
    (list || []).forEach((n) => {
      const title = String(n.title || n.menu_name || n.label || '').toLowerCase();
      const children = walk(n.children || []);
      if (title.indexOf(kw) >= 0 || children.length) {
        const copy = Object.assign({}, n, { children });
        if (children.length) copy.expand = true;
        out.push(copy);
      }
    });
    return out;
  };
  return walk(nodes);
}

function collectIds(nodes, acc) {
  (nodes || []).forEach((n) => {
    if (n.checked) acc.push(Number(n.id));
    if (n.children && n.children.length) collectIds(n.children, acc);
  });
  return acc;
}

function countChecked(nodes) {
  let n = 0;
  const walk = (list) => {
    (list || []).forEach((item) => {
      if (item.checked) n += 1;
      if (item.children && item.children.length) walk(item.children);
    });
  };
  walk(nodes);
  return n;
}

function normalizeEntries(src) {
  const v = src || {};
  const pick = (shortKey, useKey) => {
    if (v[shortKey] != null) return Number(v[shortKey]) === 1 ? 1 : 0;
    if (v[useKey] != null) return Number(v[useKey]) === 1 ? 1 : 0;
    return 0;
  };
  return {
    platform: pick('platform', 'use_platform'),
    store: pick('store', 'use_store'),
    cashier: pick('cashier', 'use_cashier'),
    mobile: pick('mobile', 'use_mobile'),
  };
}

export default {
  name: 'FourChannelPermissionEditor',
  props: {
    includePlatform: { type: Boolean, default: true },
    platformLocked: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** 是否展示四端入口开关；角色模板等场景可关闭 */
    showEntrySwitch: { type: Boolean, default: true },
    menus: {
      type: Object,
      default() {
        return {
          platform_menus: [],
          store_menus: [],
          cashier_menus: [],
          mobile_menus: [],
        };
      },
    },
    value: {
      type: Object,
      default() {
        return {
          platform: [],
          store: [],
          cashier: [],
          mobile: [],
        };
      },
    },
    entries: {
      type: Object,
      default() {
        return {
          platform: 0,
          store: 0,
          cashier: 0,
          mobile: 0,
        };
      },
    },
  },
  data() {
    return {
      current: 'store',
      keyword: '',
      treeEpoch: 0,
      treeReady: false,
      localEntries: {
        platform: 0,
        store: 0,
        cashier: 0,
        mobile: 0,
      },
      trees: {
        platform: [],
        store: [],
        cashier: [],
        mobile: [],
      },
    };
  },
  computed: {
    visibleTabs() {
      const tabs = [
        { key: 'platform', label: '平台后台', locked: this.platformLocked || !this.includePlatform },
        { key: 'store', label: '门店后台', locked: false },
        { key: 'cashier', label: '收银台', locked: false },
        { key: 'mobile', label: '手机端', locked: false },
      ];
      if (!this.includePlatform && this.platformLocked) return tabs;
      if (!this.includePlatform) return tabs.filter((t) => t.key !== 'platform');
      return tabs;
    },
    currentLabel() {
      const hit = this.visibleTabs.find((t) => t.key === this.current);
      return (hit && hit.label) || '';
    },
    isCurrentLocked() {
      if (this.isChannelHardLocked(this.current)) return true;
      if (this.showEntrySwitch && !this.isEntryOn(this.current)) return true;
      return false;
    },
    showPlatformTip() {
      return this.includePlatform && this.current === 'platform' && !this.isCurrentLocked;
    },
    counts() {
      return {
        platform: this.isEntryOn('platform') ? countChecked(this.trees.platform) : 0,
        store: this.isEntryOn('store') ? countChecked(this.trees.store) : 0,
        cashier: this.isEntryOn('cashier') ? countChecked(this.trees.cashier) : 0,
        mobile: this.isEntryOn('mobile') ? countChecked(this.trees.mobile) : 0,
      };
    },
    displayTree() {
      return filterTree(this.trees[this.current] || [], this.keyword);
    },
    hasPlatformSelected() {
      return (this.counts.platform || 0) > 0;
    },
  },
  watch: {
    menus: {
      deep: true,
      immediate: true,
      handler() {
        this.rebuildTrees();
      },
    },
    value: {
      deep: true,
      handler() {
        this.applyValue(true);
      },
    },
    entries: {
      deep: true,
      immediate: true,
      handler(v) {
        this.localEntries = normalizeEntries(v);
        this.applyEntryLocks(true);
      },
    },
    current(key) {
      this.keyword = '';
      this.treeReady = false;
      this.ensureChannelTree(key);
      this.$nextTick(() => {
        this.treeReady = true;
        this.treeEpoch += 1;
      });
    },
    includePlatform: {
      immediate: true,
      handler(v) {
        if (!v && this.current === 'platform') this.current = 'store';
        if (v && !this.visibleTabs.some((t) => t.key === this.current)) this.current = 'store';
      },
    },
  },
  methods: {
    isEntryOn(key) {
      if (!this.showEntrySwitch) return true;
      return Number(this.localEntries[key]) === 1;
    },
    isChannelHardLocked(key) {
      if (key !== 'platform') return false;
      return !!(this.platformLocked || !this.includePlatform);
    },
    rebuildTrees() {
      const m = this.menus || {};
      const hasAny = !!(
        (m.platform_menus && m.platform_menus.length)
        || (m.store_menus && m.store_menus.length)
        || (m.cashier_menus && m.cashier_menus.length)
        || (m.mobile_menus && m.mobile_menus.length)
        || (m.mall_menus && m.mall_menus.length)
      );
      this.treeReady = false;
      const empty = { platform: [], store: [], cashier: [], mobile: [] };
      this.trees = empty;
      ['store', 'cashier', 'mobile', 'platform'].forEach((key) => {
        if (key === 'platform' && this.current !== 'platform') {
          this.trees[key] = [];
          return;
        }
        if (key !== this.current && key !== 'store') {
          this.trees[key] = [];
          return;
        }
        this.trees[key] = this.cloneChannelMenus(key, m);
      });
      this.applyValue(true);
      this.applyEntryLocks(true);
      this.treeEpoch += 1;
      this.$nextTick(() => {
        this.ensureChannelTree(this.current);
        this.treeReady = hasAny;
        ['platform', 'store', 'cashier', 'mobile'].forEach((key) => {
          if (key === this.current) return;
          this.ensureChannelTree(key);
        });
      });
    },
    cloneChannelMenus(key, menus) {
      const m = menus || this.menus || {};
      if (key === 'platform') return cloneMenus(m.platform_menus || m.platform || []);
      if (key === 'store') return cloneMenus(m.store_menus || m.store || []);
      if (key === 'cashier') return cloneMenus(m.cashier_menus || m.cashier || []);
      return cloneMenus(m.mobile_menus || m.mall_menus || m.mobile || []);
    },
    ensureChannelTree(key) {
      if (!key) return;
      if (this.trees[key] && this.trees[key].length) return;
      const tree = this.cloneChannelMenus(key);
      const v = this.value || {};
      const ids = this.isEntryOn(key) ? (v[key] || []) : [];
      markChecked(tree, new Set(ids.map(Number)));
      this.$set(this.trees, key, tree);
    },
    applyValue(silent) {
      const v = this.value || {};
      ['platform', 'store', 'cashier', 'mobile'].forEach((key) => {
        if (!(this.trees[key] && this.trees[key].length)) return;
        const ids = this.isEntryOn(key) ? (v[key] || []) : [];
        markChecked(this.trees[key], new Set(ids.map(Number)));
        this.trees[key] = cloneMenus(this.trees[key]);
      });
      this.treeEpoch += 1;
      if (!silent) this.emitChange();
    },
    clearChannel(key) {
      if (!(this.trees[key] && this.trees[key].length)) return;
      setChecked(this.trees[key], false);
      this.trees[key] = cloneMenus(this.trees[key]);
    },
    applyEntryLocks(silent) {
      ['platform', 'store', 'cashier', 'mobile'].forEach((key) => {
        if (!this.isEntryOn(key)) this.clearChannel(key);
      });
      this.treeEpoch += 1;
      if (!silent) this.emitChange();
    },
    onEntryToggle(key, checked) {
      if (this.disabled || this.isChannelHardLocked(key)) return;
      const on = !!checked;
      this.$set(this.localEntries, key, on ? 1 : 0);
      if (!on) {
        this.clearChannel(key);
        this.treeEpoch += 1;
      }
      this.emitChange();
    },
    syncFromTreeRef() {
      if (this.isCurrentLocked) return;
      const ref = this.$refs['tree_' + this.current];
      const tree = Array.isArray(ref) ? ref[0] : ref;
      if (!tree) return;
      let ids = [];
      if (typeof tree.getCheckedAndIndeterminateNodes === 'function') {
        ids = (tree.getCheckedAndIndeterminateNodes() || []).map((n) => Number(n.id)).filter((n) => n > 0);
      } else if (typeof tree.getCheckedNodes === 'function') {
        ids = (tree.getCheckedNodes() || []).map((n) => Number(n.id)).filter((n) => n > 0);
      }
      const visibleSet = new Set(ids);
      const kw = String(this.keyword || '').trim().toLowerCase();
      if (!kw) {
        markChecked(this.trees[this.current], visibleSet);
      } else {
        const walk = (nodes) => {
          let any = false;
          (nodes || []).forEach((n) => {
            const title = String(n.title || n.menu_name || n.label || '').toLowerCase();
            const childHit = walk(n.children || []);
            const selfHit = title.indexOf(kw) >= 0;
            if (selfHit || childHit) {
              any = true;
              if (!(n.children && n.children.length)) {
                n.checked = visibleSet.has(Number(n.id));
              } else if (selfHit) {
                n.checked = visibleSet.has(Number(n.id));
              }
            }
          });
          return any;
        };
        walk(this.trees[this.current]);
      }
      this.trees[this.current] = cloneMenus(this.trees[this.current]);
      this.emitChange();
    },
    onCheckChange() {
      this.$nextTick(() => this.syncFromTreeRef());
    },
    expandAll(expand) {
      if (this.isCurrentLocked) return;
      setExpand(this.trees[this.current], expand);
      this.trees[this.current] = cloneMenus(this.trees[this.current]);
      this.treeEpoch += 1;
    },
    selectAll(checked) {
      if (this.isCurrentLocked) return;
      setChecked(this.trees[this.current], checked);
      this.trees[this.current] = cloneMenus(this.trees[this.current]);
      this.treeEpoch += 1;
      this.emitChange();
    },
    collectRules() {
      return {
        platform: this.isEntryOn('platform') ? collectIds(this.trees.platform, []) : [],
        store: this.isEntryOn('store') ? collectIds(this.trees.store, []) : [],
        cashier: this.isEntryOn('cashier') ? collectIds(this.trees.cashier, []) : [],
        mobile: this.isEntryOn('mobile') ? collectIds(this.trees.mobile, []) : [],
      };
    },
    getEntries() {
      return {
        use_platform: this.isEntryOn('platform') ? 1 : 0,
        use_store: this.isEntryOn('store') ? 1 : 0,
        use_cashier: this.isEntryOn('cashier') ? 1 : 0,
        use_mobile: this.isEntryOn('mobile') ? 1 : 0,
      };
    },
    emitChange() {
      const rules = this.collectRules();
      const entries = this.getEntries();
      this.$emit('input', rules);
      this.$emit('change', rules, entries);
      this.$emit('entries-change', entries);
      this.$emit('platform-change', (rules.platform || []).length > 0);
    },
    getRules() {
      return this.collectRules();
    },
  },
};
</script>

<style scoped>
.fcpe {
  display: flex;
  flex-direction: column;
  min-height: 0;
  min-width: 0;
  flex: 1;
  border: 1px solid #e6e8ef;
  border-radius: 12px;
  background: #fff;
  overflow: hidden;
}
.fcpe-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 6px;
  padding: 10px 12px 0;
  background: #f5f6fa;
  border-bottom: 1px solid #e6e8ef;
  overflow: hidden;
  flex: 0 0 auto;
}
.fcpe-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  padding: 0 14px;
  border: 0;
  border-radius: 999px 999px 0 0;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 13px;
  white-space: nowrap;
  flex: 0 0 auto;
}
.fcpe-tab.active {
  background: #fff;
  color: #1f2430;
  font-weight: 650;
  box-shadow: 0 -1px 0 #fff;
}
.fcpe-tab.locked {
  opacity: 0.72;
}
.fcpe-tab-count {
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: #eceff5;
  color: #475467;
  font-size: 12px;
  line-height: 20px;
  text-align: center;
}
.fcpe-tab.active .fcpe-tab-count {
  background: #5b5bd6;
  color: #fff;
}
.fcpe-entry {
  flex: 0 0 auto;
  margin: 10px 12px 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #f8f9fc;
  border: 1px solid #eef0f5;
}
.fcpe-entry-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.fcpe-entry-label {
  font-size: 13px;
  font-weight: 600;
  color: #1f2430;
}
.fcpe-entry-switch {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  user-select: none;
}
.fcpe-entry-switch.is-disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.fcpe-entry-input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
  pointer-events: none;
}
.fcpe-entry-track {
  position: relative;
  width: 40px;
  height: 22px;
  flex: 0 0 40px;
  border-radius: 999px;
  background: #c5cad3;
  transition: background 0.18s ease;
}
.fcpe-entry-switch.is-on .fcpe-entry-track {
  background: #5b5bd6;
}
.fcpe-entry-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(32, 42, 63, 0.28);
  transition: transform 0.18s ease;
}
.fcpe-entry-switch.is-on .fcpe-entry-thumb {
  transform: translateX(18px);
}
.fcpe-entry-text {
  color: #667085;
  font-size: 12px;
  line-height: 1.2;
  white-space: nowrap;
}
.fcpe-entry-switch.is-on .fcpe-entry-text {
  color: #1f2430;
  font-weight: 600;
}
.fcpe-entry-hint {
  margin: 6px 0 0;
  color: #667085;
  font-size: 12px;
  line-height: 1.45;
}
.fcpe-banner {
  flex: 0 0 auto;
  margin: 10px 12px 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #fff7e8;
  color: #8a5a00;
  font-size: 12px;
  line-height: 1.5;
}
.fcpe-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 12px;
  border-bottom: 1px solid #eef0f5;
  flex: 0 0 auto;
  min-width: 0;
}
.fcpe-search {
  flex: 1 1 200px;
  min-width: 160px;
  max-width: 320px;
}
.fcpe-search input {
  width: 100%;
  box-sizing: border-box;
  height: 34px;
  padding: 0 10px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
}
.fcpe-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.fcpe-link {
  height: 30px;
  padding: 0 10px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font-size: 12px;
}
.fcpe-link:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.fcpe-selected {
  margin-left: auto;
  color: #475467;
  font-size: 12px;
  white-space: nowrap;
}
.fcpe-tree-wrap {
  flex: 1 1 auto;
  min-height: 0;
  min-width: 0;
  overflow-x: hidden;
  overflow-y: auto;
  padding: 10px 14px 16px;
  -webkit-overflow-scrolling: touch;
}
.fcpe-tree {
  width: 100%;
  max-width: 100%;
}
.fcpe-locked {
  padding: 28px 16px;
  color: #475467;
  line-height: 1.7;
}
.fcpe-locked .muted {
  color: #98a2b3;
}

/* 权限树：自适应宽度、换行、禁止横向溢出与截断 */
.fcpe-tree-wrap >>> .ivu-tree,
.fcpe-tree-wrap /deep/ .ivu-tree {
  width: 100%;
  max-width: 100%;
  overflow: visible;
}
.fcpe-tree-wrap >>> .ivu-tree ul,
.fcpe-tree-wrap /deep/ .ivu-tree ul {
  width: 100%;
  max-width: 100%;
  padding-left: 18px;
  box-sizing: border-box;
}
.fcpe-tree-wrap >>> .ivu-tree > ul,
.fcpe-tree-wrap /deep/ .ivu-tree > ul {
  padding-left: 0;
}
.fcpe-tree-wrap >>> .ivu-tree-children,
.fcpe-tree-wrap /deep/ .ivu-tree-children {
  overflow: visible;
}
.fcpe-tree-wrap >>> .ivu-tree-item,
.fcpe-tree-wrap /deep/ .ivu-tree-item {
  max-width: 100%;
}
.fcpe-tree-wrap >>> .ivu-tree-title,
.fcpe-tree-wrap /deep/ .ivu-tree-title {
  white-space: normal !important;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.45;
  vertical-align: top;
}
.fcpe-tree-wrap >>> .ivu-checkbox-wrapper,
.fcpe-tree-wrap /deep/ .ivu-checkbox-wrapper {
  display: inline-flex;
  align-items: flex-start;
  white-space: normal;
  max-width: 100%;
  margin-right: 0;
}
.fcpe-tree-wrap >>> .ivu-tree-arrow,
.fcpe-tree-wrap /deep/ .ivu-tree-arrow {
  vertical-align: top;
  margin-top: 2px;
}

@media (max-width: 820px) {
  .fcpe-search {
    flex: 1 1 100%;
    max-width: none;
  }
  .fcpe-selected {
    margin-left: 0;
    width: 100%;
  }
}
</style>
