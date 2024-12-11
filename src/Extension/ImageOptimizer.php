<?php

namespace My\Plugin\Content\ImageOptimizer\Extension;

defined('_JEXEC') or die; 

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

class ImageOptimizer extends CMSPlugin implements SubscriberInterface {
    public static function getSubscribedEvents(): array {
        return ['onContentBeforeSave' => 'optimizeImage'];
    }

    public function optimizeImage(Event $event) {
        [$context, $item] = array_values($event->getArguments());

        if (isset($item->images) && is_file($item->images)) {
            $params = $this->params;
            $quality = $params->get('quality', 85);
            $maxWidth = $params->get('max_width', 1920);
            $maxHeight = $params->get('max_height', 1080);

            $this->resizeAndCompressImage($item->images, $maxWidth, $maxHeight, $quality);
        }
    }

    private function resizeAndCompressImage($filePath, $maxWidth, $maxHeight, $quality) {
        $info = getimagesize($filePath);
        if (!$info) return;

        [$width, $height] = $info;
        $type = $info['mime'];

        if ($width <= $maxWidth && $height <= $maxHeight) return;

        $newWidth = $maxWidth;
        $newHeight = ($height / $width) * $maxWidth;

        if ($newHeight > $maxHeight) {
            $newHeight = $maxHeight;
            $newWidth = ($width / $height) * $maxHeight;
        }

        $image = match ($type) {
            'image/jpeg' => imagecreatefromjpeg($filePath),
            'image/png' => imagecreatefrompng($filePath),
            'image/gif' => imagecreatefromgif($filePath),
            default => null,
        };

        if ($image) {
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagejpeg($resizedImage, $filePath, $quality);
            imagedestroy($image);
            imagedestroy($resizedImage);
        }
    }
}
