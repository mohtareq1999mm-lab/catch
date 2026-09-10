<?php

namespace App\Services\General;

use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Category;

class CategoryHierarchyService
{
    public function calculateLevel(?int $parentId): int
    {
        if ($parentId === null) {
            return 1;
        }

        $parentLevel = Category::query()->whereKey($parentId)->value('level');

        if ($parentLevel === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent category does not exist.'],
            ]);
        }

        return ((int) $parentLevel) + 1;
    }

    public function syncHierarchy(Category $category): void
    {
        $parentId = $category->parent_id === '' ? null : $category->parent_id;

        if ($parentId !== null) {
            $parentId = (int) $parentId;
        }

        $this->ensureHierarchyIsValid($category, $parentId);
        $category->level = $this->calculateLevel($parentId);
    }

    public function ensureHierarchyIsValid(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($category->exists && (int) $category->getKey() === $parentId) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be its own parent.'],
            ]);
        }

        if ($category->exists && $this->createsCycle((int) $category->getKey(), $parentId)) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be assigned to one of its descendants.'],
            ]);
        }
    }

    public function createsCycle(int $categoryId, int $parentId): bool
    {
        $currentParentId = $parentId;
        $visited = [];

        while ($currentParentId !== null) {
            if ((int) $currentParentId === $categoryId) {
                return true;
            }

            if (in_array($currentParentId, $visited, true)) {
                return true;
            }

            $visited[] = $currentParentId;
            $currentParentId = Category::query()->whereKey($currentParentId)->value('parent_id');

            if ($currentParentId !== null) {
                $currentParentId = (int) $currentParentId;
            }
        }

        return false;
    }

    public function updateDescendantLevels(Category $category): void
    {
        $children = $category->children()->get();

        foreach ($children as $child) {
            $child->level = $category->level + 1;
            $child->saveQuietly();
            $this->updateDescendantLevels($child);
        }
    }

    public function loadRecursiveChildren(SupportCollection $categories, bool $activeOnly = false): SupportCollection
    {
        $currentLevel = $categories->values();

        while ($currentLevel->isNotEmpty()) {
            $parentIds = $currentLevel->pluck('id')->filter()->values();

            if ($parentIds->isEmpty()) {
                break;
            }

            $query = Category::query()
                ->withCount('products')
                ->whereIn('parent_id', $parentIds)
                ->orderBy('id');

            if ($activeOnly) {
                $query->active();
            }

            $childrenByParent = $query->get()->groupBy('parent_id');

            $nextLevel = collect();

            foreach ($currentLevel as $category) {
                $children = $childrenByParent->get($category->id, collect())->values();
                $category->setRelation('children', $children);
                $nextLevel = $nextLevel->merge($children);
            }

            $currentLevel = $nextLevel;
        }

        return $categories;
    }

    public function loadDirectChildren(Category $category, bool $activeOnly = false): Category
    {
        $query = $category->children()->withCount('products');

        if ($activeOnly) {
            $query->active();
        }

        $category->setRelation('children', $query->orderBy('id')->get());

        return $category;
    }

    public function loadRecursiveTree(Category $category, bool $activeOnly = false): Category
    {
        $this->loadRecursiveChildren(collect([$category]), $activeOnly);

        return $category;
    }
}
