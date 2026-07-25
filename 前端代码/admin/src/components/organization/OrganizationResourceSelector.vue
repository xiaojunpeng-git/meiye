<template>
  <div class="org-resource-selector" :class="{ 'is-trigger': pickerMode === 'modal' }">
    <template v-if="pickerMode === 'modal'">
      <div class="ors-trigger" @click="openPicker">
        <span class="ors-trigger-text" :class="{ placeholder: !displayLabel }">{{ displayLabel || triggerPlaceholder }}</span>
        <Button type="primary" size="small" class="ml8" @click.stop="openPicker">选择</Button>
        <Button v-if="clearable && displayLabel" size="small" type="text" @click.stop="clearValue">清除</Button>
      </div>
      <div v-if="effectiveSelectionMode === 'org_only' && multiple && selectedTags.length" class="ors-tags">
        <Tag
          v-for="tag in selectedTags"
          :key="'t-' + tag.id"
          closable
          @on-close="removeTag(tag.id)"
        >{{ tag.label }}</Tag>
      </div>
    </template>

    <!-- transfer 到 body，避免被「新建人员」Modal 遮罩挡住 -->
    <Modal
      v-model="pickerOpen"
      :title="modalTitle"
      :width="dialogWidth"
      :styles="dialogStyles"
      :mask-closable="false"
      :transfer="true"
      :z-index="pickerZIndex"
      :class-name="treeMode ? 'ors-picker-modal ors-picker-modal-tree' : 'ors-picker-modal'"
      @on-cancel="closePicker"
    >
      <div class="ors-picker-body">
        <div class="ors-toolbar">
          <Input
            v-model="keyword"
            :placeholder="placeholder"
            clearable
            style="width: 180px; max-width: 46%"
            @on-enter="reload(1)"
          />
          <Button type="primary" class="ml14" @click="reload(1)">查询 <span class="enter-key">↵</span></Button>
          <Button v-if="treeMode" class="ml8" @click="expandAll">展开全部</Button>
          <Button v-if="treeMode" class="ml8" @click="collapseAll">收起全部</Button>
          <span v-if="multiple" class="ors-count">已选 {{ draftIds.length }}{{ maxSelect > 0 ? ' / ' + maxSelect : '' }}</span>
        </div>
        <div v-if="loading" class="ors-hint">加载中…</div>
        <div v-else-if="error" class="ors-hint error">
          {{ error }}
          <Button size="small" type="text" @click="reload(page)">重试</Button>
        </div>
        <div v-else-if="treeMode" class="ors-tree-scroll">
          <div v-if="!treeNodes.length" class="ors-hint">暂无数据</div>
          <ors-tree-node
            v-for="node in treeNodes"
            :key="nodeKey(node)"
            :node="node"
            :depth="0"
            :expanded-map="expandedMap"
            :draft-ids="draftIds"
            :draft-key="draftNodeKey"
            :selection-mode="effectiveSelectionMode"
            @toggle-expand="toggleExpand"
            @toggle-select="toggleDraftNode"
          />
        </div>
        <div v-else class="ors-list">
          <div
            v-for="item in list"
            :key="item.value"
            class="ors-item"
            :class="{ selected: isDraftSelected(item.value), disabled: item.disabled }"
            @click="toggleDraft(item)"
          >
            <span class="ors-label">{{ item.label }}</span>
            <small v-if="resource === 'employee'" class="ors-sub">{{ item.phone_masked }} · {{ item.assignment_summary }}</small>
            <small v-else-if="resource === 'store'" class="ors-sub">{{ storeSubText(item) }}</small>
            <Icon v-if="isDraftSelected(item.value)" type="ios-checkmark-circle" color="#2d8cf0" />
          </div>
          <div v-if="!list.length" class="ors-hint">暂无数据</div>
        </div>
        <div class="ors-pager" v-if="!treeMode && count > limit">
          <Page :total="count" :current="page" :page-size="limit" size="small" @on-change="reload" />
        </div>
      </div>
      <div slot="footer">
        <Button @click="closePicker">取消</Button>
        <Button type="primary" class="ml14" @click="confirmPicker">确定</Button>
      </div>
    </Modal>

    <!-- 内嵌模式：完整铺开（兼容旧用法） -->
    <template v-if="pickerMode !== 'modal'">
      <div class="ors-toolbar">
        <Input
          v-model="keyword"
          :placeholder="placeholder"
          clearable
          style="width: 240px"
          @on-enter="reload(1)"
        />
        <Button type="primary" class="ml14" @click="reload(1)">查询 <span class="enter-key">↵</span></Button>
        <span v-if="multiple" class="ors-count">已选 {{ innerValue.length }}{{ maxSelect > 0 ? ' / ' + maxSelect : '' }}</span>
      </div>
      <div v-if="loading" class="ors-hint">加载中…</div>
      <div v-else-if="error" class="ors-hint error">
        {{ error }}
        <Button size="small" type="text" @click="reload(page)">重试</Button>
      </div>
      <div v-else class="ors-list">
        <div
          v-for="item in list"
          :key="item.value"
          class="ors-item"
          :class="{ selected: isSelected(item.value), disabled: item.disabled }"
          @click="toggle(item)"
        >
          <span class="ors-label">{{ item.label }}</span>
          <small v-if="resource === 'employee'" class="ors-sub">{{ item.phone_masked }} · {{ item.assignment_summary }}</small>
          <small v-else-if="resource === 'store'" class="ors-sub">{{ storeSubText(item) }}</small>
          <Icon v-if="isSelected(item.value)" type="ios-checkmark-circle" color="#2d8cf0" />
        </div>
        <div v-if="!list.length" class="ors-hint">暂无数据</div>
      </div>
      <div class="ors-pager" v-if="count > limit">
        <Page :total="count" :current="page" :page-size="limit" size="small" @on-change="reload" />
      </div>
    </template>
  </div>
