<?php

namespace App\Services\General;

use App\Traits\HasChannelFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Category;

class CategoryService
{
    use HasChannelFilter;

    private const ALLOWED_ORDER_DIRECTIONS = ['asc', 'desc'];

    public function paginate(Request $request)
    {
        $limit = $this->getLimit($request);
        $term = trim((string) $request->get('search', ''));
        $pestCategory = $request->query('pest_category', false);
        $parent = $request->query('parent', false);
        $categoriesId = $request->query('categoriesId');
        $order = $this->resolveOrder($request);
        $query = Category::query()->active()->withCount('products')->with('media');

        if (!empty($categoriesId)) {
            $ids = is_array($categoriesId) ? $categoriesId : explode(',', $categoriesId);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        if ($term !== '') {
            $query->where(function (Builder $builder) use ($term) {
                $this->applyTranslatableLike($builder, 'name', $term, app()->getLocale());
                $builder->orWhere(function (Builder $sub) use ($term) {
                    $this->applyTranslatableLike($sub, 'details', $term, app()->getLocale());
                });
            });
        }
        if ($parent) {
            $query->whereNull('parent_id');
        }
        if ($pestCategory) {
            $query->orderBy('products_count', $order);
        } else {
            $query->orderBy('id', $order);
        }


        return $query->paginate($limit);
    }

    public function getBySlug($slug)
    {
        $category = Category::query()
            ->active()
            ->with([
                'products' => fn($q) => $this->applyChannelHomeFilter($q),
                'children' => function ($query) {
                    $query->active()->withCount('products');
                },
            ])
            ->withCount(['products' => fn($q) => $this->applyChannelHomeFilter($q)])
            ->where('slug', $slug)
            ->firstOrFail();

        app(ProductService::class)->enrichCollectionWithPricing($category->products);

        return $category;
    }

    private function applyTranslatableLike(Builder $query, string $field, string $term, string $locale): void
    {
        $query->where(function ($q) use ($field, $term, $locale) {
            $q->where($field . '->' . $locale, 'like', "%$term%")
                ->orWhere($field, 'like', "%$term%");
        });
    }

    private function resolveOrder(Request $request): string
    {
        $order = strtolower(trim((string) $request->query('order', 'desc')));

        if ($order === '') {
            return 'desc';
        }

        if (!in_array($order, self::ALLOWED_ORDER_DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'order' => __('validation.in', ['attribute' => 'order']),
            ]);
        }

        return $order;
    }

    private function getLimit(Request $request): int
    {
        $limit = (int) $request->get('limit', 15);
        if ($limit <= 0) {
            return 15;
        }

        return min($limit, 100);
    }
}
