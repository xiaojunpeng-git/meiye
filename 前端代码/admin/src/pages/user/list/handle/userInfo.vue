<template>
    <div class="user-info">
        <div class="section">
            <div class="section-hd">基本信息</div>
            <div class="section-bd">
                <div class="item">
                    <div>用户编号：</div>
                    <div class="value">{{ psInfo.uid }}</div>
                </div>
                <div class="item">
                    <div>真实姓名：</div>
                    <div class="value">{{ psInfo.real_name || '-' }}</div>
                </div>
                <div class="item">
                    <div>手机号码：</div>
                    <div class="value">{{ psInfo.phone || '-' }}</div>
                </div>
                <div class="item">
                    <div>生日：</div>
                    <div class="value">{{ psInfo.birthday || '-' }}</div>
                </div>
                <div class="item">
                    <div>性别：</div>
                    <div v-if="psInfo.sex" class="value">{{ psInfo.sex == 1 ? '男' : '女' }}</div>
                    <div v-else class="value">保密</div>
                </div>
                <div class="item">
                    <div>身份证号：</div>
                    <div class="value">{{ psInfo.card_id || '-' }}</div>
                </div>
                <div class="item">
                    <div>用户地址：</div>
                    <div class="value">{{ `${psInfo.provincials}${psInfo.addres}` || '-' }}</div>
                </div>
            </div>
        </div>
        <div class="section">
            <div class="section-hd">用户概况</div>
            <div class="section-bd">
                <div class="item">
                    <div>推广资格：</div>
                    <div class="value">{{ psInfo.spread_open ? '启用' : '禁用' }}</div>
                </div>
                <div class="item">
                    <div>归属门店：</div>
                    <div class="value">{{ psInfo.belong_store_name || '无' }}</div>
                </div>
                <div class="item">
                    <div>绑定店员：</div>
                    <div class="value">{{ psInfo.salesman_name || '无' }}</div>
                </div>
                <div class="item">
                    <div>用户状态：</div>
                    <div class="value">{{ psInfo.status ? '开启' : '锁定' }}</div>
                </div>
                <div class="item">
                    <div>用户等级：</div>
                    <div class="value">{{ psInfo.vip_name }}</div>
                </div>
                <div class="item">
                    <div>用户标签：</div>
                    <div class="value">{{ psInfo.label_list }}</div>
                </div>
                <div class="item">
                    <div>用户分组：</div>
                    <div class="value">{{ psInfo.group_name || '无' }}</div>
                </div>
                <div class="item">
                    <div>推广人：</div>
                    <div class="value">{{ psInfo.spread_uid_nickname || '无' }}</div>
                </div>
                <div class="item">
                    <div>注册时间：</div>
                    <div class="value">{{ psInfo.add_time | timeFormat }}</div>
                </div>
                <div class="item">
                    <div>登录时间：</div>
                    <div class="value">{{ psInfo.last_time | timeFormat }}</div>
                </div>
                <div v-if="psInfo.is_money_level" class="item">
                  <div>付费会员：</div>
                  <div class="value">{{ psInfo.is_ever_level == 1 ? '永久会员' : (psInfo.overdue_time ? `${psInfo.overdue_time} 到期` : '已过期') }}</div>
                </div>
            </div>
        </div>
        <div class="section">
            <div class="section-hd">用户备注</div>
            <div class="section-bd">
                <div class="item">
                    <div>备注：</div>
                    <div class="value">{{ psInfo.mark || '-' }}</div>
                </div>
            </div>
        </div>
        <div class="section">
            <div class="section-hd">推荐人 / 老客转介绍</div>
            <div class="section-bd">
                <div class="item item--referrer">
                    <div>当前推荐人：</div>
                    <div class="value">{{ referrerProfile.referrerMemberName || '未绑定' }}<span v-if="referrerProfile.referrerMemberId">（ID {{ referrerProfile.referrerMemberId }}）</span></div>
                </div>
                <div class="item item--referrer-action">
                    <Button v-if="!referrerProfile.referrerLocked" type="primary" size="small" :loading="referrerSaving" @click="openReferrerPicker">{{ referrerProfile.referrerMemberId ? '更换推荐人' : '绑定推荐人' }}</Button>
                    <Button v-if="referrerProfile.referrerMemberId && !referrerProfile.referrerLocked" size="small" :loading="referrerSaving" @click="saveReferrer(0)">清空</Button>
                    <span v-if="referrerProfile.referrerLocked" class="referrer-locked">首次疗程卡已成交，推荐人已锁定</span>
                </div>
            </div>
        </div>
        <div v-if="hasExtendInfo" class="section">
            <div class="section-hd">补充信息</div>
            <div class="section-bd">
                <template v-for="item in psInfo.extend_info">
                    <div v-if="item.value" :key="item.sort" class="item">
                        <div>{{ item.info }}：</div>
						<div class="value" v-if="item.param == 'sex'">{{item.singlearr[item.value]}}</div>
                        <div class="value" v-else>{{ item.value }}</div>
                    </div>
                </template>
            </div>
        </div>
        <div class="section" v-if="workMemberInfo">
            <div class="section-hd">企业成员信息</div>
            <div class="section-bd">
                <div class="item">
                    <!-- <div>二维码：</div> -->
                    <div class="avatar">
                        <img :src="workMemberInfo.qr_code" alt="">
                    </div>
                </div>
            </div>
            <div class="section-bd">
                <div class="item">
                    <div>姓名：</div>
                    <div class="value">{{ workMemberInfo.name || '-' }}</div>
                </div>
                <div class="item">
                    <div>职务信息：</div>
                    <div class="value">{{ workMemberInfo.position || '-' }}</div>
                </div>
                <div class="item">
                    <div>手机号码：</div>
                    <div class="value">{{ workMemberInfo.mobile || '-' }}</div>
                </div>
                <div class="item">
                    <div>性别：</div>
                    <div class="value">{{ workMemberInfo.gender | gender }}</div>
                </div>
                <div class="item">
                    <div class="width-add ">邮箱：</div>
                    <div class="value">{{ workMemberInfo.biz_mail || '-' }}</div>
                </div>
                <div class="item mr30" >
                    <div>地址：</div>
                    <div class="value">{{ workMemberInfo.address || '-' }}</div>
                </div>
                <div class="item">
                    <div>备注：</div>
                    <div class="value">{{ workMemberInfo.remark || '-' }}</div>
                </div>
            </div>
        </div>
        <div class="section" v-if="workClientInfo">
            <div class="section-hd">企业客户信息</div>
            <div class="section-bd">
                <div class="item">
                    <div>姓名：</div>
                    <div class="value">{{ workClientInfo.name || '-' }}</div>
                </div>
                <div class="item">
                    <div>职务信息：</div>
                    <div class="value">{{ workClientInfo.position || '-' }}</div>
                </div>
                <div class="item">
                    <div>备注：</div>
                    <div class="value">{{ workClientInfo.remark || '-' }}</div>
                </div>
                <div class="item">
                    <div>性别：</div>
                    <div class="value">{{ workClientInfo.gender | gender }}</div>
                </div>
                <div class="item">
                    <div>企业主体名称：</div>
                    <div class="value">{{ workClientInfo.corp_full_name || '-' }}</div>
                </div>
                 <div class="item">
                    <div>企业主体简称：</div>
                    <div class="value">{{ workClientInfo.corp_name || '-' }}</div>
                </div>
            </div>
        </div>
        <Modal v-model="referrerPickerOpen" scrollable title="选择推荐人 / 老客转介绍" :closable="true" width="900" :footer-hide="true">
            <customerInfo v-if="referrerPickerOpen" :isPromoter="1" @imageObject="selectReferrer" />
        </Modal>
    </div>
