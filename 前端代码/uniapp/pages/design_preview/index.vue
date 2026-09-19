<template>
	<view class="preview-page">
		<view class="preview-toolbar">
			<view class="preview-title">
				<text class="preview-kicker">RH BEAUTY</text>
				<text class="preview-heading">会员端视觉预览</text>
			</view>
			<view class="preview-tabs">
				<view
					v-for="item in screens"
					:key="item.key"
					class="preview-tab"
					:class="{ active: activeScreen === item.key }"
					@click="activeScreen = item.key"
				>{{ item.label }}</view>
			</view>
		</view>

		<!-- 该页只用于前端视觉与操作路径确认，不请求、不修改任何业务数据。 -->
		<view v-if="activeScreen === 'home'" class="mobile-screen home-screen">
			<view class="home-top">
				<view class="brand-lockup">
					<text class="brand-name">瑞昊美业</text>
					<text class="brand-en">REHO BEAUTY</text>
				</view>
				<view class="search-pill">
					<text class="iconfont icon-ic_search"></text>
					<text>搜索服务、产品与门店</text>
				</view>
				<text class="iconfont icon-ic_message"></text>
			</view>

			<view class="hero-card">
				<image class="hero-image" src="/static/design-preview/beauty-hero.png" mode="aspectFill"></image>
				<view class="hero-copy">
					<text class="hero-eyebrow">给自己一段舒展时光</text>
					<text class="hero-title">遇见更好的自己</text>
					<text class="hero-desc">专业护理 · 放松身心 · 焕亮生活</text>
					<view class="hero-button" @click="activeScreen = 'teacher'">立即预约</view>
				</view>
			</view>

			<view class="quick-grid card-shell">
				<view v-for="item in quickActions" :key="item.title" class="quick-item" @click="handleQuickAction(item.target)">
					<view class="quick-icon"><text class="iconfont" :class="item.icon"></text></view>
					<text>{{ item.title }}</text>
				</view>
			</view>

			<view class="section-heading">
				<view>
					<text class="section-kicker">CURATED FOR YOU</text>
					<text class="section-title">为你推荐</text>
				</view>
				<text class="section-link">查看全部</text>
			</view>
			<view class="recommend-card card-shell">
				<image class="recommend-image" src="/static/design-preview/beauty-hero.png" mode="aspectFill"></image>
				<view class="recommend-copy">
					<text class="recommend-tag">本周焕新计划</text>
					<text class="recommend-title">深层舒缓护理</text>
					<text class="recommend-desc">给肌肤与心情一份温柔的修复</text>
					<view class="text-button" @click="activeScreen = 'booking'">查看方案</view>
				</view>
			</view>

			<view class="section-heading compact">
				<view>
					<text class="section-kicker">POPULAR SERVICES</text>
					<text class="section-title">人气服务</text>
				</view>
			</view>
			<view class="service-row">
				<view v-for="item in services" :key="item.name" class="service-mini card-shell" @click="activeScreen = 'booking'">
					<image :src="item.image" mode="aspectFill"></image>
					<text>{{ item.name }}</text>
					<text class="service-price">¥{{ item.price }} 起</text>
				</view>
			</view>
		</view>

		<view v-else-if="activeScreen === 'member'" class="mobile-screen member-screen">
			<view class="plain-nav">
				<text class="plain-nav-title">会员中心</text>
				<text class="iconfont icon-ic_message"></text>
			</view>
			<view class="member-hero">
				<image class="member-hero-image" src="/static/design-preview/beauty-hero.png" mode="aspectFill"></image>
				<view class="member-overlay"></view>
				<view class="member-content">
					<view class="member-person">
						<image class="member-avatar" src="/static/design-preview/beauty-hero.png" mode="aspectFill"></image>
						<view>
							<text class="member-name">黄金明</text>
							<text class="member-level">星光会员 · 合肥滨湖店</text>
						</view>
					</view>
					<view class="growth-line">
						<view class="growth-fill"></view>
					</view>
					<view class="growth-copy"><text>成长值 2860</text><text>距离下一等级 2140</text></view>
				</view>
			</view>

			<view class="asset-panel card-shell">
				<view v-for="item in assets" :key="item.label" class="asset-item">
					<text class="asset-value">{{ item.value }}</text>
					<text>{{ item.label }}</text>
				</view>
			</view>

			<view class="section-heading compact member-heading">
				<view><text class="section-kicker">MEMBER BENEFITS</text><text class="section-title">我的权益</text></view>
				<text class="section-link">查看全部</text>
			</view>
			<view class="benefit-grid card-shell">
				<view v-for="item in benefits" :key="item.title" class="benefit-item">
					<view class="benefit-icon"><text class="iconfont" :class="item.icon"></text></view>
					<text>{{ item.title }}</text>
					<text class="benefit-desc">{{ item.desc }}</text>
				</view>
			</view>

			<view class="section-heading compact member-heading">
				<view><text class="section-kicker">MY SCHEDULE</text><text class="section-title">服务与预约</text></view>
				<text class="section-link" @click="activeScreen = 'booking'">去预约</text>
			</view>
			<view class="record-card card-shell">
				<image src="/static/design-preview/beauty-hero.png" mode="aspectFill"></image>
				<view class="record-body">
					<text class="record-status">待服务 · 09 月 22 日</text>
					<text class="record-title">焕亮舒缓护理</text>
					<text class="record-meta">服务老师：林若曦 · 14:00</text>
				</view>
				<view class="record-action" @click="activeScreen = 'booking'">查看</view>
			</view>
		</view>

		<view v-else-if="activeScreen === 'teacher'" class="mobile-screen teacher-screen">
			<view class="plain-nav">
				<text class="plain-nav-title">服务老师</text>
				<text class="plain-nav-side">合肥滨湖店</text>
			</view>
			<view class="teacher-search"><text class="iconfont icon-ic_search"></text><text>搜索服务老师</text></view>
			<view class="filter-strip">
				<view v-for="item in filters" :key="item" class="filter-chip" :class="{ selected: selectedFilter === item }" @click="selectedFilter = item">{{ item }}</view>
			</view>
			<view class="teacher-intro">
				<text class="section-kicker">FIND YOUR SPECIALIST</text>
				<text class="teacher-intro-title">选择适合你的服务老师</text>
				<text>查看擅长方向与可预约时间，再开始预约。</text>
			</view>
			<view v-for="teacher in teachers" :key="teacher.id" class="teacher-card card-shell" :class="{ chosen: selectedTeacher.id === teacher.id }" @click="selectTeacher(teacher)">
				<image class="teacher-avatar" :src="teacher.image" mode="aspectFill"></image>
				<view class="teacher-body">
					<view class="teacher-name-line"><text class="teacher-name">{{ teacher.name }}</text><text class="teacher-tag">{{ teacher.role }}</text></view>
					<text class="teacher-skill">擅长：{{ teacher.skill }}</text>
					<text class="teacher-meta">{{ teacher.experience }} · {{ teacher.availability }}</text>
				</view>
				<view class="teacher-choice">{{ selectedTeacher.id === teacher.id ? '已选择' : '选择她' }}</view>
			</view>
			<view class="preview-bottom-action" @click="activeScreen = 'booking'">下一步，选择预约时间</view>
		</view>

		<view v-else class="mobile-screen booking-screen">
			<view class="plain-nav">
				<text class="plain-nav-title">确认预约</text>
				<text class="plain-nav-side" @click="activeScreen = 'teacher'">更换老师</text>
			</view>
			<view class="booking-progress card-shell">
				<view v-for="(item, index) in bookingSteps" :key="item" class="progress-item" :class="{ current: index === 2, done: index < 2 }">
					<view class="progress-dot">{{ index + 1 }}</view>
					<text>{{ item }}</text>
				</view>
			</view>

			<view class="booking-block">
				<text class="booking-block-title">已选服务</text>
				<view class="booking-service card-shell">
					<image src="/static/design-preview/beauty-hero.png" mode="aspectFill"></image>
					<view><text class="booking-service-name">焕亮舒缓护理</text><text>预计 90 分钟 · 到店服务</text></view>
				</view>
			</view>

			<view class="booking-block">
				<text class="booking-block-title">服务老师</text>
				<view class="booking-teacher card-shell"><image :src="selectedTeacher.image" mode="aspectFill"></image><view><text>{{ selectedTeacher.name }}</text><text>{{ selectedTeacher.role }} · {{ selectedTeacher.skill }}</text></view></view>
			</view>

			<view class="booking-block">
				<text class="booking-block-title">选择日期</text>
				<view class="date-row">
					<view v-for="day in days" :key="day.label" class="date-item" :class="{ selected: selectedDay.label === day.label }" @click="selectedDay = day"><text>{{ day.week }}</text><text class="date-number">{{ day.label }}</text><text>{{ day.month }}月</text></view>
				</view>
			</view>

			<view class="booking-block">
				<text class="booking-block-title">选择时间</text>
				<view class="time-grid"><view v-for="time in times" :key="time" class="time-chip" :class="{ selected: selectedTime === time }" @click="selectedTime = time">{{ time }}</view></view>
			</view>

			<view class="appointment-summary card-shell">
				<text>预约信息</text>
				<view><text>到店时间</text><text>{{ selectedDay.month }} 月 {{ selectedDay.label }} 日 {{ selectedTime }}</text></view>
				<view><text>预约门店</text><text>合肥滨湖店</text></view>
			</view>
			<view class="preview-bottom-action">确认预约</view>
		</view>

		<view class="preview-note">前端预览 · 静态展示数据 · 不调用业务接口</view>
	</view>
