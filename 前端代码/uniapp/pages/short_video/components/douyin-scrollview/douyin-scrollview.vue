<template>
	<view>
		<!-- 	<image @click="closeSheet" src="../../static/douyin/cuowu.png" style="width: 14px; height: 14px; opacity: 0.6; position: absolute; right: 15px; margin-top: 15px;"></image> -->
		<text class="numberComment">{{pinlunNum}}条评论 <image @click="closeSheet" class="iconfont"
				:src="imgHost + '/statics/images/video/close.png'" mode=""></image></text>
		<scroll-view @scrolltolower="scrolltolower"
			:style="'width: '+ Width +'px; height: 800rpx; background-color: #fff; display: flex; flex-direction: column;'"
			:scroll-y="true">
			<text v-if="pinlunList.length == 0"
				:style="'font-size: 14px; font-weight: bold; color: #a3a1a4; margin-top: 75px; margin-left: '+ (Width/2.9) +'px; position: absolute;'">
				～ 快来评论吧 ～</text>
			<block v-for="(list,index) in pinlunList" :key="index">
				<view :style="'width: '+ Width +'px; display: flex; flex-direction: row;'">
					<!-- 1.用户的头像 -->
					<image :src="list.avatar" mode="aspectFill" class="pictrue"></image>
					<!-- 2.一级评论 -->
					<view
						:style="'width: '+ (Width*0.8) +'px; display: flex; flex-direction: column; margin-top: 20px; margin-left: 6px;'">
						<!-- 3.用户名称，并自动判断是否为视频作者 -->
						<view style="display: flex; flex-direction: row;">
							<text class="name">{{list.nickname}}</text>
							<image v-if="list.is_money_level>0" class="vip" src="@/static/images/vip.png"></image>
							<!-- 4.如果是视频作者就显示 作者 -->
							<view v-if="list.uid == 0"
								style="background-color: #E43D33; border-radius: 2.5px; margin-left: 7.5px;height: 16px;line-height: 16px;">
								<text
									style="font-size: 8px; font-weight: bold; padding-left: 4px; padding-right: 4px; font-weight: bold; color: #FFFFFF;">商家</text>
							</view>
						</view>
						<!-- 5.

						由于 rich-text 有很多 bug
						所以这里已经摒弃了，
						使用自研文本解析器

						-->
						<view @click="huifu(index)"
							:style="'width: '+ (Width*0.78) +'px; display: flex; flex-direction: row; flex-wrap: wrap; margin-top: 8rpx;    word-break: break-all;'">
							<text
								:style="'font-size: 28rpx; font-weight: 400; color: #333; margin-left: -1.5px;'">{{list.content}}</text>
						</view>
						<!-- 6.如果用户输入了 GIF 表情，就会在这里显示 -->
						<!-- 	<image v-if="list.imageURL !== ''" :src="list.imageURL" mode="aspectFill" style="width: 70px; height: 70px; margin-top: 10px; border-radius: 5px;"></image> -->
						<!-- 7.这里就是 时间、回复、点赞、点赞量显示的地方 -->
						<view
							:style="'width: '+ (Width*0.8) +'px; height: 20px; display: flex; flex-direction: row; margin-top: 8rpx;'">
							<text style="font-size: 11px; color: #999;font-weight: 400;">{{list.add_time}} · {{list.city?list.city:''}}</text>
							<text @click="huifu(index)" class="time">回复</text>
							<!-- <text v-if="uid == list.uid" @click="deletepinlun(index)" class="time">删除</text> -->
							<image v-if="!list.is_like" class="like" @click="tolike(list,index)"
								:src="imgHost + '/statics/images/video/zan01.png'"></image>
							<image v-if="list.is_like" class="like" @click="tolike(list,index)"
								:src="imgHost + '/statics/images/video/zan02.png'"></image>
							<text class="likeNum" @click="tolike(list,index)">{{list.like_num}}</text>
						</view>
						<!-- 8.用户回复的子评论会显示在这里

						这里 update 用于刷新视图

						-->
						<block v-for="(li,inde) in list.reply" v-if="list.isShow">
							<view :style="'width: '+ (Width*0.8) +'px; display: flex; flex-direction: row;'">
								<!-- 9.子评论 用户头像 -->
								<image :src="li.avatar" mode="aspectFill" class="childrenPic"></image>
								<!-- 10.动态计算宽度 子评论列表 -->
								<view
									:style="'width: '+ (Width*0.8*0.85) +'px; display: flex;  flex-direction: column; margin-top: 12px; margin-left: 6px;'">
									<view style="display: flex; flex-direction: row;">
										<view style="display: flex; flex-direction: row;">
											<!-- 11.回复评论的人显示在这里 -->
											<text class="childrenName">{{li.nickname}}</text>
											<image v-if="li.is_money_level>0" class="vip" src="@/static/images/vip.png">
											</image>
											<!-- 12.同时要判断评论人是不是作者，如果是作者就在这里显示 -->
											<view v-if="li.uid==0"
												style="background-color: #E43D33; border-radius: 2.5px; margin-left: 7.5px;height: 16px;line-height: 16px;">
												<text
													style="font-size: 8px; font-weight: 400; padding: 0 4px; color: #fff;">商家</text>
											</view>
										</view>
									</view>
									<!-- 16.

									由于 rich-text 有很多 bug
									所以这里已经摒弃了，
									使用自研文本表情解析器

									-->
									<view
										:style="'width: '+ (Width*0.8*0.85) +'px; display: flex; flex-direction: row; flex-wrap: wrap; margin-top: 8rpx;word-break: break-all;'">
										<text
											:style="'font-size: 28rpx; font-weight: 400; color: #333; margin-left: -1.5px;'">{{li.content}}</text>
									</view>
									<!-- 17.如果 子评论 用户输入了 GIF 表情，就会在这里显示 -->
									<!-- 	<image v-if="li.imageURL !== ''" :src="li.imageURL" mode="aspectFill" style="width: 70px; height: 70px; margin-top: 10px; border-radius: 5px;"></image> -->
									<!-- 18.这里就是 时间、回复、点赞、点赞量显示的地方 -->
									<view
										:style="'width: '+ (Width*0.8*0.85) +'px; height: 20px; display: flex; flex-direction: row; margin-top: 8rpx;'">
										<text style="font-size: 11px; color: #999;font-weight: 400;">{{li.add_time}} · {{li.city?li.city:''}}</text>
										<!-- <text v-if="userID == li.userID" @click="deletesonpinlun(index,inde)" class="time">删除</text> -->
										<image class="childrenLike" v-if="!li.is_like" @click="tosonlike(index,inde,li)"
											:src="imgHost + '/statics/images/video/zan01.png'"></image>
										<image class="childrenLike" v-if="li.is_like" @click="tosonlike(index,inde,li)"
											:src="imgHost + '/statics/images/video/zan02.png'"></image>
										<text class="childrenLikeNum"
											@click="tosonlike(index,inde,li)">{{li.like_num}}</text>
									</view>
								</view>
							</view>
						</block>
						<!-- 19。显示 【展开xx条评论】、【收起评论】 -->
						<view v-if="list.reply_count > 0"
							:style="'width: '+ (Width*0.8) +'px; display: flex; flex-direction: row; margin-top: 12px;'">
							<view
								style="width: 20px; height: 1px; background-color: #D8D8D8; opacity: 1; margin-top: 6.5px;">
							</view>
							<text @click="zhangkai(index,list)" v-if="list.reply_count!=list.reply.length"
								style="font-size: 11px; font-weight: 500; color: #666; margin-left: 6px;">展开{{list.reply_count}}条回复</text>
								<image v-if="list.reply_count!=list.reply.length" class="down" :src="imgHost + '/statics/images/video/ic_down02.png'"></image>
							<text @click="shouqi(list)" v-if="list.reply.length"
								style="font-size: 11px; font-weight: 500; color: #666; margin-left: 6px;">收起</text>
								<image v-if="list.reply.length" class="down" :src="imgHost + '/statics/images/video/ic_down.png'"></image>
						</view>
					</view>
				</view>
				<!-- 20.留一定的高度以免视图被遮挡 -->
				<view v-if="index == (pinlunList.length-1)" :style="'width: '+ Width +'px; height: 80px;'"></view>
			</block>
		</scroll-view>
		<!-- 21.底部，模拟假的输入框 -->
		<view v-if="show" @click="parentPinglun" class="footers" :style="'width: '+ Width +'px; height: 46px; background-color: #fff;'">
			<view
				:style="'width: '+ (Width-30) +'px; height: 32px; margin-left: 15px; margin-top: 7px; background-color: #eee; border-radius: 50px; display: flex; flex-direction: row;'">
				<text style="font-size: 13px; color: #999; margin-top: 7px; margin-left: 15px;">说说你的看法吧～</text>
				<!-- <image :src="imgHost + '/statics/images/send01.png'"
					style="width: 40rpx; margin-top: 6px; height: 40rpx; position: absolute; right: 12.5px;"></image> -->
			</view>
		</view>
		<!-- 	<view v-if="show && platform!=='ios'" @click="openPinglun" :style="'width: '+ Width +'px; height: 46px; background-color: #fff;'">
			<view :style="'width: '+ (Width-30) +'px; height: 42px; margin-left: 15px; margin-top: 5px; background-color: #eee; border-radius: 50px; display: flex; flex-direction: row;'">
				<text style="font-size: 13px; color: #999; margin-top: 7px; margin-left: 15px;">说点什么呗777~</text>
				<image :src="imgHost + '/statics/images/send01.png'" style="width: 40rpx; margin-top: 7.5px; height: 40rpx; position: absolute; right: 12.5px;"></image>
			</view>
		</view> -->
		<!--

		下面就是真正的评论框

		包含：
		1.输入框
		2.表情输入框
		3.GIF表情库
		4.最近使用表情和全部表情
		5.自己上传表情
		6. @ 自己的好友
		7.自带微博表情 和 QQ 表情
		8.能记忆输入

		 -->
		<uni-popup type="bottom" ref="openPinglun" @touchmove.stop.prevent="movehandle" @change="change">
			<view style="display: flex; flex-direction: column;">
				<view @click="openPinglun"
					:style="'width: '+ Width +'px; background-color: #F5F5F5; display: flex; flex-direction: row;'">
					<view
						class="footerPop" :style="'width: '+ (Width-30) +'px;margin-left: 15px; margin-top: 13px; background-color: #fff; border-radius: '+ borderRadius +'px; display: flex; flex-direction: row;'">
						<!--
						9.输入框
						 -->
						<input
						    class="iosOn"
							:style="'width: '+(Width-70)+'px; font-size: 15px; color: #000000; margin-left: 15px;display: inline-block;'"
							:placeholder="placeholder" placeholder-class="placeholders" :cursor-spacing="cursorSpacing" :focus="autoFocus"
							:auto-height="autoHeight" :adjust-position="adjustPosition" v-model="value"
							:disabled="disabled" maxlength="150" @linechange="linechange"
							@keyboardheightchange="keyboardheightchange" @focus="focus" @click="clickTextarea"
							@blur="blur"/>
						<view class="sendBnt on" v-if="(value!=='' || imageURL !== '') && isSend" @click="sendSMS">发送</view>
						<view class="sendBnt" v-else>发送</view>
					</view>
					<!-- 11.发送按钮

					 符合：有内容、或者是输入 GIF 图片都可以通过

					 -->
					<view v-if="(value!=='' || imageURL !== '') && isSend" @click="sendSMS"
						style="width: 30px; height: 30px; border-radius: 40px; margin-top: 30upx; margin-left: 20upx;">
						<!-- <image :src="imgHost + '/statics/images/send02.png'" style="width: 30px; height: 30px;"></image> -->
					</view>
				</view>
			</view>
			<!-- 12.用于显示用户选择的 GIF 图片 -->
			<view v-if="isShowImage" :style="'width: '+ Width +'px; height: 75px; background-color: #FFFFFF;'">
				<view style="display: flex; flex-direction: row;">
					<image :src="imageURL" mode="aspectFill"
						style="width: 60px; height: 60px; margin-top: 5px; margin-left: 25px;"></image>
					<!-- 	<image @click="deleteimageURL" src="../../static/douyin/zfxsc.png" style="width: 15px; height: 15px; position: absolute; margin-left: 70px; margin-top: 6px;"></image> -->
				</view>
			</view>
			<!-- 13.

			表情 区

			-->
			<view :style="'width: '+ Width +'px; background-color: #FFFFFF;'">
				<!--
				isToShow：这个参数用于控制显示，不动它即可
				 -->
				<block v-if="isToShow">
					<!-- 14.表情选择栏：

					 最近输入的 GIF 图在：timeEmoji
					 默认展示的 表情 ：nowEmoji
					 自己上传的图片：likeEmoji
					 GIF 图片库：gifEmoji

					 -->
					<!-- 	<scroll-view :style="'width: '+ Width +'px; height: 40px; background-color: #FFFFFF;'" :scroll-x="true" style="display: flex; flex-direction: row;" :show-scrollbar="false">
						<view :style="'display: flex; flex-direction: row; width: '+ Width +'px; padding-top: 5px; padding-bottom: 5px; border-bottom: 0.5px solid #f3f1f4;'">
							<view :style="'width: 45px; height: 30px; border-radius: 30px; position: absolute; background-color: #f8f4f7; margin-top: -4px; margin-left: '+ (Width*0.04)*currentNum +'px;'"></view>
							<image @click="timeEmoji" src="../../static/douyin/time.png" style="width: 22.5px; height: 22.5px; margin-left: 25px;"></image>
							<image @click="nowEmoji" src="../../static/douyin/biaoqing-2.png" style="width: 25px; height: 25px; margin-left: 25px; margin-top: -2.5px;"></image>
							<image @click="likeEmoji" src="../../static/douyin/xianxing.png" style="width: 27.5px; height: 27.5px; margin-left: 25px; margin-top: -2.5px;"></image>
							<view @click="gifEmoji" style="width: 22.5px; height: 22.5px; border-radius: 27.5px; border: 2px solid #303133; margin-left: 26px; margin-top: 0upx;">
								<image src="../../static/douyin/gif-2.png" style="width: 17.5px; height: 17.5px;"></image>
							</view>
						</view>
					</scroll-view> -->
					<!-- 15.表情选择栏：

					 最近输入的 GIF 图在：timeEmoji
					 默认展示的 表情 ：nowEmoji
					 自己上传的图片：likeEmoji
					 GIF 图片库：gifEmoji

					 -->
					<swiper
						:style="'width: '+ Width +'px; height: '+ (emojiHeight-40) +'px; background-color: #FFFFFF;'"
						:current="current" @change="currentChange">
						<swiper-item>
							<scroll-view
								:style="'width: '+ Width +'px; height: '+ (emojiHeight-40) +'px; background-color: #FFFFFF;'"
								:scroll-y="true">
								<text v-if="nowImage.length !== 0" @click="qingkonGIF"
									style="font-size: 12px; margin-top: -5px; z-index: 999; position: absolute; right: 20px; color: #007AFF;">清空</text>
								<view style="display: flex; flex-direction: row; flex-wrap: wrap; margin-top: 20px;">
									<block v-for="(list,index) in nowImage">
										<view>
											<image @click="clicknowImage(index)" :src="list" mode="aspectFill"
												style="width: 60px; height: 60px; margin-top: 20px; margin-left: 27.5px;">
											</image>
											<!-- <image @click="deletenowImage(index)" src="../../static/douyin/zfxsc.png" style="width: 15px; height:15px; position: absolute; margin-left: 74px; margin-top: 20px;"></image> -->
										</view>
									</block>
									<block v-if="nowImage.length == 0">
										<text
											:style="'font-size: 14px; color: #999999; margin-top: 100px; margin-left: '+ (Width/3.2) +'px;'">～
											您还没使用过图片 ～</text>
									</block>
								</view>
								<view :style="'width: '+ Width +'px; height: 80px;'"></view>
							</scroll-view>
						</swiper-item>
						<swiper-item>
							<scroll-view
								:style="'width: '+ Width +'px; height: '+ (emojiHeight-40) +'px; background-color: #FFFFFF;'"
								:scroll-y="true">
								<block v-if="nowTimeEmojiList.length !== 0">
									<text style="font-size: 12px; margin-top: 10px; margin-left: 15px;">最近使用</text>
									<text @click="qingkon"
										style="font-size: 12px; margin-top: 10px; position: absolute; right: 20px; color: #007AFF;">清空</text>
									<view
										style="display: flex; flex-direction: row; flex-wrap: wrap; margin-bottom: 10px;">
										<!-- QQ - 表情包 -->
										<block v-for="(list,index) in nowTimeEmojiList">
											<image @click="clicknowTimeEmoji(index)"
												:src="'../../static/emojis/qq/'+list.url+''"
												style="width: 35px; height: 35px; margin-top: 15px; margin-left: 18px;">
											</image>
										</block>
										<!-- 新浪微博 - 表情包 -->
										<!-- <block v-for="(list,index) in sinaEmojilist">
											<image @click="clicksinaEmoji(index)" :src="list.url" style="width: 35px; height: 35px; margin-top: 30upx; margin-left: 36upx;"></image>
										</block> -->
									</view>
								</block>
								<text style="font-size: 12px; margin-top: 10px; margin-left: 15px;">全部表情</text>
								<view style="display: flex; flex-direction: row; flex-wrap: wrap;">
									<!-- QQ - 表情包 -->
									<block v-for="(list,index) in emojilist">
										<image @click="clickEmoji(index)" :src="'../../static/emojis/qq/'+list.url+''"
											style="width: 35px; height: 35px; margin-top: 15px; margin-left: 18px;">
										</image>
									</block>
									<!-- 新浪微博 - 表情包 -->
									<!-- <block v-for="(list,index) in sinaEmojilist">
										<image @click="clicksinaEmoji(index)" :src="list.url" style="width: 35px; height: 35px; margin-top: 30upx; margin-left: 36upx;"></image>
									</block> -->
								</view>
								<view :style="'width: '+ Width +'px; height: 80px;'"></view>
							</scroll-view>
							<view
								style="position: absolute; display: flex; flex-direction: row; bottom: 0; right: 0; width: 150px; height: 40px; background-color: #FFFFFF; box-shadow: -20px -40px 20px 30px #FFFFFF; border-radius: 1px;">
								<view @click="undo"
									style="width: 65px; height: 30px; margin-top: -20px; border-radius: 20px; border: 0.5px solid #c6c5c8;">
									<!-- 						<image src="../../static/douyin/shanchu-3.png" style="width: 22.5px; height: 15px; margin-top: 7.5px; margin-left: 20px;"></image> -->
								</view>
								<!-- 16.发送按钮

								 符合：有内容、或者是输入 GIF 图片都可以通过

								 -->
								<view v-if="(value!=='' || imageURL !== '') && isSend" @click="sendSMS"
									style="width: 65px; height: 30px; margin-top: -20px; margin-left: 10px; border-radius: 20px; background-color: #ff1a63;">
									<text
										style="font-size: 14px; font-weight: bold; text-align: center; color: #FFFFFF; padding-top: 5px;">发送</text>
								</view>
								<view v-if="(value == '' && imageURL == '') && isSend"
									style="width: 65px; height: 30px; margin-top: -20px; margin-left: 10px; border-radius: 20px; background-color: #bab9bb;">
									<text
										style="font-size: 14px; font-weight: bold; text-align: center; color: #FFFFFF; padding-top: 5px;">发送</text>
								</view>
							</view>
						</swiper-item>
						<swiper-item>
							<scroll-view
								:style="'width: '+ Width +'px; height: '+ (emojiHeight-40) +'px; background-color: #FFFFFF;'"
								:scroll-y="true">
								<view style="display: flex; flex-direction: row; flex-wrap: wrap;">
									<view @click="addlikeImage"
										style="width: 60px; height: 60px; border-radius: 10upx; border: 1px solid #000000; margin-top: 20px; margin-left: 27.5px;">
										<!-- 									<image src="../../static/douyin/jia-9.png" style="width: 25px; height: 25px; margin-top: 17.5px; margin-left: 17.5px;"></image> -->
									</view>
									<block v-for="(list,index) in likeImage">
										<image @click="clickLikeImage(index)" :src="list" mode="aspectFill"
											style="width: 60px; height: 60px; margin-top: 20px; margin-left: 27.5px;">
										</image>
										<!-- <image @click="deleteImage(index)" src="../../static/douyin/zfxsc.png" style="width: 15px; height: 15px; position: absolute; right: 0; margin-top: 20px;"></image> -->
									</block>
								</view>
								<view :style="'width: '+ Width +'px; height: 80px;'"></view>
							</scroll-view>
						</swiper-item>
						<swiper-item>
							<scroll-view
								:style="'width: '+ Width +'px; height: '+ (emojiHeight-40) +'px; background-color: #FFFFFF;'"
								:scroll-y="true" @scrolltolower="scrolltolowerGIF">
								<view style="display: flex; flex-direction: row; flex-wrap: wrap;">
									<view @click="searchGIF"
										style="width: 60px; height: 60px; border-radius: 5px; border: 1px solid #000000; margin-top: 20px; margin-left: 27.5px;">
										<!-- 			<image src="../../static/douyin/sousuo-7.png" style="width: 25px; height: 25px; margin-top: 17.5px; margin-left: 17.5px;"></image> -->
									</view>
									<block v-for="(list,index) in gifAndpnglist">
										<image @click="clickGIF(index)" :src="list.url" mode="aspectFill"
											style="width: 60px; height: 60px; margin-top: 20px; margin-left: 27.5px;">
										</image>
									</block>
								</view>
								<view :style="'width: '+ Width +'px; height: 80px;'"></view>
							</scroll-view>
						</swiper-item>
					</swiper>
				</block>
				<block v-if="!isToShow">
					<!-- 这里为空即可，不加任何东西 -->
				</block>
			</view>
		</uni-popup>
		<!-- 1.

		 这个弹窗用于搜索 GIF

		 -->
		<uni-popup type="bottom" ref="searchEmoji" @touchmove.stop.prevent="movesearch" @change="searchGIFChange">
			<view v-if="searchGIFValue !== ''"
				:style="'width: '+ Width +'px; height: 80px; border-bottom: 0.5px solid #e3e1e5; background-color: #FFFFFF; border-top-left-radius: 10px; border-top-right-radius: 10px;'">
				<!-- 2.

				 搜出来的 GIF 都在这里

				 -->
				<scroll-view
					:style="'width: '+ Width +'px; height: 80px; border-bottom: 0.5px solid #e3e1e5; display: flex; flex-direction: row; white-space: nowrap;'"
					:scroll-x="true">
					<block v-for="(list,index) in GifList">
						<image @click="selectGIF(index)" :src="list.url" mode="aspectFill"
							style="width: 60px; height: 60px; margin-top: 10px; margin-left: 10px;"></image>
					</block>
					<view style="width: 10px; height: 60px; margin-left: 5px;"></view>
				</scroll-view>
			</view>
			<!-- 3.
			 动态图输入框
			 -->
			<view :style="'width: '+ Width +'px; background-color: #FFFFFF; display: flex; flex-direction: row;'">
				<view
					:style="'width: '+ (Width-30) +'px; margin-left: 15px; margin-top: 10px; background-color: #FFFFFF; margin-bottom: 10px; display: flex; flex-direction: row;'">
					<input
						:style="'width: '+ (Width*0.8) +'px; height: 20px; margin-bottom: 10px; font-size: 15px; color: #000000; margin-top: 7.5px; margin-left: 15px;'"
						placeholder="搜索表情包" v-model="searchGIFValue" :auto-focus="false" :adjust-position="true"
						@blur="blurGIF" />
					<!-- <image v-if="searchGIFValue !== ''" @click="clearSearchValue" src="../../static/douyin/chacha-4.png" style=" width: 12.5px; height: 12.5px; margin-top: 10px; margin-left: 15px;"></image> -->
				</view>
			</view>
			<view v-if="platform=='ios'"
				:style="'width: '+ Width +'px; height: '+ (emojiHeight+40) +'px; background-color: #FFFFFF;'">
				<!-- 这里不要动就行 -->
			</view>
			<view v-if="platform!=='ios'"
				:style="'width: '+ Width +'px; height: '+ emojiHeight +'px; background-color: #FFFFFF;'">
				<!-- 这里不要动就行 -->
			</view>
		</uni-popup>
	</view>
