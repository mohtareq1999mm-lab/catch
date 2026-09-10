<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Database\Models\SectionTypeSetting;

class SectionTypeSettingObserver
{
    use HasCache;

    public function created(SectionTypeSetting $sectionTypeSetting): void
    {
        $this->flushContentPagesCache();
    }

    public function updated(SectionTypeSetting $sectionTypeSetting): void
    {
        $this->flushContentPagesCache();
    }

    public function deleted(SectionTypeSetting $sectionTypeSetting): void
    {
        $this->flushContentPagesCache();
    }

    /**
     * Invalidate the frontend content pages cache so the next request
     * rebuilds from the database.
     */
    private function flushContentPagesCache(): void
    {
        $this->flushTag(FrontendResource::CONTENT_PAGES->value);
    }
}
