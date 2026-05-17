<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $apiKey;
    protected $model;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model  = config('services.gemini.model', 'gemini-2.5-flash');
    }

    /**
     * Single-turn chat (kept for backwards compatibility).
     */
    public function chat($prompt, $systemInstruction = null): ?string
    {
        return $this->chatWithHistory(
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $systemInstruction
        );
    }

    /**
     * Multi-turn chat with full conversation history.
     */
    public function chatWithHistory(array $history, string $systemPrompt = null): ?string
    {
        $data = [
            'contents'         => $history,
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ],
        ];

        if ($systemPrompt) {
            $data['system_instruction'] = [
                'parts' => [['text' => $systemPrompt]]
            ];
        }

        $json = $this->postToGemini('generateContent', $data);

        return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * Analyze an image alongside a text prompt.
     */
    public function analyzeImage(string $imageBase64, string $prompt): ?string
    {
        $data = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageBase64]]
                ]
            ]]
        ];

        $json = $this->postToGemini('generateContent', $data);

        return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * Robust HTTP POST wrapper with automatic Rate Limit (429) retries and exponential backoff.
     */
    protected function postToGemini(string $endpoint, array $data, int $maxRetries = 3, int $initialDelayMs = 1500): ?array
    {
        $url = "{$this->baseUrl}/{$this->model}:{$endpoint}?key={$this->apiKey}";

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(30)->post($url, $data);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();
                $body   = $response->body();

                Log::warning("Gemini API Attempt {$attempt} failed with status {$status}: {$body}");

                if ($status === 429) {
                    $delay = $initialDelayMs * pow(2, $attempt - 1);
                    Log::info("Gemini Rate Limit (429) detected. Waiting " . ($delay / 1000) . " seconds before retry (Attempt {$attempt}/{$maxRetries})...");
                    usleep($delay * 1000);
                    continue;
                }

                // If not 429, don't waste time retrying permanent failures (e.g. 400 bad schema, 403 invalid key)
                Log::error("Gemini API permanent failure: Status {$status} - Body: {$body}");
                break;

            } catch (\Exception $e) {
                Log::error("Gemini API Exception on attempt {$attempt}: " . $e->getMessage());
                if ($attempt < $maxRetries) {
                    $delay = $initialDelayMs * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                }
            }
        }

        return null;
    }
}
