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
        // Local embed models have a hard context window (mxbai-embed-large =
        // 512 tokens ≈ 2000 chars); Ollama ERRORS rather than truncating when
        // the input overflows. Cap each text so long bodies (MFR macro
        // sections) still embed — the lead paragraphs carry the gist that
        // semantic search matches on. Cap is generous enough not to touch
        // normal short embeds.
        // 1200 chars stays under 512 tokens even for number-dense text
        // (tables tokenize at ~2.5 chars/token vs ~4 for prose).
        $cap = (int) config('ai.embed_max_chars', 1200);
        $texts = array_map(fn ($t) => mb_strlen($t) > $cap ? mb_substr($t, 0, $cap) : $t, $texts);

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
