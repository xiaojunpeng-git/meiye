<template>
  <div class="pending-user" @scroll="userListApi">
    <div
      class="list"
      :class="selIndex === index ? 'bor' : ''"
      v-for="(item, index) in userList"
      :key="index"
      @click="selectUser(item, index)"
    >
      <div class="item">
        <div class="avatar">
          <img :src="item.avatar" alt="头像" />
        </div>
        <div class="item-right">
          <div class="user">
            <div class="inner">
              <div class="name">{{ userDisplayName(item) }}</div>
              <div>（ID:{{ item.uid }}）</div>
              <div class="phone">手机号：{{ item.phone || '暂无' }}</div>
            </div>
          </div>
          <div class="money">
            <div>
              积分：<span class="num">{{ item.integral }}</span>
            </div>
            <div class="now-money">
              余额：<span class="num">{{ item.now_money }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'orderList',
  props: {
    userList: {
      type: Array,
    },
    total: {
      type: Number,
    },
  },
  data() {
    return {
      selIndex: 0,
    };
  },
  methods: {
    /** 优先姓名 → 昵称 → 手机号，否则「游客」 */
    userDisplayName(item) {
      if (!item) return '游客';
      const real = String(item.real_name || '').trim();
      if (real) return real;
      const nick = String(item.nickname || '').trim();
      if (nick) return nick;
      const phone = String(item.phone || '').trim();
      if (phone) return phone;
      return '游客';
    },
    userListApi(e) {
      if (
        !e ||
        (e.target.scrollHeight - e.target.scrollTop - e.target.clientHeight <=
          0 &&
          this.userList.length < this.total)
      ) {
        this.$emit('addPage');
      } else {
        return;
      }
    },
    selectUser(item, index) {
      this.selIndex = index;
      this.$emit('selectUser', item);
    },
  },
};
</script>

<style scoped lang="stylus">
.pending-user::-webkit-scrollbar {
  width: 0 !important;
}

.pending-user {
  -ms-overflow-style: none;
}

.pending-user {
  overflow: -moz-scrollbars-none;
}

.pending-user {
  width: 100%;
  height: calc(100% - 96px);
  overflow: hidden;
  overflow-y: scroll;

  .list {
    padding: 15px 16px;
    cursor: pointer;

    +.list {
      border-top: 1px solid #F5F5F5;
    }
  }

  .bor {
    background: #F3F9FF;
    border-color: transparent;
    border-radius: 6px;

    +.list {
      border-color: transparent;
    }
  }

  .item {
    display: flex;
    align-items: center;
    font-size: 16px;

    .avatar {
      width: 46px;
      height: 46px;
      margin-right: 16px;

      img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
      }
    }

    .item-right {
      flex: 1;
      min-width: 0;
      height: 46px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;

      .user {
        .inner {
          display: inline-flex;
          max-width: 100%;
          font-size: 14px;
          color: rgba(0, 0, 0, 0.85);
        }

        .name {
          flex: 1;
          min-width: 0;
          overflow: hidden;
          white-space: nowrap;
          text-overflow: ellipsis;
          font-weight: bold;
          font-size: 16px;
        }

        .phone {
          margin-left: 12px;
          color: #666666;
        }
      }

      .money {
        display: flex;
        color: #333333;
        line-height: 14px;
        font-size: 14px;

        .num {
          color: #FF0000;
        }
      }

      .now-money {
        margin-left: 14px;
      }
    }
  }
}
</style>