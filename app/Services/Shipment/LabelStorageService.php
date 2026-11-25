<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Exceptions\InvalidLabelContentException;
use App\Models\Shipment;
use App\Models\ShipmentLabel;
use App\Services\Shipment\Labels\LabelContentValidator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LabelStorageService
{
    public function __construct(private readonly LabelContentValidator $validator) {}

    public function store(Shipment $shipment, string $content, ?string $filename = null, array $metadata = []): ShipmentLabel
    {
        $validated = $this->validator->validate($content, $filename);

        $disk = $this->disk();
        $name = Str::uuid()->toString() . '.' . $validated['format'];
        $dir = trim((string) config('shipping.labels.directory', 'shipping-labels'), '/');
        $path = $dir . '/' . $shipment->getKey() . '/' . $name;

        if (!$disk->put($path, $content)) {
            throw new InvalidLabelContentException('Failed to store label content.');
        }

        return ShipmentLabel::create([
            'shipment_id' => $shipment->getKey(),
            'provider_code' => $shipment->provider_code,
            'provider_mode' => $shipment->provider_mode,
            'format' => $validated['format'],
            'mime_type' => $validated['mime_type'],
            'size_bytes' => $validated['size_bytes'],
            'path' => $path,
            'sha256' => $validated['sha256'],
            'metadata' => $metadata,
        ]);
    }

    public function disk(): Filesystem
    {
        $disk = (string) config('shipping.labels.disk', 'private');
        // Fail closed: labels must not be written to public disks.
        $publicRoot = (string) config('filesystems.disks.public.root', '');
        $resolvedRoot = (string) config("filesystems.disks.{$disk}.root", '');
        $visibility = (string) config("filesystems.disks.{$disk}.visibility", 'private');

        if ($disk === 'public' || $visibility === 'public') {
            throw new InvalidLabelContentException('Label storage disk must be private.');
        }
        if ($publicRoot !== '' && $resolvedRoot !== '' && realpath($resolvedRoot) !== false && realpath($resolvedRoot) === realpath($publicRoot)) {
            throw new InvalidLabelContentException('Label storage disk resolves to a public path.');
        }

        return Storage::disk($disk);
    }
}
