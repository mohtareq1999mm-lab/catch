<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Database\Models\Hero;

class HeroObserver
{
    use HasCache;

    public function created(Hero $hero): void
    {
        $this->flushHeroCache();
    }

    public function updated(Hero $hero): void
    {
        $this->flushHeroCache();
    }

    public function deleted(Hero $hero): void
    {
        $this->flushHeroCache();
    }

    public function restored(Hero $hero): void
    {
        $this->flushHeroCache();
    }

    public function forceDeleted(Hero $hero): void
    {
        $this->flushHeroCache();
    }

    private function flushHeroCache(): void
    {
        $this->flushTag(FrontendResource::HERO->value);
        $this->flushTag(FrontendResource::CONTENT_PAGES->value);
    }
}

