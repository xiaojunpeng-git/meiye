<template>
  <div
    class="organization-store-scope-picker"
    :class="{ 'organization-store-scope-picker--org-multiple': isOrganizationMultiple }"
  >
    <Button class="organization-store-scope-picker__trigger" :loading="loading" @click="togglePanel">
      <Icon type="ios-git-network-outline" />
      <span v-if="loading">读取权限范围</span>
      <span v-else-if="isOrganizationMultiple && selectedOrganizationLabels.length" class="organization-store-scope-picker__selected-organizations">
        <span v-for="organization in selectedOrganizationLabels" :key="organization.id" class="organization-store-scope-picker__selected-organization">{{ organization.name }}</span>
      </span>
      <span v-else>{{ displayLabel }}</span>
      <Icon type="ios-arrow-down" />
    </Button>
    <Button
      v-if="isOrganizationMultiple"
      class="organization-store-scope-picker__clear"
      type="text"
      :disabled="!selectedOrganizationIds.length"
      @click.stop="clearSelection"
    >清空</Button>

    <section
      v-show="open"
      ref="panel"
      v-transfer-dom
      data-transfer="true"
      class="organization-store-scope-picker__panel"
      :style="panelStyle"
      aria-label="组织和门店权限范围"
    >
      <header>组织 / 门店</header>
      <div class="organization-store-scope-picker__body">
        <div class="organization-store-scope-picker__tree">
          <p v-if="loading" class="organization-store-scope-picker__empty">正在读取组织范围。</p>
          <p v-else-if="!visibleNodes.length" class="organization-store-scope-picker__empty">当前权限范围内暂无门店。</p>
          <div v-for="node in visibleNodes" :key="node.key" class="organization-store-scope-picker__tree-row" :style="{ paddingLeft: `${node.depth * 18}px` }">
            <button
              v-if="node.hasChildren"
              type="button"
              class="organization-store-scope-picker__toggle"
              :aria-label="node.expanded ? '收起组织' : '展开组织'"
              @click.stop="toggleNode(node.key)"
            >
              <Icon :type="node.expanded ? 'ios-arrow-down' : 'ios-arrow-forward'" />
            </button>
            <span v-else class="organization-store-scope-picker__toggle-placeholder" />
            <button
              type="button"
              class="organization-store-scope-picker__option"
              :class="{
                'organization-store-scope-picker__option--selected': isNodeSelected(node),
                'organization-store-scope-picker__option--store': node.storeId > 0
              }"
              @click="selectNode(node)"
            >{{ node.name }}</button>
          </div>
        </div>
        <div class="organization-store-scope-picker__stores">
          <p class="organization-store-scope-picker__stores-title">{{ selectedOrganizationName || '选择组织后查看组织及下级门店' }}</p>
          <div v-if="selectedStores.length" class="organization-store-scope-picker__store-list">
            <button
              v-for="store in selectedStores"
              :key="store.id"
              type="button"
              :class="{ 'is-active': selectedStoreIds.length === 1 && selectedStoreIds[0] === store.id }"
              @click="selectStore(store)"
            >{{ store.name }}</button>
          </div>
          <p v-else class="organization-store-scope-picker__empty">请选择左侧组织。</p>
        </div>
      </div>
      <footer>
        <button type="button" @click="chooseAll">{{ clearLabel }}</button>
        <span>选择组织查询其全部下级门店；选择门店仅查询该门店。</span>
      </footer>
    </section>
  </div>
</template>

<script>
/* eslint-disable comma-dangle */
import TransferDom from 'iview/src/directives/transfer-dom';

