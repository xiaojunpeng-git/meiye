// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import { tableDelApi } from '@/api/common';
export function modalSure (delfromData) {
    return new Promise((resolve, reject) => {
        let content = '';
        if (delfromData.info !== undefined) {
            // content = `<p>${delfromData.title}</p><p>${delfromData.info}</p>`
            content = `<p>${delfromData.info}</p>`
        } else if(delfromData.tips !== undefined){
            content = `<p>${delfromData.tips}</p>`
        } else{
            content = `<p>确定要${delfromData.title}吗？</p><p>${delfromData.title}后将无法恢复，请谨慎操作！</p>`
        }
        this.$Modal.confirm({
            title: delfromData.title,
            content: content,
            loading: true,
            onOk: () => {
                setTimeout(() => {
                    this.$Modal.remove();
                    if (delfromData.success) {
                        delfromData.success.then(async res => {
                            resolve(res);
                        }).catch(res => {
                            reject(res)
                        })
                    } else {
                        tableDelApi(delfromData).then(async res => {
                            resolve(res);
                        }).catch(res => {
                            reject(res)
                        })
                    }
                }, 300);
            },
            onCancel: () => {
               // this.$Message.info('取消成功');
            }
        });
    })
}

export function getFileType(fileName) {
    // 后缀获取
    let suffix = '';
    // 获取类型结果
    let result = '';
    try {
        const flieArr = fileName.split('.');
        suffix = flieArr[flieArr.length - 1];
    } catch (err) {
        suffix = '';
    }
    // fileName无后缀返回 false
    if (!suffix) { return false; }
    suffix = suffix.toLocaleLowerCase();
    // 图片格式
    const imglist = ['png', 'jpg', 'jpeg', 'bmp', 'gif'];
    // 进行图片匹配
    result = imglist.find(item => item === suffix);
    if (result) {
        return 'image';
    }
    // 匹配 视频
    const videolist = ['mp4', 'm2v', 'mkv', 'rmvb', 'wmv', 'avi', 'flv', 'mov', 'm4v'];
    result = videolist.find(item => item === suffix);
    if (result) {
        return 'video';
    }
    // 其他 文件类型
    return 'other';
}

function parseTime(time) {
	const [hours, minutes] = time.split(':').map(Number);
	return new Date(0, 0, 0, hours, minutes).getTime();
}

function formatTime(time) {
	const date = new Date(time);
	const hours = date.getHours().toString().padStart(2, '0');
	const minutes = date.getMinutes().toString().padStart(2, '0');
	return `${hours}:${minutes}`;
}

// 时间分段计算方法；
export function splitTimeRange(timeRange, intervalMinutes){
	
	const [startTime, endTime] = timeRange;
	const start = parseTime(startTime);
	const end = parseTime(endTime);
	const interval = intervalMinutes * 60 * 1000; // 转换为毫秒
		
	const result = [];
	let currentStart = start;
		
	while (currentStart + interval <= end) {
	  const currentEnd = currentStart + interval;
	  let data = {
		  start:formatTime(currentStart),
		  end:formatTime(currentEnd),
		  stock:0,
		  service_price:0
	  }
	  result.push(data);
	  currentStart = currentEnd;
	}
		
	// 处理最后一个时间段
	if (currentStart < end) {
		 const remainingTime = end - currentStart;
		 if (remainingTime >= interval) {
		   let data = {
			   start:formatTime(currentStart),
			   end:formatTime(end),
			   stock:0,
			   service_price:0
		   }
		   result.push(data);
		 }
	}
	return result;
}

function parseTimeM(timeStr) {
    const [hours, minutes] = timeStr.split(':').map(Number);
    return hours * 60 + minutes; // 返回分钟数
}

// 判断是否有交集
export function hasIntersection(timeRanges) {
    for (let i = 0; i < timeRanges.length; i++) {
        for (let j = i + 1; j < timeRanges.length; j++) {
            const [start1, end1] = timeRanges[i].map(parseTimeM);
            const [start2, end2] = timeRanges[j].map(parseTimeM);
            if (start1 < end2 && end1 > start2) {
                return true;
            }
        }
    }
    return false;
}

// 判断是否递增
export function isTimeRangesIncreasing(timeRanges) {
    for (let i = 1; i < timeRanges.length; i++) {
        const [start1, end1] = timeRanges[i - 1].map(parseTimeM);
        const [start2, end2] = timeRanges[i].map(parseTimeM);
        if (end1 > start2) {
            return false;
        }
    }
    return true;
}
