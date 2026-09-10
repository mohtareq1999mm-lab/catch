<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Models\ContentPage;

class ContentPageObserver
{
    use HasCache;

    public function created(ContentPage $contentPage): void
    {
        $this->flushContentPagesCache();
    }

    public function updated(ContentPage $contentPage): void
    {
        $this->flushContentPagesCache();
    }

    public function deleted(ContentPage $contentPage): void
    {
        $this->flushContentPagesCache();
    }

    public function restored(ContentPage $contentPage): void
    {
        $this->flushContentPagesCache();
    }

    public function forceDeleted(ContentPage $contentPage): void
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
