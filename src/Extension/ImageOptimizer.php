<?php

namespace My\Plugin\Content\ImageOptimizer\Extension;

defined('_JEXEC') or die; 

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Factory;

class ImageOptimizer extends CMSPlugin implements SubscriberInterface {
    public static function getSubscribedEvents(): array {
        return ['onContentBeforeSave' => 'optimizeImage'];
    }

    public function optimizeImage(Event $event) {
        [$context, $item] = array_values($event->getArguments());

        if (isset($item->images)) {
            $images = json_decode($item->images, true);
            $image = $images['image_fulltext'] ?? null;
        }

        if ($image) {
            $filePath = $this->getFilePath($image);
            if (!$filePath || !is_readable($filePath)) {
                Factory::getApplication()->enqueueMessage('Invalid image file: ' . $image, 'error');
                return;
            }

            $params = $this->params;
            $quality = max(0, min(100, $params->get('quality', 85))); // Ensure quality is between 0 and 100
            $maxWidth = $params->get('max_width', 1920);
            $maxHeight = $params->get('max_height', 1080);            
            $resizeMode = $params->get('resize_mode', 'fit'); // Default to 'fit'
            $resizeModeIntro = $params->get('resize_mode_intro', 'fit'); // Default to 'fit'
            
            $info = getimagesize($filePath);
            $imageType = $info[2] ?? null; // Ensure imageType is defined

            $this->resizeAndCompressImage($filePath, $maxWidth, $maxHeight, $quality, $resizeMode);

            // Create a thumbnail version of the image
            $quality = max(0, min(100, $params->get('quality_intro', 85))); // Ensure quality is between 0 and 100
            $maxIntroWidth = $params->get('max_intro_width', 350);
            $maxIntroHeight = $params->get('max_intro_height', 350);
            $thumbnailPath = $this->createThumbnail($filePath, $imageType, $quality, $maxIntroWidth, $maxIntroHeight, $resizeModeIntro);
            if ($thumbnailPath) {
                $images['image_intro'] = $this->getRelativePath($thumbnailPath);
            }

            // if ALT is empty add article title
            if(empty($images['image_intro_alt'])){
                $images['image_intro_alt'] = $item->title;
            }
            if(empty($images['image_fulltext_alt'])){
                $images['image_fulltext_alt'] = $item->title;
            }
            $item->images = json_encode($images);
            
        }
    }

    private function getFilePath($joomlaImagePath) {
        $parts = explode('#', $joomlaImagePath);
        if (count($parts) > 1) {
            $joomlaImagePath = $parts[1];
        }
        if (preg_match('/^joomlaImage:\/\/local-images\/(.+?)\?/', $joomlaImagePath, $matches)) {
            $relativePath = $matches[1];
            return JPATH_ROOT . '/images/' . $relativePath;
        }
        return null;
    }

    private function resizeAndCompressImage($filePath, $maxWidth, $maxHeight, $quality, $resizeMode) {
        $info = getimagesize($filePath);
        if (!$info) {
            Factory::getApplication()->enqueueMessage('Invalid image file: ' . $filePath, 'error');
            return;
        }

        [$width, $height, $imageType] = $info;
        
        if ($resizeMode === 'crop') {
            list($newWidth, $newHeight, $srcX, $srcY, $srcWidth, $srcHeight) = $this->calculateCropDimensions($width, $height, $maxWidth, $maxHeight);
        } else {
            $srcX = $srcY = 0;
            $srcWidth = $width;
            $srcHeight = $height;
            list($newWidth, $newHeight) = $this->calculateNewDimensions($width, $height, $maxWidth, $maxHeight);
        }

        try {
            $image = $this->createImageFromType($filePath, $imageType);
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage('Error creating image: ' . $e->getMessage(), 'error');
            return;
        }

        if ($image) {
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            if ($imageType == IMAGETYPE_PNG) {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }

            imagecopyresampled($resizedImage, $image, 0, 0, $srcX, $srcY, $newWidth, $newHeight, $srcWidth, $srcHeight);

            $this->saveImage($resizedImage, $filePath, $imageType, $quality);
            imagedestroy($image);
            imagedestroy($resizedImage);
        }
    }

