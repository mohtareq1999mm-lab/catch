<?php

namespace App\Enums;

/**
 * Content family of a FILE-type digital asset.
 *
 * Taxonomy follows the approved Digital Products feature spec. TEXT is
 * intentionally absent: TXT/RTF/ODT belong to DOCUMENT per the original
 * requirements. SPREADSHEET and PRESENTATION are separate families because
 * they carry distinct Office MIME namespaces and preview behavior.
 *
 * Categories only ever combine with DigitalAssetType::FILE. URL/LICENSE/
 * ACCESS assets are category-less by design.
 */
enum DigitalAssetCategory: string
{
    case DOCUMENT = 'DOCUMENT';
    case SPREADSHEET = 'SPREADSHEET';
    case PRESENTATION = 'PRESENTATION';
    case ARCHIVE = 'ARCHIVE';
    case AUDIO = 'AUDIO';
    case VIDEO = 'VIDEO';
    case IMAGE = 'IMAGE';
    case SOFTWARE = 'SOFTWARE';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
