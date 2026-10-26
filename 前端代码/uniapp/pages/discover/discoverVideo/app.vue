<template>
	<web-view class="web-view" 
	:webview-styles="webviewStyles" 
	:src="url" :style="[boxStyle]"></web-view>
</template>

<script>
	export default {
		data() {
			return {
				windowH: 0,
				windowW: 0,
				webviewStyles: {
					progress: {
						color: 'transparent'
					}
				},
				url: ''
			}
		},
		computed:{
			boxStyle(){
				const res = uni.getWindowInfo();
				return {
					'width':res.windowWidth + 'px',
					'height':res.windowHeight + 'px'
				}
			}
		},
		onLoad(option) {
			this.url = decodeURIComponent(option.url + '&statusBarHeight=' + uni.getWindowInfo().statusBarHeight);
		}
	}
</script>
