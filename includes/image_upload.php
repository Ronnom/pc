<?php
/**
 * Product image upload helper
 */

if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Normalize $_FILES array for multiple uploads
 */
function normalizeUploadFilesArray(array $fileField) {
    $normalized = [];

    if (!isset($fileField['name'])) {
        return $normalized;
    }

    if (!is_array($fileField['name'])) {
        return [$fileField];
    }

    $count = count($fileField['name']);
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name' => $fileField['name'][$i] ?? '',
            'type' => $fileField['type'][$i] ?? '',
            'tmp_name' => $fileField['tmp_name'][$i] ?? '',
            'error' => $fileField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $fileField['size'][$i] ?? 0
        ];
    }

    return $normalized;
}

/**
 * Upload a single product image
 *
 * @return array{path:string,width:int,height:int}
 */
function uploadProductImage(array $file, array $options = []) {
    $maxSize = (int)($options['max_size'] ?? UPLOAD_MAX_SIZE);
    $maxWidth = (int)($options['max_width'] ?? 2000);
    $maxHeight = (int)($options['max_height'] ?? 2000);
    $quality = (int)($options['jpeg_quality'] ?? 85);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception('Invalid upload source.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        throw new Exception('Image size is invalid or exceeds limit.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES, true)) {
        throw new Exception('Unsupported image type. Allowed: JPG, PNG, GIF, WEBP.');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo) {
        throw new Exception('File is not a valid image.');
    }

    $originalWidth = (int)$imageInfo[0];
    $originalHeight = (int)$imageInfo[1];
    if ($originalWidth <= 0 || $originalHeight <= 0) {
        throw new Exception('Invalid image dimensions.');
    }

    $uploadDir = APP_ROOT . '/uploads/products/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('Unable to create upload directory.');
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    $extension = $extensionMap[$mimeType] ?? 'jpg';
    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $uploadDir . $filename;

    $newWidth = $originalWidth;
    $newHeight = $originalHeight;
    if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int)max(1, floor($originalWidth * $ratio));
        $newHeight = (int)max(1, floor($originalHeight * $ratio));
    }

    $supportsGdResize = function_exists('imagecreatetruecolor') &&
        function_exists('imagecopyresampled');

    if (!$supportsGdResize || ($newWidth === $originalWidth && $newHeight === $originalHeight)) {
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save uploaded image.');
        }

        return [
            'path' => 'uploads/products/' . $filename,
            'width' => $originalWidth,
            'height' => $originalHeight
        ];
    }

    $srcImage = null;
    if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $srcImage = @imagecreatefromjpeg($file['tmp_name']);
    } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
        $srcImage = @imagecreatefrompng($file['tmp_name']);
    } elseif ($mimeType === 'image/gif' && function_exists('imagecreatefromgif')) {
        $srcImage = @imagecreatefromgif($file['tmp_name']);
    } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $srcImage = @imagecreatefromwebp($file['tmp_name']);
    }

    if (!$srcImage) {
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save uploaded image.');
        }

        return [
            'path' => 'uploads/products/' . $filename,
            'width' => $originalWidth,
            'height' => $originalHeight
        ];
    }

    $dstImage = imagecreatetruecolor($newWidth, $newHeight);
    if ($mimeType === 'image/png' || $mimeType === 'image/gif' || $mimeType === 'image/webp') {
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled(
        $dstImage,
        $srcImage,
        0,
        0,
        0,
        0,
        $newWidth,
        $newHeight,
        $originalWidth,
        $originalHeight
    );

    $saved = false;
    if ($mimeType === 'image/jpeg' && function_exists('imagejpeg')) {
        $saved = imagejpeg($dstImage, $targetPath, $quality);
    } elseif ($mimeType === 'image/png' && function_exists('imagepng')) {
        $saved = imagepng($dstImage, $targetPath, 6);
    } elseif ($mimeType === 'image/gif' && function_exists('imagegif')) {
        $saved = imagegif($dstImage, $targetPath);
    } elseif ($mimeType === 'image/webp' && function_exists('imagewebp')) {
        $saved = imagewebp($dstImage, $targetPath, $quality);
    }

    imagedestroy($srcImage);
    imagedestroy($dstImage);

    if (!$saved) {
        throw new Exception('Failed to process uploaded image.');
    }

    return [
        'path' => 'uploads/products/' . $filename,
        'width' => $newWidth,
        'height' => $newHeight
    ];
}
