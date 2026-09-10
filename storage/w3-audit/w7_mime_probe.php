<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

Storage::fake('private');

$mp3 = UploadedFile::fake()->createWithContent('tone.mp3', "\xFF\xFB\x90\x44" . str_repeat("\x00", 100));
$mp4 = UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat('V', 200));

foreach ([['mp3', $mp3], ['mp4', $mp4]] as [$ext, $f]) {
    echo "$ext detected=" . $f->getMimeType() . ' client=' . $f->getClientMimeType() . PHP_EOL;
    try {
        app(App\Services\Digital\AssetTypeRegistry::class);
        // replicate service validation pieces:
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath());
        echo "  finfo=$detected" . PHP_EOL;
    } catch (Throwable $e) {
        echo '  ERR ' . $e->getMessage() . PHP_EOL;
    }
}
