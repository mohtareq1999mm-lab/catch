<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Typed section kinds for static pages.
 *
 * Hybrid typed model: type enum + JSON content/config + Media Library.
 * Screenshot is semantically distinct but reuses image media infrastructure.
 */
final class StaticSectionType extends Enum
{
    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const SCREENSHOT = 'screenshot';

    /**
     * Values that are image-backed (share image collection/mime rules).
     */
    public static function imageTypes(): array
    {
        return [self::IMAGE, self::SCREENSHOT];
    }

    public static function isImageType(?string $type): bool
    {
        return in_array($type, self::imageTypes(), true);
    }

    public static function isVideoType(?string $type): bool
    {
        return $type === self::VIDEO;
    }

    public static function isMediaType(?string $type): bool
    {
        return self::isImageType($type) || self::isVideoType($type);
    }
}