export default {
  name: 'OrganizationStoreScopePicker',
  directives: { TransferDom },
  props: {
    value: { type: Array, default: () => [] },
    loadScope: { type: Function, required: true },
    /** 外部已知的当前选中名称，用于编辑表单回显。 */
    selectedLabel: { type: String, default: '' },
    /** 仅组织选择场景的回显 ID，供组织树加载后补齐名称与高亮。 */
    selectedOrganizationId: { type: [Number, String], default: 0 },
    /** 不同业务场景可覆盖未选择时的提示文字。 */
    emptyLabel: { type: String, default: '当前权限范围' },
    /** 清空当前选择的按钮文案。 */
    clearLabel: { type: String, default: '当前权限范围' },
    /**
     * org_store：组织或门店均可选；org_only：仅可选组织；
     * store_only：组织仅用于展开门店；org_multiple：仅可多选组织。
     */
    selectionMode: { type: String, default: 'org_store' },
  },
  data() {
    return {
      open: false,
      loading: false,
      tree: [],
      expandedKeys: {},
      selectedOrganizationKey: '',
      selectedOrganizationName: '',
      selectedStores: [],
      selectedStoreIds: [],
      selectedOrganizationIds: [],
      displayLabel: this.emptyLabel,
      panelStyle: {},
    };
  },
  computed: {
    isStoreOnly() { return this.selectionMode === 'store_only'; },
    isOrganizationOnly() { return this.selectionMode === 'org_only'; },
    isOrganizationMultiple() { return this.selectionMode === 'org_multiple'; },
    selectedOrganizationLabels() {
      if (!this.isOrganizationMultiple) return [];
      return this.selectedOrganizationIds.map((id) => ({
        id,
        name: this.findOrganizationName(id) || `组织 #${id}`,
      }));
    },
    visibleNodes() {
      const result = [];
      const walk = (nodes, depth) => {
        (nodes || []).forEach((node, index) => {
          const key = `${node.node_type === 'store' ? 'store' : 'org'}:${node.id}:${depth}:${index}`;
          const children = Array.isArray(node.children) ? node.children : [];
          const expanded = Boolean(this.expandedKeys[key]);
          const option = {
            key,
            node,
            depth,
            name: node.title || node.name || (node.node_type === 'store' ? `门店${node.id}` : `组织${node.id}`),
            storeId: Number(node.store_id || (node.node_type === 'store' ? node.id : 0)),
            hasChildren: children.length > 0,
            expanded,
          };
          // 仅组织选择场景中，门店仍在右侧作为该组织的范围说明展示。
          if (!((this.isOrganizationMultiple || this.isOrganizationOnly) && option.storeId > 0)) result.push(option);
          if (children.length && expanded) walk(children, depth + 1);
        });
      };
      walk(this.tree, 0);
      return result;
    },
  },
  watch: {
    value: {
      immediate: true,
      handler(value) {
        this.selectedStoreIds = [...new Set((value || []).map(Number).filter(Boolean))];
        if (this.isOrganizationMultiple) {
          this.selectedOrganizationIds = [...this.selectedStoreIds];
          this.syncOrganizationMultipleLabel();
        } else if (this.isStoreOnly) {
          this.syncStoreOnlyLabel();
        }
      },
    },
    selectedLabel: {
      immediate: true,
      handler(value) {
        const label = String(value || '').trim();
        if (label) this.displayLabel = label;
        else if (this.isStoreOnly) this.syncStoreOnlyLabel();
        else if (!this.selectedStoreIds.length && !this.selectedOrganizationKey) this.displayLabel = this.emptyLabel;
      },
    },
    selectedOrganizationId() {
      this.syncOrganizationOnlyLabel();
    },
  },
  created() { this.refresh(); },
  mounted() {
    document.addEventListener('mousedown', this.handleOutsidePointerDown, true);
    window.addEventListener('resize', this.updatePanelPosition);
    window.addEventListener('scroll', this.updatePanelPosition, true);
    this.$nextTick(() => this.updatePanelPosition());
  },
  beforeDestroy() {
    document.removeEventListener('mousedown', this.handleOutsidePointerDown, true);
    window.removeEventListener('resize', this.updatePanelPosition);
    window.removeEventListener('scroll', this.updatePanelPosition, true);
    this.setPanelOpen(false);
  },
  methods: {
    handleOutsidePointerDown(event) {
      const panel = this.$refs.panel;
      const isInsideTrigger = this.$el && this.$el.contains(event.target);
      const isInsidePanel = panel && panel.contains(event.target);
      if (this.open && !isInsideTrigger && !isInsidePanel) this.setPanelOpen(false);
    },
    setPanelOpen(open) {
      this.open = Boolean(open);
      this.$nextTick(() => {
        const modalBody = this.$el && this.$el.closest('.ivu-modal-body');
        if (!modalBody) return;
        const hasOpenPanel = Boolean(modalBody.querySelector('.organization-store-scope-picker__panel'));
        modalBody.classList.toggle('organization-store-scope-picker-open', hasOpenPanel);
        this.updatePanelPosition();
      });
    },
    updatePanelPosition() {
      if (!this.open || !this.$el || typeof window === 'undefined') return;
      const triggerRect = this.$el.getBoundingClientRect();
      const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
      const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
      const panelWidth = Math.min(480, Math.max(320, viewportWidth - 24));
      const left = Math.max(12, Math.min(triggerRect.left, viewportWidth - panelWidth - 12));
      const estimatedPanelHeight = 380;
      const below = viewportHeight - triggerRect.bottom - 12;
      const above = triggerRect.top - 12;
      const openUpward = below < estimatedPanelHeight && above > below;
      const pageX = window.pageXOffset || window.scrollX || 0;
      const pageY = window.pageYOffset || window.scrollY || 0;
      const top = openUpward
        ? Math.max(pageY + 12, pageY + triggerRect.top - estimatedPanelHeight - 6)
        : pageY + triggerRect.bottom + 6;
      this.panelStyle = {
        // 面板由 transfer-dom 挂到 body，此处使用文档绝对坐标，避免
        // iView 弹窗/页签 transform 让 fixed 坐标再次叠加偏移。
        position: 'absolute',
        zIndex: 10000,
        width: `${panelWidth}px`,
        left: `${pageX + left}px`,
        top: `${top}px`,
        bottom: 'auto',
      };
    },
    togglePanel() {
      this.setPanelOpen(!this.open);
      if (this.open && !this.tree.length && !this.loading) this.refresh();
    },
    async refresh() {
      this.loading = true;
      try {
        const result = await this.loadScope();
        const data = (result && result.data) || result || {};
        this.tree = Array.isArray(data.tree) ? data.tree : [];
        this.syncOrganizationMultipleLabel();
        this.syncOrganizationOnlyLabel();
        this.syncStoreOnlyLabel();
      } catch (error) {
        this.tree = [];
        this.$Message.error((error && error.msg) || (error && error.message) || '权限范围读取失败');
      } finally { this.loading = false; }
    },
    toggleNode(key) {
      this.$set(this.expandedKeys, key, !this.expandedKeys[key]);
    },
    nodeStoreIds(node) {
      const ids = [];
      const walk = (item) => {
        const storeId = Number(item && (item.store_id || (item.node_type === 'store' ? item.id : 0)));
        if (storeId > 0) ids.push(storeId);
        (Array.isArray(item && item.children) ? item.children : []).forEach(walk);
      };
      walk(node);
      return [...new Set(ids)];
    },
    nodeStores(node) {
      const stores = [];
      const walk = (item) => {
        const storeId = Number(item && (item.store_id || (item.node_type === 'store' ? item.id : 0)));
        if (storeId > 0) {
          stores.push({
            id: storeId,
            name: item.title || item.name || `门店${storeId}`,
            orgId: Number(item.org_id || item.organization_id || 0),
          });
        }
        (Array.isArray(item && item.children) ? item.children : []).forEach(walk);
      };
      walk(node);
      return stores.filter((store, index, all) => all.findIndex((item) => item.id === store.id) === index);
    },
    isNodeSelected(option) {
      if (this.isOrganizationMultiple && option.storeId <= 0) {
        return this.selectedOrganizationIds.includes(Number(option.node && option.node.id));
      }
      if (this.isOrganizationOnly && option.storeId <= 0) {
        return this.selectedOrganizationKey === option.key ||
          Number(option.node && option.node.id) === Number(this.selectedOrganizationId || 0);
      }
      return this.selectedOrganizationKey === option.key;
    },
    syncOrganizationMultipleLabel() {
      if (!this.isOrganizationMultiple) return;
      const ids = this.selectedOrganizationIds;
      if (!ids.length) {
        this.displayLabel = this.emptyLabel;
        return;
      }
      if (ids.length === 1) {
        this.displayLabel = this.findOrganizationName(ids[0]) || '已选 1 个组织';
        return;
      }
      this.displayLabel = `已选 ${ids.length} 个组织`;
    },
    syncOrganizationOnlyLabel() {
      if (!this.isOrganizationOnly || this.selectedOrganizationKey) return;
      const orgId = Number(this.selectedOrganizationId || 0);
      if (!orgId) return;
      const label = this.findOrganizationName(orgId);
      if (label) this.displayLabel = label;
    },
    findOrganizationName(orgId) {
      const targetId = Number(orgId);
      let found = '';
      const walk = (nodes) => {
        (nodes || []).some((node) => {
          if (Number(node && node.id) === targetId && node.node_type !== 'store') {
            found = node.title || node.name || '';
            return true;
          }
          return walk(node && node.children);
        });
        return Boolean(found);
      };
      walk(this.tree);
      return found;
    },
    findStoreName(storeId) {
      const targetId = Number(storeId);
      let found = '';
      const walk = (nodes) => {
        (nodes || []).some((node) => {
          const nodeStoreId = Number(node && (node.store_id || (node.node_type === 'store' ? node.id : 0)));
          if (nodeStoreId === targetId) {
            found = node.title || node.name || '';
            return true;
          }
          return walk(node && node.children);
        });
        return Boolean(found);
      };
      walk(this.tree);
      return found;
    },
    syncStoreOnlyLabel() {
      if (!this.isStoreOnly) return;
      const storeId = Number(this.selectedStoreIds[0] || 0);
      if (!storeId) {
        this.displayLabel = this.emptyLabel;
        return;
      }
      this.displayLabel = this.findStoreName(storeId) || `门店 #${storeId}`;
    },
    selectNode(option) {
      if (option.storeId > 0) {
        if (this.isOrganizationMultiple || this.isOrganizationOnly) return;
        this.selectStore({ id: option.storeId, name: option.name, orgId: option.node.org_id || option.node.organization_id || 0 });
        return;
      }
      if (this.isOrganizationMultiple) {
        this.selectOrganization(option);
        return;
      }
      const storeIds = this.nodeStoreIds(option.node);
      // 人员可以直属于没有绑定门店的组织（例如总部中心、董事会）。
      // 组织选择本身必须生效，门店范围为空只表示该组织当前没有下级门店。
      this.selectedOrganizationKey = option.key;
      this.selectedOrganizationName = option.name;
      this.selectedStores = this.nodeStores(option.node);
      if (this.isStoreOnly) return;
      this.displayLabel = option.name;
      if (this.isOrganizationOnly) {
        this.emitChange([], option.name, {
          nodeType: 'org',
          orgId: Number(option.node && option.node.id) || 0,
          storeId: 0,
        });
        return;
      }
      this.emitChange(storeIds, option.name, {
        nodeType: 'org',
        orgId: Number(option.node && option.node.id) || 0,
        storeId: 0,
      });
    },
    selectOrganization(option) {
      const orgId = Number(option.node && option.node.id);
      if (!orgId) return;
      this.selectedOrganizationKey = option.key;
      this.selectedOrganizationName = option.name;
      this.selectedStores = this.nodeStores(option.node);
      const ids = this.selectedOrganizationIds.includes(orgId)
        ? this.selectedOrganizationIds.filter((id) => id !== orgId)
        : [...this.selectedOrganizationIds, orgId];
      this.selectedOrganizationIds = ids;
      this.syncOrganizationMultipleLabel();
      this.emitChange(ids, this.displayLabel, {
        nodeType: 'org',
        orgId,
        orgIds: ids,
      });
    },
    selectStore(store) {
      const storeId = Number(store && store.id);
      if (!storeId) return;
      this.displayLabel = store.name || `门店${storeId}`;
      this.setPanelOpen(false);
      this.emitChange([storeId], this.displayLabel, {
        nodeType: 'store',
        orgId: Number(store.orgId || 0),
        storeId,
      });
    },
    chooseAll() {
      this.clearSelection();
      this.setPanelOpen(false);
    },
    clearSelection() {
      this.selectedOrganizationKey = '';
      this.selectedOrganizationName = '';
      this.selectedStores = [];
      this.displayLabel = this.emptyLabel;
      this.selectedOrganizationIds = [];
      this.emitChange([], this.displayLabel, { nodeType: 'all', orgId: 0, storeId: 0, orgIds: [] });
    },
    emitChange(storeIds, label, context = {}) {
      const ids = [...new Set((storeIds || []).map(Number).filter(Boolean))];
      this.selectedStoreIds = ids;
      this.$emit('input', ids);
      this.$emit('change', { storeIds: ids, label, ...context });
    },
    reset() {
      this.selectedOrganizationKey = '';
      this.selectedOrganizationName = '';
      this.selectedStores = [];
      this.selectedStoreIds = [];
      this.selectedOrganizationIds = [];
      this.displayLabel = this.emptyLabel;
      this.setPanelOpen(false);
    },
  },
};
</script>