</template>

<script>
import dayjs from "dayjs";
import template from '../../../setting/devise/template.vue';
import customerInfo from '@/components/customerInfo';
import { customerV3ReferrerApi, updateCustomerV3ReferrerApi } from '@/api/user';

export default {
    components: { template, customerInfo },
    name: 'userInfo',
    props: {
        psInfo: Object,
        workMemberInfo:Object,
        workClientInfo:Object
    },
    filters: {
        timeFormat (value) {
            if (!value) {
                return '-';
            }
            return dayjs(value * 1000).format('YYYY-MM-DD HH:mm:ss');
        },
        gender (value) {
            if(value == 1){
                return '男'
            }else if(value == 2){
                return '女'
            }else{
                return '未知'
            }
        }
    },
    computed: {
        hasExtendInfo() {
            return this.psInfo.extend_info.some(item => item.value);
        }
    },
    data () {
        return {
            referrerPickerOpen: false,
            referrerSaving: false,
            referrerProfile: {
                referrerMemberId: 0,
                referrerMemberName: '',
                referrerLocked: false
            }
        };
    },
    watch: {
        'psInfo.uid': {
            immediate: true,
            handler (uid) {
                if (Number(uid) > 0) this.loadReferrer();
            }
        }
    },
    methods: {
        loadReferrer () {
            const memberId = Number(this.psInfo.uid || 0);
            if (!memberId) return;
            customerV3ReferrerApi(memberId).then((res) => {
                if (res && res.status === 200 && res.data) this.referrerProfile = { ...this.referrerProfile, ...res.data };
            }).catch(() => {
                this.referrerProfile = { referrerMemberId: 0, referrerMemberName: '', referrerLocked: false };
            });
        },
        openReferrerPicker () {
            if (!this.referrerSaving && !this.referrerProfile.referrerLocked) this.referrerPickerOpen = true;
        },
        selectReferrer (member) {
            this.referrerPickerOpen = false;
            const referrerMemberId = Number((member && (member.uid || member.memberId || member.id)) || 0);
            if (!referrerMemberId) return;
            this.saveReferrer(referrerMemberId);
        },
        saveReferrer (referrerMemberId) {
            const memberId = Number(this.psInfo.uid || 0);
            if (!memberId || this.referrerSaving) return;
            this.referrerSaving = true;
            updateCustomerV3ReferrerApi(memberId, {
                referrerMemberId,
                idempotencyKey: this.referrerCommandKey(memberId)
            }).then((res) => {
                if (res && res.status === 200) {
                    this.referrerProfile = { ...this.referrerProfile, ...(res.data || {}) };
                    this.$Message.success('推荐人已保存');
                    this.$emit('referrer-saved', memberId);
                }
            }).catch((error) => {
                this.$Message.error((error && error.msg) || '推荐人保存失败');
            }).finally(() => {
                this.referrerSaving = false;
            });
        },
        referrerCommandKey (memberId) {
            const random = (window.crypto && window.crypto.randomUUID)
                ? window.crypto.randomUUID().replace(/-/g, '')
                : `${Date.now()}${Math.random().toString(16).slice(2)}`;
            return `CMD-${random}`;
        }
    }
}
</script>

<style lang="less" scoped>
 .width-add {
   width: 40px;
   }
   .mr30 {
    margin-right:30px
   }

.user-info {
    .section {
        padding: 25px 0;
        border-bottom: 1px dashed #EEEEEE;

        &-hd {
            padding-left: 10px;
            border-left: 3px solid #1890FF;
            font-weight: 500;
            font-size: 14px;
            line-height: 16px;
            color: #303133;
        }

        &-bd {
            display: flex;
            flex-wrap: wrap;
        }

        .item {
            flex: 0 0 calc(~"(100% - 60px) / 3");
            display: flex;
            margin: 16px 30px 0 0;
            font-size: 13px;
            color: #606266;

            &:nth-child(3n+3) {
                margin: 16px 0 0;
            }
        }

        .item--referrer-action {
            gap: 8px;
            align-items: center;
        }

        .referrer-locked {
            color: #8a5900;
        }

        .value {
            flex: 1;
        }
        .avatar{
        width: 60px;
        height: 60px;
        overflow: hidden;
            img{
                width: 100%;
                height: 100%;
            }
        }
    }
}
</style>
