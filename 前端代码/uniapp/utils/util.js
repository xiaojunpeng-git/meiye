// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import {
	TOKENNAME,
	HTTP_REQUEST_URL
} from '../config/app.js';
import store from '../store';
import {
	customerType
} from '@/api/api.js'
// #ifdef APP-PLUS
import permision from "./permission.js"
// #endif
export default {
	/**
	 * opt  object | string
	 * to_url object | string
	 * 例:
	 * this.Tips('/pages/test/test'); 跳转不提示
	 * this.Tips({title:'提示'},'/pages/test/test'); 提示并跳转
	 * this.Tips({title:'提示'},{tab:1,url:'/pages/index/index'}); 提示并跳转值table上
	 * tab=1 一定时间后跳转至 table上
	 * tab=2 一定时间后跳转至非 table上
	 * tab=3 一定时间后返回上页面
	 * tab=4 关闭所有页面，打开到应用内的某个页面
	 * tab=5 关闭当前页面，跳转到应用内的某个页面
	 */
	Tips: function(opt, to_url) {
		if (typeof opt == 'string') {
			to_url = opt;
			opt = {};
		}
		let title = opt.title || '',
			icon = opt.icon || 'none',
			endtime = opt.endtime || 2000,
			success = opt.success;
		if (title) uni.showToast({
			title: title,
			icon: icon,
			duration: endtime,
			success
		})
		if (to_url != undefined) {
			if (typeof to_url == 'object') {
				let tab = to_url.tab || 1,
					url = to_url.url || '';
				switch (tab) {
					case 1:
						//一定时间后跳转至 table
						setTimeout(function() {
							uni.navigateTo({
								url: url
							})
						}, endtime);
						break;
					case 2:
						//跳转至非table页面
						setTimeout(function() {
							uni.navigateTo({
								url: url,
							})
						}, endtime);
						break;
					case 3:
						//返回上页面
						setTimeout(function() {
							// #ifndef H5
							const parsedDelta = parseInt(url, 10);
							uni.navigateBack({
								delta: Number.isFinite(parsedDelta) && parsedDelta > 0 ? parsedDelta : 1,
							})
							// #endif
							// #ifdef H5
							history.back();
							// #endif
						}, endtime);
						break;
					case 4:
						//关闭所有页面，打开到应用内的某个页面
						setTimeout(function() {
							uni.reLaunch({
								url: url,
							})
						}, endtime);
						break;
					case 5:
						//关闭当前页面，跳转到应用内的某个页面
						setTimeout(function() {
							uni.redirectTo({
								url: url,
							})
						}, endtime);
						break;
				}

			} else if (typeof to_url == 'function') {
				setTimeout(function() {
					to_url && to_url();
				}, endtime);
			} else {
				//没有提示时跳转不延迟
				setTimeout(function() {
					uni.navigateTo({
						url: to_url,
					})
				}, title ? endtime : 0);
			}
		}
	},
	// 格式化日期为 YYYY/MM/DD（获取本月）
	formatDate(date){
		const year = date.getFullYear()
		const month = String(date.getMonth() + 1).padStart(2, '0')
		const day = String(date.getDate()).padStart(2, '0')
		return `${year}/${month}/${day}`
	},
	getCurrentMonthRange(){
		const date = new Date();
		let firstDay = new Date(date.getFullYear(), date.getMonth(), 1);
		let lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0)
		return {
			start: this.formatDate(firstDay),
			end: this.formatDate(lastDay)
		}
	},
	// 格式化日期为 YYYY/MM/DD（获取本周）
	getCurrentWeekRange(){
		const now = new Date();
		const dayOfWeek = now.getDay(); // 0是周日，1是周一，...，6是周六
		const diffToMonday = dayOfWeek === 0 ? -6 : 1 - dayOfWeek; // 如果是周日，则上周一
		const monday = new Date(now);
		monday.setDate(now.getDate() + diffToMonday);
		const sunday = new Date(monday);
		sunday.setDate(monday.getDate() + 6);
		return {
		  start: this.formatDate(monday),
		  end: this.formatDate(sunday)
		};
	},
	getLastMonthRange() {
		const now = new Date();
		// 1. 计算上月的年份和月份（处理1月跨年的情况：1月的上月是去年12月）
		const currentMonth = now.getMonth(); // 0=1月，1=2月...11=12月
		const lastMonth = currentMonth === 0 ? 11 : currentMonth - 1; // 上月月份
		const lastMonthYear = currentMonth === 0 ? now.getFullYear() - 1 : now.getFullYear(); // 上月年份

		// 2. 生成上月1号的日期对象
		const lastMonthFirstDay = new Date(lastMonthYear, lastMonth, 1);

		// 3. 生成上月最后一天的日期对象（当月1号减1天 = 上月最后一天）
		const lastMonthLastDay = new Date(lastMonthYear, lastMonth + 1, 0);

		// 4. 返回格式化后的起止日期（复用你原有的formatDate方法）
		return {
			start: this.formatDate(lastMonthFirstDay),
			end: this.formatDate(lastMonthLastDay)
		};
	},
	// 格式化日期为 YYYY/MM/DD（获取昨天）
	getCurrentYesterdayRange(){
		const today = new Date();
		const yesterday = new Date(today);
		yesterday.setDate(today.getDate() - 1);
		return {
		  start: this.formatDate(yesterday),
		  end: this.formatDate(yesterday)
		};
	},
	// 格式化日期为 YYYY/MM/DD（获取今天）
	getCurrentTodayRange(){
		const today = new Date();
		return {
		  start: this.formatDate(today),
		  end: this.formatDate(today)
		};
	},
	// 处理价格
	HandlePrice: function(num, type) {
		let obj = []
		if (typeof num == 'number') {
			obj = num.toString().split(".");
		} else {
			obj = num.split(".");
		}
		if (type) {
			if (obj.length) {
				return '.' + obj[1];
			} else {
				return ''
			}
		} else {
			return obj[0];
		}
	},
	// 正常返回上一页，刷新时破坏了层级，所以判断判断返回首页；
	backPage(){
		let pages = getCurrentPages();
		if (pages.length > 1) {
			uni.navigateBack()
		} else {
			uni.switchTab({
				url: '/pages/index/index'
			});
		}
	},
	// 设置缓存
	setCache: function(key, value, ttl){
		const currentTime = Date.now();
		const expireTime = currentTime + ttl; // ttl是过期时间（单位：毫秒）
		uni.setStorage({
		  key: key,
		  data: {
		    value: value,
		    expireTime: expireTime
		  },
		  success: function() {
		    console.log('缓存设置成功');
		  }
		});
	},
	// 获取缓存
	getCache: function(key){
		const res = uni.getStorageSync(key);
		const currentTime = Date.now();
		const expireTime = res.expireTime;
		if(expireTime && currentTime < expireTime){
			// 缓存未过期，返回缓存数据
			return res.value;
		}else{
			uni.removeStorage({
				key: key,
				success:function(){
					console.log('缓存已过期，已移除');
				}
			})
			// 缓存已过期，返回null
			return null
		}
	},
	// 将网络图片转化为base64
	getBase64Image: function(imageUrl) {
	  return new Promise((resolve, reject) => {
	    uni.request({
	      url: imageUrl, // 服务器图片地址
	      responseType: 'arraybuffer', // 设置响应类型为arraybuffer以接收二进制数据
	      success: (res) => {
	        let base64Image = 'data:image/jpeg;base64,' + uni.arrayBufferToBase64(res.data);
	        resolve(base64Image);
	      },
	      fail: (err) => {
	        reject(err);
	      }
	    });
	  });
	},
	// 计算头部自定义导航高度；
	getWXStatusHeight() {
		// 获取距上
		const barTop = uni.getWindowInfo().statusBarHeight;
		// #ifdef MP
		// 获取胶囊按钮位置信息
		const menuButtonInfo = uni.getMenuButtonBoundingClientRect() || 0;
		// 获取导航栏高度
		const barHeight = menuButtonInfo.height + (menuButtonInfo.top - barTop) * 2;
		let barWidth = menuButtonInfo.width;
		// #endif
		// #ifndef MP
		// 获取导航栏高度
		const barHeight = parseInt(barTop) || 40;
		let barWidth = '100%'
		// #endif
		return {
			// #ifdef MP
			menuButtonInfo,
			// #endif
			barHeight,
			barTop,
			barWidth
		}
	},
	/**
	 * 省市区地址分解
	 */
	addressInfo: function(str) {
		let reg = /.+?(省|市|自治区|自治州|行政区|盟|旗|县|区)/g // 省市区的正则
		const area = str.match(reg) // 分割省市区
		let citys = ['北京市', '天津市', '上海市', '重庆市']
		let address_component = {
			city: "",
			district: "",
			province: area[0]
		}
		if (citys.indexOf(area[0]) == -1) {
			address_component.city = area[1]
			address_component.district = area[2]
		} else {
			address_component.city = area[0]
			address_component.district = area[1]
		}
		return address_component
	},
	/**
	 * 跳转路径封装函数
	 * @param url 跳转路径
	 * 选择跳转到其他小程序时，在h5也可以跳转路径
	 */
	JumpPath: function(url) {
		let arr = url.split('@APPID=');
		if (arr.length > 1) {
			//#ifdef MP
			uni.navigateToMiniProgram({
				appId: arr[arr.length - 1], // 此为生活缴费appid
				path: arr[0], // 此为生活缴费首页路径
				envVersion: "release",
				success: res => {
					console.log("打开成功", res);
				},
				fail: err => {
					console.log('sgdhgf', err);
				}
			})
			//#endif
			//#ifndef MP
			this.Tips({
				title: 'h5与app端不支持跳转外部小程序'
			});
			//#endif
		} else {
			if (url == '/pages/short_video/appSwiper/index' || url == '/pages/short_video/nvueSwiper/index') {
				//#ifdef APP
				url = '/pages/short_video/appSwiper/index'
				//#endif
				//#ifndef APP
				url = '/pages/short_video/nvueSwiper/index'
				//#endif
			}
			if (url.indexOf("http") != -1) {
				// #ifdef H5
				location.href = url;
				// #endif
				// #ifdef MP || APP-PLUS
				uni.navigateTo({
					url: `/pages/annex/web_view/index?url=${url}`
				});
				// #endif
			} else {
				if (['/pages/goods_cate/goods_cate', '/pages/order_addcart/order_addcart', '/pages/user/index',
						'/pages/index/index','/pages/store_list/index'
					]
					.indexOf(url) == -1) {
					uni.navigateTo({
						url: url
					})
				} else {
					uni.reLaunch({
						url: url
					})
				}
			}
		}
	},
	/**
	 * 移除数组中的某个数组并组成新的数组返回
	 * @param array array 需要移除的数组
	 * @param int index 需要移除的数组的键值
	 * @param string | int 值
	 * @return array
	 *
	 */
	ArrayRemove: function(array, index, value) {
		const valueArray = [];
		if (array instanceof Array) {
			for (let i = 0; i < array.length; i++) {
				if (typeof index == 'number' && array[index] != i) {
					valueArray.push(array[i]);
				} else if (typeof index == 'string' && array[i][index] != value) {
					valueArray.push(array[i]);
				}
			}
		}
		return valueArray;
	},
	/**
	 * 生成海报获取文字
	 * @param string text 为传入的文本
	 * @param int num 为单行显示的字节长度
	 * @return array
	 */
	textByteLength: function(text, num) {
		let strLength = 0;
		let rows = 1;
		let str = 0;
		let arr = [];
		for (let j = 0; j < text.length; j++) {
			if (text.charCodeAt(j) > 255) {
				strLength += 2;
				if (strLength > rows * num) {
					strLength++;
					arr.push(text.slice(str, j));
					str = j;
					rows++;
				}
			} else {
				strLength++;
				if (strLength > rows * num) {
					arr.push(text.slice(str, j));
					str = j;
					rows++;
				}
			}
		}
		arr.push(text.slice(str, text.length));
		return [strLength, arr, rows] //  [处理文字的总字节长度，每行显示内容的数组，行数]
	},
	textByteLengths: function(text, num, width, ctx) {
		let strLength = 0;
		let rows = 1;
		let str = 0;
		let arr = [];
		let start = 0;
		for (let j = 0; j < text.length; j++) {
			let txt = text.slice(start, j);
			if (rows > 1) {
				txt = txt + '...';
			}
			if (ctx.measureText(txt).width > width) {
				let end = j - 1;
				arr.push(text.slice(start, end));
				start = end;
				rows++;
			}
		}
		arr.push(text.slice(start));
		return [strLength, arr, rows] //  [处理文字的总字节长度，每行显示内容的数组，行数]
	},

	/**
	 * 获取分享海报
	 * @param array arr2 海报素材
	 * @param string store_name 素材文字
	 * @param string price 价格
	 * @param string ot_price 原始价格
	 * @param function successFn 回调函数
	 *
	 *
	 */
	PosterCanvas: function(fontColor, themeColor, siteName, arr2, store_name, price, ot_price, posterTitle, successFn) {
		let that = this;
		// uni.showLoading({
		// 	title: '海报生成中',
		// 	mask: true
		// });
		const ctx = uni.createCanvasContext('myCanvas');
		ctx.clearRect(0, 0, 0, 0);


		/**
		 * 只能获取合法域名下的图片信息,本地调试无法获取
		 *
		 */
		ctx.fillStyle = '#fff';
		ctx.fillRect(0, 0, 750, 1306);
		Promise.all([
			new Promise((resolve, reject) => {
				uni.getImageInfo({
				    src: arr2[0],
				    success: resolve,
				    fail: reject
				});
			}),
			new Promise((resolve, reject) => {
			    uni.getImageInfo({
			        src: arr2[1],
			        success: resolve,
			        fail: reject
			    });
			})
		]).then(([res,resImg])=>{
			const WIDTH = res.width;
			const productWidth = resImg.width;  // arr2[1] 的宽度
			const productHeight = resImg.height; // arr2[1] 的高度
			// const HEIGHT = res.height;
			// ctx.drawImage(arr2[0], 0, 0, WIDTH, 1050);
			ctx.save();
			ctx.setFillStyle(themeColor);
			ctx.fillRect(0, 0, WIDTH, 108);
			ctx.setFontSize(33)
			ctx.setFillStyle('#fff');
			ctx.setTextAlign('center');
			ctx.fillText(posterTitle, WIDTH / 2, 65);
			ctx.save();
			// 商品图片的显示区域（685x685）
			const containerWidth = 685;
			const containerHeight = 685;
			const containerX = 32; // x 起始位置
			const containerY = 141; // y 起始位置

			// 计算图片缩放后的尺寸（保持宽高比）
			let drawWidth, drawHeight;
			const ratio = Math.min(
			    containerWidth / productWidth,
			    containerHeight / productHeight
			);
			drawWidth = productWidth * ratio;
			drawHeight = productHeight * ratio;

			// 计算居中位置
			const offsetX = (containerWidth - drawWidth) / 2;
			const offsetY = (containerHeight - drawHeight) / 2;
			// 先绘制淡灰色背景（确保图片未覆盖的区域是纯色）
			ctx.setFillStyle('#f9f9f9');
			ctx.fillRect(containerX, containerY, containerWidth, containerHeight);

			// 再绘制居中图片
			ctx.drawImage(
			    arr2[1],
			    containerX + offsetX, // x 位置（居中）
			    containerY + offsetY, // y 位置（居中）
			    drawWidth,
			    drawHeight
			);
			ctx.save();

			// ctx.setFillStyle(themeColor);
			// ctx.fillRect(32, 847, 85, 40);
			// ctx.setFontSize(30)
			// ctx.setFillStyle('#fff');
			// ctx.setTextAlign('left');
			// ctx.fillText('商城', 43, 878);
			// ctx.save();
			// ctx.setFontSize(36)
			// ctx.setFillStyle('#000');
			// ctx.fillText(siteName, 140, 880);
			// ctx.save();

			// const CONTENT_ROW_LENGTH = 36;
			ctx.setFontSize(38);
			const CONTENT_ROW_LENGTH = 35;
			let [contentLeng, contentArray, contentRows] = that.textByteLengths(store_name,
				CONTENT_ROW_LENGTH, 690, ctx);
			if (contentRows > 2) {
				contentRows = 2;
				let textArray = contentArray.slice(0, 2);
				textArray[textArray.length - 1] += '...';
				contentArray = textArray;
			}
			ctx.setTextAlign('left');
			// ctx.setFontSize(38);
			ctx.setFillStyle('#000');
			let contentHh = 38;
			for (let m = 0; m < contentArray.length; m++) {
				if (m) {
					ctx.fillText(contentArray[m], 30, 900 + contentHh * m + 18);
				} else {
					ctx.fillText(contentArray[m], 30, 900 + contentHh * m);
				}
			}
			ctx.save();
			ctx.setTextAlign('left')
			ctx.setFontSize(32);
			ctx.setFillStyle('#999');
			ctx.fillText('￥' + ot_price, 30, 1148 + contentHh);
			var underline = function(ctx, text, x, y, size, color, thickness, offset) {
				var width = ctx.measureText(text).width;

				switch (ctx.textAlign) {
					case "center":
						x -= (width / 2);
						break;
					case "right":
						x -= width;
						break;
				}

				y += size + offset;

				ctx.beginPath();
				ctx.strokeStyle = color;
				ctx.lineWidth = thickness;
				ctx.moveTo(x, y);
				ctx.lineTo(x + width, y);
				ctx.stroke();
			}
			underline(ctx, '￥' + ot_price, 30, 1146, 28, '#999', 2, 0);
			ctx.save();
			ctx.setTextAlign('left')
			ctx.setFontSize(54);
			ctx.setFillStyle(fontColor);
			ctx.fillText('￥' + price, 20, 1070 + contentHh);
			ctx.save();
			ctx.setFillStyle('#F5F5F5');
			ctx.fillRect(0, 1222, WIDTH, 84);
			ctx.setFontSize(28);
			ctx.setFillStyle('#999');
			ctx.setTextAlign('center');
			ctx.fillText('长按识别图中的二维码查看商品详情', WIDTH / 2, 1272);
			ctx.save();
			let r = 93;
			let d = r * 2;
			let cx = WIDTH - d - 30;
			// let cy = 1112;
			let cy = 1000;
			ctx.arc(cx + r, cy + r, r, 0, 2 * Math.PI);
			ctx.drawImage(arr2[2], cx, cy, d, d);
			ctx.restore();
			// ctx.setTextAlign('left')
			// ctx.setFontSize(28);
			// ctx.setFillStyle('#999');
			// ctx.fillText('长按或扫描查看', 490, 1030 + contentHh);
			ctx.draw(true, function() {
				uni.canvasToTempFilePath({
					canvasId: 'myCanvas',
					fileType: 'png',
					// destWidth: WIDTH,
					// destHeight: HEIGHT,
					success: function(res) {
						// uni.hideLoading();
						successFn && successFn(res.tempFilePath);
					}
				})
			});
		}).catch(err=>{
			that.Tips({
				title: '无法获取图片信息'
			});
		})
		// uni.getImageInfo({
		// 	src: arr2[0],
		// 	success: function(res) {

		// 	},
		// 	fail: function(err) {
		// 		that.Tips({
		// 			title: '无法获取图片信息'
		// 		});
		// 	}
		// })
	},
	/**
	 * 获取砍价/拼团海报
	 * @param array arr2 海报素材 背景图
	 * @param string store_name 素材文字
	 * @param string price 价格
	 * @param string ot_price 原始价格
	 * @param function successFn 回调函数
	 *
	 *
	 */
	bargainPosterCanvas: function(arr2, title, label, msg, price, userData, typeText, successFn) {
		let that = this;
		const ctx = uni.createCanvasContext('myCanvas');
		ctx.clearRect(0, 0, 0, 0);
		/**
		 * 只能获取合法域名下的图片信息,本地调试无法获取
		 *
		 */
		Promise.all([
			new Promise((resolve, reject) => {
				uni.getImageInfo({
				    src: arr2[0],
				    success: resolve,
				    fail: reject
				});
			}),
			new Promise((resolve, reject) => {
			    uni.getImageInfo({
			        src: arr2[1],
			        success: resolve,
			        fail: reject
			    });
			})
		]).then(([res,resImg])=>{
			const wd = res.width;
			const hg = res.height;
			const productWidth = resImg.width;  // arr2[1] 的宽度
			const productHeight = resImg.height; // arr2[1] 的高度
			ctx.fillStyle = '#FFFFFF';
			ctx.fillRect(0, 0, wd, hg);
			ctx.drawImage(arr2[0], 0, 0, wd, hg);
			ctx.drawImage(arr2[3], wd * 0.032, hg * 0.0684, wd * 0.97, hg * 0.85);

			ctx.save();
			that.handleBorderRect(ctx, wd * (1 - 0.7733) / 2, hg * 0.2315, wd * 0.7733, wd * 0.7733, wd * 0.016)
			ctx.clip();

			// 商品图片的显示区域（685x685）
			const containerWidth = wd * 0.7733;
			const containerHeight = wd * 0.7733;
			const containerX = wd * (1 - 0.7733) / 2; // x 起始位置
			const containerY = hg * 0.2315; // y 起始位置

			// 计算图片缩放后的尺寸（保持宽高比）
			let drawWidth, drawHeight;
			const ratio = Math.min(
			    containerWidth / productWidth,
			    containerHeight / productHeight
			);
			drawWidth = productWidth * ratio;
			drawHeight = productHeight * ratio;

			// 计算居中位置
			const offsetX = (containerWidth - drawWidth) / 2;
			const offsetY = (containerHeight - drawHeight) / 2;
			// 先绘制淡灰色背景（确保图片未覆盖的区域是纯色）
			ctx.setFillStyle('#f9f9f9');
			ctx.fillRect(containerX, containerY, containerWidth, containerHeight);

			// 再绘制居中图片
			ctx.drawImage(
			    arr2[1],
			    containerX + offsetX, // x 位置（居中）
			    containerY + offsetY, // y 位置（居中）
			    drawWidth,
			    drawHeight
			);
			// ctx.drawImage(arr2[1], wd * (1 - 0.7733) / 2, hg * 0.2315, wd * 0.7733, wd * 0.7733);
			ctx.restore();

			ctx.drawImage(arr2[2], wd * 0.70, hg * 0.78, wd * 0.18, wd * 0.18);
			ctx.drawImage(arr2[6], wd * 0.112, hg * 0.8122, wd * 0.0346, wd * 0.0346);

			ctx.save();
			that.handleBorderRect(ctx, wd * 0.0746, hg * 0.0224, wd * 0.0693, wd * 0.0693, wd * 0.0693 / 2)
			ctx.clip();
			ctx.fillStyle = '#FFFFFF';
			ctx.fillRect(wd * 0.0746, hg * 0.0224, wd * 0.0693, wd * 0.0693);
			ctx.restore();

			ctx.save();
			that.handleBorderRect(ctx, wd * 0.076, hg * 0.0232, wd * 0.0666, wd * 0.0666, wd * 0.0666 / 2)
			ctx.clip();
			ctx.drawImage(arr2[5], wd * 0.076, hg * 0.0232, wd * 0.0666, wd * 0.0666);
			ctx.restore();

			ctx.font = '60px sans-serif'
			ctx.setFontSize(60);
			// 测量价格文本宽度
			const metrics = ctx.measureText(price)
			ctx.drawImage(arr2[4], wd * 0.1546 + metrics.width + 24, hg * 0.7929, wd * 0.184, hg * 0.0491);

			// 价格
			ctx.setTextAlign('left');
			ctx.setTextBaseline('top');
			ctx.setFillStyle('#FF2F26');
			ctx.fillText(price, wd * 0.1546, hg * 0.7912);
			//标题
			const CONTENT_ROW_LENGTH = 28;
			let [contentLeng, contentArray, contentRows] = that.textByteLength(title,
				CONTENT_ROW_LENGTH);
			if (contentRows > 2) {
				contentRows = 2;
				let textArray = contentArray.slice(0, 2);
				textArray[textArray.length - 1] += '…';
				contentArray = textArray;
			}
			ctx.setFillStyle('#333333');
			if (contentArray.length < 2) {
				ctx.setFontSize(32);
			} else {
				ctx.setFontSize(32);
			}
			let contentHh = 12;
			for (let m = 0; m < contentArray.length; m++) {
				if (m) {
					ctx.fillText(contentArray[m], wd * 0.112, hg * 0.1298 + contentHh * m + 36, wd * 0.624);
				} else {
					ctx.fillText(contentArray[m], wd * 0.112, hg * 0.1298, wd * 0.624);
				}
			}
			// 标签内容
			// ctx.setTextAlign('left')
			// ctx.setFontSize(16);
			// ctx.setFillStyle('#FFF');
			// ctx.fillText('label', wd * labelx, hg * labely);
			// ctx.save();
			// msg
			ctx.setFillStyle('#666666');
			ctx.setFontSize(25);
			ctx.fillText(msg, wd * 0.1146, hg * 0.8543);
			// 邀请你
			ctx.setFillStyle('#FFFFFF');
			ctx.setFontSize(27);
			ctx.fillText(userData.nickname + '邀请你' + typeText, wd * 0.1706, hg * 0.0318);
			// 扫描二维码
			ctx.setFontSize(24);
			ctx.setTextAlign('center');
			ctx.fillText('长按识别或扫描二维码进入', wd * 0.5, hg * 0.9482);

			ctx.draw(false, () => {
				uni.canvasToTempFilePath({
					canvasId: 'myCanvas',
					fileType: 'png',
					quality: 1,
					success: (res) => {
						successFn && successFn(res.tempFilePath);
						uni.hideLoading();
					}
				})
			});
		}).catch(err=>{
			uni.hideLoading();
			that.Tips({
				title: '无法获取图片信息'
			});
		})
		// uni.getImageInfo({
		// 	src: arr2[0],
		// 	success: function(res) {

		// 	},
		// 	fail: function(err) {
		// 		uni.hideLoading();
		// 		that.Tips({
		// 			title: '无法获取图片信息'
		// 		});
		// 	}
		// })
	},

	/**
	 * 图片圆角设置
	 * @param string x x轴位置
	 * @param string y y轴位置
	 * @param string w 图片宽
	 * @param string y 图片高
	 * @param string r 圆角值
	 */
	handleBorderRect(ctx, x, y, w, h, r) {
		ctx.beginPath();
		// 左上角
		ctx.arc(x + r, y + r, r, Math.PI, 1.5 * Math.PI);
		ctx.moveTo(x + r, y);
		ctx.lineTo(x + w - r, y);
		ctx.lineTo(x + w, y + r);
		// 右上角
		ctx.arc(x + w - r, y + r, r, 1.5 * Math.PI, 2 * Math.PI);
		ctx.lineTo(x + w, y + h - r);
		ctx.lineTo(x + w - r, y + h);
		// 右下角
		ctx.arc(x + w - r, y + h - r, r, 0, 0.5 * Math.PI);
		ctx.lineTo(x + r, y + h);
		ctx.lineTo(x, y + h - r);
		// 左下角
		ctx.arc(x + r, y + h - r, r, 0.5 * Math.PI, Math.PI);
		ctx.lineTo(x, y + r);
		ctx.lineTo(x + r, y);

		ctx.fill();
		ctx.closePath();
	},
	/**
	 * 用户信息分享海报
	 * @param array arr2 海报素材  1背景 0二维码
	 * @param string nickname 昵称
	 * @param string sitename 价格
	 * @param function successFn 回调函数
	 *
	 *
	 */
	userPosterCanvas: function(arr2, nickname, sitename, index, w, h, successFn) {
		let that = this;
		// uni.showLoading({
		// 	title: '海报生成中',
		// 	mask: true
		// });
		const ctx = uni.createCanvasContext('myCanvas' + index);
		ctx.clearRect(0, 0, 0, 0);
		/**
		 * 只能获取合法域名下的图片信息,本地调试无法获取
		 *
		 */
		uni.getImageInfo({
			src: arr2[1],
			success: function(res) {
				// const WIDTH = res.width;
				// const HEIGHT = res.height;
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, w, h);
				ctx.drawImage(arr2[1], 0, 0, w, h);
				ctx.save();


				ctx.drawImage(arr2[0], w-86, 386, 70, 70);
				ctx.save();

				ctx.setFontSize(16);
				ctx.setFillStyle('#333');
				ctx.fillText(nickname, 45, 414);
				ctx.save();


				const CONTENT_ROW_LENGTH = 25;
				const store_name = '邀请您加入' + sitename;
				let [contentLeng, contentArray, contentRows] = that.textByteLength(store_name,
					CONTENT_ROW_LENGTH);
				if (contentRows > 2) {
					contentRows = 2;
					let textArray = contentArray.slice(0, 2);
					textArray[textArray.length - 1] += '...';
					contentArray = textArray;
				}
				ctx.setTextAlign('left');
				ctx.setFillStyle('#999999');
				ctx.setFontSize(12);

				let contentHh = 38;
				for (let m = 0; m < contentArray.length; m++) {
					if (m) {
						ctx.fillText(contentArray[m], 16, 456);
					} else {
						ctx.fillText(contentArray[m], 16, 440);
					}
				}
				ctx.save();

				that.handleBorderRect(ctx, 16, 396, 24, 24, 12)
				ctx.clip();
				ctx.drawImage(arr2[2], 16, 396, 24, 24);
				ctx.draw(true, function() {
					uni.canvasToTempFilePath({
						canvasId: 'myCanvas' + index,
						fileType: 'png',
						quality: 1,
						success: function(res) {
							uni.hideLoading();
							successFn && successFn(res.tempFilePath);
						}
					})
				});
			},
			fail: function(err) {
				uni.hideLoading();
				that.Tips({
					title: ''
				});
			}
		})
	},

	// uniapp 判断 IOS和Android的GPS是否开启
	checkOpenGPSServiceByAndroidIOS() {
		let system = uni.getSystemInfoSync(); // 获取系统信息
		console.log('yyy', system);
		if (system.platform === 'android') { // 判断平台
			var context = plus.android.importClass("android.content.Context");
			var locationManager = plus.android.importClass("android.location.LocationManager");
			var main = plus.android.runtimeMainActivity();
			var mainSvr = main.getSystemService(context.LOCATION_SERVICE);
			if (!mainSvr.isProviderEnabled(locationManager.GPS_PROVIDER)) {
				return false
			} else {
				return true
			}
		} else if (system.platform === 'ios' || system.platform === 'devtools') {
			var cllocationManger = plus.ios.import("CLLocationManager")
			var enable = cllocationManger.locationServicesEnabled();
			var status = cllocationManger.authorizationStatus();
			plus.ios.deleteObject(cllocationManger);
			if (enable && status != 2) {
				return true
				console.log("手机系统的定位已经打开");
			} else {
				return false
				console.log("手机系统的定位没有打开");
			}
		}
	},

	/*
	 * 单图上传
	 * @param object opt
	 * @param callable successCallback 成功执行方法 data
	 * @param callable errorCallback 失败执行方法
	 */
	uploadImageOne: function(opt, successCallback, errorCallback) {
		const app = getApp();
		app.globalData.isUploading = true;
		let that = this;
		if (typeof opt === 'string') {
			let url = opt;
			opt = {};
			opt.url = url;
		}
		let count = opt.count || 1,
			sizeType = opt.sizeType || ['compressed'],
			sourceType = opt.sourceType || ['album', 'camera'],
			is_load = opt.is_load || true,
			uploadUrl = opt.url || '',
			inputName = opt.name || 'pics',
			fileType = opt.fileType || 'image';
		uni.chooseImage({
			count: count, //最多可以选择的图片总数
			sizeType: sizeType, // 可以指定是原图还是压缩图，默认二者都有
			sourceType: sourceType, // 可以指定来源是相册还是相机，默认二者都有
			success: function(res) {
				app.globalData.isUploading = false;
				//启动上传等待中...
				uni.showLoading({
					title: '图片上传中',
				});
				uni.uploadFile({
					url: HTTP_REQUEST_URL + '/api/' + uploadUrl,
					filePath: res.tempFilePaths[0],
					fileType: fileType,
					name: inputName,
					formData: {
						'filename': inputName
					},
					header: {
						// #ifdef MP
						"Content-Type": "multipart/form-data",
						// #endif
						[TOKENNAME]: 'Bearer ' + store.state.app.token
					},
					success: function(res) {
						uni.hideLoading();
						if (res.statusCode == 403) {
							that.Tips({
								title: res.data
							});
						} else if (res.statusCode == 413) {
							that.Tips({
								title: '上传图片失败,请重新上传小尺寸图片'
							});
						} else {
							let data = res.data ? JSON.parse(res.data) : {};
							if (data.status == 200) {
								successCallback && successCallback(data)
							} else {
								errorCallback && errorCallback(data);
								that.Tips({
									title: data.msg
								});
							}
						}
					},
					fail: function(res) {
						uni.hideLoading();
						that.Tips({
							title: '上传图片失败'
						});
					}
				})
			}
		})
	},
	/*
	 * 单图上传压缩版
	 * @param object opt
	 * @param callable successCallback 成功执行方法 data
	 * @param callable errorCallback 失败执行方法
	 */
	uploadImageChange: function(opt, successCallback, errorCallback, sizeCallback) {
		let that = this;
		if (typeof opt === 'string') {
			let url = opt;
			opt = {};
			opt.url = url;
		}
		let count = opt.count || 1,
			sizeType = opt.sizeType || ['compressed'],
			sourceType = opt.sourceType || ['album', 'camera'],
			is_load = opt.is_load || true,
			uploadUrl = opt.url || '',
			inputName = opt.name || 'pics',
			fileType = opt.fileType || 'image';
		uni.chooseImage({
			count: count, //最多可以选择的图片总数
			sizeType: sizeType, // 可以指定是原图还是压缩图，默认二者都有
			sourceType: sourceType, // 可以指定来源是相册还是相机，默认二者都有
			success: function(res) {
				//启动上传等待中...
				let imgSrc
				let objImg = res.tempFilePaths;
				objImg.forEach(item => {
					uni.getImageInfo({
						src: item,
						success(ress) {
							uni.showLoading({
								title: '图片上传中',
							});
							if (res.tempFiles[0].size <= 2097152) {
								uploadImg(ress.path)
								return
							}
							// uploadImg(canvasPath.tempFilePath)
							let canvasWidth, canvasHeight, xs, maxWidth = 750
							xs = ress.width / ress.height // 宽高比例
							if (ress.width > maxWidth) {
								canvasWidth = maxWidth // 这里是最大限制宽度
								canvasHeight = maxWidth / xs
							} else {
								canvasWidth = ress.width
								canvasHeight = ress.height
							}
							sizeCallback && sizeCallback({
								w: canvasWidth,
								h: canvasHeight
							})
							let canvas = uni.createCanvasContext('canvas');
							canvas.width = canvasWidth
							canvas.height = canvasHeight
							canvas.clearRect(0, 0, canvasWidth, canvasHeight);
							canvas.drawImage(ress.path, 0, 0, canvasWidth, canvasHeight)
							canvas.save();
							// 这里的画布drawImage是一种异步属性  可能存在未绘制全就执行了draw的问题  so添加延迟
							setTimeout(e => {
								canvas.draw(true, () => {
									uni.canvasToTempFilePath({
										canvasId: 'canvas',
										fileType: 'JPEG',
										destWidth: canvasWidth,
										destHeight: canvasHeight,
										quality: 0.7,
										success: function(canvasPath) {
											uploadImg(canvasPath
												.tempFilePath)
										}
									})
								});
							}, 200)


						}
					})
				})
			}
		})

		function uploadImg(filePath) {
			uni.uploadFile({
				url: HTTP_REQUEST_URL + '/api/' + uploadUrl,
				filePath,
				fileType: fileType,
				name: inputName,
				formData: {
					'filename': inputName
				},
				header: {
					// #ifdef MP
					"Content-Type": "multipart/form-data",
					// #endif
					[TOKENNAME]: 'Bearer ' + store.state.app.token
				},
				success: function(res) {
					uni.hideLoading();
					if (res.statusCode == 403) {
						that.Tips({
							title: res.data
						});
					} else {
						let data = res.data ? JSON.parse(res.data) : {};
						if (data.status == 200) {
							successCallback && successCallback(data)
						} else {
							errorCallback && errorCallback(data);
							that.Tips({
								title: data.msg
							});
						}
					}
				},
				fail: function(res) {
					uni.hideLoading();
					that.Tips({
						title: '上传图片失败'
					});
				}
			})
		}
	},

	/**
	 * 处理客服不同的跳转；
	 *
	 */
	getCustomer(userInfo, url, storeInfo, show) {
		let self = this;
		customerType().then(res => {
			let data = res.data;
			if (data.customer_type == 1) {
				uni.makePhoneCall({
					phoneNumber: data.customer_phone
				});
			} else if (data.customer_type == 2) {
				let href = data.customer_url;
				let hrefO = href + '?uid=' + userInfo.uid + '&nickName=' + userInfo.nickname + '&phone=' +
					userInfo.phone + '&sex=' + userInfo.sex + '&avatar=' + userInfo.avatar +
					'&openid=' + userInfo.openid;
				let hrefT = href + '&uid=' + userInfo.uid + '&nickName=' + userInfo.nickname + '&phone=' +
					userInfo.phone + '&sex=' + userInfo.sex + '&avatar=' + userInfo.avatar +
					'&openid=' + userInfo.openid;
				let urls = encodeURIComponent(href.indexOf('?') === -1 ? hrefO : hrefT);
				if (data.customer_url.indexOf('work.weixin.qq.com') > 0) {
					// #ifdef MP
					wx.openCustomerServiceChat({
						extInfo: {
							url: data.customer_url
						},
						corpId: data.wechat_work_corpid,
						showMessageCard: show ? true : false,
						sendMessageTitle: show ? storeInfo.store_name : '',
						sendMessagePath: show ? storeInfo.path : '',
						sendMessageImg: show ? storeInfo.image : '',
						success(res) {},
						fail(err) {
							self.Tips({
								title: err.errMsg
							});
						}
					})
					// #endif
					// #ifdef H5
					return window.location.href = data.customer_url
					// #endif
					// #ifdef APP-PLUS
					plus.runtime.openURL(data.customer_url)
					// #endif
				} else {
					uni.navigateTo({
						url: `/pages/annex/web_view/index?url=${urls}`
					});
				}
			} else {
				uni.navigateTo({
					url: url || '/pages/extension/customer_list/chat'
				})
			}
		}).catch(err => {
			self.Tips({
				title: err
			});
		})
	},
	/**
	 * 小程序头像获取上传
	 * @param uploadUrl 上传接口地址
	 * @param filePath 上传文件路径
	 * @param successCallback success回调
	 * @param errorCallback err回调
	 */
	uploadImgs(uploadUrl, filePath, successCallback, errorCallback) {
		let that = this;
		uni.uploadFile({
			url: HTTP_REQUEST_URL + '/api/' + uploadUrl,
			filePath: filePath,
			fileType: 'image',
			name: 'pics',
			formData: {
				'filename': 'pics'
			},
			header: {
				// #ifdef MP
				"Content-Type": "multipart/form-data",
				// #endif
				[TOKENNAME]: 'Bearer ' + store.state.app.token
			},
			success: (res) => {
				uni.hideLoading();
				if (res.statusCode == 403) {
					that.Tips({
						title: res.data
					});
				} else if (res.statusCode == 413) {
					that.Tips({
						title: '上传图片失败,请重新上传小尺寸图片'
					});
				} else {
					let data = res.data ? JSON.parse(res.data) : {};
					if (data.status == 200) {
						successCallback && successCallback(data)
					} else {
						errorCallback && errorCallback(data);
						that.Tips({
							title: data.msg
						});
					}
				}
			},
			fail: (err) => {
				uni.hideLoading();
				that.Tips({
					title: '上传图片失败'
				});
			}
		})
	},
	/**
	 * 小程序比较版本信息
	 * @param v1 当前版本
	 * @param v2 进行比较的版本
	 * @return boolen
	 *
	 */
	compareVersion(v1, v2) {
		v1 = v1.split('.')
		v2 = v2.split('.')
		const len = Math.max(v1.length, v2.length)

		while (v1.length < len) {
			v1.push('0')
		}
		while (v2.length < len) {
			v2.push('0')
		}

		for (let i = 0; i < len; i++) {
			const num1 = parseInt(v1[i])
			const num2 = parseInt(v2[i])

			if (num1 > num2) {
				return 1
			} else if (num1 < num2) {
				return -1
			}
		}

		return 0
	},
	/**
	 * 处理服务器扫码带进来的参数
	 * @param string param 扫码携带参数
	 * @param string k 整体分割符 默认为：&
	 * @param string p 单个分隔符 默认为：=
	 * @return object
	 *
	 */
	// #ifdef MP
	getUrlParams: function(param, k, p) {
		if (typeof param != 'string') return {};
		k = k ? k : '&'; //整体参数分隔符
		p = p ? p : '='; //单个参数分隔符
		var value = {};
		if (param.indexOf(k) !== -1) {
			param = param.split(k);
			for (var val in param) {
				if (param[val].indexOf(p) !== -1) {
					var item = param[val].split(p);
					value[item[0]] = item[1];
				}
			}
		} else if (param.indexOf(p) !== -1) {
			var item = param.split(p);
			value[item[0]] = item[1];
		} else {
			return param;
		}
		return value;
	},
	// #endif
	/*
	 * 合并数组
	 */
	SplitArray(list, sp) {
		if (typeof list != 'object') return [];
		if (sp === undefined) sp = [];
		for (var i = 0; i < list.length; i++) {
			sp.push(list[i]);
		}
		return sp;
	},
	trim(backUrlCRshlcICwGdGY) {
		return String.prototype.trim.call(backUrlCRshlcICwGdGY);
	},
	includeArray (list1, list2) {
		let status = false
		if(!list1){
			status = true
		}else{
			let arr = list1.split(",");
			list2.forEach(item => {
			    if (arr.includes(item)) status = true;
			});
		}
		return status
	},
	auth: function(e) {
		return this.includeArray(e,uni.getStorageSync('mall_unique_auth'))
	},
	$h: {
		//除法函数，用来得到精确的除法结果
		//说明：javascript的除法结果会有误差，在两个浮点数相除的时候会比较明显。这个函数返回较为精确的除法结果。
		//调用：$h.Div(arg1,arg2)
		//返回值：arg1除以arg2的精确结果
		Div: function(arg1, arg2) {
			arg1 = parseFloat(arg1);
			arg2 = parseFloat(arg2);
			var t1 = 0,
				t2 = 0,
				r1, r2;
			try {
				t1 = arg1.toString().split(".")[1].length;
			} catch (e) {}
			try {
				t2 = arg2.toString().split(".")[1].length;
			} catch (e) {}
			r1 = Number(arg1.toString().replace(".", ""));
			r2 = Number(arg2.toString().replace(".", ""));
			return this.Mul(r1 / r2, Math.pow(10, t2 - t1));
		},
		//加法函数，用来得到精确的加法结果
		//说明：javascript的加法结果会有误差，在两个浮点数相加的时候会比较明显。这个函数返回较为精确的加法结果。
		//调用：$h.Add(arg1,arg2)
		//返回值：arg1加上arg2的精确结果
		Add: function(arg1, arg2) {
			arg2 = parseFloat(arg2);
			var r1, r2, m;
			try {
				r1 = arg1.toString().split(".")[1].length
			} catch (e) {
				r1 = 0
			}
			try {
				r2 = arg2.toString().split(".")[1].length
			} catch (e) {
				r2 = 0
			}
			m = Math.pow(100, Math.max(r1, r2));
			return (this.Mul(arg1, m) + this.Mul(arg2, m)) / m;
		},
		//减法函数，用来得到精确的减法结果
		//说明：javascript的加法结果会有误差，在两个浮点数相加的时候会比较明显。这个函数返回较为精确的减法结果。
		//调用：$h.Sub(arg1,arg2)
		//返回值：arg1减去arg2的精确结果
		Sub: function(arg1, arg2) {
			arg1 = parseFloat(arg1);
			arg2 = parseFloat(arg2);
			var r1, r2, m, n;
			try {
				r1 = arg1.toString().split(".")[1].length
			} catch (e) {
				r1 = 0
			}
			try {
				r2 = arg2.toString().split(".")[1].length
			} catch (e) {
				r2 = 0
			}
			m = Math.pow(10, Math.max(r1, r2));
			//动态控制精度长度
			n = (r1 >= r2) ? r1 : r2;
			return ((this.Mul(arg1, m) - this.Mul(arg2, m)) / m).toFixed(n);
		},
		//乘法函数，用来得到精确的乘法结果
		//说明：javascript的乘法结果会有误差，在两个浮点数相乘的时候会比较明显。这个函数返回较为精确的乘法结果。
		//调用：$h.Mul(arg1,arg2)
		//返回值：arg1乘以arg2的精确结果
		Mul: function(arg1, arg2) {
			arg1 = parseFloat(arg1);
			arg2 = parseFloat(arg2);
			var m = 0,
				s1 = arg1.toString(),
				s2 = arg2.toString();
			try {
				m += s1.split(".")[1].length
			} catch (e) {}
			try {
				m += s2.split(".")[1].length
			} catch (e) {}
			return Number(s1.replace(".", "")) * Number(s2.replace(".", "")) / Math.pow(10, m);
		},
	},
	// 获取地理位置;
	$L: {
		async getLocation() {
			// #ifdef APP-PLUS
			let status = await this.checkPermission();
			if (status !== 1) {
				return;
			}
			// #endif
			// #ifdef MP-WEIXIN || MP-TOUTIAO || MP-QQ
			let status = await this.getSetting();
			if (status === 2) {
				this.openSetting();
				return;
			}
			// #endif

			this.doGetLocation();
		},
		doGetLocation() {
			uni.getLocation({
				success: (res) => {
					uni.removeStorageSync('CACHE_LONGITUDE');
					uni.removeStorageSync('CACHE_LATITUDE');
					uni.setStorageSync('CACHE_LONGITUDE', res.longitude);
					uni.setStorageSync('CACHE_LATITUDE', res.latitude);
				},
				fail: (err) => {
					// #ifdef MP-BAIDU
					if (err.errCode === 202 || err.errCode === 10003) { // 202模拟器 10003真机 user deny
						this.openSetting();
					}
					// #endif
					// #ifndef MP-BAIDU
					if (err.errMsg.indexOf("auth deny") >= 0) {
						uni.showToast({
							title: "访问位置被拒绝"
						})
					} else {
						uni.showToast({
							title: err.errMsg
						})
					}
					// #endif
				}
			})
		},
		getSetting: function() {
			return new Promise((resolve, reject) => {
				uni.getSetting({
					success: (res) => {
						if (res.authSetting['scope.userLocation'] === undefined) {
							resolve(0);
							return;
						}
						if (res.authSetting['scope.userLocation']) {
							resolve(1);
						} else {
							resolve(2);
						}
					}
				});
			});
		},
		openSetting: function() {
			uni.openSetting({
				success: (res) => {
					if (res.authSetting && res.authSetting['scope.userLocation']) {
						this.doGetLocation();
					}
				},
				fail: (err) => {}
			})
		},
		async checkPermission() {
			let status = permision.isIOS ? await permision.requestIOS('location') :
				await permision.requestAndroid('android.permission.ACCESS_FINE_LOCATION');

			if (status === null || status === 1) {
				status = 1;
			} else if (status === 2) {
				uni.showModal({
					content: "系统定位已关闭",
					confirmText: "确定",
					showCancel: false,
					success: function(res) {}
				})
			} else if (status.code) {
				uni.showModal({
					content: status.message
				})
			} else {
				uni.showModal({
					content: "需要定位权限",
					confirmText: "设置",
					success: function(res) {
						if (res.confirm) {
							permision.gotoAppSetting();
						}
					}
				})
			}
			return status;
		},
	}
}
