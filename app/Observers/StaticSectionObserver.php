<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Database\Models\StaticSection;

class StaticSectionObserver
{
    use HasCache;

    public function created(StaticSection $staticSection): void
    {
        $this->flushStaticPagesCache();
    }

    public function updated(StaticSection $staticSection): void
    {
        $this->flushStaticPagesCache();
    }

    public function deleted(StaticSection $staticSection): void
    {
        $this->flushStaticPagesCache();
    }

    public function restored(StaticSection $staticSection): void
    {
        $this->flushStaticPagesCache();
    }

    public function forceDeleted(StaticSection $staticSection): void
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