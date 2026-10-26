<template>
  <view class="changelog-page" :style="colorStyle">
    <view v-if="loadError" class="state-wrap">
      <text class="state-text">加载失败，请重试</text>
      <view class="retry-btn" @tap="retryLoad">重试</view>
    </view>
    <view v-else-if="!loading && !groupedList.length" class="state-wrap">
      <text class="state-text">暂时没有更新记录</text>
    </view>
    <view v-else class="list-wrap">
      <view v-for="(group, gIndex) in groupedList" :key="gIndex" class="day-group">
        <view class="day-header">
          <text class="day-date">{{ formatDayTitle(group.publish_date) }}</text>
          <text v-if="group.headerVersion" class="day-version">V{{ group.headerVersion }}</text>
        </view>
        <view
          v-for="(log, lIndex) in group.logs"
          :key="log.id || lIndex"
          class="log-card"
          @tap="openDetail(log)"
        >
          <view class="log-title-row" v-if="log.title || (group.showPerLogVersion && log.version)">
            <text v-if="log.title" class="log-title">{{ log.title }}</text>
            <text v-if="group.showPerLogVersion && log.version" class="log-version">V{{ log.version }}</text>
          </view>
          <view v-if="log.summary" class="log-summary">{{ log.summary }}</view>
          <view v-for="(typeGroup, tIndex) in getTypeGroups(log.items)" :key="tIndex" class="type-group">
            <view class="type-label">{{ typeGroup.label }}</view>
            <view v-for="(item, iIndex) in typeGroup.items" :key="iIndex" class="type-item">
              <text class="dot">·</text>
              <text>{{ item.content }}</text>
            </view>
          </view>
        </view>
      </view>
      <view v-if="loading" class="loading-more">加载中...</view>
      <view v-else-if="finished && groupedList.length" class="loading-more">没有更多了</view>
    </view>

    <view v-if="showDetail" class="popup-mask" @tap="closeDetail">
      <view class="detail-box" @tap.stop>
        <view class="detail-head">
          <text class="detail-title">{{ detailInfo.title }}</text>
          <text class="iconfont icon-guanbi" @tap="closeDetail"></text>
        </view>
        <view class="detail-meta">
          <text>{{ formatDayTitle(detailInfo.publish_date) }}</text>
          <text v-if="detailInfo.version">V{{ detailInfo.version }}</text>
        </view>
        <scroll-view scroll-y class="detail-scroll">
          <view v-for="(typeGroup, tIndex) in getTypeGroups(detailInfo.items)" :key="tIndex" class="type-group">
            <view class="type-label">{{ typeGroup.label }}</view>
            <view v-for="(item, iIndex) in typeGroup.items" :key="iIndex" class="type-item">
              <text class="dot">·</text>
              <text>{{ item.content }}</text>
            </view>
          </view>
        </scroll-view>
      </view>
    </view>

    <view v-if="showImportantPopup" class="popup-mask" @tap="closeImportantPopup">
      <view class="popup-box" @tap.stop>
        <view class="popup-title">重要更新</view>
        <view class="popup-content">{{ importantPopupContent }}</view>
        <view class="popup-btn" @tap="closeImportantPopup">我知道了</view>
      </view>
    </view>
  </view>
</template>

<script>
import { getChangelogList, getChangelogDetail, getChangelogUnread } from '@/api/changelog.js';
import { LAST_CHANGELOG_READ_TIME, LAST_IMPORTANT_CHANGELOG_POPUP_ID } from '@/config/cache';
import colors from '@/mixins/color.js';

const CHANGE_TYPE_ORDER = ['add', 'optimize', 'adjust', 'fix', 'offline'];
const CHANGE_TYPE_MAP = {
  add: '新增',
  optimize: '优化',
  adjust: '调整',
  fix: '修复',
  offline: '下线',
};

