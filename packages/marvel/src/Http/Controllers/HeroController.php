<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Services\General\HeroService;
use App\Traits\HasCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\StoreHeroRequest;
use Marvel\Http\Requests\UpdateHeroRequest;
use Marvel\Http\Resources\HeroResource;
use Marvel\Traits\ApiResponse;

class HeroController extends CoreController
{
    use ApiResponse, HasCache;

    public function __construct(
        private HeroService $heroService
    ) {
        $this->middleware('permission:' . Permission::VIEW_HERO)->only(['index', 'show']);
        $this->middleware('permission:' . Permission::CREATE_HERO)->only('store');
        $this->middleware('permission:' . Permission::UPDATE_HERO)->only(['update', 'toggleActive', 'changeStatus']);
        $this->middleware('permission:' . Permission::DELETE_HERO)->only('destroy');
    }

    public function index(Request $request)
    {
        $heroes = $this->heroService->listForAdmin($request);
        $data = HeroResource::collection($heroes)->response()->getData(true);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => $data['data'] ?? [],
            'page' => $data['meta']['current_page'] ?? 0,
            'current_page' => $data['meta']['current_page'] ?? 0,
            'from' => $data['meta']['from'] ?? 0,
            'to' => $data['meta']['to'] ?? 0,
            'last_page' => $data['meta']['last_page'] ?? 0,
            'path' => $data['meta']['path'] ?? '',
            'per_page' => $data['meta']['per_page'] ?? 0,
            'total' => $data['meta']['total'] ?? 0,
            'next_page_url' => $data['links']['next'] ?? '',
            'prev_page_url' => $data['links']['prev'] ?? '',
            'last_page_url' => $data['links']['last'] ?? '',
            'first_page_url' => $data['links']['first'] ?? '',
        ]);
    }

    public function store(StoreHeroRequest $request)
    {
        try {
            $hero = $this->heroService->createHero(
                $request->validated(),
                $request->file('media')
            );
            $this->flushTag(FrontendResource::HERO->value);

            return $this->apiResponse(HERO_CREATED_SUCCESSFULLY, 201, true, HeroResource::make($hero));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function show(string $id)
    {
        try {
            $hero = $this->heroService->findById($id);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, HeroResource::make($hero));
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    public function update(UpdateHeroRequest $request, string $id)
    {
        try {
            $hero = $this->heroService->findById($id);
            $updated = $this->heroService->updateHero(
                $hero,
                $request->validated(),
                $request->file('media')
            );
            $this->flushTag(FrontendResource::HERO->value);

            return $this->apiResponse(HERO_UPDATED_SUCCESSFULLY, 200, true, HeroResource::make($updated));
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function destroy(string $id)
    {
        try {
            $hero = $this->heroService->findById($id);
            $this->heroService->deleteHero($hero);
            $this->flushTag(FrontendResource::HERO->value);

            return $this->apiResponse(HERO_DELETED_SUCCESSFULLY, 200, true);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false, null);
        }
    }

    public function toggleActive(string $id)
    {
        try {
            $hero = $this->heroService->findById($id);
            $updated = $this->heroService->toggleActive($hero);
            $this->flushTag(FrontendResource::HERO->value);

            return $this->apiResponse(HERO_STATUS_CHANGED, 200, true, HeroResource::make($updated));
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    public function changeStatus(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:heroes,id',
            'is_active' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ]);

        try {
            $hero = $this->heroService->findById((int) $request->id);
            if ($request->has('is_active') || $request->has('status')) {
                $targetActive = $request->has('is_active')
                    ? (bool) $request->boolean('is_active')
                    : (bool) $request->boolean('status');
                $updated = $this->heroService->updateHero($hero, ['is_active' => $targetActive]);
            } else {
                $updated = $this->heroService->toggleActive($hero);
            }
            $this->flushTag(FrontendResource::HERO->value);

            return $this->apiResponse(HERO_STATUS_CHANGED, 200, true, HeroResource::make($updated));
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }
}

