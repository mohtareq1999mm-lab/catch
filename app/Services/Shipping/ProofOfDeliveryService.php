<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentPod;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class ProofOfDeliveryService
{
    private const ALLOWED_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_PHOTO_KILOBYTES = 10240;
    private const MAX_SIGNATURE_REFERENCE_CHARS = 1024;
    private const MAX_OTP_REFERENCE_CHARS = 191;
    private const MAX_METADATA_BYTES = 65536;

    public function store(Shipment $shipment, array $data, Authenticatable $actor): ShipmentPod
    {
        $validated = $this->validate($data, $shipment);
        $path = null;

        try {
            return DB::transaction(function () use ($shipment, $validated, $actor, &$path): ShipmentPod {
                Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

                $this->assertNoSuccessfulPod($shipment->id);
                $this->assertReferencesBelongToShipment($shipment->id, $validated);

                /** @var UploadedFile $photo */
                $photo = $validated['photo'];
                $path = $photo->store('shipment-pods/' . $shipment->id, 'private');
                if ($path === false) {
                    throw new \RuntimeException('Failed to store POD photo.');
                }

                return ShipmentPod::query()->create([
                    'shipment_id' => $shipment->id,
                    'shipment_assignment_id' => $validated['shipment_assignment_id'] ?? null,
                    'shipment_delivery_attempt_id' => $validated['shipment_delivery_attempt_id'] ?? null,
                    'status' => 'successful',
                    'recipient_name' => $validated['recipient_name'],
                    'captured_at' => $validated['captured_at'],
                    'file_reference' => $path,
                    'signature_reference' => $validated['signature_reference'],
                    'otp_reference' => $validated['otp_reference'],
                    'uploaded_by' => $actor->getAuthIdentifier(),
                    'metadata' => $validated['metadata'],
                ]);
            }, 1); // No retries: a retried closure could leave an orphaned upload.
        } catch (QueryException $e) {
            $this->cleanupStoredFile($path);
            if ($this->isDuplicateSuccessfulPodViolation($e)) {
                throw $this->duplicateSuccessfulPodException();
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->cleanupStoredFile($path);
            throw $e;
        }
    }

    public function temporaryUrl(ShipmentPod $pod, Authenticatable $actor, int $minutes = 10): string
    {
        $minutes = max(1, min($minutes, 30));

        return URL::temporarySignedRoute('api.v1.shipments.pods.file', now()->addMinutes($minutes), [
            'pod' => $pod->id,
            'uid' => $actor->getAuthIdentifier(),
        ]);
    }

    public function fileResponse(ShipmentPod $pod)
    {
        $path = $pod->file_reference;
        if (! is_string($path)
            || ! preg_match('#^shipment-pods/[1-9][0-9]*/[A-Za-z0-9_.-]+$#', $path)
            || str_contains($path, '..')
            || ! Storage::disk('private')->exists($path)
        ) {
            abort(404);
        }

        return Storage::disk('private')->response($path);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, Shipment $shipment): array
    {
        $errors = [];

        $photo = $data['photo'] ?? null;
        if (! ($photo instanceof UploadedFile) || ! $photo->isValid()) {
            $errors['photo'] = [__('validation.required', ['attribute' => 'photo'])];
        } else {
            $extension = strtolower((string) $photo->getClientOriginalExtension());
            $mime = (string) $photo->getMimeType();
            $sizeKb = $photo->getSize() / 1024;
            $validExtension = in_array($extension, self::ALLOWED_PHOTO_EXTENSIONS, true);
            $validMime = in_array($mime, self::ALLOWED_PHOTO_MIMES, true);
            $realPath = $photo->getRealPath();
            $isImage = is_string($realPath) && $realPath !== '' && @getimagesize($realPath) !== false;
            if (! $validExtension || ! $validMime || ! $isImage) {
                $errors['photo'] = [__('validation.image', ['attribute' => 'photo'])];
            } elseif ($sizeKb > self::MAX_PHOTO_KILOBYTES) {
                $errors['photo'] = [__('validation.max.file', ['attribute' => 'photo', 'max' => self::MAX_PHOTO_KILOBYTES])];
            }
        }

        $recipient = $data['recipient_name'] ?? null;
        if (! is_string($recipient) || trim($recipient) === '') {
            $errors['recipient_name'] = [__('validation.required', ['attribute' => 'recipient_name'])];
        }

        $capturedAt = $data['captured_at'] ?? null;
        $normalizedCapturedAt = $this->normalizeCapturedAt($capturedAt);
        if ($normalizedCapturedAt === null) {
            $errors['captured_at'] = [__('validation.date', ['attribute' => 'captured_at'])];
        }

        $assignmentId = $this->normalizeNullableInt($data['shipment_assignment_id'] ?? null, 'shipment_assignment_id', $errors);
        $attemptId = $this->normalizeNullableInt($data['shipment_delivery_attempt_id'] ?? null, 'shipment_delivery_attempt_id', $errors);
        $signatureReference = $this->normalizeOptionalString(
            $data['signature_reference'] ?? null,
            'signature_reference',
            self::MAX_SIGNATURE_REFERENCE_CHARS,
            $errors
        );
        $otpReference = $this->normalizeOptionalString(
            $data['otp_reference'] ?? null,
            'otp_reference',
            self::MAX_OTP_REFERENCE_CHARS,
            $errors
        );
        $metadata = $this->normalizeOptionalMetadata($data['metadata'] ?? null, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['recipient_name'] = trim((string) $recipient);
        $data['captured_at'] = $normalizedCapturedAt;
        $data['shipment_assignment_id'] = $assignmentId;
        $data['shipment_delivery_attempt_id'] = $attemptId;
        $data['signature_reference'] = $signatureReference;
        $data['otp_reference'] = $otpReference;
        $data['metadata'] = $metadata;
        $data['shipment_id'] = $shipment->id;

        return $data;
    }

    private function normalizeCapturedAt(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        // Accept strict ISO8601/RFC3339-like timestamps; reject relative/natural strings.
        $formats = [
            \DateTimeInterface::ATOM,
            \DateTimeInterface::RFC3339,
            \DateTimeInterface::RFC3339_EXTENDED,
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:sp',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:s.up',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s.u\Z',
        ];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $trimmed);
            $errors = \DateTimeImmutable::getLastErrors();
            $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($dt instanceof \DateTimeImmutable && ! $hasErrors) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function normalizeNullableInt(mixed $value, string $field, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        $errors[$field] = [__('validation.integer', ['attribute' => $field])];
        return null;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function normalizeOptionalString(mixed $referenceValue, string $field, int $maxChars, array &$errors): ?string
    {
        if ($referenceValue === null || $referenceValue === '' || (is_string($referenceValue) && trim($referenceValue) === '')) {
            return null;
        }
        if (! is_string($referenceValue) || ! mb_check_encoding($referenceValue, 'UTF-8')) {
            $errors[$field] = [__('validation.string', ['attribute' => $field])];
            return null;
        }
        if (mb_strlen($referenceValue, 'UTF-8') > $maxChars) {
            $errors[$field] = [__('validation.max.string', ['attribute' => $field, 'max' => $maxChars])];
            return null;
        }

        return $referenceValue;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array<mixed>|null
     */
    private function normalizeOptionalMetadata(mixed $metadata, array &$errors): ?array
    {
        if ($metadata === null || $metadata === '' || (is_string($metadata) && trim($metadata) === '')) {
            return null;
        }

        if (is_string($metadata)) {
            $metadata = $this->decodeMetadataJson($metadata);
        }

        if (! is_array($metadata)) {
            $errors['metadata'] = [__('validation.json', ['attribute' => 'metadata'])];
            return null;
        }

        if (! $this->validateMetadataEncoding($metadata, $errors)) {
            return null;
        }

        return $metadata;
    }

    /** @return array<mixed>|null */
    private function decodeMetadataJson(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $metadata
     * @param array<string, list<string>> $errors
     */
    private function validateMetadataEncoding(array $metadata, array &$errors): bool
    {
        try {
            $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors['metadata'] = [__('validation.json', ['attribute' => 'metadata'])];
            return false;
        }

        if (strlen($encoded) > self::MAX_METADATA_BYTES) {
            $errors['metadata'] = [__('validation.max.string', ['attribute' => 'metadata', 'max' => self::MAX_METADATA_BYTES])];
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function assertReferencesBelongToShipment(int $shipmentId, array $validated): void
    {
        $assignmentId = $validated['shipment_assignment_id'] ?? null;
        $attemptId = $validated['shipment_delivery_attempt_id'] ?? null;

        $assignment = null;
        if ($assignmentId !== null) {
            $assignment = ShipmentAssignment::query()->whereKey($assignmentId)->first();
            if ($assignment === null || (int) $assignment->shipment_id !== $shipmentId) {
                throw ValidationException::withMessages([
                    'shipment_assignment_id' => [__('validation.exists', ['attribute' => 'shipment_assignment_id'])],
                ]);
            }
        }

        if ($attemptId !== null) {
            $attempt = ShipmentDeliveryAttempt::query()->whereKey($attemptId)->first();
            if ($attempt === null || (int) $attempt->shipment_id !== $shipmentId) {
                throw ValidationException::withMessages([
                    'shipment_delivery_attempt_id' => [__('validation.exists', ['attribute' => 'shipment_delivery_attempt_id'])],
                ]);
            }
            if ($assignment !== null && (int) $attempt->shipment_assignment_id !== (int) $assignment->id) {
                throw ValidationException::withMessages([
                    'shipment_delivery_attempt_id' => [__('validation.in', ['attribute' => 'shipment_delivery_attempt_id'])],
                ]);
            }
        }
    }

    private function assertNoSuccessfulPod(int $shipmentId): void
    {
        $exists = ShipmentPod::query()
            ->where('shipment_id', $shipmentId)
            ->where('status', 'successful')
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw $this->duplicateSuccessfulPodException();
        }
    }

    private function isDuplicateSuccessfulPodViolation(QueryException $e): bool
    {
        $message = $e->getMessage();
        $driverCode = $e->errorInfo[1] ?? null;

        return str_contains($message, 'shipment_pods_one_successful_per_shipment')
            || ((int) $driverCode === 1062 && str_contains($message, 'shipment_pods'));
    }

    private function duplicateSuccessfulPodException(): ValidationException
    {
        return ValidationException::withMessages([
            'shipment_id' => [__('validation.custom.shipment_pod.duplicate_successful')],
        ]);
    }

    private function cleanupStoredFile(?string $path): void
    {
        if (is_string($path) && $path !== '' && Storage::disk('private')->exists($path)) {
            Storage::disk('private')->delete($path);
        }
    }
}
