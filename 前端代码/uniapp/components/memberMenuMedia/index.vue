<template>
 <view class="member-menu-media">
  <image v-if="image && failedImage !== image" :src="image" mode="aspectFit" @error="failedImage = image" />
  <text v-else class="iconfont" :class="icon" :style="{ fontSize: iconSize + 'rpx' }"></text>
 </view>
</template>
<script>
import { memberMenuImage, resolveMemberMenuIcon } from '../../../shared/memberMenuMedia';
import { HTTP_REQUEST_URL } from '@/config/app';
// 图片失败仅记录当前组件状态，不清空已保存的图片、图标或菜单资料。
export default {
 props: { item: { type: Object, default: () => ({}) }, iconSize: { type: Number, default: 38 } },
 data() { return { failedImage: '' }; },
 computed: {
  image() { const pic = memberMenuImage(this.item); return pic.startsWith('/') && !pic.startsWith('//') ? HTTP_REQUEST_URL.replace(/\/$/, '') + pic : pic; },
  icon() { return resolveMemberMenuIcon(this.item); }
 }
};
</script>
<style scoped>
.member-menu-media { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.member-menu-media image { width: 100%; height: 100%; display: block; }
.member-menu-media .iconfont { font-size: inherit; line-height: 1; color: var(--view-theme, #7b2941); }
</style>
