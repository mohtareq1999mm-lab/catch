<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hero\HeroResource;
use App\Services\General\HeroService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class HeroController extends Controller
{
    use ApiResponse, HasCache;

    public function __construct(
        private HeroService $heroService
    ) {
    }

    public function index(Request $request)
    {
        $cacheKey = md5($request->fullUrl() . '|' . app()->getLocale());
        $payload = $this->remember(FrontendResource::HERO->value, $cacheKey, function () use ($request) {
            $hero = $this->heroService->getActiveHero();
            if (!$hero) {
                return null;
            }
            return HeroResource::make($hero)->toArray($request);
        });

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $payload);
    }
}

