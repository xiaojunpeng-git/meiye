<template>
    <div>
        <div id="container" style="width:100%;height:450px;"></div>
    </div>
</template>

<script>
    // +----------------------------------------------------------------------
    // | MOHE [ MOHE赋能开发者，助力企业发展 ]
    // +----------------------------------------------------------------------
    // | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
    // +----------------------------------------------------------------------
    // | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
    // +----------------------------------------------------------------------
    // | Author: MOHE Team <admin@mohe.com>
    // +----------------------------------------------------------------------
    import { TMap } from "./index";
    export default {
        props: {
            lat: {
                type: Number,
                default: 34.34127
            },
            lon: {
                type: Number,
                default: 108.93984
            },
            mapKey: {
                tyep: String
            },
            address: {
                tyep: String
            }
        },
        data() {
            return {
                geocoder: undefined,
				map: null,
                marker: null
            };
        },
        created() {
			this.initMap();
        },
        methods: {
			initMap() {
				TMap(this.mapKey).then(qq=>{
					// 初始化地图
					var center = new qq.maps.LatLng(this.lat, this.lon);
					this.map = new qq.maps.Map(document.getElementById('container'), {
					  // center: center, // 默认中心点
					  zoom: 15,
					});
					this.searchKeyword(this.address);
					// 监听地图点击事件
					qq.maps.event.addListener(this.map, 'click', this.handleMapClick);
				})
			},
			searchKeyword(address = "陕西省西安市") {
				// 使用腾讯位置服务API进行地址解析
				let that = this;
				that.$jsonp("https://apis.map.qq.com/ws/geocoder/v1?", {
				  address: `${address}`,
				  key: that.mapKey,
				  output: "jsonp",
				}).then((data) => {
					if (data.status === 0 && data.result.location) {
					  const location = data.result.location;
					  const latLng = new qq.maps.LatLng(location.lat, location.lng);
					  that.map.setCenter(latLng);
					  that.map.setZoom(15);
					  // 移除旧的标记点
					  if (that.marker) {
					    that.marker.setMap(null);
					  }
					  // 添加新的标记点
					  that.marker = new qq.maps.Marker({
					    position: latLng,
					    map: that.map,
					  });
					  that.$emit("getCoordinates", data.result);
					} else {
					  that.$Message.error(data.message)
					}
				}).catch(err=>{
					that.$Message.error("获取城市编码失败")
				})
			},

			handleMapClick(event) {
				let that = this;
				const latLng = event.latLng;
				// 移除旧的标记点
				if (that.marker) {
				  that.marker.setMap(null);
				}
				// 添加新的标记点
				that.marker = new qq.maps.Marker({
				  position: latLng,
				  map: that.map,
				});
				// 使用腾讯位置服务API进行逆地址解析
				that.$jsonp("https://apis.map.qq.com/ws/geocoder/v1?", {
				  location: `${latLng.getLat()},${latLng.getLng()}`,
				  key: that.mapKey,
				  output: "jsonp",
				}).then((data) => {
					if (data.status === 0 && data.result.address) {
					  that.$emit("getCoordinates", data.result);
					} else {
					  that.$Message.error(data.message);
					}
				}).catch(err=>{
					that.$Message.error("获取城市编码失败")
				})
			}
        }
    };
</script>

<style scoped>
</style>
