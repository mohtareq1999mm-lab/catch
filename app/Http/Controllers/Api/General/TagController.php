<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Database\Models\Tag;
use Marvel\Http\Resources\TagResource;
use Marvel\Traits\ApiResponse;

class TagController extends Controller
{
    use ApiResponse, HasCache;

    public function index(Request $request)
    {
        $tags = Tag::query();
        $order = $request->input("order", "desc");
        $limit = min(100, max(1, (int) $request->input("limit", 15)));

        if ($request->filled('tagsIds')) {
            $ids = is_array($request->tagsIds) ? $request->tagsIds : explode(',', $request->tagsIds);
            $tags->whereIn('id', $ids);
        }

        if ($request->filled('slugs')) {
            $slugs = is_array($request->slugs) ? $request->slugs : explode(',', $request->slugs);
            $tags->whereIn('slug', $slugs);
        }
        $tags = $tags->orderBy('id', $order)->paginate($limit);
        $tagsCache = $this->remember('tags', md5($request->fullUrl()), $tags);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, TagResource::collection($tagsCache));
    }

    public function show(Request $request, string $slug)
    {
        $tag = Tag::query()->where('slug', $slug)->first();

        if (!$tag) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, TagResource::make($tag));
    }
}
