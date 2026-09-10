<?php

namespace Marvel\Services\Import\ImageHandlers;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

class ZipImageHandler
{
    protected string $extractPath;

    protected array $extractedFiles = [];

    public function __construct()
    {
        $this->extractPath = storage_path('app/temp/import_images_' . Str::random(8));
    }

    public function extract(UploadedFile $zipFile): void
    {
        if (!is_dir($this->extractPath)) {
            mkdir($this->extractPath, 0755, true);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipFile->path());

        if ($result !== true) {
            throw new Exception("Failed to open ZIP file: error code {$result}");
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'image/bmp'];
        $maxFileSize = 5 * 1024 * 1024;
        $totalFiles = $zip->numFiles;
        $maxFiles = 500;
        $totalSize = 0;
        $maxTotalSize = 50 * 1024 * 1024;

        for ($i = 0; $i < $totalFiles; $i++) {
            if ($i >= $maxFiles) {
                throw new Exception("ZIP contains more than {$maxFiles} files");
            }

            $filename = $zip->getNameIndex($i);
            $safeName = basename($filename);

            if (str_starts_with($safeName, '.')) {
                continue;
            }

            $stat = $zip->statIndex($i);
            $totalSize += $stat['size'];

            if ($totalSize > $maxTotalSize) {
                throw new Exception("ZIP total size exceeds {$maxTotalSize} bytes");
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'zip_img_');
            file_put_contents($tempFile, $content);

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempFile);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedMimes)) {
                unlink($tempFile);
                continue;
            }

            if (filesize($tempFile) > $maxFileSize) {
                unlink($tempFile);
                continue;
            }

            $destPath = $this->extractPath . '/' . $safeName;
            rename($tempFile, $destPath);
            $this->extractedFiles[] = $destPath;
        }

        $zip->close();

        Log::info("Extracted " . count($this->extractedFiles) . " images from ZIP to {$this->extractPath}");
    }

    public function findImage(string $filename): ?string
    {
        $searchName = basename($filename);
        $exactPath = $this->extractPath . '/' . $searchName;

        if (file_exists($exactPath)) {
            return $exactPath;
        }

        $pattern = pathinfo($searchName, PATHINFO_FILENAME);
        foreach ($this->extractedFiles as $filePath) {
            $base = pathinfo($filePath, PATHINFO_FILENAME);
            if ($base === $pattern) {
                return $filePath;
            }
        }

        return null;
    }

    public function attachToModel(HasMedia $model, string $filePath, string $collection = 'products'): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $model->addMedia($filePath)
            ->toMediaCollection($collection);
    }

    public function cleanup(): void
    {
        foreach ($this->extractedFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        if (is_dir($this->extractPath)) {
            @rmdir($this->extractPath);
        }
    }
}