</template>
<script>
	// 1.先引入表情库，（完全手撸，十分繁琐）（这个是 QQ 的表情库）
	// import emojiList from '../../common/emoji/biaoqin.js'
	// 2.这个表情库是新浪微博 的表情库
	// import sinaEmojiList from '../../common/emoji/sina.js'
	// 3.这个一定要引入进来，用来解析生成的 <html>【🌟🌟🌟🌟🌟】十分重要【这里已经对原来的文件进行了改编】
	import parsehtml from '../../common/html-parse/parse_html.js'
	// 4.这里引入时间函数 用来上传时用的
	import time from '../../common/time-utils/currentData.js'
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import {
		commentList,
		markeComment,
		replyCommentList,
		replyCommentLike
	} from '@/api/short-video.js';
	import {
		mapGetters
	} from 'vuex';
	export default {
		computed: mapGetters(['uid']),
		data() {
			return {
				replyList: [],
				// 每一个参数都重要
				// 解释如下：
				// ----- start ----- 评论输入框部分
				num: 1.15, //用来处理评论的高度
				plHeight: 0, //评论高度
				value: "", //用户在输入框输入信息的数据
				autoHeight: false, //这个默认即可（用于评论框自动适应高度）
				borderRadius: 50, //评论框圆角大小
				lineheight: 0, //默认即可（用于处理评论框高度）
				percent: 1, //默认（用于处理发送按钮已经评论框宽度）
				show: true, //默认（是否显示评论框）
				emojiHeight: 0, //表情区域的高度
				emojiheight: 0, //用于处理表情区域的高度
				emojihi: 0,
				cursorSpacing: 20, //键盘距离输入框的距离
				autoFocus: false, //是否自动聚焦（默认聚焦）
				isopen: false, //(默认不展开)
				isToShow: false, //默认
				adjustPosition: true, //默认
				placeholder: "说说你的看法吧～",
				disabled: false,

				//
				current: 1, //当前切换的位置
				emojilist: [], //表情数组
				nowTimeEmojiList: [], //最近输入的表情
				sinaEmojilist: [], //新浪微博表情
				currentNum: 4.4, //默认
				likeImage: [], //默认
				nowImage: [], //默认

				gifAndpngList: [], //默认
				gifAndpnglist: [], //默认

				searchGIFValue: "", //默认
				isSearcopen: false, //默认
				GifList: [], //默认
				isShowImage: false, //默认
				imageURL: "", //默认

				platform: "", //默认
				systemVerson: "", //系统版本
				ischangepinlun: true, //默认显示全面屏手机评论样式

				// ----- end ----- 评论输入框部分

				// ----- start ----- 评论内容部分
				pinlunList: [], //用于在界面展示的 评论数组
				pinlunListX: [], //这个是用来存储原始评论数据的数组
				pinlun_list: [], //用于存储界面 评论数组 的副本
				isSend: true, //默认
				userID: "", //当前用户的 ID
				update: true, //用于刷新视图

				huifuUser: "", //回复信息的人的名字
				gethuifuUser: "", //被回复的人（也就是接收者的名字）
				gethuifuUserID: "", //被回复的人的 ID
				huifuindex: -1, //回复信息所在下标
				iszhangkai: false, //默认不展开评论
				imgHost: HTTP_REQUEST_URL,
				// ----- end ----- 评论内容部分
				limit: 10,
				page: 1,
				pages: 1,
				videoIDs: 0,
				parentNum: 0,
				isIos:false
			}
		},
		name: "douyin-scrollview",
		props: {
			Width: {
				type: Number,
				default: 0
			},
			Height: {
				type: Number,
				default: 0
			},
			pinlunNum: {
				type: Number,
				default: 0
			},
			videoID: {
				type: Number,
				default: 0
			}
		},
		watch: {
			// 监听 输入表情值 并搜索 GIF 表情
			searchGIFValue(val) {
				this.isopen = false;
				if (val !== '') {
					var sms = []
					for (let i = 0; i < this.gifAndpngList.length; i++) {
						if (this.gifAndpngList[i].name.indexOf(val) !== -1) {
							sms.push(this.gifAndpngList[i]);
						}
					}
					this.GifList = sms;
				}
			},
			// 监听 输入框输入数据
			value(val) {
				if (val == "") {
					this.autoHeight = false
					if (this.imageURL !== '') {
						this.percent = 0.9
					} else {
						this.percent = 1
					}
				} else {
					this.autoHeight = true
					this.percent = 0.9
				}
			},
			// 监听 GIF图片（用户选择一个 GIF 图片以后这个值就会被赋值）
			imageURL(val) {
				if (val !== '') {
					this.percent = 0.9
				} else {
					if (this.value == '') {
						this.percent = 1
					}
				}
			},
			// 监听 输入框 高度变化
			lineheight(newVal, oldVal) {
				if (Math.abs(newVal) < 30) {
					this.borderRadius = 50
				} else {
					this.borderRadius = 10
				}
			}
		},
		created() {
			// 1.isToShow 用于输入框显示（默认不改）
			this.isToShow = false;
			// 2.判断当前设备信息
			var model = uni.getSystemInfoSync().model;
			// 3.判断当前设备信息
			this.platform = uni.getSystemInfoSync().platform;
			// 3.1 获取系统版本
			this.emojiHeight = 0;
			// 6.获取评论信息
			// #ifdef H5
			this.getnewpinlun();
			// #endif
			// 7.根据设备信息，处理评论区域高度
			if (uni.getSystemInfoSync().platform == 'ios' && (model !== 'iPhone6' || model !== 'iPhone6s' || model !==
					'iPhone7' || model !== 'iPhone8')) {
				this.num = 1.25
			} else {
				this.num = 1.15
			}
			this.windowHeight = uni.getSystemInfoSync().screenHeight;
			this.emojiHeight = this.windowHeight / 2.1;
			this.adjustPosition = true;
			this.plHeight = this.Height - (this.Height / this.num);
			// 8.聚焦输入
			// this.focus();
			// 9.判断是否有 GIF 图片
			if (this.imageURL !== '') {
				this.percent = 0.9
			}
			// 10.把之前准备好的 emoji 表情赋值给 数组，用于展示
			// this.emojilist = emojiList
			// this.sinaEmojilist = sinaEmojiList
			// 11.看看有没有喜欢的 GIF，有的话赋值
			this.likeImage = uni.getStorageSync("likeImage");
			// 11.看看有没有当前的 GIF，有的话赋值
			this.nowImage = uni.getStorageSync("nowImage");
			// 11.看看有没有当前的 emoji ，有的话赋值
			this.nowTimeEmojiList = uni.getStorageSync("nowTimeEmojiList");
			// 12.请求 GIF 表情库（这里的表情库都存在 json 文件里面了）
			/*
			1.资源来源

			GitHub：https://github.com/zhaoolee/ChineseBQB
			Gitee：https://gitee.com/mirrors/ChineseBQB

			这两个库 数据都是同步的，可以去参考，国内的话可以用 gitee 访问下载，如何丢到服务器里面

			*/
			// uni.request({
			// 	url: 'https://vkceyugu.cdn.bspapp.com/VKCEYUGU-bdb24c6d-8c19-4f80-8e7e-c9c9f037f131/bf6f0d2e-e065-4685-a104-218c42add104.json',
			// 	success: (res) => {
			// 		var gifAndpngList = res.data.data
			// 		var sms = []
			// 		for(let i=0;i<gifAndpngList.length;i++){
			// 			/*
			// 			它的资源很多，这里只获取 gif 部分
			// 			*/
			// 			if(gifAndpngList[i].name.indexOf("gif") !== -1){
			// 				sms.push(gifAndpngList[i])
			// 			}
			// 		}
			// 		this.gifAndpngList = sms
			// 	}
			// })
			// this.getPhone();
		},
		methods: {
			// getPhone(){
			// 	let phone = uni.getSystemInfoSync();  //调用方法获取机型
			// 	if (phone.platform == 'ios') {
			// 	    this.isIos = true
			// 	} else if (phone.platform == 'android') {
			// 	    this.isIos = false
			// 	}
			// },
			/*

			---- 第一段，这里面含有 http 后台真实请求，以及返回处理的操作以及数据

			【🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟】
			---- start ----
			*/
			getnewpinlun(id, type) {
				// #ifdef H5
				this.videoIDs = this.videoID;
				let ids = this.videoID
				// #endif
				// #ifdef MP
				this.videoIDs = id;
				let ids = id
				if (type) {
					this.pages = 1
					this.pinlunList = []
				}
				// #endif
				// 这里是对评论信息做处理
				commentList(ids, {
					limit: this.limit,
					page: this.pages
				}).then(res => {
					this.pages = this.pages + 1
					this.pinlunList = this.pinlunList.concat(res.data);
				}).catch(err => {
					return uni.showToast({
						title: err,
						icon: 'none',
						duration: 2000
					});
				})
			},
			sendSMS() {
				this.isSend = false;
				uni.showLoading({
					title: '正在发送...'
				})
				let pid = this.parentNum ? 0 : this.pinlunList[this.huifuindex].id;
				let data = {
					id: this.videoIDs,
					pid: pid,
					content: this.value
				}
				markeComment(data).then(res => {
					uni.hideLoading();
					this.$emit('pinlunFun', this.pinlunNum + 1, this.videoIDs);
					this.value = "";
					this.imageURL = "";
					this.$refs.openPinglun.close();
					this.autoFocus = false;
					this.isSend = true;
					this.pages = 1;
					this.pinlunList = [];
					this.getnewpinlun(this.videoIDs);
				}).catch(err => {
					return uni.showToast({
						title: err,
						icon: 'none',
						duration: 2000
					});
				});
			},
			tolike(item, index) {
				// 处理评论
				replyCommentLike('like', item.id).then(res => {
					this.pinlunList[index].is_like = !this.pinlunList[index].is_like
					const video = this.pinlunList[index];
					item.is_like ? video.like_num += 1 : video.like_num -= 1;
				}).catch(err => {
					return uni.showToast({
						title: err,
						icon: 'none',
						duration: 2000
					});
				})
			},
			tosonlike(index, inde, item) {
				replyCommentLike('like', item.id).then(res => {
					this.pinlunList[index].reply[inde].is_like = !this.pinlunList[index].reply[inde].is_like
					const video = this.pinlunList[index].reply[inde];
					item.is_like ? video.like_num += 1 : video.like_num -= 1;
				}).catch(err => {
					return uni.showToast({
						title: err,
						icon: 'none',
						duration: 2000
					});
				})
			},
			zhangkai(index, item) {
				// 1.点击展开评论，一开始是不展开的（如果点击展开，就把 评论的副本的子评论赋值给当前页面页面评论，这样当前页面就可以显示子评论了）
				if (item.isShow == undefined || item.isShow == 'undefined') {
					this.page = 1;
				}
				replyCommentList(item.id, {
					limit: this.limit,
					page: this.page
				}).then(res => {
					this.page = this.page + 1;
					item.reply = item.reply.concat(res.data);
					if (item.reply.length) {
						item.isShow = true;
					}
					this.$set(this, 'pinlunList', this.pinlunList);
				}).catch(err => {
					return uni.showToast({
						title: err,
						icon: 'none',
						duration: 2000
					});
				})
			},
			scrolltolower() {
				this.getnewpinlun(this.videoIDs);
			},
			shouqi(item) {
				this.page = 1
				item.reply = [];
				item.isShow = false;
				this.$set(this, 'pinlunList', this.pinlunList);
			},
			deletesonpinlun(index, inde) {},
			// deletepinlun(index){
			// 	uni.showModal({
			// 		title: '确定删除？',
			// 		content: '删除后子评论将被删除',
			// 		success: (res) => {
			// 			if(res.confirm){
			// 				uni.showLoading({
			// 					title: "正在删除"
			// 				})
			// 				commentDel().then(res=>{
			// 					uni.hideLoading();
			// 					this.getnewpinlun();
			// 				}).catch(err=>{
			// 					return this.$util.Tips({
			// 						title: err.msg
			// 					});
			// 				})
			// 			}
			// 		}
			// 	})
			// },
			addlikeImage() {
				uni.showModal({
					title: '⏰演示项目提醒⏰',
					content: '请前往 douyin-scrollview.nvue组件\naddlikeImage()函数进行配置',
					success: () => {}
				})
			},

			/*
			【🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟🌟】

			----- end -----
			*/

			sonhuifu(index, inde) {
				// 1.子评论回复
				/*
				（1）先处理回复人信息
				（2）处理被回复人信息
				（3）改变 “发送” 状态
				（4）打开评论框
				*/
				this.huifuUser = uni.getStorageSync("user").username;
				this.gethuifuUser = this.pinlunList[index].sonPinlun[inde].username;
				this.gethuifuUserID = this.pinlunList[index].sonPinlun[inde].userID;
				this.placeholder = "回复：" + this.gethuifuUser;
				this.huifuindex = index;
				this.openPinglun();
			},
			huifu(index) {
				// 1.评论回复
				/*
				（1）先处理回复人信息
				（2）处理被回复人信息
				（3）改变 “发送” 状态
				（4）打开评论框
				*/
			    this.autoFocus = false;
				this.parentNum = 0;
				this.placeholder = "回复：" + this.pinlunList[index].nickname;
				this.huifuindex = index;
				this.openPinglun();
				setTimeout(() => {
				   this.autoFocus = true;
				},100)
			},
			clicknowImage(index) {
				// 点击 GIF 图片信息
				this.imageURL = this.nowImage[index];
				this.isShowImage = true;
			},
			selectGIF(index) {
				// 选择 GIF 图片
				/*
				（1）获取 GIF 链接
				（2）清理搜索的 GIF 输入框信息
				（3）缓存 GIF 图片信息
				*/
				this.imageURL = this.GifList[index].url;
				this.isShowImage = true;
				this.searchGIFValue = ""
				this.$refs.searchEmoji.close();
				if (uni.getStorageSync("nowImage").length == 0) {
					this.nowImage = []
					this.nowImage.push(this.imageURL)
					uni.setStorageSync("nowImage", this.nowImage);
				} else {
					this.nowImage = uni.getStorageSync("nowImage");
					let isTrue = true;
					for (let i = 0; i < this.nowImage.length; i++) {
						if (this.imageURL == this.nowImage[i]) {
							isTrue = false;
						}
					}
					if (isTrue) {
						this.nowImage.push(this.imageURL)
					}
					uni.setStorageSync("nowImage", this.nowImage);
				}
			},
			deleteimageURL() {
				// 清理 GIF 图片
				this.imageURL = "";
				this.isShowImage = false;
			},
			clickGIF(index) {
				// 在选择 GIF 列表，点击 GIF 图片，选择图片
				/*
				（1）获取 GIF 链接
				（2）清理搜索的 GIF 输入框信息
				（3）缓存 GIF 图片信息
				*/
				this.imageURL = this.gifAndpnglist[index].url;
				this.isShowImage = true;
				if (uni.getStorageSync("nowImage").length == 0) {
					this.nowImage = []
					this.nowImage.push(this.imageURL)
					uni.setStorageSync("nowImage", this.nowImage);
				} else {
					this.nowImage = uni.getStorageSync("nowImage");
					let isTrue = true;
					for (let i = 0; i < this.nowImage.length; i++) {
						if (this.imageURL == this.nowImage[i]) {
							isTrue = false;
						}
					}
					if (isTrue) {
						this.nowImage.push(this.imageURL)
					}
					uni.setStorageSync("nowImage", this.nowImage);
				}
			},
			clearSearchValue() {
				// 点击搜索 GIF 的小叉叉，清理输入的值
				this.searchGIFValue = ""
			},
			blurGIF() {
				// 搜索 GIF 图片失去聚焦时
				// 关闭 GIF 输入框
				this.windowHeight = uni.getSystemInfoSync().screenHeight;
				this.emojiHeight = this.windowHeight / 2.1;
				if (this.searchGIFValue == '') {
					this.$refs.searchEmoji.close();
				}
			},
			searchGIF() {
				// 点击搜索 GIF 图片
				// 打开输入框
				this.emojiHeight = 0;
				if (uni.getSystemInfoSync().platform == 'ios') {
					this.$refs.searchEmoji.open('bottom');
				} else {
					setTimeout(() => {
						this.$refs.searchEmoji.open('bottom');
					}, 500)
				}
			},
			deletenowImage(index) {
				// 删除当前图片
				var sms = []
				for (let i = 0; i < this.nowImage.length; i++) {
					if (this.nowImage[i] !== this.nowImage[index]) {
						sms.push(this.nowImage[i])
					}
				}
				this.nowImage = sms;
				uni.setStorageSync("nowImage", this.nowImage);
			},
			clickLikeImage(index) {
				// 点击喜欢的图片之后
				/*
				（1）获取图片信息
				（2）缓存图片 在本地
				*/
				if (uni.getStorageSync("nowImage").length == 0) {
					this.nowImage = []
					this.nowImage.push(this.likeImage[index])
					uni.setStorageSync("nowImage", this.nowImage);
				} else {
					this.nowImage = uni.getStorageSync("nowImage");
					let isTrue = true;
					for (let i = 0; i < this.nowImage.length; i++) {
						if (this.likeImage[index] == this.nowImage[i]) {
							isTrue = false;
						}
					}
					if (isTrue) {
						this.nowImage.push(this.likeImage[index])
					}
					uni.setStorageSync("nowImage", this.nowImage);
				}
			},
			deleteImage(index) {
				// 删除 图片
				/*
				（1）更新当前 GIF 图片列表
				（2）同时去看看 最近使用图片里面有没有当前要删除的图片，
					如果有的话就一同删除掉
				*/
				uni.showModal({
					title: '确定删除？',
					success: (re) => {
						if (re.confirm) {
							uni.removeSavedFile({
								filePath: this.likeImage[index],
								success: () => {
									var sms = []
									for (let i = 0; i < this.likeImage.length; i++) {
										if (this.likeImage[i] !== this.likeImage[index]) {
											sms.push(this.likeImage[i])
										}
									}
									var smh = []
									for (let i = 0; i < this.nowImage.length; i++) {
										if (this.nowImage[i] !== this.likeImage[index]) {
											smh.push(this.nowImage[i])
										}
									}
									this.nowImage = smh;
									uni.setStorageSync("nowImage", this.nowImage);
									this.likeImage = sms;
									uni.setStorageSync("likeImage", this.likeImage);
								}
							});
						}
					}
				})
			},

			qingkonGIF() {
				// 清空当前 GIF 图片
				uni.showModal({
					title: '确定清空？',
					success: (re) => {
						if (re.confirm) {
							this.nowImage = []
							uni.removeStorageSync("nowImage");
						}
					}
				})
			},
			searchGIFChange(e) {

			},
			change(e) {
				// 输入框开关变化
				/*
				（1）如果打开输入框（show == true）
					- 此时请求 GIF 列表图片信息
				（2）如果关闭输入框
					- 恢复默认设置
				*/
				this.isToShow = false;
				if (e.show == true) {
					this.show = false;
					this.getGif();
				} else {
					uni.hideKeyboard();
					this.show = true
					this.autoFocus = false;
					// setTimeout(() => {
					//    this.autoFocus = true;
					// },0)
					this.isopen = false;
					this.current = 1;
					this.currentNum = 4.4;
					this.isShowImage = false;
					this.cursorSpacing = 20;
					this.placeholder = "说说你的看法吧～";
				}
			},
			/*

			以下方法都是输入框 状态变化，以及逻辑切换等信息

			虽然不起眼但是很重要 【🌟🌟🌟🌟🌟】

			（如果是要复制的话都是要复制的）

			*/
			// ------- start -------\
			closeSheet() {
				this.$emit('closeScrollview');
			},
			movehandle() {
				// this.autoFocus = false;
			},
			movesearch() {},
			parentPinglun() {
				this.autoFocus = false;
				this.parentNum = 1;
				setTimeout(() => {
				   this.autoFocus = true;
				},100)
				this.openPinglun();
			},
			openPinglun() {
				this.$refs.openPinglun.open('bottom')
			},
			linechange(event) {
				this.lineheight = event.detail.height
			},
			keyboardheightchange() {},
			blur() {
				// uni.hideKeyboard();

			},
			clickTextarea() {
				this.isopen = false;
				this.disabled = false;
				this.emojiHeight = 0;
			},
			focus() {
				this.isopen = false;
				this.emojiHeight = 0;
				setTimeout(() => {
					setTimeout(() => {
						this.isToShow = false;
						if (this.imageURL !== '') {
							this.isShowImage = true;
						}
					}, 1500)
				}, 20)
			},
			toemoji() {
				if (this.isopen == false) {
					this.windowHeight = uni.getSystemInfoSync().screenHeight;
					this.emojiHeight = this.windowHeight / 2.1;
					this.disabled = true;
					setTimeout(() => {
						this.isopen = true;
						this.isToShow = true;
					}, 500)
				} else {
					this.isShowImage = false;
					this.isToShow = false;
					this.isopen = false;
					this.disabled = false;
					this.emojiHeight = 0;
					if (this.imageURL !== '') {
						setTimeout(() => {
							this.isShowImage = true;
						}, 1300)
					}
				}
			},
			undo() {
				if (this.value !== "") {
					var str = ""
					for (let i = 0; i < this.value.length - 1; i++) {
						str += this.value[i]
					}
					this.value = str;
				}
			},
			timeEmoji() {
				this.currentNum = 1
				this.current = 0
			},
			nowEmoji() {
				this.currentNum = 4.3
				this.current = 1
			},
			likeEmoji() {
				this.currentNum = 7.6
				this.current = 2
			},
			gifEmoji() {
				this.currentNum = 11.0
				this.current = 3
			},
			qingkon() {
				uni.showModal({
					title: '确定清空？',
					success: (re) => {
						if (re.confirm) {
							this.nowTimeEmojiList = []
							uni.removeStorageSync("nowTimeEmojiList");
						}
					}
				})
			},
			clicknowTimeEmoji(index) {
				var str = this.nowTimeEmojiList[index].alt;
				this.value += str;
			},
			clickEmoji(index) {
				var str = this.emojilist[index].alt;
				this.value += str;
				if (uni.getStorageSync("nowTimeEmojiList").length == 0) {
					this.nowTimeEmojiList = []
					this.nowTimeEmojiList.push(this.emojilist[index])
					uni.setStorageSync("nowTimeEmojiList", this.nowTimeEmojiList);
				} else {
					this.nowTimeEmojiList = uni.getStorageSync("nowTimeEmojiList");
					let isTrue = true;
					for (let i = 0; i < this.nowTimeEmojiList.length; i++) {
						if (this.emojilist[index].alt == this.nowTimeEmojiList[i].alt) {
							isTrue = false;
						}
					}
					if (isTrue) {
						this.nowTimeEmojiList.push(this.emojilist[index])
					}
					uni.setStorageSync("nowTimeEmojiList", this.nowTimeEmojiList);
				}
			},
			clicksinaEmoji(index) {
				console.log(this.sinaEmojilist[index])
			},
			currentChange(e) {
				var num = e.detail.current + 1
				switch (num) {
					case 1:
						this.currentNum = 1
						break;
					case 2:
						this.currentNum = 4.3
						break;
					case 3:
						this.currentNum = 7.6
						break;
					case 4:
						this.currentNum = 11.0
						break;
					default:
						break;
				}
			},
			getGif() {
				var list = []
				for (let i = 0; i < 15; i++) {
					var num = Math.round(Math.random() * this.gifAndpngList.length);
					list.push(this.gifAndpngList[num]);
				}
				this.gifAndpnglist = list
			},
			scrolltolowerGIF() {
				for (let i = 0; i < 15; i++) {
					var num = Math.round(Math.random() * this.gifAndpngList.length);
					this.gifAndpnglist.push(this.gifAndpngList[num]);
				}
			}
			// ------- end -------
		}
	}
