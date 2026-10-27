<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ImagePreprocessingService
{
    public function prepare(string $path, int $id): string
    {
        $image = imagecreatefromstring(Storage::get($path));
        if (! $image) {
            throw new \RuntimeException('Image cannot be decoded.');
        }
        if (function_exists('exif_read_data') && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'jpg') {
            $exif = @exif_read_data(Storage::path($path));
            $orientation = $exif['Orientation'] ?? 1;
            if (in_array($orientation, [3, 6, 8])) {
                $rotated = imagerotate($image, match ($orientation) {
                    3 => 180,6 => -90,8 => 90
                }, 0);
                imagedestroy($image);
                $image = $rotated;
            }
        }
        $working = 'processed/'.$id.'/working.png';
        Storage::makeDirectory('processed/'.$id);
        imagepng($image, Storage::path($working));
        imagedestroy($image);

        return $working;
    }

    public function crop(string $path, array $box, int $id, string $key): ?string
    {
        if (count($box) !== 4 || $box[2] <= $box[0] || $box[3] <= $box[1]) {
            return null;
        }
        $image = imagecreatefromstring(Storage::get($path));
        $w = imagesx($image);
        $h = imagesy($image);
        $x = max(0, (int) (($box[1] - 35) * $w / 1000));
        $y = max(0, (int) (($box[0] - 45) * $h / 1000));
        $right = min($w, (int) (($box[3] + 35) * $w / 1000));
        $bottom = min($h, (int) (($box[2] + 35) * $h / 1000));
        $crop = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $right - $x, 'height' => $bottom - $y]);
        imagedestroy($image);
        if (! $crop) {
            return null;
        }$target = 'processed/'.$id.'/'.$key.'.png';
        imagepng($crop, Storage::path($target));
        imagedestroy($crop);

        return $target;
    }
}
