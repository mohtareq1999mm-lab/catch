<?php

namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\ProductVariant;
use Marvel\Exceptions\MarvelBadRequestException;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Marvel\Traits\MediaManager;

class PromotionRepository extends BaseRepository
{
    use MediaManager;
    protected $fieldSearchable = [
        'name' => 'like',
        'type',
        'code' => 'like',
        'status',
    ];

    protected $dataArray = [
        'name',
        'type',
        'type_amount',
        'value',
        'discount',
        'code',
        'max_discount_amount',
        'required_quantity_type',
        'minimum_order_amount',
        'apply_to',
        'limiter',
        'start_at',
        'end_at',
        'status',
    ];

    public function getDataArray(): array
    {
        return $this->dataArray;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    public function model()
    {
        return Promotion::class;
    }

    public function storePromotion(Request $request)
    {
        try {
            $data = $request->only($this->dataArray);
            $data['slug'] = $this->makeSlug($request);

            $data = $this->normalizePromotionData($data);
            $promotion = $this->create($data);
            $this->syncPromotionProducts($promotion, $request);

            if ($request->hasFile('image-desktop')) {
                if (!$this->uploadSingleImage($request, 'image-desktop', $promotion, 'promotions-desktop', 'promotions')) {
                    throw new MarvelBadRequestException('Image upload failed');
                }
            }
            if ($request->hasFile('image-mobile')) {
                if (!$this->uploadSingleImage($request, 'image-mobile', $promotion, 'promotions-mobile', 'promotions')) {
                    throw new MarvelBadRequestException('Image upload failed');
                }
            }

            return $promotion;
        } catch (Exception $e) {
            Log::error('Promotion store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function updatePromotion($id, Request $request)
    {
        try {
            $promotion = $this->find($id);
            if (!$promotion) {
                throw new MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE);
            }

            $data = $request->only($this->dataArray);
            $data = $this->normalizePromotionData($data);
            $data['slug'] = $this->makeSlug($request, 'slug', $promotion->id);
            $promotion->update($data);
            $this->syncPromotionProducts($promotion, $request);

            if ($request->hasFile('image-desktop')) {
                if (!$this->updateSingleImage($request, 'image-desktop', $promotion, 'promotions-desktop', 'promotions')) {
                    throw new MarvelBadRequestException('Image upload failed');
                }
            }
            if ($request->hasFile('image-mobile')) {
                if (!$this->updateSingleImage($request, 'image-mobile', $promotion, 'promotions-mobile', 'promotions')) {
                    throw new MarvelBadRequestException('Image upload failed');
                }
            }
            return $promotion;
        } catch (MarvelBadRequestException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Promotion update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    private function normalizePromotionData(array $data): array
    {
        if (array_key_exists('discount', $data) && !array_key_exists('value', $data)) {
            $data['value'] = $data['discount'];
        }

        if (array_key_exists('value', $data) && !array_key_exists('discount', $data)) {
            $data['discount'] = $data['value'];
        }

        return $data;
    }

    private function syncPromotionProducts(Promotion $promotion, Request $request): void
    {
        if ($request->has('product_ids')) {
            $promotion->products()->sync($request->input('product_ids', []));
        }

        if ($request->has('gift_product_ids')) {
            if (!Schema::hasColumn('promotion_gift_products', 'product_variant_id')) {
                throw new MarvelBadRequestException('Gift variants require a migration.');
            }
            $giftProducts = collect($request->input('gift_product_ids', []))
                ->mapWithKeys(fn($productId) => [(int) $productId => ['quantity' => 1, 'product_variant_id' => null]])
                ->all();

            $promotion->giftProducts()->sync($giftProducts);
        }

        if ($request->has('gift_products')) {
            if (!Schema::hasColumn('promotion_gift_products', 'product_variant_id')) {
                throw new MarvelBadRequestException('Gift variants require a migration.');
            }
            $giftProducts = collect($request->input('gift_products', []))
                ->mapWithKeys(function ($gift) {
                    $productId = (int) $gift['product_id'];
                    $variantId = isset($gift['product_variant_id']) ? (int) $gift['product_variant_id'] : null;

                    if ($variantId) {
                        $variant = ProductVariant::query()->whereKey($variantId)->first();
                        if (!$variant || (int) $variant->product_id !== $productId) {
                            throw new MarvelBadRequestException('Gift variant does not belong to the selected product.');
                        }
                    }

                    return [
                        $productId => [
                            'quantity' => max(1, (int) ($gift['quantity'] ?? 1)),
                            'product_variant_id' => $variantId,
                        ],
                    ];
                })
                ->all();

            $promotion->giftProducts()->sync($giftProducts);
        }
    }
}