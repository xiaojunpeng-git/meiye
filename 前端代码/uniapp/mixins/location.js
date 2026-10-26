// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import { getGeocoder } from '@/api/user.js';
export default {
  props: {
  	storeInfor: {
  		type: Object,
  		default: () => {}
  	}
  },
  data() {
    return {
		location: {},
		addressInfo:'选择地址',
		addressInfos:"选择地址"
	};
  },
  watch: {
  	location(value) {
		this.$emit('locationTap', value);
  	}
  },
  mounted(){
	uni.$off('activeHome');
	uni.$on('activeHome', data => {
		if(data){
			this.$emit('storeTap', data.id);
		}
	});
  },
  methods: {
	selfLocation() {
		let self = this
		// #ifdef MP || APP-PLUS
		uni.getLocation({
			type: 'gcj02',
			success: (res) => {
				try {
					uni.setStorageSync('user_latitude', res.latitude);
					uni.setStorageSync('user_longitude', res.longitude);
					self.getGeocoderCity(res.latitude,res.longitude);
					self.location = { latitude: res.latitude, longitude: res.longitude };
				} catch {}
			},
			fail:(res)=>{
				// #ifdef MP
				uni.getSetting({
					success: res=>{
						if(typeof(res.authSetting['scope.userLocation']) != 'undefined' && !res.authSetting['scope.userLocation']){
						  uni.setStorageSync('refuseLocation', true);
						}
					}
				})
				// #endif
			}
		});
		// #endif
		// #ifdef H5
		if (this.$wechat.isWeixin()) {
			this.$wechat.location().then(res => {
				uni.setStorageSync('user_latitude', res.latitude);
				uni.setStorageSync('user_longitude', res.longitude);
				self.getGeocoderCity(res.latitude,res.longitude);
			})
		} else {
			uni.getLocation({
				type: 'gcj02',
				success: function(res) {
					try {
						uni.setStorageSync('user_latitude', res.latitude);
						uni.setStorageSync('user_longitude', res.longitude);
						self.getGeocoderCity(res.latitude,res.longitude);
					} catch {}
				}
			});
		}
		// #endif
	},
	getGeocoderCity(latitude,longitude){
		getGeocoder({
			lat: latitude,
			long: longitude
		}).then(res=>{
			let address = res.data.address_component;
			this.addressInfo = address?(address.city+address.district).slice(0,8) : '选择地址';
			this.addressInfos = address?(address.city+address.district) : '选择地址';
		})
	},
	chooseLocation: function() {
		let that = this;
		if(that.fixConfig == 0){
			if(!this.belongIndex){
				return false;
			}
			uni.navigateTo({
				url:'/pages/store/list/index?type=1&isCollage=2&is_select=1&storeId='+this.storeInfor.storeId
			})
		}else{
			uni.chooseLocation({
				success: (res) => {
					let address = that.$util.addressInfo(res.address);
					this.addressInfo = address?(address.city+address.district).slice(0,8) : '选择地址';
					this.addressInfos = address?(address.city+address.district) : '选择地址';
					that.location = { latitude: res.latitude, longitude: res.longitude };
				},
				fail: (err)=>{
					console.log(err)
				}
			})
		}
	}
  }
};
