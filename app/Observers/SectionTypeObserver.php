<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Database\Models\SectionType;

class SectionTypeObserver
{
    use HasCache;

    public function created(SectionType $sectionType): void
    {
        $this->flushContentPagesCache();
    }

    public function updated(SectionType $sectionType): void
    {
        $this->flushContentPagesCache();
    }

    public function deleted(SectionType $sectionType): void
    {
        $this->flushContentPagesCache();
    }

    public function restored(SectionType $sectionType): void
    {
        $this->flushContentPagesCache();
    }

    public function forceDeleted(SectionType $sectionType): void
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
