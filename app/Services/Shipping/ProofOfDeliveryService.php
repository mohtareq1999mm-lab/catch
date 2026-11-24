<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShipmentPod;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
use Marvel\Enums\Role;

class ProofOfDeliveryService
{
    public function store(Shipment $shipment, array $data, Authenticatable $actor): ShipmentPod
    {
        $validated = $this->validate($data);

        if (ShipmentPod::query()->where('shipment_id', $shipment->id)->where('status', 'successful')->exists()) {
            throw ValidationException::withMessages([
                'shipment_id' => [__('validation.custom.shipment_pod.duplicate_successful')],
            ]);
        }

        /** @var UploadedFile $photo */
        $photo = $validated['photo'];
        $path = $photo->store('shipment-pods/' . $shipment->id, 'private');

        return ShipmentPod::query()->create([
            'shipment_id' => $shipment->id,
            'shipment_assignment_id' => $validated['shipment_assignment_id'] ?? null,
            'shipment_delivery_attempt_id' => $validated['shipment_delivery_attempt_id'] ?? null,
            'status' => 'successful',
            'recipient_name' => $validated['recipient_name'],
            'captured_at' => $validated['captured_at'],
            'file_reference' => $path,
            'signature_reference' => $validated['signature_reference'] ?? null,
            'otp_reference' => $validated['otp_reference'] ?? null,
            'uploaded_by' => $actor->getAuthIdentifier(),
            'metadata' => $validated['metadata'] ?? null,
        ]);
    }

    public function temporaryUrl(ShipmentPod $pod, int $minutes = 10): string
    {
        $minutes = max(1, min($minutes, 30));

        return URL::temporarySignedRoute('api.v1.shipments.pods.file', now()->addMinutes($minutes), [
            'pod' => $pod->id,
        ]);
    }

    public function fileResponse(ShipmentPod $pod)
    {
        if (! Storage::disk('private')->exists($pod->file_reference)) {
            abort(404);
        }

        return Storage::disk('private')->response($pod->file_reference);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $errors = [];

        if (! isset($data['photo']) || ! ($data['photo'] instanceof UploadedFile)) {
            $errors['photo'] = [__('validation.required', ['attribute' => 'photo'])];
        }

        $recipient = $data['recipient_name'] ?? null;
        if (! is_string($recipient) || trim($recipient) === '') {
            $errors['recipient_name'] = [__('validation.required', ['attribute' => 'recipient_name'])];
        }

        $capturedAt = $data['captured_at'] ?? null;
        if (! is_string($capturedAt) || trim($capturedAt) === '' || strtotime($capturedAt) === false) {
            $errors['captured_at'] = [__('validation.required', ['attribute' => 'captured_at'])];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['recipient_name'] = trim((string) $recipient);
        $data['captured_at'] = date('Y-m-d H:i:s', strtotime((string) $capturedAt));

        return $data;
    }
}
