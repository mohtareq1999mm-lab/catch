<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Database\Models\StaticPage;

class StaticPageObserver
{
    use HasCache;

    public function created(StaticPage $staticPage): void
    {
        $this->flushStaticPagesCache();
    }

    public function updated(StaticPage $staticPage): void
    {
        $this->flushStaticPagesCache();
    }

    public function deleted(StaticPage $staticPage): void
    {
        $this->flushStaticPagesCache();
    }

    public function restored(StaticPage $staticPage): void
    {
        $this->flushStaticPagesCache();
    }

    public function forceDeleted(StaticPage $staticPage): void
    {
        $this->flushStaticPagesCache();
    }

    /**
     * Invalidate the frontend static pages cache so the next request
     * rebuilds from the database.
     */
    private function flushStaticPagesCache(): void
    {
        $this->flushTag(FrontendResource::STATIC_PAGES->value);
    }
}