</template>

<script>
import { getOrganizationResourceSelector } from '@/api/store';

const OrsTreeNode = {
  name: 'OrsTreeNode',
  props: {
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    expandedMap: { type: Object, default: () => ({}) },
    draftIds: { type: Array, default: () => [] },
    draftKey: { type: String, default: '' },
    selectionMode: { type: String, default: 'org_or_store' },
  },
  computed: {
    key() {
      const t = this.node.node_type === 'store' ? 'store' : 'org';
      return `${t}:${this.node.id}`;
    },
    hasChildren() {
      return Array.isArray(this.node.children) && this.node.children.length > 0;
    },
    expanded() {
      return !!this.expandedMap[this.key];
    },
    isStore() {
      return this.node.node_type === 'store';
    },
    selectable() {
      if (this.node.disabled) return false;
      if (this.selectionMode === 'org_only') return !this.isStore;
      if (this.selectionMode === 'store_only') return this.isStore;
      return true; // org_or_store
    },
    selected() {
      const id = Number(this.node.id);
      if (this.draftIds.indexOf(id) === -1) return false;
      // 组织/门店数字 ID 可能重叠，必须按节点类型区分，否则点组织看起来「点不了」
      if (this.selectionMode === 'org_only') return !this.isStore;
      if (this.selectionMode === 'store_only') return this.isStore;
      if (this.draftKey === 'store') return this.isStore;
      if (this.draftKey === 'org') return !this.isStore;
      return !this.isStore;
    },
  },
  methods: {
    onExpand() {
      if (!this.hasChildren) return;
      this.$emit('toggle-expand', this.key);
    },
    onSelect() {
      if (!this.selectable) return;
      this.$emit('toggle-select', this.node);
    },
  },
  render(h) {
    const pad = 8 + this.depth * 18;
    const children = [];
    if (this.hasChildren) {
      children.push(h('span', {
        class: ['ors-caret', this.expanded ? 'open' : ''],
        on: { click: (e) => { e.stopPropagation(); this.onExpand(); } },
      }, this.expanded ? '▼' : '▶'));
    } else {
      children.push(h('span', { class: 'ors-caret-placeholder' }));
    }
    children.push(h('span', {
      class: ['ors-tree-label', this.isStore ? 'is-store' : 'is-org'],
    }, this.node.name || this.node.label || ''));
    if (this.selected) {
      children.push(h('Icon', { props: { type: 'ios-checkmark-circle', color: '#2d8cf0' } }));
    }
    const row = h('div', {
      class: {
        'ors-tree-row': true,
        selected: this.selected,
        disabled: !this.selectable,
        'is-store': this.isStore,
      },
      style: { paddingLeft: pad + 'px' },
      on: { click: this.onSelect },
    }, children);

    const kids = [];
    if (this.expanded && this.hasChildren) {
      this.node.children.forEach((child) => {
        kids.push(h('ors-tree-node', {
          key: `${child.node_type || 'org'}:${child.id}`,
          props: {
            node: child,
            depth: this.depth + 1,
            expandedMap: this.expandedMap,
            draftIds: this.draftIds,
            draftKey: this.draftKey,
            selectionMode: this.selectionMode,
          },
          on: {
            'toggle-expand': (k) => this.$emit('toggle-expand', k),
            'toggle-select': (n) => this.$emit('toggle-select', n),
          },
        }));
      });
    }
    return h('div', { class: 'ors-tree-node' }, [row].concat(kids));
  },
};

