<?php

declare(strict_types=1);

namespace Marvel\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\CmsPage;
use Marvel\Database\Repositories\CmsPageRepository;
use Marvel\Exceptions\MarvelException;

class CmsPageService
{
    public function __construct(
        private readonly CmsPageRepository $repository
    ) {
    }

    /**
     * Paginate CMS pages.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository
            ->where($filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Fetch a single page by slug (legacy support).
     */
    public function getBySlug(string $slug): CmsPage
    {
        $page = $this->repository->findOneByField('slug', $slug);

        if (!$page) {
            throw new MarvelException(NOT_FOUND);
        }

        return $page;
    }

    /**
     * Fetch a single page by path (Puck format).
     */
    public function getByPath(string $path): CmsPage
    {
        $page = $this->repository->findOneByField('path', $path);

        if (!$page) {
            throw new MarvelException(NOT_FOUND);
        }

        return $page;
    }

    /**
     * Create a CMS page within a transaction.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): CmsPage
    {
        return DB::transaction(function () use ($data): CmsPage {
            $payload = $this->preparePayload($data);
            /** @var CmsPage $page */
            $page = $this->repository->create($payload);
            return $page;
        });
    }

    /**
     * Update a CMS page within a transaction.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): CmsPage
    {
        return DB::transaction(function () use ($id, $data): CmsPage {
            $page = $this->repository->findOrFail($id);
            $payload = $this->preparePayload($data, $page);
            /** @var CmsPage $updated */
            $updated = $this->repository->update($payload, $page->id);
            return $updated;
        });
    }

    /**
     * Delete a page.
     */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $page = $this->repository->findOrFail($id);
            $page->delete();
        });
    }

    /**
     * Normalize payload to support both Puck and legacy formats.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, ?CmsPage $existing = null): array
    {
        // Get path from request or existing
        $path = $data['path'] ?? $existing?->path;

        // Auto-generate slug from path if not provided
        $slug = $data['slug'] ?? $existing?->slug;
        if (!$slug && $path) {
            $slug = Str::slug(trim($path, '/')) ?: 'home';
        }

        // Handle both Puck format (data.content) and legacy format (content)
        $content = $data['content'] ?? null;
        $puckData = $data['data'] ?? null;

        // If Puck format is provided, extract content for legacy field
        if ($puckData && isset($puckData['content'])) {
            $content = $puckData['content'];
        }

        // Sort content by order if order field exists (legacy support)
        if (is_array($content)) {
            $content = $this->sortContent($content);
        }

        return [
            'path' => $path,
            'slug' => $slug,
            'title' => $data['title'] ?? $existing?->title,
            'content' => $content ?? $existing?->content,
            'data' => $puckData ?? $existing?->data,
            'meta' => $data['meta'] ?? $existing?->meta,
        ];
    }

    /**
     * Sort content by order field if present.
     *
     * @param array<int, mixed> $content
     * @return array<int, mixed>
     */
    private function sortContent(array $content): array
    {
        return collect($content)
            ->sortBy('order')
            ->values()
            ->all();
    }
}


