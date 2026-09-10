<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Faqs\FaqResource;
use App\Services\General\faqService;
use App\Traits\HasCache;
use Marvel\Traits\ApiResponse;

class FAQController extends Controller
{
    use ApiResponse, HasCache;
    private $faqService;
    public function __construct(faqService $faqService)
    {
        $this->faqService = $faqService;
    }

    public function index()
    {
        $faqs = $this->faqService->getfaqs();
        $faqsCache = $this->remember(FrontendResource::FAQS->value, md5(request()->fullUrl()), $faqs);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY,200 , true,FaqResource::collection($faqs));
    }
}