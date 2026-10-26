<template>
	<view>
		<base-drawer mode="bottom" :visible="storeShow" background-color="transparent" mask maskClosable @close="close">
			<view class="w-full bg--w111-fff rd-t-40rpx py-32">
				<view class="mt-76 px-32">
					<view class="mb-64 flex-between-center"
						v-for="(item,index) in storeData" :key="index"
						@tap='tapStore(index,item)'>
						<view class="flex-1 fs-28 text--w111-333 line1 text-center" :class="active==index?'text-w111-1890FF':''">{{item.name}}</view>
					</view>
					<view v-if="!storeData.length">
						<emptyPage title="暂无门店信息～" src="/statics/images/noOrder.gif"></emptyPage>
					</view>
				</view>
			</view>
		</base-drawer>
	</view>
</template>

<script>
	import {HTTP_REQUEST_URL} from '@/config/app';
	import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		props: {
			storeData: {
				type: Array,
				default: [],
			},
			storeShow: {
				type: Boolean,
				default: false
			}
		},
		components: {
			baseDrawer,
			emptyPage
		},
		data() {
			return {
				active: 0,
				imgHost: HTTP_REQUEST_URL
			};
		},
		methods: {
			tapStore(e,row){
				this.active = e;
				this.$emit('OnChangeStore', row);
			},
			close: function() {
				this.$emit('changeClose');
			},
		}
	}
</script>