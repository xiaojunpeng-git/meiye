<template>
  <div class="tree-node">
    <div
      class="tree-node-content"
      :class="{ active: selectedId === node.id }"
      @click="handleClick"
    >
      <span
        class="tree-toggle"
        :class="{ leaf: !hasChildren, expanded: node.expanded }"
        @click.stop="handleToggle"
      >
        <Icon type="md-arrow-right" />
      </span>
      <span class="tree-icon">
        <Icon v-if="hasChildren" type="md-folder" />
        <Icon v-else type="md-map-pin" />
      </span>
      <span class="tree-label">{{ node.name }}</span>
      <span class="tree-count">{{ node.store_count || 0 }}</span>
    </div>
    <div
      v-if="hasChildren && node.expanded && node.children.length"
      class="tree-children"
    >
      <region-tree-node
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :selected-id="selectedId"
        @select="$emit('select', $event)"
        @toggle="$emit('toggle', $event)"
      />
    </div>
  </div>
</template>

<script>
export default {
  name: 'RegionTreeNode',
  props: {
    node: {
      type: Object,
      required: true,
    },
    selectedId: {
      type: [Number, String],
      default: null,
    },
  },
  computed: {
    hasChildren() {
      return this.node.children && this.node.children.length > 0;
    },
  },
  methods: {
    handleClick() {
      this.$emit('select', this.node);
    },
    handleToggle() {
      this.$emit('toggle', this.node);
    },
  },
};
</script>

<style scoped lang="stylus">
.tree-node
  position relative

.tree-node-content
  display flex
  align-items center
  gap 8px
  padding 10px 12px
  cursor pointer
  border-radius 6px
  transition all 0.3s
  position relative
  &:hover
    background #f6fffe
  &.active
    background #e6f7f3
    color #07cd9a
    .tree-icon
      color #07cd9a
    .tree-count
      background #d4f5ed
      color #07cd9a

.tree-toggle
  width 16px
  height 16px
  display flex
  align-items center
  justify-content center
  cursor pointer
  color #999
  font-size 10px
  transition transform 0.3s
  flex-shrink 0
  &.expanded
    transform rotate(90deg)
  &.leaf
    visibility hidden

.tree-icon
  width 20px
  height 20px
  display flex
  align-items center
  justify-content center
  color #07cd9a
  flex-shrink 0

.tree-label
  flex 1
  overflow hidden
  text-overflow ellipsis
  white-space nowrap
  font-size 14px
  color #333

.tree-count
  font-size 12px
  color #999
  background #f5f5f5
  padding 2px 8px
  border-radius 10px
  flex-shrink 0

.tree-children
  margin-left 16px
  position relative
  &::before
    content ''
    position absolute
    left -8px
    top 0
    bottom 0
    width 1px
    background #e8e8e8

.tree-children .tree-node-content::before
  content ''
  position absolute
  left -8px
  top 50%
  width 8px
  height 1px
  background #e8e8e8
</style>