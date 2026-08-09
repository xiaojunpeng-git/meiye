<?php

namespace app\services\query\provider;

class StaffUnifiedQueryWorkerContextResolver extends MemberUnifiedQueryWorkerContextResolver
{
    public function pageCode(): string
    {
        return StaffUnifiedQueryProvider::PAGE_CODE;
    }
}

