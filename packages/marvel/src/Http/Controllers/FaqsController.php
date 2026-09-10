<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\FaqsRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CreateFaqsRequest;
use Marvel\Http\Requests\UpdateFaqsRequest;
use Marvel\Traits\ApiResponse;
use Marvel\Http\Resources\FaqResource;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * @OA\Schema(
 *     schema="Faq",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="faq_title", type="string", example="How to return?"),
 *     @OA\Property(property="slug", type="string", example="how-to-return"),
 *     @OA\Property(property="faq_description", type="string", example="You can return within 30 days."),
 *     @OA\Property(property="faq_type", type="string", example="global"),
 *     @OA\Property(property="issued_by", type="string", example="Admin"),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="translated_languages", type="array", @OA\Items(type="string", example="en"))
 * )
 */
class FaqsController extends CoreController
{
    use ApiResponse , HasCache;
    public $repository;

    public function __construct(FaqsRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_FAQS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_FAQ, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_FAQ, ["only" => ["update", "reorder"]]);
        $this->middleware("permission:" . Permission::DELETE_FAQ, ["only" => ["destroy"]]);
    }



    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $order = $request->order;
        $sortedBy = $request->sortedBy ?? 'asc';

        if ($order && in_array($order, ['id', 'faq_title', 'faq_description', 'status', 'created_at', 'updated_at'])) {
            $this->repository->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        }

        $faqs = $this->repository->paginate($limit);
        $faqs->withQueryString();
        $faqData = FaqResource::collection($faqs)->response()->getData(true);
        $faqDataCache = $this->remember(FrontendResource::FAQS->value, md5($request->fullUrl()), $faqData);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $faqDataCache['data'] ?? [],
            "page" => $faqDataCache['meta']['current_page'] ?? 0,
            "current_page" => $faqDataCache['meta']['current_page'] ?? 0,
            "from" => $faqDataCache['meta']['from'] ?? 0,
            "to" => $faqDataCache['meta']['to'] ?? 0,
            "last_page" => $faqDataCache['meta']['last_page'] ?? 0,
            "path" => $faqDataCache['meta']['path'] ?? "",
            "per_page" => $faqDataCache['meta']['per_page'] ?? 0,
            "total" => $faqDataCache['meta']['total'] ?? 0,
            "next_page_url" => $faqDataCache['links']['next'] ?? "",
            "prev_page_url" => $faqDataCache['links']['prev'] ?? "",
            "last_page_url" => $faqDataCache['links']['last'] ?? "",
            "first_page_url" => $faqDataCache['links']['first'] ?? "",
        ]);
    }


    public function store(CreateFaqsRequest $request)
    {
        try {
            $faq = $this->repository->storeFaqs($request);
            $this->flushTag(FrontendResource::FAQS->value);
            return $this->apiResponse(FAQ_CREATED_SUCCESSFULLY, 201, true, FaqResource::make($faq));
        } catch (MarvelException $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }


    public function show($id)
    {
        try {
            $faq = $this->repository->findOrFail($id);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, FaqResource::make($faq));
        } catch (MarvelException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }


    public function update(UpdateFaqsRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $faq = $this->updateFaqs($request);
            $this->flushTag(FrontendResource::FAQS->value);
            return $this->apiResponse(FAQ_UPDATED_SUCCESSFULLY, 200, true, FaqResource::make($faq));
        } catch (MarvelException $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }


    public function updateFaqs(UpdateFaqsRequest $request)
    {
        $faqs = $this->repository->findOrFail($request['id']);
        $faqsUpdate = $this->repository->updateFaqs($request, $faqs);
        return $faqsUpdate;
    }


    public function reorder(Request $request)
    {
        try {
            $request->validate([
                'faqs' => 'required|array',
                'faqs.*' => 'required|exists:faqs,id',
            ]);
            $this->repository->reorder($request->faqs);
            return $this->apiResponse(FAQS_REORDERED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function destroy($id, Request $request)
    {
        $request->merge(['id' => $id]);
        return $this->deleteFaq($request);
    }

    public function deleteFaq(Request $request)
    {
        try {
            $id = $request->id;
            $user = $request->user();
            if ($user && ($user->hasPermissionTo(Permission::DELETE_FAQ))) {
                $this->repository->findOrFail($id)->delete();
                $this->flushTag(FrontendResource::FAQS->value);
                return $this->apiResponse(FAQ_DELETED_SUCCESSFULLY, 200, true);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
