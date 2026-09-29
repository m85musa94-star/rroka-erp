<?php

namespace App\Services;

use App\Models\StudioAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded image on the studio disk with a thumbnail, and creates
 * its studio_assets row. Identical files (same SHA-256) are not stored twice.
 */
class StudioStorage
{
    public const DISK = 'studio';

    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const THUMB_EDGE = 480;

    /** Local disk in production loses files on every deploy: refuse instead of losing data. */
    public static function isReady(): bool
    {
        return ! (app()->isProduction() && config('filesystems.disks.'.self::DISK.'.driver') === 'local');
    }

    /**
     * @return array{0: StudioAsset, 1: bool} the asset and whether it already existed
     */
    public function store(UploadedFile $file, array $attributes, ?int $userId): array
    {
        if (! self::isReady()) {
            throw ValidationException::withMessages(['files' => __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.')]);
        }

        $sha = hash_file('sha256', $file->getRealPath());
        if ($existing = StudioAsset::where('sha256', $sha)->first()) {
            return [$existing, true];
        }

        $info = @getimagesize($file->getRealPath());
        $mime = $info['mime'] ?? null;
        if (! isset(self::MIME_EXT[$mime])) {
            throw ValidationException::withMessages(['files' => __('الملف :name ليس صورة JPG أو PNG أو WEBP.', ['name' => $file->getClientOriginalName()])]);
        }

        $base = now()->format('Y/m').'/'.Str::uuid();
        $path = $base.'.'.self::MIME_EXT[$mime];
        $disk = Storage::disk(self::DISK);
        $disk->put($path, file_get_contents($file->getRealPath()));
        $thumb = $this->thumbnail($file->getRealPath(), $mime, $info[0], $info[1]);
        $thumbPath = null;
        if ($thumb !== null) {
            $thumbPath = 'thumbs/'.$base.'.jpg';
            $disk->put($thumbPath, $thumb);
        }

        try {
            $asset = new StudioAsset($attributes);
            $asset->forceFill([
                'disk' => self::DISK, 'path' => $path, 'thumb_path' => $thumbPath, 'mime_type' => $mime,
                'size_bytes' => $file->getSize(), 'width' => $info[0], 'height' => $info[1],
                'sha256' => $sha, 'uploaded_by' => $userId,
            ])->save();
        } catch (\Throwable $e) {
            // The row was refused (e.g. a control rule): do not leave orphan files behind.
            $disk->delete(array_filter([$path, $thumbPath]));
            throw $e;
        }

        return [$asset, false];
    }

    /** Removes the files of an asset whose row is already deleted. */
    public function deleteFiles(StudioAsset $asset): void
    {
        Storage::disk($asset->disk)->delete(array_filter([$asset->path, $asset->thumb_path]));
    }

    private function thumbnail(string $source, string $mime, int $w, int $h): ?string
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
        };
        if (! $img) {
            return null;
        }
        $img = $this->orient($img, $source, $mime);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, self::THUMB_EDGE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagejpeg($out, null, 80);

        return ob_get_clean() ?: null;
    }

    /** Phone photos are often stored sideways with an EXIF orientation flag. */
    private function orient(\GdImage $img, string $source, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $o = (int) (@exif_read_data($source)['Orientation'] ?? 1);

        return match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        } ?: $img;
    }
}
