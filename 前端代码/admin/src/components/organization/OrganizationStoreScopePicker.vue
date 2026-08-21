<template>
  <div class="organization-store-scope-picker">
    <Button class="organization-store-scope-picker__trigger" :loading="loading" @click="togglePanel">
      <Icon type="ios-git-network-outline" />
      <span>{{ loading ? '读取权限范围' : displayLabel }}</span>
      <Icon type="ios-arrow-down" />
    </Button>

    <section v-if="open" class="organization-store-scope-picker__panel" aria-label="组织和门店权限范围">
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
                'organization-store-scope-picker__option--selected': selectedOrganizationKey === node.key,
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
        <button type="button" @click="chooseAll">当前权限范围</button>
        <span>选择组织查询其全部下级门店；选择门店仅查询该门店。</span>
      </footer>
    </section>
  </div>
</template>

<script>
export default {
  name: 'OrganizationStoreScopePicker',
  props: {
    value: { type: Array, default: () => [] },
    loadScope: { type: Function, required: true },
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
      displayLabel: '当前权限范围',
    };
  },
  computed: {
    visibleNodes() {
      const result = [];
      const walk = (nodes, depth) => {
        (nodes || []).forEach((node, index) => {
          const key = `${node.node_type === 'store' ? 'store' : 'org'}:${node.id}:${depth}:${index}`;
          const children = Array.isArray(node.children) ? node.children : [];
          const expanded = Boolean(this.expandedKeys[key]);
          result.push({
            key,
            node,
            depth,
            name: node.title || node.name || (node.node_type === 'store' ? `门店${node.id}` : `组织${node.id}`),
            storeId: Number(node.store_id || (node.node_type === 'store' ? node.id : 0)),
            hasChildren: children.length > 0,
            expanded,
          });
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
      },
    },
  },
  created() { this.refresh(); },
  methods: {
    togglePanel() {
      this.open = !this.open;
      if (this.open && !this.tree.length && !this.loading) this.refresh();
    },
    async refresh() {
      this.loading = true;
      try {
        const result = await this.loadScope();
        const data = (result && result.data) || result || {};
        this.tree = Array.isArray(data.tree) ? data.tree : [];
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
    selectNode(option) {
      if (option.storeId > 0) {
        this.selectStore({ id: option.storeId, name: option.name, orgId: option.node.org_id || option.node.organization_id || 0 });
        return;
      }
      const storeIds = this.nodeStoreIds(option.node);
      if (!storeIds.length) return;
      this.selectedOrganizationKey = option.key;
      this.selectedOrganizationName = option.name;
      this.selectedStores = this.nodeStores(option.node);
      this.displayLabel = option.name;
      this.emitChange(storeIds, option.name, {
        nodeType: 'org',
        orgId: Number(option.node && option.node.id) || 0,
        storeId: 0,
      });
    },
    selectStore(store) {
      const storeId = Number(store && store.id);
      if (!storeId) return;
      this.displayLabel = store.name || `门店${storeId}`;
      this.open = false;
      this.emitChange([storeId], this.displayLabel, {
        nodeType: 'store',
        orgId: Number(store.orgId || 0),
        storeId,
      });
    },
    chooseAll() {
      this.selectedOrganizationKey = '';
      this.selectedOrganizationName = '';
      this.selectedStores = [];
      this.displayLabel = '当前权限范围';
      this.open = false;
      this.emitChange([], this.displayLabel, { nodeType: 'all', orgId: 0, storeId: 0 });
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
      this.displayLabel = '当前权限范围';
      this.open = false;
    },
  },
};
</script>

<style scoped>
.organization-store-scope-picker { position: relative; display: inline-block; }
.organization-store-scope-picker__trigger { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; color: #515a6e; }
.organization-store-scope-picker__panel { position: absolute; z-index: 1000; top: calc(100% + 6px); left: 0; width: 480px; overflow: hidden; border: 1px solid #dcdee2; border-radius: 4px; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .14); }
.organization-store-scope-picker__panel header { padding: 12px 14px 8px; border-bottom: 1px solid #edf0f5; color: #17233d; font-weight: 600; }
.organization-store-scope-picker__body { display: flex; min-height: 230px; }
.organization-store-scope-picker__tree { position: relative; flex: 1; max-height: 260px; overflow: auto; padding: 7px 8px; border-right: 1px solid #edf0f5; }
.organization-store-scope-picker__tree-row { display: flex; align-items: center; min-height: 31px; }
.organization-store-scope-picker__toggle, .organization-store-scope-picker__toggle-placeholder { display: inline-grid; flex: none; width: 22px; height: 31px; place-items: center; }
.organization-store-scope-picker__toggle { border: 0; border-radius: 3px; padding: 0; background: transparent; color: #657386; cursor: pointer; }
.organization-store-scope-picker__toggle:hover { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__option { display: block; flex: 1; min-width: 0; min-height: 31px; border: 0; border-radius: 3px; padding: 0 8px; background: #fff; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__option:hover, .organization-store-scope-picker__option--selected { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__option--store { color: #657386; }
.organization-store-scope-picker__stores { width: 205px; max-height: 260px; overflow: auto; padding: 10px 12px; }
.organization-store-scope-picker__stores-title { min-height: 18px; margin: 0 0 8px; color: #666; font-size: 12px; line-height: 18px; }
.organization-store-scope-picker__store-list button { display: block; width: 100%; min-height: 31px; border: 0; border-radius: 3px; padding: 5px 8px; background: transparent; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__store-list button:hover, .organization-store-scope-picker__store-list button.is-active { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__empty { margin: 0; padding: 8px 2px; color: #bbb; font-size: 12px; line-height: 1.55; }
.organization-store-scope-picker__panel footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-top: 1px solid #edf0f5; color: #999; font-size: 12px; line-height: 1.45; }
.organization-store-scope-picker__panel footer button { flex: none; border: 0; padding: 0; background: transparent; color: #2d8cf0; font: inherit; font-size: 12px; cursor: pointer; }
</style>
