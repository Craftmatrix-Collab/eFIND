<?php
/**
 * Save uploaded profile image with optional compression/resizing.
 * Falls back to move_uploaded_file when optimization is unavailable.
 */
if (!function_exists('saveOptimizedUploadedImage')) {
    function saveOptimizedUploadedImage(array $file, $targetPath, $maxDimension = 1280, $jpegQuality = 82) {
        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return false;
        }

        $tmpPath = $file['tmp_name'];

        if (!extension_loaded('gd')) {
            return move_uploaded_file($tmpPath, $targetPath);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $tmpPath) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $mimeType = strtolower($detectedMime ?: ($file['type'] ?? ''));

        $supportedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $supportedMimes, true)) {
            return move_uploaded_file($tmpPath, $targetPath);
        }

        $sourceBytes = file_get_contents($tmpPath);
        if ($sourceBytes === false) {
            return move_uploaded_file($tmpPath, $targetPath);
        }

        $image = @imagecreatefromstring($sourceBytes);
        if (!$image) {
            return move_uploaded_file($tmpPath, $targetPath);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $outputImage = $image;

        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = (int)round($width * $ratio);
            $newHeight = (int)round($height * $ratio);
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }

            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $outputImage = $resizedImage;
        }

        ob_start();
        $writeOk = false;
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $writeOk = imagejpeg($outputImage, null, $jpegQuality);
        } elseif ($mimeType === 'image/png') {
            $writeOk = imagepng($outputImage, null, 6);
        } elseif ($mimeType === 'image/webp' && function_exists('imagewebp')) {
            $writeOk = imagewebp($outputImage, null, $jpegQuality);
        }
        $optimizedBytes = ob_get_clean();

        if ($outputImage !== $image) {
            imagedestroy($outputImage);
        }
        imagedestroy($image);

        if (!$writeOk || $optimizedBytes === false || $optimizedBytes === '') {
            return move_uploaded_file($tmpPath, $targetPath);
        }

        if (strlen($optimizedBytes) >= strlen($sourceBytes)) {
            return move_uploaded_file($tmpPath, $targetPath);
        }

        return file_put_contents($targetPath, $optimizedBytes) !== false;
    }
}