export default {
  mixins: [colors],
  data() {
    return {
      page: 1,
      limit: 20,
      loading: false,
      finished: false,
      loadError: false,
      rawList: [],
      showImportantPopup: false,
      importantPopupContent: '',
      importantPopupId: 0,
      showDetail: false,
      detailInfo: {},
      hasMarkedRead: false,
      hasCheckedPopup: false,
    };
  },
  computed: {
    groupedList() {
      const dayMap = {};
      this.rawList.forEach((item) => {
        const day = item.publish_date || '';
        if (!day) return;
        if (!dayMap[day]) {
          dayMap[day] = {
            publish_date: day,
            logs: [],
          };
        }
        dayMap[day].logs.push(item);
      });
      return Object.values(dayMap)
        .map((group) => {
          const versions = group.logs
            .map((log) => String(log.version || '').trim())
            .filter((v) => v !== '');
          const uniqueVersions = Array.from(new Set(versions));
          // 同日版本规则：
          // 1) 仅一篇，或同日多篇版本号完全一致 → 日期行展示该版本
          // 2) 同日多篇且版本号不一致（或有的有版本、有的没有）→ 日期行不展示版本，改在各篇卡片上分别展示
          const sameVersion = uniqueVersions.length === 1 && versions.length === group.logs.length;
          const single = group.logs.length === 1;
          return {
            ...group,
            headerVersion: single || sameVersion ? (uniqueVersions[0] || '') : '',
            showPerLogVersion: !single && !sameVersion,
          };
        })
        .sort((a, b) => {
          return String(b.publish_date).localeCompare(String(a.publish_date));
        });
    },
  },
  onLoad() {
    this.resetAndLoad();
  },
  onReachBottom() {
    this.loadList();
  },
  onPullDownRefresh() {
    this.hasMarkedRead = false;
    this.hasCheckedPopup = false;
    this.resetAndLoad();
  },
  methods: {
    resetAndLoad() {
      this.page = 1;
      this.rawList = [];
      this.finished = false;
      this.loadError = false;
      this.loadList(true);
    },
    retryLoad() {
      this.loadError = false;
      if (!this.rawList.length) {
        this.resetAndLoad();
      } else {
        this.loadList();
      }
    },
    loadList(isRefresh = false) {
      if (this.loading || this.finished) {
        if (isRefresh) uni.stopPullDownRefresh();
        return;
      }
      this.loading = true;
      getChangelogList({ page: this.page, limit: this.limit })
        .then((res) => {
          const list = (res.data && res.data.list) || [];
          this.rawList = this.rawList.concat(list);
          this.finished = list.length < this.limit;
          this.page += 1;
          this.loadError = false;
          // 首次成功加载：各执行一次已读标记 / 重要弹窗，避免互相重复触发
          if (!this.hasMarkedRead) {
            this.hasMarkedRead = true;
            this.markAsRead();
          }
          if (!this.hasCheckedPopup) {
            this.hasCheckedPopup = true;
            this.checkImportantPopup();
          }
        })
        .catch(() => {
          this.loadError = true;
        })
        .finally(() => {
          this.loading = false;
          uni.stopPullDownRefresh();
        });
    },
    markAsRead() {
      getChangelogUnread()
        .then((res) => {
          const data = res.data || {};
          if (data.server_time) {
            uni.setStorageSync(LAST_CHANGELOG_READ_TIME, data.server_time);
          }
        })
        .catch(() => {
          const now = Math.floor(Date.now() / 1000);
          uni.setStorageSync(LAST_CHANGELOG_READ_TIME, now);
        });
    },
    checkImportantPopup() {
      const popupLog = this.rawList.find((item) => Number(item.is_important) === 1 && Number(item.is_popup) === 1);
      if (!popupLog) return;
      const shownId = uni.getStorageSync(LAST_IMPORTANT_CHANGELOG_POPUP_ID);
      if (String(shownId) === String(popupLog.id)) return;
      this.importantPopupId = popupLog.id;
      this.importantPopupContent = popupLog.summary || popupLog.title || '系统有重要更新，请查看更新日志。';
      this.showImportantPopup = true;
    },
    closeImportantPopup() {
      this.showImportantPopup = false;
      if (this.importantPopupId) {
        uni.setStorageSync(LAST_IMPORTANT_CHANGELOG_POPUP_ID, this.importantPopupId);
      }
    },
    getTypeGroups(items) {
      const list = items || [];
      const groups = {};
      list.forEach((item) => {
        const type = item.change_type || 'add';
        if (!groups[type]) groups[type] = [];
        groups[type].push(item);
      });
      return CHANGE_TYPE_ORDER
        .filter((type) => groups[type] && groups[type].length)
        .map((type) => ({
          label: CHANGE_TYPE_MAP[type],
          items: groups[type],
        }));
    },
    formatDayTitle(dateStr) {
      if (!dateStr) return '';
      const parts = String(dateStr).split('-');
      if (parts.length !== 3) return dateStr;
      return `${parts[0]}年${Number(parts[1])}月${Number(parts[2])}日`;
    },
    openDetail(log) {
      if (!log || !log.id) return;
      if (log.items && log.items.length) {
        this.detailInfo = log;
        this.showDetail = true;
        return;
      }
      getChangelogDetail(log.id)
        .then((res) => {
          this.detailInfo = res.data || log;
          this.showDetail = true;
        })
        .catch((err) => {
          uni.showToast({ title: err.msg || '加载失败', icon: 'none' });
        });
    },
    closeDetail() {
      this.showDetail = false;
      this.detailInfo = {};
    },
  },
};
</script>

