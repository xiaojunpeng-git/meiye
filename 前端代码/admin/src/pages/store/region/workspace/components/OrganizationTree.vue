<template>
  <div class="tree-node" role="treeitem" :aria-expanded="hasChildren ? isOpen : false">
    <div
      v-if="visible"
      class="tree-row"
      :class="{ active: node.id === selectedId, 'search-match': isMatch }"
      :style="{ '--depth': depth }"
      :data-org-id="node.id"
      @click="$emit('select', node.id)"
    >
      <button
        class="tree-toggle"
        :class="{ open: isOpen, leaf: !hasChildren }"
        :aria-label="isOpen ? '收起' : '展开'"
        @click.stop="toggle"
      >
        <svg-icon name="chevron" />
      </button>
      <span class="tree-folder"><svg-icon :name="isRoot ? 'home' : 'branch'" /></span>
      <span class="tree-label" :title="node.name">{{ node.name }}</span>
      <button class="tree-more" aria-label="更多操作" @click.stop="$emit('more', { node, event: $event })"><svg-icon name="more" /></button>
    </div>
    <div v-if="hasChildren && isOpen" class="tree-children">
      <organization-tree
        v-for="child in visibleChildren"
        :key="child.id"
        :node="child"
        :nodes="nodes"
        :selected-id="selectedId"
        :search="search"
        :depth="depth + 1"
        @select="$emit('select', $event)"
        @toggle="$emit('toggle', $event)"
        @more="$emit('more', $event)"
      />
    </div>
  </div>
</template>

<script>
import SvgIcon from "./SvgIcon";

export default {
  name: "OrganizationTree",
  components: { SvgIcon },
  props: {
    node: { type: Object, required: true },
    nodes: { type: Array, required: true },
    selectedId: { type: [Number, String], default: 0 },
    search: { type: String, default: "" },
    depth: { type: Number, default: 0 },
  },
  computed: {
    isRoot() {
      return this.node.parentId === null || this.node.parentId === 0 || this.node.parentId === undefined;
    },
    children() {
      return this.nodes.filter((item) => Number(item.parentId) === Number(this.node.id));
    },
    hasChildren() {
      return this.children.length > 0;
    },
    isOpen() {
      return Boolean(this.search) || Boolean(this.node.open);
    },
    isMatch() {
      const query = (this.search || "").toLowerCase();
      if (!query) return false;
      return Boolean(this.node.name && this.node.name.toLowerCase().includes(query));
    },
    visible() {
      return !this.search || this.isMatch || this.children.some((child) => this.nodeVisible(child));
    },
    visibleChildren() {
      return this.children.filter((child) => this.nodeVisible(child));
    },
  },
  methods: {
    nodeVisible(node) {
      const query = (this.search || "").toLowerCase();
      if (!query) return true;
      const nameHit = node.name && node.name.toLowerCase().includes(query);
      if (nameHit) return true;
      return this.nodes.some((child) => Number(child.parentId) === Number(node.id) && this.nodeVisible(child));
    },
    toggle() {
      this.$emit("toggle", this.node);
    },
  },
};
</script>
