<?php

declare(strict_types=1);

namespace App\Services\Shipment\Labels;

use App\Exceptions\InvalidLabelContentException;

final class LabelContentValidator
{
    /** @var array<string, array{mime:string, magic:list<string>}> */
    private const FORMATS = [
        'pdf' => ['mime' => 'application/pdf', 'magic' => ["%PDF-"]],
        'png' => ['mime' => 'image/png', 'magic' => ["\x89PNG\r\n\x1a\n"]],
        'zpl' => ['mime' => 'text/plain', 'magic' => ["^XA", "^XA\n", "^xa"]],
    ];

    /**
     * @return array{format:string,mime_type:string,size_bytes:int,sha256:string}
     */
    public function validate(string $content, ?string $filename = null): array
    {
        $size = strlen($content);
        if ($size === 0) {
            throw InvalidLabelContentException::empty();
        }

        $maxBytes = (int) config('shipping.labels.max_bytes', 5 * 1024 * 1024);
        if ($size > $maxBytes) {
            throw InvalidLabelContentException::tooLarge($maxBytes);
        }

        $detected = $this->detectFormat($content);
        if ($detected === null) {
            throw InvalidLabelContentException::invalidMagicBytes();
        }

        [$format, $mime] = $detected;
        $allowed = config('shipping.labels.allowed_formats', ['pdf', 'png', 'zpl']);
        if (!is_array($allowed) || !in_array($format, $allowed, true)) {
            throw InvalidLabelContentException::invalidFormat();
        }

        if ($filename !== null) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== '' && $ext !== $format) {
                throw InvalidLabelContentException::invalidMagicBytes();
            }
        }

        return [
            'format' => $format,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => hash('sha256', $content),
        ];
    }

    /** @return array{0:string,1:string}|null */
    private function detectFormat(string $content): ?array
    {
        foreach (self::FORMATS as $format => $def) {
            foreach ($def['magic'] as $magic) {
                if (str_starts_with($content, $magic)) {
                    return [$format, $def['mime']];
                }
            }
        }

        return null;
    }
}