</template>

<script>
export default {
	data() {
		const image = '/static/design-preview/beauty-hero.png';
		return {
			activeScreen: 'home',
			screens: [
				{ key: 'home', label: '首页' },
				{ key: 'member', label: '会员' },
				{ key: 'teacher', label: '老师' },
				{ key: 'booking', label: '预约' },
			],
			quickActions: [
				{ title: '预约服务', icon: 'icon-ic_clock', target: 'teacher' },
				{ title: '会员权益', icon: 'icon-ic_crown', target: 'member' },
				{ title: '优惠券', icon: 'icon-ic_coupon', target: 'member' },
				{ title: '服务记录', icon: 'icon-ic_card2', target: 'booking' },
			],
			services: [
				{ name: '焕亮护理', price: '298', image },
				{ name: '舒缓修护', price: '368', image },
				{ name: '深层清洁', price: '198', image },
			],
			assets: [
				{ value: '0.00', label: '余额' },
				{ value: '2', label: '优惠券' },
				{ value: '2', label: '我的卡包' },
			],
			benefits: [
				{ title: '会员等级', desc: '专属权益', icon: 'icon-ic_crown' },
				{ title: '生日礼遇', desc: '温柔相伴', icon: 'icon-ic_gift3' },
				{ title: '积分商城', desc: '限量兑换', icon: 'icon-ic_Money2' },
				{ title: '专属客服', desc: '贴心服务', icon: 'icon-ic_customerservice' },
			],
			filters: ['全部', '面部护理', '身体舒缓', '美甲美睫'],
			selectedFilter: '全部',
			teachers: [
				{ id: 1, name: '林若曦', role: '高级美容师', skill: '敏感肌修护、焕亮管理', experience: '6 年经验', availability: '今日可约', image },
				{ id: 2, name: '苏妍', role: '护理老师', skill: '深层清洁、补水舒缓', experience: '4 年经验', availability: '明日可约', image },
				{ id: 3, name: '周芷晴', role: '美甲老师', skill: '手足护理、轻奢美甲', experience: '5 年经验', availability: '今日可约', image },
			],
			selectedTeacher: { id: 1, name: '林若曦', role: '高级美容师', skill: '敏感肌修护、焕亮管理', image },
			bookingSteps: ['服务项目', '服务老师', '日期时段', '确认预约'],
			days: [
				{ week: '今天', label: '20', month: '9' },
				{ week: '周日', label: '21', month: '9' },
				{ week: '周一', label: '22', month: '9' },
				{ week: '周二', label: '23', month: '9' },
			],
			selectedDay: { week: '周一', label: '22', month: '9' },
			times: ['10:00', '11:30', '13:30', '15:00', '16:30', '18:00'],
			selectedTime: '14:00',
		};
	},
	methods: {
		handleQuickAction(target) {
			this.activeScreen = target;
		},
		selectTeacher(teacher) {
			this.selectedTeacher = teacher;
		},
	},
};
</script>