<style lang="scss" scoped>
.changelog-page {
  min-height: 100vh;
  background: #f5f5f5;
  padding-bottom: 40rpx;
}

.state-wrap {
  padding: 120rpx 40rpx;
  text-align: center;
}

.state-text {
  font-size: 28rpx;
  color: #999;
}

.retry-btn {
  display: inline-block;
  margin-top: 24rpx;
  padding: 12rpx 40rpx;
  font-size: 28rpx;
  color: #fff;
  background: var(--view-theme, #e93323);
  border-radius: 40rpx;
}

.list-wrap {
  padding: 24rpx 20rpx;
}

.day-group {
  margin-bottom: 24rpx;
}

.day-header {
  display: flex;
  align-items: center;
  margin-bottom: 16rpx;
  padding: 0 8rpx;
}

.day-date {
  font-size: 30rpx;
  font-weight: 600;
  color: #333;
}

.day-version {
  margin-left: 16rpx;
  font-size: 24rpx;
  color: #999;
}

.log-card {
  background: #fff;
  border-radius: 24rpx;
  padding: 28rpx 32rpx;
  margin-bottom: 16rpx;
}

.log-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12rpx;
}

.log-title {
  font-size: 30rpx;
  font-weight: 600;
  flex: 1;
  padding-right: 12rpx;
}

.log-version {
  font-size: 24rpx;
  color: #999;
  flex-shrink: 0;
}

.log-summary {
  font-size: 26rpx;
  color: #666;
  margin-bottom: 12rpx;
}

.type-group {
  margin-top: 8rpx;
}

.type-label {
  font-size: 28rpx;
  font-weight: 600;
  color: #333;
  margin-bottom: 8rpx;
}

.type-item {
  display: flex;
  font-size: 26rpx;
  color: #666;
  line-height: 1.6;
  margin-bottom: 6rpx;
  padding-left: 8rpx;
}

.dot {
  margin-right: 8rpx;
  flex-shrink: 0;
}

.loading-more {
  text-align: center;
  font-size: 24rpx;
  color: #999;
  padding: 20rpx 0;
}

.popup-mask {
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 999;
}

.popup-box {
  width: 600rpx;
  background: #fff;
  border-radius: 24rpx;
  padding: 40rpx 32rpx;
}

.popup-title {
  font-size: 32rpx;
  font-weight: 600;
  text-align: center;
  margin-bottom: 20rpx;
}

.popup-content {
  font-size: 28rpx;
  color: #666;
  line-height: 1.6;
  margin-bottom: 32rpx;
}

.popup-btn {
  text-align: center;
  font-size: 30rpx;
  color: var(--view-theme, #e93323);
  font-weight: 600;
}

.detail-box {
  width: 640rpx;
  max-height: 70vh;
  background: #fff;
  border-radius: 24rpx;
  padding: 32rpx;
  display: flex;
  flex-direction: column;
}

.detail-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12rpx;
}

.detail-title {
  font-size: 32rpx;
  font-weight: 600;
  flex: 1;
  padding-right: 16rpx;
}

.detail-meta {
  font-size: 24rpx;
  color: #999;
  margin-bottom: 16rpx;

  text + text {
    margin-left: 12rpx;
  }
}

.detail-scroll {
  max-height: 50vh;
}
</style>
