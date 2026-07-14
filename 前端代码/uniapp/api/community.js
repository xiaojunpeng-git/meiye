import request from "@/utils/request.js";

export function getCommunityConfig() {
	return request.get('community/config', {}, {
		noAuth: true
	});
}

export function getTopicApi(data) {
	return request.get('community/topic', data, {
		noAuth: true
	});
}

export function getProductApi(data) {
	return request.get('community/product_list', data, {
		noAuth: true
	});
}

export function communitySaveApi(data) {
	return request.post('community/community_save', data);
}

export function communityInfoApi(id) {
	return request.get(`community/detail/${id}`,{},{
		noAuth: true
	});
}

export function communityUpdateApi(id,data) {
	return request.post(`community/community_update/${id}`, data);
}

export function communityListApi(data) {
	return request.get(`community/list`,data,{
		noAuth: true
	});
}

export function communityLikeApi(id, data) {
	return request.post(`community/community_like/${id}`, data);
}

export function communityReplySaveApi(data) {
	return request.post(`community/comment/save`, data);
}

export function communityReplyListApi(data) {
	return request.get(`community/comment/list`, data, {
		noAuth: true
	});
}

export function communityReplyLikeApi(id,data) {
	return request.post(`community/comment_like/${id}`, data);
}

export function communityUserInfoApi(id,data) {
	return request.get(`community/user_info/${id}`, data, {
		noAuth: true
	});
}

export function communityUpdateDescApi(data) {
	return request.post(`community/update_desc`, data);
}

export function communitySetInsterestApi(uid,data) {
	return request.post(`community/set_interest/${uid}`, data);
}

export function communityLikeListApi(params,) {
	return request.get(`community/like_list`, params);
}

export function communityFlowListApi(type, params) {
	return request.get(`community/follow_list/${type}`, params);
}

export function communityFlowIndexApi() {
	return request.get(`community/follow`);
}

export function communityFriendListApi(params) {
	return request.get(`community/user_friend`, params);
}

export function communityRecommendListApi(params) {
	return request.get(`community/recommend_list`, params);
}

export function communityElegantListApi(params) {
	return request.get(`community/elegant_list`, params);
}

export function communityDeleteApi(id) {
	return request.delete(`community/community_delete/${id}`);
}

export function communityShareApi(id) {
	return request.get(`community/share/${id}`);
}

export function communityReplyDeleteApi(id) {
	return request.delete(`community/comment_delete/${id}`);
}

export function communityBrowseApi(id) {
	return request.put(`community/browse/${id}`);
}

export function communityTopicCountApi(id) {
	return request.get(`community/topic_count/${id}`,{},{
		noAuth: true
	});
}

export function communityMessageApi(data) {
	return request.get(`community/message`, data);
}

export function communityProductListApi(data) {
	return request.get(`community/product/list`, data);
}