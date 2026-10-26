<template>
	<view :style="colorStyle" class="pt-40 pr-20 pl-20">
		<view class="pt-32 pr-24 pb-24 pl-24 bg--w111-fff rd-16rpx">
			<textarea v-model="desc" :maxlength="maxWords" placeholder="介绍一下自己" placeholder-style="color: #999999;" class="w-full h-240 fs-26" @input="inputHandle" />
			<view class="pt-28 text-right fs-28 text--w111-999">{{desc.length}}/{{maxWords}}</view>
		</view>
		<view class="fixed-lb w-full pb-safe bg--w111-fff">
			<view class="flex-y-center h-120 px-20">
				<navigator open-type="navigateBack" hover-class="none" class="flex-1 flex-center h-80 border-theme rd-40rpx text-w111-theme fw-500 fs-28">取消</navigator>
				<view class="flex-1 flex-center h-80 border-theme rd-40rpx ml-20 bg-color text--w111-fff fw-500 fs-28" @tap="saveDesc">保存</view>
			</view>
		</view>
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import {
		communityUserInfoApi,
		communityUpdateDescApi,
	} from "@/api/community.js";

	export default {
		mixins: [colors],
		data() {
			return {
				is_store: 0,
				id: 0,
				desc: '',
				maxLines: 5,
				maxWords: 100,
			}
		},
		onLoad(options) {
			this.id = options.id;
			this.is_store = options.is_store || 0;
			this.getUserInfo();
		},
		methods: {
			getUserInfo() {
				communityUserInfoApi(this.id, {
					is_store: this.is_store
				}).then(res => {
					this.desc = res.data.desc;
				}).catch(err => {
					this.$util.Tips({
						title: err
					}, {
						tab: 3
					});
				});
			},
			inputHandle(event) {
				this.$nextTick(() => {
					let value = event.detail.value;
					let lines = value.split('\n');
					this.desc = value.slice(0, this.maxWords);
					if (lines.length > this.maxLines) {
						lines.length = this.maxLines;
						this.desc = lines.join('\n');
						this.$util.Tips({
							title: `最多${this.maxLines}行`
						});
					}
				})

			},
			saveDesc() {
				if (this.desc == '') return this.$util.Tips({
					title: '请输入介绍'
				});
				communityUpdateDescApi({
					desc: this.desc
				}).then(res => {
					this.$util.Tips({
						title: res.msg
					}, {
						tab: 3
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
		},
	}
</script>

<style lang="scss" scoped>
</style>