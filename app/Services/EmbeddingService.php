<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmbeddingService
{
    /**
     * Embed one text, return float[] of length config('ai.embed_dim').
     */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Embed many texts in one API call.
     *
     * @param  string[]  $texts
     * @return array<int, float[]>
     */
    public function embedBatch(array $texts): array
    {
        return match (config('ai.embed_provider')) {
            'openai' => $this->openai($texts),
            'voyage' => $this->voyage($texts),
            default  => $this->ollama($texts),
        };
    }

    private function ollama(array $texts): array
    {
        $res = Http::asJson()
            ->timeout(60)
            ->post(rtrim(config('ai.ollama_url'), '/').'/api/embed', [
                'model' => config('ai.embed_model'),
                'input' => $texts,
            ]);

        if ($res->failed()) {
            throw new RuntimeException('Ollama embed failed: '.$res->body());
        }

        return $res->json('embeddings');
    }

    private function voyage(array $texts): array
    {
        $res = Http::withToken(config('ai.voyage_key'))
            ->asJson()
            ->timeout(30)
            ->post(config('ai.voyage_url'), [
                'input' => $texts,
                'model' => config('ai.embed_model'),
            ]);

        if ($res->failed()) {
            throw new RuntimeException('Voyage embed failed: '.$res->body());
        }

        return array_map(fn ($d) => $d['embedding'], $res->json('data'));
    }

    private function openai(array $texts): array
    {
        $res = Http::withToken(config('ai.openai_key'))
            ->asJson()
            ->timeout(30)
            ->post(config('ai.openai_url'), [
                'input' => $texts,
                'model' => config('ai.embed_model'),
            ]);

        if ($res->failed()) {
            throw new RuntimeException('OpenAI embed failed: '.$res->body());
        }

        return array_map(fn ($d) => $d['embedding'], $res->json('data'));
    }
}