export default {
  name: 'OrganizationResourceSelector',
  components: { OrsTreeNode },
  props: {
    value: { type: [Number, Array, String], default: () => [] },
    resource: { type: String, default: 'employee' }, // organization|store|employee|org_store_tree
    multiple: { type: Boolean, default: false },
    maxSelect: { type: Number, default: 0 },
    disabledIds: { type: Array, default: () => [] },
    placeholder: { type: String, default: '搜索…' },
    scopeOrgId: { type: Number, default: 0 },
    pickerMode: { type: String, default: 'embedded' },
    modalTitle: { type: String, default: '请选择' },
    triggerPlaceholder: { type: String, default: '请选择' },
    clearable: { type: Boolean, default: true },
    /** 组织—门店树模式 */
    treeMode: { type: Boolean, default: false },
    /**
     * org_or_store：所属组织（组织/门店均可，单选，按类型回写）
     * org_only：组织数据权限（仅组织，可多选）
     * store_only：当前任职门店（仅门店）
     * auto：按 resource 推断
     */
    selectionMode: { type: String, default: 'auto' },
    /** org_or_store：父表单当前任职门店，用于回显门店名与重开勾选门店节点 */
    echoStoreId: { type: [Number, String], default: 0 },
  },
  data() {
    return {
      keyword: '',
      list: [],
      treeNodes: [],
      count: 0,
      page: 1,
      limit: 20,
      loading: false,
      error: '',
      timer: null,
      pickerOpen: false,
      draftIds: [],
      draftMeta: {}, // id -> { node_type, org_id, store_id, label }
      draftNodeKey: '', // 单选 org_or_store 时记录类型
      labelMap: {},
      expandedMap: {},
      lastPickMeta: null, // 最近一次确认的节点，优先用于触发器文案
    };
  },
  computed: {
    effectiveSelectionMode() {
      if (this.selectionMode && this.selectionMode !== 'auto') return this.selectionMode;
      if (this.resource === 'organization') return 'org_only';
      if (this.resource === 'store') return 'store_only';
      if (this.treeMode) return 'org_or_store';
      return 'org_only';
    },
    /** 高于人员弹窗(约 1100)；mask/wrap 必须同值，不能再用 CSS 单独压 wrap */
    pickerZIndex() {
      return 3600;
    },
    dialogWidth() {
      return this.treeMode ? '32vw' : 720;
    },
    dialogStyles() {
      if (!this.treeMode) return {};
      return {
        top: '6vh',
        width: '32vw',
        minWidth: '360px',
        maxWidth: '480px',
      };
    },
    echoStoreIdNum() {
      return Number(this.echoStoreId || 0);
    },
    innerValue() {
      if (this.multiple) {
        const v = Array.isArray(this.value) ? this.value : [];
        return v.map((x) => Number(x)).filter((x) => x > 0);
      }
      const n = Number(this.value || 0);
      return n > 0 ? [n] : [];
    },
    displayLabel() {
      if (!this.innerValue.length) return '';
      if (this.multiple) {
        return this.innerValue.map((id) => this.labelMap[id] || this.labelMap[`org:${id}`] || ('#' + id)).join('、');
      }
      const id = this.innerValue[0];
      if (this.effectiveSelectionMode === 'store_only') {
        return this.labelMap[`store:${id}`] || this.labelMap[id] || ('#' + id);
      }
      if (this.lastPickMeta && this.lastPickMeta.label
        && this.lastPickMeta.node_type === 'org'
        && Number(this.lastPickMeta.org_id || this.lastPickMeta.value || 0) === id) {
        return this.lastPickMeta.label;
      }
      return this.labelMap[`org:${id}`] || this.labelMap[id] || ('#' + id);
    },
    selectedTags() {
      return this.innerValue.map((id) => ({
        id,
        label: this.labelMap[id] || ('#' + id),
      }));
    },
  },
  watch: {
    resource() {
      if (this.pickerMode !== 'modal' || this.pickerOpen) this.reload(1);
    },
    scopeOrgId() {
      if (this.pickerMode !== 'modal' || this.pickerOpen) this.reload(1);
    },
    value: {
      immediate: true,
      handler() {
        this.ensureLabels();
      },
    },
    echoStoreId: {
      immediate: true,
      handler() {
        this.ensureLabels();
      },
    },
  },
  mounted() {
    if (this.pickerMode !== 'modal') {
      this.reload(1);
    } else {
      this.ensureLabels();
    }
  },
  beforeDestroy() {
    if (this.timer) clearTimeout(this.timer);
  },
  methods: {
    nodeKey(node) {
      return `${node.node_type || 'org'}:${node.id}`;
    },
    storeSubText(item) {
      const org = (item && item.org_name) || '';
      const phone = (item && item.phone) || '';
      if (org && phone) return `${org} · ${phone}`;
      return org || phone || '';
    },
    isSelected(id) {
      return this.innerValue.indexOf(Number(id)) !== -1;
    },
    isDraftSelected(id) {
      return this.draftIds.indexOf(Number(id)) !== -1;
    },
    openPicker() {
      const mode = this.effectiveSelectionMode;
      const asStore = mode === 'store_only';
      this.draftIds = [];
      this.draftMeta = {};
      // org_or_store：有任职门店时回显门店节点，否则回显组织节点
      if (mode === 'org_or_store' && this.echoStoreIdNum > 0) {
        const sid = this.echoStoreIdNum;
        const oid = Number(this.value || 0);
        const label = this.labelMap[`store:${sid}`] || this.labelMap[sid] || '';
        this.draftIds = [sid];
        this.draftNodeKey = 'store';
        this.$set(this.draftMeta, sid, {
          node_type: 'store',
          org_id: oid,
          store_id: sid,
          label,
          value: sid,
        });
      } else {
        this.draftIds = this.innerValue.slice();
        this.draftNodeKey = mode === 'store_only' ? 'store' : 'org';
        this.innerValue.forEach((id) => {
          this.$set(this.draftMeta, id, {
            node_type: asStore ? 'store' : 'org',
            org_id: asStore ? 0 : id,
            store_id: asStore ? id : 0,
            label: this.labelMap[id] || '',
            value: id,
          });
        });
      }
      this.pickerOpen = true;
      this.$nextTick(() => this.reload(1));
    },
    closePicker() {
      this.pickerOpen = false;
    },
    clearValue() {
      const empty = this.multiple ? [] : 0;
      this.lastPickMeta = null;
      this.$emit('input', empty);
      this.$emit('change', empty, null);
    },
    removeTag(id) {
      if (!this.multiple) return;
      const next = this.innerValue.filter((x) => Number(x) !== Number(id));
      this.$emit('input', next);
      this.$emit('change', next, null);
    },
    confirmPicker() {
      if (this.treeMode && this.effectiveSelectionMode === 'org_or_store' && !this.multiple) {
        const id = this.draftIds[0] || 0;
        const meta = this.draftMeta[id] || { node_type: 'org', org_id: id, store_id: 0, label: this.labelMap[id] || '' };
        this.lastPickMeta = { ...meta };
        if (meta.node_type === 'store') {
          if (meta.label) {
            this.$set(this.labelMap, `store:${meta.store_id}`, meta.label);
            this.$set(this.labelMap, Number(meta.store_id), meta.label);
          }
        } else if (meta.label) {
          this.$set(this.labelMap, id, meta.label);
        }
        // 所属组织字段：组织节点写 org_id；门店节点由父组件同时写 org+store
        const emitId = meta.node_type === 'store' ? Number(meta.org_id || 0) : Number(id);
        if (meta.node_type === 'org' && meta.label) {
          this.$set(this.labelMap, emitId, meta.label);
        }
        this.$emit('input', emitId);
        this.$emit('change', emitId, {
          ...meta,
          value: emitId,
          label: meta.label,
        });
        this.pickerOpen = false;
        return;
      }
      if (this.multiple) {
        this.draftIds.forEach((id) => {
          const m = this.draftMeta[id];
          if (m && m.label) {
            this.$set(this.labelMap, id, m.label);
            if (m.node_type === 'org') this.$set(this.labelMap, `org:${id}`, m.label);
          }
        });
        this.$emit('input', this.draftIds.slice());
        this.$emit('change', this.draftIds.slice(), this.draftIds.map((id) => this.draftMeta[id] || { value: id }));
      } else {
        const id = this.draftIds[0] || 0;
        const meta = this.draftMeta[id] || this.list.find((x) => Number(x.value) === Number(id)) || { value: id, label: this.labelMap[id] || '' };
        this.lastPickMeta = { ...meta, node_type: meta.node_type || (this.effectiveSelectionMode === 'store_only' ? 'store' : 'org') };
        if (meta.label) {
          this.$set(this.labelMap, Number(id), meta.label);
          if (this.effectiveSelectionMode === 'store_only' || meta.node_type === 'store') {
            this.$set(this.labelMap, `store:${id}`, meta.label);
          } else {
            this.$set(this.labelMap, `org:${id}`, meta.label);
          }
        }
        this.$emit('input', id);
        this.$emit('change', id, {
          ...meta,
          node_type: this.lastPickMeta.node_type,
          org_id: Number(meta.org_id || (this.lastPickMeta.node_type === 'org' ? id : 0)),
          store_id: Number(meta.store_id || (this.lastPickMeta.node_type === 'store' ? id : 0)),
          value: id,
          label: meta.label || '',
        });
      }
      this.pickerOpen = false;
    },
    toggleDraftNode(node) {
      if (!node || node.disabled) return;
      const isStore = node.node_type === 'store';
      const mode = this.effectiveSelectionMode;
      if (mode === 'org_only' && isStore) return;
      if (mode === 'store_only' && !isStore) return;
      const id = Number(node.id || node.value || 0);
      if (!(id > 0)) return;
      const label = node.name || node.label || '';
      const nodeType = isStore ? 'store' : 'org';
      const meta = {
        node_type: nodeType,
        org_id: isStore ? Number(node.org_id || 0) : id,
        store_id: isStore ? id : 0,
        label,
        value: id,
      };
      // 标签缓存按类型隔离，避免同号组织/门店互相覆盖显示名
      if (label) this.$set(this.labelMap, `${nodeType}:${id}`, label);
      if (label) this.$set(this.labelMap, id, label);
      if (this.multiple) {
        const next = this.draftIds.slice();
        const idx = next.indexOf(id);
        if (idx >= 0) {
          next.splice(idx, 1);
          this.$delete(this.draftMeta, id);
        } else {
          if (this.maxSelect > 0 && next.length >= this.maxSelect) {
            this.$Message.error('已达到最大选择数');
            return;
          }
          next.push(id);
          this.$set(this.draftMeta, id, meta);
        }
        this.draftIds = next;
        this.draftNodeKey = 'org';
      } else {
        // 同号切换组织/门店时 draftIds 可能不变，必须改 draftNodeKey 触发重绘
        this.draftNodeKey = nodeType;
        this.draftMeta = { [id]: meta };
        this.draftIds = [id];
      }
    },
    toggleDraft(item) {
      if (item.disabled) return;
      const id = Number(item.value);
      if (item.label) this.$set(this.labelMap, id, item.label);
      this.$set(this.draftMeta, id, {
        node_type: this.resource === 'store' ? 'store' : 'org',
        org_id: this.resource === 'store' ? Number(item.org_id || 0) : id,
        store_id: this.resource === 'store' ? id : 0,
        label: item.label || '',
        value: id,
      });
      if (this.multiple) {
        const next = this.draftIds.slice();
        const idx = next.indexOf(id);
        if (idx >= 0) next.splice(idx, 1);
        else {
          if (this.maxSelect > 0 && next.length >= this.maxSelect) {
            this.$Message.error('已达到最大选择数');
            return;
          }
          next.push(id);
        }
        this.draftIds = next;
      } else {
        this.draftIds = [id];
      }
    },
    toggle(item) {
      if (item.disabled) return;
      const id = Number(item.value);
      if (item.label) this.$set(this.labelMap, id, item.label);
      if (this.multiple) {
        const next = this.innerValue.slice();
        const idx = next.indexOf(id);
        if (idx >= 0) next.splice(idx, 1);
        else {
          if (this.maxSelect > 0 && next.length >= this.maxSelect) {
            this.$Message.error('已达到最大选择数');
            return;
          }
          next.push(id);
        }
        this.$emit('input', next);
        this.$emit('change', next, item);
      } else {
        this.$emit('input', id);
        this.$emit('change', id, item);
      }
    },
    toggleExpand(key) {
      this.$set(this.expandedMap, key, !this.expandedMap[key]);
    },
    expandAll() {
      const walk = (nodes) => {
        (nodes || []).forEach((n) => {
          const k = this.nodeKey(n);
          this.$set(this.expandedMap, k, true);
          walk(n.children || []);
        });
      };
      walk(this.treeNodes);
    },
    collapseAll() {
      this.expandedMap = {};
    },
    ensureLabels() {
      const tasks = [];
      const missingOrg = this.innerValue.filter((id) => !this.labelMap[id]);
      if (missingOrg.length && this.effectiveSelectionMode !== 'store_only') {
        tasks.push(getOrganizationResourceSelector({
          resource: 'organization',
          ids: missingOrg.join(','),
          page: 1,
          limit: Math.min(50, missingOrg.length),
          scope_org_id: Number(this.scopeOrgId) > 0 ? Number(this.scopeOrgId) : undefined,
        }).then((res) => {
          const data = (res && res.data) || res || {};
          (data.list || []).forEach((row) => {
            this.$set(this.labelMap, Number(row.value), row.label || ('#' + row.value));
          });
        }).catch(() => {}));
      }
      const storeIds = [];
      if (this.effectiveSelectionMode === 'store_only') {
        this.innerValue.forEach((id) => {
          if (!this.labelMap[id] && !this.labelMap[`store:${id}`]) storeIds.push(id);
        });
      }
      if (this.echoStoreIdNum > 0
        && !this.labelMap[this.echoStoreIdNum]
        && !this.labelMap[`store:${this.echoStoreIdNum}`]) {
        storeIds.push(this.echoStoreIdNum);
      }
      const uniqStore = [...new Set(storeIds.map(Number).filter((n) => n > 0))];
      if (uniqStore.length) {
        tasks.push(getOrganizationResourceSelector({
          resource: 'store',
          ids: uniqStore.join(','),
          page: 1,
          limit: Math.min(50, uniqStore.length),
          scope_org_id: Number(this.scopeOrgId) > 0 ? Number(this.scopeOrgId) : undefined,
        }).then((res) => {
          const data = (res && res.data) || res || {};
          (data.list || []).forEach((row) => {
            const id = Number(row.value);
            const label = row.label || ('#' + id);
            this.$set(this.labelMap, id, label);
            this.$set(this.labelMap, `store:${id}`, label);
          });
        }).catch(() => {}));
      }
      return Promise.all(tasks);
    },
    /** 组织数据权限/所属组织：树里去掉门店节点 */
    stripStoreNodes(nodes) {
      return (nodes || [])
        .filter((n) => (n.node_type || 'org') !== 'store')
        .map((n) => ({
          ...n,
          node_type: 'org',
          children: this.stripStoreNodes(n.children || []),
        }));
    },
    collectLabelsFromTree(nodes) {
      (nodes || []).forEach((n) => {
        const id = Number(n.id || 0);
        const label = n.name || n.label || '';
        if (id > 0 && label) {
          const typ = n.node_type === 'store' ? 'store' : 'org';
          this.$set(this.labelMap, `${typ}:${id}`, label);
          if (typ === 'store' || !this.labelMap[id]) this.$set(this.labelMap, id, label);
        }
        this.collectLabelsFromTree(n.children || []);
      });
    },
    expandToSelected() {
      if (!(this.draftIds || []).length) return;
      const wantId = Number(this.draftIds[0] || 0);
      const wantStore = this.draftNodeKey === 'store';
      const path = [];
      const find = (nodes, trail) => {
        for (const n of (nodes || [])) {
          const next = trail.concat([this.nodeKey(n)]);
          const isStore = n.node_type === 'store';
          if (Number(n.id) === wantId && !!isStore === wantStore) {
            path.push(...next);
            return true;
          }
          if (find(n.children || [], next)) return true;
        }
        return false;
      };
      if (find(this.treeNodes, [])) {
        path.forEach((k) => this.$set(this.expandedMap, k, true));
      }
    },
    openForcedNodes(nodes) {
      (nodes || []).forEach((n) => {
        if (n._force_open || (n.children && n.children.length)) {
          this.$set(this.expandedMap, this.nodeKey(n), true);
        }
        this.openForcedNodes(n.children || []);
      });
    },
    reload(page) {
      this.page = page || 1;
      this.loading = true;
      this.error = '';
      if (typeof getOrganizationResourceSelector !== 'function') {
        this.loading = false;
        this.error = '组织选择接口未就绪，请重新发布商家端前端';
        return;
      }
      const useTree = !!this.treeMode;
      const params = {
        resource: useTree ? 'org_store_tree' : this.resource,
        keyword: this.keyword,
        page: this.page,
        limit: useTree ? 50 : this.limit,
        disabled_ids: this.disabledIds.join(','),
      };
      if (Number(this.scopeOrgId) > 0) {
        params.scope_org_id = Number(this.scopeOrgId);
      }
      let settled = false;
      const finish = (errMsg) => {
        if (settled) return;
        settled = true;
        if (this.timer) {
          clearTimeout(this.timer);
          this.timer = null;
        }
        this.loading = false;
        if (errMsg) this.error = errMsg;
      };
      this.timer = setTimeout(() => {
        finish('加载超时，请重试');
      }, 15000);
      let p;
      try {
        p = getOrganizationResourceSelector(params);
      } catch (e) {
        finish((e && e.message) || '加载失败');
        return;
      }
      if (!p || typeof p.then !== 'function') {
        finish('组织选择接口返回异常');
        return;
      }
      p.then((res) => {
        if (settled) return;
        const data = (res && res.data) || res || {};
        if (useTree) {
          let tree = data.tree || [];
          // 所属组织 / 组织数据权限：弹层只展示组织，不出现门店
          if (this.effectiveSelectionMode === 'org_only') {
            tree = this.stripStoreNodes(tree);
          }
          this.treeNodes = tree;
          this.list = [];
          this.count = Number(data.count || 0);
          this.collectLabelsFromTree(this.treeNodes);
          // 回显门店时补齐 draftMeta 上的 org_id/label
          if (this.draftNodeKey === 'store' && this.draftIds[0]) {
            const sid = Number(this.draftIds[0]);
            const m = this.draftMeta[sid] || {};
            const label = this.labelMap[`store:${sid}`] || m.label || '';
            let orgId = Number(m.org_id || this.value || 0);
            const walk = (nodes) => {
              for (const n of (nodes || [])) {
                if (n.node_type === 'store' && Number(n.id) === sid) {
                  orgId = Number(n.org_id || orgId);
                  return true;
                }
                if (walk(n.children || [])) return true;
              }
              return false;
            };
            walk(this.treeNodes);
            this.$set(this.draftMeta, sid, {
              node_type: 'store',
              org_id: orgId,
              store_id: sid,
              label,
              value: sid,
            });
          }
          if (!Object.keys(this.expandedMap).length) {
            this.treeNodes.forEach((n) => this.$set(this.expandedMap, this.nodeKey(n), true));
          }
          this.openForcedNodes(this.treeNodes);
          this.expandToSelected();
        } else {
          this.list = data.list || [];
          this.count = Number(data.count || 0);
          this.list.forEach((row) => {
            if (row && row.value && row.label) {
              this.$set(this.labelMap, Number(row.value), row.label);
            }
          });
        }
        finish();
      }).catch((e) => {
        finish((e && e.msg) || (e && e.message) || '加载失败');
      });
    },
  },
};
</script>

