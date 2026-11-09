<?php

namespace App\Services\General;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Hero;

class HeroService
{
    public function listForAdmin(Request $request): LengthAwarePaginator
    {
        $limit = (int) ($request->per_page ?? $request->limit ?? 15);
        $limit = max(1, min(100, $limit));

        $query = Hero::query()->with('media');

        if ($request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
            $query->where('is_active', $this->parseBoolean($request->input('is_active')));
        } elseif ($request->has('active') && $request->input('active') !== null && $request->input('active') !== '') {
            $query->where('is_active', $this->parseBoolean($request->input('active')));
        }

        if ($request->filled('type') && in_array($request->input('type'), [Hero::TYPE_IMAGE, Hero::TYPE_VIDEO], true)) {
            $query->where('type', $request->input('type'));
        }

        return $query->orderByDesc('is_active')->orderByDesc('id')->paginate($limit);
    }

    public function getActiveHero(): ?Hero
    {
        return Hero::query()
            ->active()
            ->with('media')
            ->orderByDesc('id')
            ->first();
    }

    public function findById(int|string $id): Hero
    {
        return Hero::with('media')->findOrFail($id);
    }

    public function createHero(array $data, ?UploadedFile $mediaFile = null): Hero
    {
        if (!$mediaFile) {
            throw ValidationException::withMessages(['media' => ['The media field is required.']]);
        }

        $type = $data['type'] ?? Hero::TYPE_IMAGE;
        $this->validateMediaMimeForType($mediaFile, $type);

        $isActive = array_key_exists('is_active', $data)
            ? $this->parseBoolean($data['is_active'])
            : true;

        $hero = DB::transaction(function () use ($data, $type, $isActive) {
            if ($isActive) {
                $this->deactivateOtherHeroes();
            }

            return Hero::create([
                'type' => $type,
                'description' => $data['description'] ?? null,
                'is_active' => $isActive,
            ]);
        });

        try {
            $this->attachHeroMedia($hero, $mediaFile, $type);
        } catch (\Throwable $e) {
            try {
                $hero->delete();
            } catch (\Throwable $deleteException) {
                report($deleteException);
            }
            throw $e;
        }

        return $hero->load('media');
    }

    public function updateHero(Hero $hero, array $data, ?UploadedFile $mediaFile = null): Hero
    {
        $existingType = $hero->type ?? Hero::TYPE_IMAGE;
        $resolvedType = $data['type'] ?? $existingType;

        if ($resolvedType !== $existingType && !$mediaFile) {
            throw ValidationException::withMessages([
                'media' => ['The media field is required when changing type to ' . $resolvedType . '.'],
            ]);
        }

        if ($mediaFile) {
            $this->validateMediaMimeForType($mediaFile, $resolvedType);
        }

        DB::transaction(function () use ($hero, $data, $resolvedType) {
            $updatePayload = [
                'type' => $resolvedType,
            ];

            if (array_key_exists('description', $data)) {
                $updatePayload['description'] = $data['description'];
            }

            if (array_key_exists('is_active', $data)) {
                $isActive = $this->parseBoolean($data['is_active']);
                $updatePayload['is_active'] = $isActive;

                if ($isActive) {
                    $this->deactivateOtherHeroes((int) $hero->id);
                }
            }

            $hero->update($updatePayload);
        });

        if ($mediaFile) {
            $hero->clearMediaCollection(Hero::COLLECTION_IMAGE);
            $hero->clearMediaCollection(Hero::COLLECTION_VIDEO);
            $this->attachHeroMedia($hero, $mediaFile, $resolvedType);
        }

        return $hero->fresh(['media']);
    }

    public function toggleActive(Hero $hero): Hero
    {
        return DB::transaction(function () use ($hero) {
            $newState = !$hero->is_active;

            if ($newState) {
                $this->deactivateOtherHeroes((int) $hero->id);
            }

            $hero->update(['is_active' => $newState]);

            return $hero->fresh(['media']);
        });
    }

    public function deleteHero(Hero $hero): void
    {
        try {
            $hero->clearMediaCollection(Hero::COLLECTION_IMAGE);
            $hero->clearMediaCollection(Hero::COLLECTION_VIDEO);
        } catch (\Throwable $e) {
            report($e);
        }

        $hero->delete();
    }

    private function deactivateOtherHeroes(?int $excludeId = null): void
    {
        $query = Hero::query()->where('is_active', true);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        $query->update(['is_active' => false]);
    }

    private function attachHeroMedia(Hero $hero, UploadedFile $mediaFile, string $type): void
    {
        $collection = $hero->getMediaCollectionNameForType($type);
        $clientMime = $mediaFile->getClientMimeType() ?: $mediaFile->getMimeType();
        $media = $hero->addMedia($mediaFile)->toMediaCollection($collection);

        if ($media->mime_type === 'application/x-empty' && $clientMime) {
            $media->update(['mime_type' => $clientMime]);
        }
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

    private function validateMediaMimeForType(UploadedFile $file, string $type): void
    {
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));

        $imageMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
        $videoMimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo', 'video/avi', 'video/mov'];

        if ($type === Hero::TYPE_IMAGE) {
            if (!in_array($mime, $imageMimes, true)) {
                throw ValidationException::withMessages([
                    'media' => ['The media must be an image (jpeg, png, webp, gif).'],
                ]);
            }
        } elseif ($type === Hero::TYPE_VIDEO) {
            if (!in_array($mime, $videoMimes, true)) {
                throw ValidationException::withMessages([
                    'media' => ['The media must be a video (mp4, webm, ogg, mov, avi).'],
                ]);
            }
        }
    }
}