    private function calculateNewDimensions($width, $height, $maxWidth, $maxHeight): array {
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        return [round($width * $ratio), round($height * $ratio)];
    }

    private function createImageFromType($filePath, $imageType) {
        return match ($imageType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => imagecreatefrompng($filePath),
            IMAGETYPE_GIF => imagecreatefromgif($filePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($filePath),
            default => null,
        };
    }

    private function saveImage($image, $filePath, $imageType, $quality) {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                imagejpeg($image, $filePath, $quality);
                break;
            case IMAGETYPE_PNG:
                $quality = 9 - (int)(($quality / 100) * 9);
                imagepng($image, $filePath, $quality);
                break;
            case IMAGETYPE_GIF:
                imagegif($image, $filePath);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image, $filePath, $quality);
                break;
        }
    }


    private function createThumbnail($filePath, $imageType, $quality, $maxIntroWidth, $maxIntroHeight, $resizeModeIntro) {

        $info = getimagesize($filePath);
        if (!$info) {
            Factory::getApplication()->enqueueMessage('Invalid image file: ' . $filePath, 'error');
            return null;
        }

        [$width, $height, $imageType] = $info;
        list($newWidth, $newHeight) = $this->calculateNewDimensions($width, $height, $maxIntroWidth, $maxIntroHeight);

        $image = $this->createImageFromType($filePath, $imageType);
        
        if ($image) {
            // Ensure only the image name includes the "_thumb" suffix
            $pathParts = pathinfo($filePath);
            $thumbnailPath = $pathParts['dirname'] . '/' . $pathParts['filename'] . '_thumb.' . $pathParts['extension'];

            // ... resizing logic according to $resizeMode ...
            if ($resizeModeIntro === 'crop') {
                list($newWidth, $newHeight, $srcX, $srcY, $srcWidth, $srcHeight) = $this->calculateCropDimensions($width, $height, $maxIntroWidth, $maxIntroHeight);
            } else {
                $srcX = $srcY = 0;
                $srcWidth = $width;
                $srcHeight = $height;
                list($newWidth, $newHeight) = $this->calculateNewDimensions($width, $height, $maxIntroWidth, $maxIntroHeight);
            }

            $thumbnailImage = imagecreatetruecolor($newWidth, $newHeight);

            imagecopyresampled($thumbnailImage, $image, 0, 0, $srcX, $srcY, $newWidth, $newHeight, $srcWidth, $srcHeight);

            if (!is_dir(dirname($thumbnailPath))) {
                mkdir(dirname($thumbnailPath), 0755, true);
            }
            $this->saveImage($thumbnailImage, $thumbnailPath, $imageType, $quality);

            imagedestroy($thumbnailImage);
        }

        imagedestroy($image);

        return file_exists($thumbnailPath) ? $thumbnailPath : null;
    }

    private function getRelativePath($fullPath) {
        return str_replace(JPATH_ROOT . '/', '', $fullPath);
    }

    private function calculateCropDimensions($width, $height, $maxWidth, $maxHeight): array {
        $srcRatio = $width / $height;
        $dstRatio = $maxWidth / $maxHeight;

        if ($srcRatio > $dstRatio) {
            $srcHeight = $height;
            $srcWidth = (int)($height * $dstRatio);
            $srcX = (int)(($width - $srcWidth) / 2);
            $srcY = 0;
        } else {
            $srcWidth = $width;
            $srcHeight = (int)($width / $dstRatio);
            $srcX = 0;
            $srcY = (int)(($height - $srcHeight) / 2);
        }

        return [$maxWidth, $maxHeight, $srcX, $srcY, $srcWidth, $srcHeight];
    }

}
