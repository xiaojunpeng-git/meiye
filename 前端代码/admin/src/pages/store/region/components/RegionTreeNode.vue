<template>
  <div class="tree-node">
    <div
      class="tree-node-content"
      :class="{ active: selectedId === node.id }"
      @click="handleSelect"
    >
      <span
        class="tree-toggle"
        :class="{ expanded: node.expanded, leaf: !hasChildren }"
        @click.stop="handleToggle"
      >
        <Icon type="ios-arrow-forward" size="12" />
      </span>
      <span class="tree-icon">
        <Icon :type="hasChildren ? 'md-folder' : 'md-pin'" size="16" />
      </span>
      <span class="tree-label">{{ node.name }}</span>
      <span class="tree-count">{{ nodeCount }}</span>
      <span class="tree-node-actions" @click.stop>
        <Tooltip content="编辑" transfer>
          <span class="tree-action-btn" @click="$emit('edit', node)"><Icon type="md-create" size="14" /></span>
        </Tooltip>
        <Tooltip content="删除" transfer>
          <span class="tree-action-btn danger" @click="$emit('delete', node)"><Icon type="md-trash" size="14" /></span>
        </Tooltip>
      </span>
    </div>
    <div v-if="hasChildren" class="tree-children" :class="{ collapsed: !node.expanded }">
      <region-tree-node
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :selected-id="selectedId"
        :count-field="countField"
        @select="$emit('select', $event)"
        @toggle="$emit('toggle', $event)"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
      />
    </div>
  </div>
</template>

<script>
export default {
  name: "RegionTreeNode",
  props: {
    node: {
      type: Object,
      required: true,
    },
    selectedId: {
      type: Number,
      default: 0,
    },
    countField: {
      type: String,
      default: "store_count",
    },
  },
  computed: {
    hasChildren() {
      return Array.isArray(this.node.children) && this.node.children.length > 0;
    },
    nodeCount() {
      return Number(this.node[this.countField] || 0);
    },
  },
  methods: {
    handleSelect() {
      this.$emit("select", this.node);
    },
    handleToggle() {
      if (!this.hasChildren) return;
      this.$emit("toggle", this.node);
    },
  },
};
</script>

<style scoped lang="stylus">
$theme-color = #2d8cf0
$theme-light = #ecf5ff

.tree-node
  position relative
  font-size 14px

.tree-node-content
  display flex
  align-items center
  gap 8px
  padding 10px 12px
  cursor pointer
  border-radius 6px
  transition all 0.3s ease
  position relative
  min-height 40px

  .tree-node-actions
    display none
    align-items center
    gap 4px
    margin-left 4px

  &:hover
    background $theme-light

    .tree-node-actions
      display flex

  &.active
    background $theme-light
    color $theme-color
    
    .tree-icon
      color $theme-color
    
    .tree-count
      background $theme-light
      color $theme-color

.tree-toggle
  width 16px
  height 16px
  display flex
  align-items center
  justify-content center
  cursor pointer
  color #999
  font-size 10px
  transition transform 0.3s ease
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
  color $theme-color
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

.tree-action-btn
  width 22px
  height 22px
  display flex
  align-items center
  justify-content center
  border-radius 4px
  color #666
  cursor pointer

  &:hover
    background rgba(45, 140, 240, 0.15)
    color $theme-color

  &.danger:hover
    background rgba(237, 64, 20, 0.1)
    color #ed4014

.tree-children
  margin-left 20px
  position relative
  overflow hidden
  
  &::before
    content ''
    position absolute
    left -10px
    top 0
    bottom 0
    width 1px
    background #e8e8e8

  &.collapsed
    max-height 0
    opacity 0

  &:not(.collapsed)
    max-height 1000px
    opacity 1
    transition all 0.3s ease

.tree-children .tree-node-content::before
  content ''
  position absolute
  left -10px
  top 50%
  width 8px
  height 1px
  background #e8e8e8
  transform translateY(-50%)
</style>