<style scoped lang="scss">
$ink: #322528;
$wine: #7a1f38;
$wine-soft: #f3e3e6;
$warm-white: #fffaf8;
$line: #ebdfe0;

.preview-page { min-height: 100vh; background: #f4eeeb; color: $ink; padding-bottom: 44rpx; font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', sans-serif; }
.preview-toolbar { box-sizing: border-box; padding: 36rpx 28rpx 22rpx; background: #fff; border-bottom: 1rpx solid #eee3e3; }
.preview-title { display: flex; align-items: baseline; justify-content: space-between; }
.preview-kicker, .section-kicker { color: #a47880; font-size: 18rpx; letter-spacing: 2rpx; }
.preview-heading { font-size: 30rpx; font-weight: 600; }
.preview-tabs { margin-top: 26rpx; display: flex; padding: 8rpx; border-radius: 32rpx; background: #f7f1f0; }
.preview-tab { flex: 1; text-align: center; padding: 12rpx 0; border-radius: 24rpx; color: #8d777b; font-size: 23rpx; }
.preview-tab.active { color: #fff; background: $wine; box-shadow: 0 8rpx 18rpx rgba(122, 31, 56, .18); }
.mobile-screen { width: 100%; max-width: 750rpx; margin: 0 auto; box-sizing: border-box; background: $warm-white; min-height: calc(100vh - 188rpx); padding: 28rpx 24rpx 136rpx; }
.card-shell { background: #fff; border: 1rpx solid rgba(196, 161, 166, .22); box-shadow: 0 12rpx 28rpx rgba(96, 51, 57, .06); border-radius: 24rpx; }
.home-top, .plain-nav { display: flex; align-items: center; justify-content: space-between; }
.home-top { gap: 16rpx; margin-bottom: 24rpx; }
.brand-lockup { width: 124rpx; display: flex; flex-direction: column; }
.brand-name { font-size: 26rpx; font-weight: 600; letter-spacing: 1rpx; }
.brand-en { margin-top: 3rpx; color: #a48489; font-size: 14rpx; letter-spacing: 2rpx; }
.search-pill, .teacher-search { flex: 1; display: flex; align-items: center; gap: 12rpx; color: #a28e91; font-size: 22rpx; background: #f8f3f1; border-radius: 32rpx; padding: 16rpx 22rpx; }
.home-top > .iconfont { color: $wine; font-size: 34rpx; }
.hero-card { position: relative; overflow: hidden; height: 330rpx; border-radius: 28rpx; box-shadow: 0 14rpx 34rpx rgba(119, 64, 71, .14); }
.hero-image { width: 100%; height: 100%; }
.hero-copy { position: absolute; left: 30rpx; top: 38rpx; display: flex; flex-direction: column; align-items: flex-start; max-width: 360rpx; }
.hero-eyebrow { color: #956c73; font-size: 20rpx; letter-spacing: 1rpx; }
.hero-title { margin-top: 12rpx; color: #4a3033; font-family: STSong, 'Songti SC', serif; font-size: 48rpx; font-weight: 600; letter-spacing: 1rpx; }
.hero-desc { margin-top: 10rpx; color: #775e62; font-size: 22rpx; }
.hero-button, .preview-bottom-action { color: #fff; background: $wine; border-radius: 34rpx; font-size: 24rpx; text-align: center; box-shadow: 0 10rpx 18rpx rgba(122, 31, 56, .18); }
.hero-button { margin-top: 24rpx; padding: 14rpx 28rpx; }
.quick-grid { display: flex; justify-content: space-around; margin-top: 24rpx; padding: 24rpx 12rpx; }
.quick-item { width: 25%; display: flex; flex-direction: column; align-items: center; gap: 12rpx; color: #594347; font-size: 21rpx; }
.quick-icon { display: flex; justify-content: center; align-items: center; width: 72rpx; height: 72rpx; border-radius: 50%; background: $wine-soft; color: $wine; }
.quick-icon .iconfont { font-size: 32rpx; }
.section-heading { margin: 44rpx 6rpx 18rpx; display: flex; align-items: flex-end; justify-content: space-between; }
.section-heading > view { display: flex; flex-direction: column; gap: 5rpx; }
.section-heading.compact { margin-top: 34rpx; }
.section-title { font-family: STSong, 'Songti SC', serif; font-size: 34rpx; font-weight: 600; }
.section-link { color: #9b7179; font-size: 21rpx; }
.recommend-card { display: flex; overflow: hidden; min-height: 178rpx; }
.recommend-image { width: 228rpx; height: 178rpx; }
.recommend-copy { padding: 22rpx; display: flex; flex-direction: column; align-items: flex-start; }
.recommend-tag, .record-status { color: #9c6f78; font-size: 20rpx; }
.recommend-title { margin-top: 8rpx; font-size: 28rpx; font-weight: 600; }
.recommend-desc { margin-top: 7rpx; color: #957f82; font-size: 20rpx; }
.text-button { margin-top: auto; color: $wine; font-size: 21rpx; }
.service-row { display: flex; gap: 16rpx; overflow: hidden; }
.service-mini { flex: 1; overflow: hidden; padding-bottom: 18rpx; display: flex; flex-direction: column; gap: 10rpx; color: #583e43; font-size: 21rpx; }
.service-mini image { width: 100%; height: 148rpx; }
.service-mini text { padding: 0 16rpx; }
.service-mini .service-price { color: $wine; font-size: 18rpx; }
.plain-nav { min-height: 64rpx; margin-bottom: 28rpx; }
.plain-nav-title { font-family: STSong, 'Songti SC', serif; font-size: 38rpx; font-weight: 600; }
.plain-nav-side { color: #9a737a; font-size: 22rpx; }
.member-hero { position: relative; overflow: hidden; height: 252rpx; border-radius: 26rpx; }
.member-hero-image, .member-overlay { position: absolute; width: 100%; height: 100%; left: 0; top: 0; }
.member-overlay { background: rgba(78, 35, 45, .26); }
.member-content { position: absolute; inset: 0; padding: 30rpx; box-sizing: border-box; color: #fff; display: flex; flex-direction: column; justify-content: flex-end; }
.member-person { display: flex; align-items: center; gap: 16rpx; }
.member-avatar { width: 70rpx; height: 70rpx; border-radius: 50%; border: 3rpx solid rgba(255, 255, 255, .78); }
.member-name { display: block; font-size: 30rpx; font-weight: 600; }.member-level { display: block; margin-top: 5rpx; font-size: 19rpx; }
.growth-line { overflow: hidden; height: 9rpx; margin-top: 24rpx; background: rgba(255,255,255,.4); border-radius: 8rpx; }.growth-fill { height: 100%; width: 58%; background: #fff4e9; border-radius: inherit; }
.growth-copy { display: flex; justify-content: space-between; margin-top: 10rpx; font-size: 18rpx; }
.asset-panel { position: relative; margin: -18rpx 14rpx 0; padding: 24rpx 12rpx; display: flex; }.asset-item { width: 33.33%; display: flex; flex-direction: column; align-items: center; gap: 8rpx; color: #8a7478; font-size: 21rpx; }.asset-value { color: $ink; font-size: 32rpx; font-weight: 600; }
.member-heading { margin-top: 38rpx; }.benefit-grid { display: grid; grid-template-columns: 1fr 1fr; padding: 8rpx; }.benefit-item { display: flex; flex-direction: column; padding: 24rpx 22rpx; gap: 8rpx; font-size: 23rpx; }.benefit-desc { color: #9a8588; font-size: 19rpx; }.benefit-icon { color: $wine; }.benefit-icon .iconfont { font-size: 35rpx; }
.record-card { display: flex; align-items: center; padding: 16rpx; gap: 16rpx; }.record-card image { width: 104rpx; height: 104rpx; border-radius: 16rpx; }.record-body { flex: 1; display: flex; flex-direction: column; gap: 7rpx; }.record-title { font-size: 26rpx; font-weight: 600; }.record-meta { color: #9b8588; font-size: 19rpx; }.record-action { padding: 12rpx 18rpx; color: $wine; border: 1rpx solid #d9b8bd; border-radius: 24rpx; font-size: 21rpx; }
.teacher-search { padding: 20rpx 24rpx; }.filter-strip { display: flex; gap: 14rpx; overflow: hidden; padding: 24rpx 0; }.filter-chip { flex-shrink: 0; padding: 12rpx 20rpx; border-radius: 28rpx; background: #f7efed; color: #80676c; font-size: 21rpx; }.filter-chip.selected { color: #fff; background: $wine; }.teacher-intro { margin: 10rpx 2rpx 24rpx; display: flex; flex-direction: column; gap: 8rpx; color: #947f82; font-size: 21rpx; }.teacher-intro-title { color: $ink; font-family: STSong, 'Songti SC', serif; font-size: 34rpx; font-weight: 600; }
.teacher-card { display: flex; align-items: center; gap: 18rpx; padding: 18rpx; margin-bottom: 18rpx; }.teacher-card.chosen { border-color: #b47b88; box-shadow: 0 8rpx 28rpx rgba(122,31,56,.12); }.teacher-avatar { width: 108rpx; height: 108rpx; border-radius: 18rpx; }.teacher-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8rpx; }.teacher-name-line { display: flex; align-items: center; gap: 9rpx; }.teacher-name { font-size: 28rpx; font-weight: 600; }.teacher-tag { color: #9a737a; font-size: 18rpx; }.teacher-skill, .teacher-meta { color: #8a7779; font-size: 19rpx; }.teacher-choice { padding: 13rpx 16rpx; border-radius: 24rpx; color: $wine; background: $wine-soft; font-size: 20rpx; }.preview-bottom-action { position: fixed; z-index: 4; bottom: 24rpx; left: 28rpx; right: 28rpx; max-width: 694rpx; margin: auto; padding: 25rpx 0; }
.booking-progress { display: flex; padding: 24rpx 8rpx; }.progress-item { position: relative; flex: 1; display: flex; flex-direction: column; align-items: center; gap: 9rpx; color: #a98f94; font-size: 18rpx; }.progress-item:not(:last-child)::after { content: ''; position: absolute; left: 62%; top: 15rpx; width: 76%; height: 1rpx; background: #e7d9da; }.progress-dot { position: relative; z-index: 1; width: 30rpx; height: 30rpx; border-radius: 50%; display: flex; justify-content: center; align-items: center; background: #eadfe0; color: #9c8186; }.progress-item.done, .progress-item.current { color: $wine; }.progress-item.done .progress-dot, .progress-item.current .progress-dot { color: #fff; background: $wine; }.progress-item.done:not(:last-child)::after { background: #b67987; }
.booking-block { margin-top: 32rpx; }.booking-block-title { display: block; margin: 0 4rpx 15rpx; font-size: 27rpx; font-weight: 600; }.booking-service, .booking-teacher { display: flex; align-items: center; gap: 16rpx; padding: 16rpx; }.booking-service image, .booking-teacher image { width: 96rpx; height: 96rpx; border-radius: 16rpx; }.booking-service > view, .booking-teacher > view { display: flex; flex-direction: column; gap: 9rpx; color: #8f7b7d; font-size: 20rpx; }.booking-service-name, .booking-teacher text:first-child { color: $ink; font-size: 26rpx; font-weight: 600; }
.date-row { display: flex; gap: 12rpx; }.date-item { flex: 1; padding: 17rpx 0; display: flex; flex-direction: column; align-items: center; gap: 6rpx; border: 1rpx solid #eadedf; border-radius: 18rpx; color: #947d80; font-size: 18rpx; }.date-number { color: $ink; font-size: 30rpx; font-weight: 600; }.date-item.selected { border-color: $wine; background: $wine-soft; color: $wine; }.date-item.selected .date-number { color: $wine; }.time-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14rpx; }.time-chip { padding: 17rpx 0; text-align: center; border: 1rpx solid #eadedf; border-radius: 14rpx; color: #795f64; font-size: 22rpx; }.time-chip.selected { color: #fff; background: $wine; border-color: $wine; }.appointment-summary { margin-top: 36rpx; padding: 24rpx; display: flex; flex-direction: column; gap: 17rpx; font-size: 23rpx; font-weight: 600; }.appointment-summary > view { display: flex; justify-content: space-between; color: #957e82; font-size: 20rpx; font-weight: 400; }.appointment-summary > view text:last-child { color: $ink; }
.preview-note { padding: 22rpx 0 0; color: #9e8d8f; font-size: 19rpx; text-align: center; }
</style>
