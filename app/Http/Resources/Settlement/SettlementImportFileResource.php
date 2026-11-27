<?php

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * #240: serialized private import evidence header.
 *
 * `disk` and `path` are deliberately omitted: the storage layout of the
 * private settlement disk stays server-side; no URL can be built for this
 * resource.
 */
class SettlementImportFileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'statement_key' => $this->statement_key,
            'provider_code' => $this->provider_code,
            'mode' => $this->mode,
            'account_code' => $this->account_code,
            'original_name' => $this->original_name,
            'file_hash' => $this->file_hash,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'parser_version' => $this->parser_version,
            'parsed_line_count' => (int) $this->parsed_line_count,
            'malformed_rows' => $this->malformed_rows ?? [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
