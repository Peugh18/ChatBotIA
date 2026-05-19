<?php

namespace App\Services\Utility;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageService
{
    protected $apiToken;
    protected $apiVersion;

    public function __construct()
    {
        $this->apiToken = config('services.whatsapp.api_token');
        $this->apiVersion = config('services.whatsapp.api_version');
    }

    public function downloadWhatsAppImage($mediaId)
    {
        try {
            // 1. Get the media URL from Meta
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}";
            $response = Http::withToken($this->apiToken)->get($url);

            if (!$response->successful()) {
                Log::error('Error fetching media URL from Meta: ' . $response->body());
                return null;
            }

            $mediaUrl = $response->json()['url'] ?? null;

            if (!$mediaUrl) {
                Log::error('Media URL not found in Meta response');
                return null;
            }

            // 2. Download the actual image
            $imageResponse = Http::withToken($this->apiToken)->get($mediaUrl);

            if (!$imageResponse->successful()) {
                Log::error('Error downloading image from Meta URL: ' . $imageResponse->body());
                return null;
            }

            return $imageResponse->body();
        } catch (\Exception $e) {
            Log::error('ImageService Exception: ' . $e->getMessage());
            return null;
        }
    }

    public function toBase64($binaryData)
    {
        return base64_encode($binaryData);
    }
}
