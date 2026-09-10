<?php

namespace App\Services\General;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;
use Marvel\Enums\StaticSectionType;

class StaticPageService
{
    /**
     * List all static pages with their sections eager loaded (with media to avoid N+1).
     */
    public function getAll(): Collection
    {
        return StaticPage::with('staticSections.media')->get();
    }

    /**
     * Retrieve a static page by slug with sections eager loaded.
     */
    public function getBySlug(string $slug): ?StaticPage
    {
        return StaticPage::with('staticSections.media')->where('slug', $slug)->first();
    }

    /**
     * Retrieve a static page by slug or fail with a 404-equivalent exception.
     */
    public function getPageOrFail(string $slug): StaticPage
    {
        $page = $this->getBySlug($slug);

        if (!$page) {
            throw (new ModelNotFoundException())->setModel(StaticPage::class);
        }

        return $page;
    }

    /**
     * Update a fixed static page (title / is_active). Page identity is never
     * changed through this path.
     */
    public function updatePage(StaticPage $page, array $data): StaticPage
    {
        $page->update($data);

        return $page->load('staticSections.media');
    }

    /**
     * Create a section belonging to the given page with optional media.
     *
     * Consistency: DB row is created inside a transaction first. Media is
     * attached after commit. If media fails the section is deleted to avoid
     * an orphan text/media mismatch.
     */
    public function createSection(StaticPage $page, array $data, ?UploadedFile $mediaFile = null): StaticSection
    {
        $normalized = $this->normalizeCreateData($data);

        // If type requires media but no file supplied, validation should have caught it.
        // Guard again for programmatic callers.
        if (StaticSectionType::isMediaType($normalized['type']) && !$mediaFile) {
            throw ValidationException::withMessages(['media' => ['The media field is required.']]);
        }

        $section = null;

        DB::transaction(function () use ($page, $normalized, &$section) {
            $section = $page->staticSections()->create($normalized);
        });

        if ($mediaFile) {
            $this->validateMediaMimeForType($mediaFile, $normalized['type']);
            try {
                $collection = $this->resolveCollectionForType($normalized['type']);
                $section->addMedia($mediaFile)->toMediaCollection($collection);
                $section->load('media');
            } catch (\Throwable $e) {
                // Compensate: delete the DB row to avoid orphan section without expected media
                try {
                    $section->delete();
                } catch (\Throwable $deleteException) {
                    report($deleteException);
                }
                throw $e;
            }
        }

        return $section->load('media');
    }

    /**
     * Update a section only when it belongs to the given page.
     *
     * A section owned by another page is treated as not found so its existence
     * is never leaked through a different page's route.
     *
     * Media semantics:
     * - media file supplied => replace (clear old collections, attach new)
     * - remove_media truthy and no new file => clear all media
     * - type change to text without new file => clear media
     * - otherwise keep existing media
     */
    public function updateSection(StaticPage $page, StaticSection $section, array $data, ?UploadedFile $mediaFile = null): StaticSection
    {
        $this->assertSectionBelongsToPage($page, $section);

        $removeMedia = $this->parseBoolean($data['remove_media'] ?? false);
        unset($data['remove_media'], $data['media']);

        $resolvedType = $data['type'] ?? $section->type ?? StaticSectionType::TEXT;
        $existingType = $section->type ?? StaticSectionType::TEXT;

        // Normalize is_active if present
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $this->parseBoolean($data['is_active']);
        }

        // If type is being changed to text and no explicit remove, we will clear media
        $typeChangingToText = $resolvedType === StaticSectionType::TEXT && $existingType !== StaticSectionType::TEXT;
        $shouldClearForTypeChange = $typeChangingToText && !$mediaFile && !$removeMedia;

        // If switching to a media type without file and without existing media -> require file
        if (StaticSectionType::isMediaType($resolvedType) && !$mediaFile && !$removeMedia && !$shouldClearForTypeChange) {
            $targetCollection = $this->resolveCollectionForType($resolvedType);
            $hasMedia = $section->getMedia($targetCollection)->isNotEmpty();

            // Also check the other collection if switching image<->video
            if (!$hasMedia && $resolvedType !== $existingType) {
                // Old media is in different collection, so target is empty
                $hasMedia = false;
            }

            if (!$hasMedia && $resolvedType !== $existingType) {
                throw ValidationException::withMessages(['media' => ['The media field is required when changing type to ' . $resolvedType . '.']]);
            }
        }

        DB::transaction(function () use ($section, $data) {
            if (!empty($data) || array_key_exists('config', $data) || array_key_exists('is_active', $data) || array_key_exists('content', $data) || array_key_exists('title', $data) || array_key_exists('type', $data)) {
                $section->update($data);
            }
        });