<style scoped>
.organization-store-scope-picker { position: relative; display: inline-block; }
.organization-store-scope-picker--org-multiple { display: flex; align-items: flex-start; width: 100%; gap: 8px; }
.organization-store-scope-picker__trigger { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; color: #515a6e; }
.organization-store-scope-picker--org-multiple .organization-store-scope-picker__trigger { width: auto; height: auto; min-height: 36px; flex: 1; justify-content: flex-start; white-space: normal; }
.organization-store-scope-picker__clear { flex: none; min-width: 46px; height: 36px; padding: 0 8px; }
.organization-store-scope-picker__selected-organizations { display: inline-flex; flex: 1; flex-wrap: wrap; gap: 5px; min-width: 0; }
.organization-store-scope-picker__selected-organization { display: inline-block; max-width: 180px; overflow: hidden; border-radius: 3px; padding: 2px 7px; background: #edf5ff; color: #2d8cf0; text-overflow: ellipsis; white-space: nowrap; }
.organization-store-scope-picker__panel { position: absolute; z-index: 10000; top: calc(100% + 6px); left: 0; width: 480px; overflow: hidden; border: 1px solid #dcdee2; border-radius: 4px; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .14); }
.organization-store-scope-picker__panel header { padding: 12px 14px 8px; border-bottom: 1px solid #edf0f5; color: #17233d; font-weight: 600; }
.organization-store-scope-picker__body { display: flex; min-height: 307px; }
.organization-store-scope-picker__tree { position: relative; flex: 1; max-height: 347px; overflow: auto; padding: 7px 8px; border-right: 1px solid #edf0f5; }
.organization-store-scope-picker__tree-row { display: flex; align-items: center; min-height: 31px; }
.organization-store-scope-picker__toggle, .organization-store-scope-picker__toggle-placeholder { display: inline-grid; flex: none; width: 22px; height: 31px; place-items: center; }
.organization-store-scope-picker__toggle { border: 0; border-radius: 3px; padding: 0; background: transparent; color: #657386; cursor: pointer; }
.organization-store-scope-picker__toggle:hover { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__option { display: block; flex: 1; min-width: 0; min-height: 31px; border: 0; border-radius: 3px; padding: 0 8px; background: #fff; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__option:hover, .organization-store-scope-picker__option--selected { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__option--store { color: #657386; }
.organization-store-scope-picker__stores { width: 205px; max-height: 347px; overflow: auto; padding: 10px 12px; }
.organization-store-scope-picker__stores-title { min-height: 18px; margin: 0 0 8px; color: #666; font-size: 12px; line-height: 18px; }
.organization-store-scope-picker__store-list button { display: block; width: 100%; min-height: 31px; border: 0; border-radius: 3px; padding: 5px 8px; background: transparent; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__store-list button:hover, .organization-store-scope-picker__store-list button.is-active { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__empty { margin: 0; padding: 8px 2px; color: #bbb; font-size: 12px; line-height: 1.55; }
.organization-store-scope-picker__panel footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-top: 1px solid #edf0f5; color: #999; font-size: 12px; line-height: 1.45; }
.organization-store-scope-picker__panel footer button { flex: none; border: 0; padding: 0; background: transparent; color: #2d8cf0; font: inherit; font-size: 12px; cursor: pointer; }
</style>
