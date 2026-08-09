<?php

namespace app\services\customer\care\query;

interface CustomerCareQueryRepository
{
    /** @return array{rows:array,total:int,hasMore:bool} */
    public function taskPage(
        CustomerCareQueryScope $scope,
        array $query,
        array $after,
        int $now,
        int $dayEnd
    ): array;

    /** @return array{today:int,overdue:int,future:int,completed:int,all:int} */
    public function taskBucketCounts(
        CustomerCareQueryScope $scope,
        array $query,
        int $now,
        int $dayEnd
    ): array;

    /** @return array{rows:array,total:int,hasMore:bool} */
    public function recordPage(CustomerCareQueryScope $scope, array $query, array $after): array;

    /** @return array{memberIds:int[],total:int,hasMore:bool} */
    public function customerMemberPage(CustomerCareQueryScope $scope, array $query, array $after): array;

    /** @return array<int,array> keyed by member id */
    public function memberProfiles(CustomerCareQueryScope $scope, array $memberIds): array;

    /** @return array<int,array> keyed by member id */
    public function memberCareSummaries(
        CustomerCareQueryScope $scope,
        array $memberIds,
        int $now
    ): array;

    /** @return array{counts:array,employeeRows:array,validRecordCount:int} */
    public function teamSummary(CustomerCareQueryScope $scope, int $now, int $dayEnd): array;

    /** @return array[] */
    public function activeAssignees(CustomerCareQueryScope $scope): array;

    public function authorizedMember(
        CustomerCareQueryScope $scope,
        int $memberId,
        int $businessStoreId
    ): ?array;

    public function authorizedTask(CustomerCareQueryScope $scope, int $taskId): ?array;

    public function authorizedRecord(CustomerCareQueryScope $scope, int $recordId): ?array;

    /** @return array<int,array> keyed by member id */
    public function latestRecordsByMember(CustomerCareQueryScope $scope, array $memberIds): array;

    /** @return array<int,array> keyed by task id */
    public function recordsByTask(CustomerCareQueryScope $scope, array $taskIds): array;
}
