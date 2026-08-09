<?php

namespace app\services\customer\care\query;

final class CustomerCareProjectionErrorCode
{
    public const INVALID_QUERY = 'CARE_QUERY_INVALID';
    public const QUERY_FORBIDDEN = 'CARE_QUERY_FORBIDDEN';
    public const ACTION_UNSUPPORTED = 'CARE_ACTION_UNSUPPORTED';
    public const MEMBER_NOT_FOUND = 'CARE_MEMBER_NOT_FOUND';
    public const TASK_NOT_FOUND = 'CARE_TASK_NOT_FOUND';
    public const RECORD_NOT_FOUND = 'CARE_RECORD_NOT_FOUND';
    public const DEPENDENCY_NOT_READY = 'CARE_PROJECTION_DEPENDENCY_NOT_READY';
    public const PROJECTION_REFRESH_REQUIRED = 'CARE_PROJECTION_REFRESH_REQUIRED';
}
