/** 手机端预约选项是否开启（总后台-系统设置-基础设置-系统信息） */
export function isMobileReservationOpen(cache) {
	try {
		const cfg = cache || uni.getStorageSync('BASIC_CONFIG') || {};
		return Number(cfg.mobile_reservation_switch) === 1;
	} catch (e) {
		return false;
	}
}
