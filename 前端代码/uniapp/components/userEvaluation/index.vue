<template>
	<!-- 评价列表 -->
	<view>
		<scroll-view ref="scroll" scroll-x="true" class="scroll w-690 mt-24 pl-20" show-scrollbar="true" @scroll="scroll">
			<view id="scrollBox" ref="scrollBox" class="scrll-box">
				<view class="inline-block mr-20" v-for="item in reply" :key="item.id" @click.stop="details(item)">
					<view class="h-192 rd-16rpx bg--w111-f5f5f5 flex justify-between">
						<view class="flex-1 p-24">
							<view class="flex-y-center relative">
								<image class="w-64 h-64 rd-50-p111-" :class='item.is_money_level?"border-F1BB0D":"border-EEEEEE"' :src="item.avatar" mode="aspectFill"></image>
								<image src="@/static/images/king.png" class="w-18 h-18 absolute top-f12 left-36" v-if="item.is_money_level"></image>
								<view class="flex-col pl-16">
									<view class="flex-y-center">
										<text class="text--w111-333 fs-24">{{ item.nickname }}</text>
										<view v-if="item.level_name" class="flex-center min-w-52 pl-12 pr-12 h-26 bg-w111-FEF0D9 border-facc7d borde rd-50rpx fs-18 fw-500 text-w111-DFA541 ml-10">
										    <text class="iconfont icon-huiyuandengji fs-20 mr-4"></text>
										    {{item.level_name}}
										</view>
									</view>
									<view class="w-20 h-20 lh-20rpx">
										<text class="iconfont icon-ic_star1 fs-18 text-primary-con" v-for="star in Number(item.star)" :key="star"></text>
									</view>
								</view>
							</view>
							<view class="w-324 mt-12 text--w111-333 fs-24 break_word line2">{{ item.comment }}</view>
						</view>
						<image v-if="item.pics.length" class="w-192 h-192 rd-16rpx" :src="item.pics[0]"></image>
					</view>
				</view>
			</view>
		</scroll-view>
		<view class="flex-center mt-24" v-if="reply.length > 1">
			<view class="w-64 h-6 rd-3px bg--w111-eee scrpll-l">
				<view class="w-32 h-6 rd-3px scrpll-line" :style="{ left: srollLeft + '%' }"></view>
			</view>
		</view>
	</view>
</template>
<script>
import { mapGetters } from 'vuex';
import { getReplyPraise, getUnReplyPraise } from '@/api/store.js';
export default {
	computed: mapGetters(['isLogin']),
	props: {
		reply: {
			type: Array,
			default: () => []
		},
		fromTo: {
			type: Number,
			default: 0
		}
	},
	data() {
		return {
			srollLeft: 0, // 距离左边
			srollBoxWidth: 0, //内部宽度
			boxWidth: 0 //容器宽度
		};
	},
	mounted() {
		this.$nextTick((e) => {
			this.srollLeft = 0;
			const query = wx.createSelectorQuery().in(this);
			query
				.select('.scrll-box')
				.boundingClientRect((res) => {
					this.srollBoxWidth = res.width;
				})
				.exec();
			query
				.select('.scroll')
				.boundingClientRect((res) => {
					this.boxWidth = res.width - 10;
				})
				.exec();
		});
	},
	methods: {
		scroll(e) {
			this.srollLeft = ((e.detail.scrollLeft / (this.srollBoxWidth - this.boxWidth)) * 100).toFixed(0) / 2;
		},
		details(item) {
			if (this.isLogin) {
				uni.navigateTo({
					url: '/pages/goods/goods_comment_con/comment_con?id=' + item.id
				});
			} else {
				this.$emit('changeLogin');
			}
		},
		getpreviewImage: function (indexw, indexn) {
			uni.previewImage({
				urls: this.reply[indexw].pics,
				current: this.reply[indexw].pics[indexn]
			});
		},
		praise(item, indexw) {
			if (this.isLogin) {
				if (item.is_praise) {
					getUnReplyPraise(item.id).then((res) => {
						item.is_praise = !item.is_praise;
						item.praise = item.praise - 1;
						this.$emit('replyFun', this.reply);
						return this.$util.Tips({
							title: res.msg
						});
					});
				} else {
					getReplyPraise(item.id).then((res) => {
						item.is_praise = !item.is_praise;
						item.praise = item.praise + 1;
						this.$emit('replyFun', this.reply);
						return this.$util.Tips({
							title: res.msg
						});
					});
				}
			} else {
				this.$emit('changeLogin');
			}
		}
	}
};
</script>
<style lang="scss">
.scrll-box {
	display: flex;
	flex-wrap: nowrap;
	width: max-content;
}
.scrpll-l {
	position: relative;
}
.scrpll-line {
	position: absolute;
	background-color: var(--view-theme);
	left: 0;
	top: 0;
}
.text-primary-con {
	color: var(--view-theme);
}
</style>