</script>

<style lang="scss">
	.sendBnt{
		width: 92rpx;
		height: 52rpx;
		background: #DDDDDD;
		border-radius: 32rpx;
		flex-direction: row;
		align-items: center;
		justify-content: center;
		font-size: 22rpx;
		font-weight: 500;
		color: #fff;
		margin-right: 8rpx;
		&.on{
			background: #E93323;
		}
	}
	.down{
		width: 24rpx;
		height: 24rpx;
		margin: 4rpx;
	}
	.androidOn{
		height: 20px;
		margin: 10px 0;
	}
	.iosOn{
		padding: 0;
		height: 35px;
		margin-right: 15px;
		// #ifdef MP
		// padding: 0;
		// height: 40px;
		// #endif
		// #ifndef MP
		// height: 20px;
		// margin: 10px 0;
		// #endif
	}
	.placeholders{
		font-size: 26rpx;
		color: #ccc;
	}
	.footers{
		margin-bottom: calc(0rpx+ constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		margin-bottom: calc(0rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	}
	.footerPop{
		margin-bottom: 13px;
		align-items: center;
	}
	.vip {
		width: 56rpx;
		height: 20rpx;
		margin-top: 6rpx;
		margin-left: 16rpx;
	}

	.numberComment {
		font-size: 32rpx;
		font-weight: 600;
		text-align: center;
		color: #333;
		margin-top: 34rpx;
		position: relative;

		.iconfont {
			position: absolute;
			right: 30rpx;
			width: 36rpx;
			height: 36rpx;
		}
	}

	.pictrue {
		width: 64rpx;
		height: 64rpx;
		border: 1px solid #fff;
		border-radius: 50rpx;
		margin-top: 20px;
		margin-left: 15px;
	}

	.name {
		font-size: 24rpx;
		font-weight: 400;
		color: #999;
	}

	.time {
		font-size: 11px;
		font-weight: 500;
		color: #666;
		margin-left: 12px;
	}

	.like {
		color: #999;
		position: absolute;
		right: 45rpx;
		margin-left: 5rpx;
		width: 26rpx;
		height: 26rpx;
		top: 6rpx;
	}

	.likeNum {
		font-size: 22rpx;
		color: #a3a1a4;
		position: absolute;
		// #ifdef H5
		right: 22rpx;
		// #endif
		// #ifdef MP
		right: 15rpx;
		// #endif
		margin-top: 2rpx;
	}

	.childrenPic {
		width: 40rpx;
		height: 40rpx;
		border-radius: 50%;
		margin-top: 12px;
	}

	.childrenName {
		font-size: 24rpx;
		font-weight: 400;
		color: #999;
	}

	.childrenLike {
		color: #999;
		position: absolute;
		right: 10rpx;
		width: 27rpx;
		height: 27rpx;
		top: 4rpx;
	}

	.childrenLikeNum {
		font-size: 22rpx;
		color: #a3a1a4;
		position: absolute;
		right: -12rpx;
		margin-top: 2rpx;
	}
</style>
