<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Models\Section;

class SectionObserver
{
    use HasCache;

    public function created(Section $section): void
    {
        $this->flushContentPagesCache();
    }

    public function updated(Section $section): void
    {
        $this->flushContentPagesCache();
    }

    public function deleted(Section $section): void
    {
        $this->flushContentPagesCache();
    }

    public function restored(Section $section): void
    {
        $this->flushContentPagesCache();
    }

    public function forceDeleted(Section $section): void
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
