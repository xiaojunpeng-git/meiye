<script>
import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
export default {
	props:{
		visible: {
		    type: Boolean,
		    default: false,
		},
		ensureInfo: {
			type: Object,
			default: () => {}
		},
	},
	components:{
		baseDrawer
	},
	methods:{
		closeDrawer() {
		  this.$emit('closeDrawer');
		}
	}
}
</script>
<template>
  <view>
    <base-drawer mode="bottom" :visible="visible" background-color="transparent" mask maskClosable @close="closeDrawer">
      <view class="w-full bg--w111-fff rd-t-40rpx py-32">
        <view class="text-center fs-32 text--w111-333 fw-500">服务</view>
        <view class="mt-64 px-32">
          <view class="mb-38" v-for="(item,index) in ensureInfo.ensure" :key="index">
            <view class="flex-y-center">
              <image class="w-34 h-34" :src="item.image"></image>
              <text class="pl-12 text--w111-333 fs-28 fw-500">{{item.name}}</text>
            </view>
            <view class="mt-6 pl-40 fs-22 text--w111-999"> {{item.desc}} </view>
          </view>
        </view>
        <view class="mx-20 pb-safe">
          <view class="mt-52 h-72 flex-center rd-36px bg-color fs-26 text--w111-fff" @click="closeDrawer">确定</view>
        </view>
      </view>
    </base-drawer>
  </view>
</template>