<style scoped>
.org-resource-selector { border: 1px solid #e8eaec; border-radius: 6px; padding: 12px; background: #fff; }
.org-resource-selector.is-trigger { border: 0; padding: 0; background: transparent; }
.ors-trigger {
  display: flex;
  align-items: center;
  min-height: 32px;
  padding: 4px 8px;
  border: 1px solid #dcdee2;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
}
.ors-trigger-text { flex: 1; color: #17233d; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ors-trigger-text.placeholder { color: #c5c8ce; }
.ors-tags { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
.ors-toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.ml14 { margin-left: 14px; }
.ml8 { margin-left: 8px; }
.enter-key { margin-left: 2px; font-weight: 600; }
.ors-count { color: #808695; font-size: 12px; margin-left: 8px; }
.ors-list { max-height: 280px; overflow: auto; }
.ors-item { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: 4px; cursor: pointer; position: relative; }
.ors-item:hover { background: #f5f7fa; }
.ors-item.selected { background: #f0f7ff; }
.ors-item.disabled { opacity: 0.45; cursor: not-allowed; }
.ors-label { font-weight: 500; color: #17233d; }
.ors-sub { color: #808695; font-size: 12px; }
.ors-item .ivu-icon { position: absolute; right: 10px; top: 12px; }
.ors-hint { padding: 16px; text-align: center; color: #808695; }
.ors-hint.error { color: #ed4014; }
.ors-pager { margin-top: 10px; text-align: right; }
.ors-picker-body { min-height: 240px; }
.ors-tree-scroll {
  max-height: calc(88vh - 220px);
  overflow-x: hidden;
  overflow-y: auto;
  border: 1px solid #e8eaec;
  border-radius: 8px;
  padding: 8px 0;
}
.ors-tree-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 34px;
  padding-right: 12px;
  cursor: pointer;
  position: relative;
}
.ors-tree-row:hover { background: #f5f7fa; }
.ors-tree-row.selected { background: #f0f7ff; }
.ors-tree-row.disabled { opacity: 0.45; cursor: not-allowed; }
.ors-caret, .ors-caret-placeholder {
  width: 16px;
  text-align: center;
  color: #808695;
  font-size: 10px;
  flex: 0 0 16px;
}
.ors-tree-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #17233d;
}
.ors-tree-label.is-org { font-weight: 500; }
.ors-tree-label.is-store { color: #515a6e; }
</style>

<style>
/*
 * 禁止给 .ors-picker-modal 写死 z-index！
 * 层级只交给 Modal 的 :z-index prop 统一设置 mask + wrap。
 */
.ors-picker-modal-tree .ivu-modal {
  width: 32vw !important;
  min-width: 360px !important;
  max-width: 480px !important;
  top: 6vh !important;
  margin: 0 auto;
}
.ors-picker-modal-tree .ivu-modal-content {
  max-height: 88vh;
  display: flex;
  flex-direction: column;
}
.ors-picker-modal-tree .ivu-modal-body {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  padding-bottom: 8px;
}
.ors-picker-modal-tree .ivu-modal-footer {
  flex-shrink: 0;
}
</style>