        // Media handling after DB commit
        try {
            if ($mediaFile) {
                $this->validateMediaMimeForType($mediaFile, $resolvedType);
                // Replace: clear both collections then add to resolved type's collection
                $section->clearMediaCollection(StaticSection::COLLECTION_IMAGE);
                $section->clearMediaCollection(StaticSection::COLLECTION_VIDEO);
                $collection = $this->resolveCollectionForType($resolvedType);
                $section->addMedia($mediaFile)->toMediaCollection($collection);
            } elseif ($removeMedia) {
                $section->clearMediaCollection(StaticSection::COLLECTION_IMAGE);
                $section->clearMediaCollection(StaticSection::COLLECTION_VIDEO);
            } elseif ($shouldClearForTypeChange) {
                $section->clearMediaCollection(StaticSection::COLLECTION_IMAGE);
                $section->clearMediaCollection(StaticSection::COLLECTION_VIDEO);
            } elseif ($mediaFile === null && isset($data['type']) && StaticSectionType::isMediaType($existingType) && $resolvedType !== $existingType) {
                // Type changed between media kinds without new file: keep old behavior requires file (already validated above)
                // If we reach here, we clear stale collection for safety when switching image<->video with new file already handled
            }
        } catch (\Throwable $e) {
            report($e);
            throw $e;
        }

        return $section->load('media');
    }

    /**
     * Delete a section only when it belongs to the given page.
     * Media is deleted via cascade (Media Library handles file removal on model delete).
     */
    public function deleteSection(StaticPage $page, StaticSection $section): void
    {
        $this->assertSectionBelongsToPage($page, $section);
        // Clear media first to ensure files are removed even if DB cascade is interrupted
        try {
            $section->clearMediaCollection(StaticSection::COLLECTION_IMAGE);
            $section->clearMediaCollection(StaticSection::COLLECTION_VIDEO);
        } catch (\Throwable $e) {
            report($e);
        }
        $section->delete();
    }

    /**
     * Reorder sections of the given page only.
     *
     * Every supplied id must belong to the page, otherwise a 404-equivalent
     * exception is raised. The underlying update is additionally scoped by
     * static_page_id so a bypass can never touch another page's rows.
     * Wrapped in a transaction for TiDB/MySQL atomicity.
     */
    public function reorderSections(StaticPage $page, array $sectionIds): void
    {
        $existingCount = StaticSection::query()
            ->where('static_page_id', $page->id)
            ->whereIn('id', $sectionIds)
            ->count();

        if ($existingCount !== count(array_unique($sectionIds))) {
            throw (new ModelNotFoundException())->setModel(StaticSection::class);
        }

        DB::transaction(function () use ($page, $sectionIds) {
            StaticSection::setNewOrder(
                $sectionIds,
                1,
                'id',
                function ($query) use ($page) {
                    $query->where('static_page_id', $page->id);
                }
            );
        });
    }

    private function assertSectionBelongsToPage(StaticPage $page, StaticSection $section): void
    {
        if ((int) $section->static_page_id !== (int) $page->id) {
            throw (new ModelNotFoundException())->setModel(StaticSection::class);
        }
    }

    private function normalizeCreateData(array $data): array
    {
        $normalized = $data;
        unset($normalized['media'], $normalized['remove_media']);

        $normalized['type'] = $normalized['type'] ?? StaticSectionType::TEXT;

        if (array_key_exists('is_active', $normalized)) {
            $normalized['is_active'] = $this->parseBoolean($normalized['is_active']);
        } else {
            $normalized['is_active'] = true;
        }

        if (array_key_exists('config', $normalized) && $normalized['config'] === null) {
            $normalized['config'] = null;
        }

        // Ensure content defaults to empty map for text if not supplied? Let validation handle required.
        return $normalized;
    }

    private function resolveCollectionForType(?string $type): string
    {
        if (in_array($type, StaticSectionType::imageTypes(), true)) {
            return StaticSection::COLLECTION_IMAGE;
        }
        if ($type === StaticSectionType::VIDEO) {
            return StaticSection::COLLECTION_VIDEO;
        }
        // Default fallback — treat as image collection for safety
        return StaticSection::COLLECTION_IMAGE;
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }
        return (bool) $value;
    }

    private function validateMediaMimeForType(UploadedFile $file, ?string $type): void
    {
        $mime = $file->getMimeType() ?: $file->getClientMimeType();

        $imageMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
        $videoMimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo', 'video/avi', 'video/mov'];

        if (in_array($type, StaticSectionType::imageTypes(), true)) {
            if (!in_array(strtolower((string) $mime), $imageMimes, true)) {
                throw ValidationException::withMessages(['media' => ['The media must be an image (jpeg, png, webp, gif).']]);
            }
        } elseif ($type === StaticSectionType::VIDEO) {
            if (!in_array(strtolower((string) $mime), $videoMimes, true)) {
                throw ValidationException::withMessages(['media' => ['The media must be a video (mp4, webm, ogg, mov, avi).']]);
            }
        }
    }
}