<template>
	<tuiDrawer :visible="display" mode="bottom" backgroundColor="transparent" @close="close">
		<view class="store-regions">
			<view class="region-title">
				<text>选择门店地区</text>
				<button class="close-button" role="button" aria-label="关闭" @click="close"><text class="iconfont icon-ic_close"></text></button>
			</view>
			<view class="region-breadcrumb">
				<button class="crumb" role="button" aria-label="全部地区" :class="{active: !path.length}" @click="backTo(0)">全部地区</button>
				<button v-for="(item,index) in path" :key="item.id" class="crumb" role="button" :aria-label="item.label" @click="backTo(index + 1)">{{ item.label }}</button>
			</view>
			<scroll-view class="region-options" scroll-y :scroll-top="scrollTop">
				<view v-if="loading" class="region-state">加载中…</view>
				<view v-else-if="failed" class="region-state">
					<text>地区加载失败</text><button class="retry" role="button" aria-label="重试" @click="loadRegions">重试</button>
				</view>
				<view v-else>
					<button class="region-option all-option" role="button" :aria-label="allLabel" @click="submit(path)">
						<text>{{ allLabel }}</text><text v-if="isSelectedPath" class="iconfont icon-duihao2"></text>
					</button>
					<button v-for="item in options" :key="item.id" class="region-option" role="button" :aria-label="item.label" @click="select(item)">
						<text class="region-name">{{ item.label }}</text>
						<text v-if="item.children.length" class="iconfont icon-ic_rightarrow"></text>
						<text v-else-if="selectedId === item.id" class="iconfont icon-duihao2"></text>
					</button>
					<view v-if="!regions.length" class="region-state">暂无可筛选地区</view>
					<view v-if="incompleteCount && !path.length" class="region-note">{{ incompleteCount }}家门店地区信息待完善</view>
				</view>
			</scroll-view>
		</view>
	</tuiDrawer>
</template>

<script>
	import tuiDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import {getStoreRegions} from '@/api/new_store.js';

	// 门店专用筛选器，不复用全国地址缓存；草稿选择只在确认时提交给列表。
	export default {
		components: {tuiDrawer},
		props: {
			display: {type: Boolean, default: false},
			query: {type: Object, default: () => ({})},
			value: {type: Array, default: () => []}
		},
		data() {
			return {regions: [], path: [], loading: false, failed: false, incompleteCount: 0, requestId: 0, scrollTop: 0};
		},
		computed: {
			options() {
				return this.path.length ? this.path[this.path.length - 1].children : this.regions;
			},
			allLabel() {
				// 直辖市属于省级节点，但对用户不能称作“全省”。
				return this.path.length === 1 ? (/市$/.test(this.path[0].label) ? '全市门店' : '全省门店') : this.path.length === 2 ? '全市门店' : '全部地区';
			},
			selectedId() {
				return this.value.length ? this.value[this.value.length - 1].id : 0;
			},
			isSelectedPath() {
				return (this.path.length ? this.path[this.path.length - 1].id : 0) === this.selectedId;
			}
		},
		watch: {
			display(open) {
				if (open) this.loadRegions();
				else this.requestId++;
			},
			query: {
				deep: true,
				handler() {
					if (this.display) this.loadRegions();
				}
			}
		},
		beforeDestroy() {
			this.requestId++;
		},
		methods: {
			loadRegions() {
				const request = ++this.requestId;
				this.loading = true;
				this.failed = false;
				this.path = [];
				// 不缓存用户/关键字/商品相关结果；关闭或范围变化后丢弃旧响应。
				return getStoreRegions(Object.assign({}, this.query)).then(res => {
					if (request !== this.requestId) return;
					if (!res.data || !Array.isArray(res.data.regions)) throw new Error('Invalid region response');
					this.regions = res.data.regions;
					this.incompleteCount = Number(res.data.incomplete_count) || 0;
					let nodes = this.regions;
					for (const selected of this.value) {
						const node = nodes.find(item => item.id === selected.id);
						if (!node || !node.children.length) break;
						this.path.push(node);
						nodes = node.children;
					}
					this.loading = false;
				}).catch(() => {
					if (request !== this.requestId) return;
					this.loading = false;
					this.failed = true;
				});
			},
			backTo(length) {
				this.path = this.path.slice(0, length);
				this.resetScroll();
			},
			select(item) {
				const next = this.path.concat(item);
				if (!item.children.length) return this.submit(next);
				this.path = next;
				this.resetScroll();
			},
			resetScroll() {
				this.scrollTop = 1;
				this.$nextTick(() => {this.scrollTop = 0;});
			},
			submit(path) {
				// 只传地区 ID/名称，不把服务端树或门店信息带进列表查询。
				this.$emit('close');
				this.$emit('submit', path.map(item => ({id: item.id, label: item.label})));
			},
			close() {
				this.$emit('close');
			}
		}
	};
</script>

<style scoped lang="scss">
	.store-regions {background: #fff; border-radius: 16rpx 16rpx 0 0; padding-bottom: env(safe-area-inset-bottom); color: #333;}
	.region-title {height: 100rpx; display: flex; align-items: center; justify-content: center; position: relative; font-size: 30rpx; font-weight: 600;}
	.close-button {position: absolute; right: 12rpx; top: 12rpx; width: 76rpx; height: 76rpx; padding: 0; display: flex; align-items: center; justify-content: center; background: transparent; font-size: 30rpx;}
	button::after {border: 0;}
	.region-breadcrumb {display: flex; flex-wrap: wrap; padding: 0 24rpx; border-bottom: 1rpx solid #eee;}
	.crumb {margin: 0 20rpx 0 0; padding: 18rpx 0; max-width: 100%; border-radius: 0; background: transparent; color: #555; font-size: 26rpx; line-height: 36rpx; white-space: normal; overflow-wrap: break-word;}
	.crumb.active {color: var(--view-theme);}
	.region-options {height: 550rpx; max-height: 60vh; box-sizing: border-box;}
	.region-option {margin: 0; padding: 24rpx 32rpx; min-height: 88rpx; width: 100%; box-sizing: border-box; border-radius: 0; background: #fff; font-size: 28rpx; line-height: 40rpx; display: flex; align-items: center; justify-content: space-between; text-align: left;}
	.region-name {flex: 1; min-width: 0; overflow-wrap: break-word;}
	.region-option .iconfont {margin-left: 20rpx; flex-shrink: 0; color: var(--view-theme);}
	.all-option {color: var(--view-theme);}
	.region-state {padding: 48rpx 24rpx; text-align: center; color: #777; font-size: 26rpx;}
	.retry {display: flex; align-items: center; justify-content: center; width: 144rpx; height: 72rpx; margin: 20rpx auto 0; padding: 0; background: #f5f5f5; color: var(--view-theme); font-size: 26rpx; line-height: normal;}
	.region-note {padding: 24rpx 32rpx; color: #888; font-size: 24rpx;}
</style>
