<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case PENDING = 'pending';
    case GENERATING = 'generating';
    case GENERATED = 'generated';
    case PDF_GENERATING = 'pdf_generating';
    case READY = 'ready';
    case FAILED = 'failed';
    case VERIFIED = 'verified';
    case DOWNLOADED = 'downloaded';
    case PRINTED = 'printed';
    case CORRECTED = 'corrected';
    case CANCELLED = 'cancelled';
    case ARCHIVED = 'archived';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::GENERATING, self::CANCELLED],
            self::GENERATING => [self::GENERATED, self::FAILED],
            self::GENERATED => [self::PDF_GENERATING, self::READY, self::FAILED, self::VERIFIED, self::DOWNLOADED, self::PRINTED, self::CORRECTED, self::CANCELLED],
            self::PDF_GENERATING => [self::READY, self::FAILED],
            self::READY => [self::PDF_GENERATING, self::DOWNLOADED, self::PRINTED, self::VERIFIED, self::FAILED, self::CORRECTED, self::CANCELLED, self::ARCHIVED],
            self::FAILED => [self::PDF_GENERATING, self::CANCELLED],
            self::VERIFIED => [self::DOWNLOADED, self::PRINTED, self::CANCELLED, self::ARCHIVED],
            self::DOWNLOADED => [self::PRINTED, self::VERIFIED, self::CANCELLED, self::ARCHIVED],
            self::PRINTED => [self::DOWNLOADED, self::VERIFIED, self::CANCELLED, self::ARCHIVED],
            self::CORRECTED => [self::CANCELLED, self::ARCHIVED],
            self::CANCELLED => [self::ARCHIVED],
            self::ARCHIVED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
