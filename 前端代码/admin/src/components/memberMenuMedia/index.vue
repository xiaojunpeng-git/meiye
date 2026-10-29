<template>
  <span class="member-menu-media" :style="{ fontSize: size + 'px' }">
    <img v-if="image && failedImage !== image" :src="image" :alt="item.name || ''" @error="failedImage = image" />
    <span v-else class="member-menu-glyph" aria-hidden="true">{{ glyph }}</span>
  </span>
</template>
<script>
import { memberMenuImage, resolveMemberMenuIcon, resolveMemberOrderIcon } from '../../../../shared/memberMenuMedia';
import glyphs from '@/assets/member-menu-glyphs.json';
// 图片失败仅记录当前组件状态，不清空已保存的图片、图标或菜单资料。
export default {
  props: { item: { type: Object, default: () => ({}) }, size: { type: Number, default: 24 }, kind: { type: String, default: 'menu' }},
  data() { return { failedImage: '' }; },
  computed: {
    // 订单入口按稳定业务key兜底，不能按服务菜单名称误配或读取无关旧图片。
    image() { return this.kind === 'order' ? '' : memberMenuImage(this.item); },
    // 使用会员端同一份本地字体；未知图标按通用会员图标兜底，不依赖外部字体CDN。
    glyph() { const icon = this.kind === 'order' ? resolveMemberOrderIcon(this.item) : resolveMemberMenuIcon(this.item); const key = icon.split(/\s+/).find(name => glyphs[name]); return String.fromCodePoint(parseInt(glyphs[key] || glyphs[this.kind === 'order' ? 'icon-ic_order1' : 'icon-ic_user'], 16)); }
  }
};
</script>
<style scoped>
@font-face { font-family: member-menu-preview; src: url('../../../../shared/memberMenuIcons.woff2') format('woff2'); }
.member-menu-media { display: inline-flex; width: 1em; height: 1em; align-items: center; justify-content: center; flex-shrink: 0; color: #7b2941; }
.member-menu-media img { display: block; width: 100%; height: 100%; object-fit: contain; }
.member-menu-glyph { font-family: member-menu-preview !important; font-style: normal; font-size: inherit; line-height: 1; }
</style>
