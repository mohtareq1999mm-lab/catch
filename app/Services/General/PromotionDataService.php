<?php

namespace App\Services\General;

use App\Traits\HasChannelFilter;
use Marvel\Database\Models\Promotion;

class PromotionDataService
{
    use HasChannelFilter;

    public function paginatePromotion($request)
    {
        $limit = min(100, max(1, (int) $request->get('limit', 10)));
        $start_date = $request->query('start_date');
        $end_date   = $request->query('end_date');
        $promotionsId = $request->query('promotionsId');
        $order = $request->query('order', 'desc');

        $query = Promotion::query()->valid()->when($start_date, function ($query) use ($start_date) {
            $query->where('created_at', '>=', $start_date);
            })
            ->when($end_date, function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            });

        if (!empty($promotionsId)) {
            $ids = is_array($promotionsId) ? $promotionsId : explode(',', $promotionsId);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        return $query->orderBy('id', $order)->paginate($limit);
    }

    public function getPromotionBySlug($slug)
    {
        $Promotion = Promotion::search('slug', $slug, app()->getLocale())->first();
        if ($Promotion) {
            $Promotion->load(['products' => fn($q) => $this->applyChannelHomeFilter($q)]);
            app(ProductService::class)->enrichCollectionWithPricing($Promotion->products);
        }
        return $Promotion;
    }
}
