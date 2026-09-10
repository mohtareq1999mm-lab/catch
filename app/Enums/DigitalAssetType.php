<?php

namespace App\Enums;

/**
 * Digital asset delivery/storage type.
 *
 * This is NOT the content family (see DigitalAssetCategory). It answers
 * HOW an asset is delivered, not WHAT it contains:
 *
 * - FILE    physically stored file on a private disk (the only pipeline
 *           implemented today).
 * - URL     external link delivered by controlled redirect (future).
 * - LICENSE credential revealed from an encrypted key pool (future, A2).
 * - ACCESS  access/activation grant (future).
 *
 * Values match the digital_assets.type column ('FILE' persisted today;
 * 'LICENSE' was pre-reserved as DigitalAsset::TYPE_LICENSE).
 */
enum DigitalAssetType: string
{
    case FILE = 'FILE';
    case URL = 'URL';
    case LICENSE = 'LICENSE';
    case ACCESS = 'ACCESS';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
