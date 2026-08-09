<?php

namespace App\Services;

/**
 * LLM provider contract. Implementations: ClaudeCliService (Claude Code CLI,
 * billed to the subscription), ClaudeService (Anthropic API), OpenAiService.
 */
interface Llm
{
    /** @return array<int, array<string, mixed>> extracted fund rows */
    public function extractFunds(string $rawText): array;

    /**
     * @param  array<int, array>  $funds
     * @param  string[]  $recalled
     * @return array<int, array<string, mixed>>
     */
    public function recommend(array $funds, string $feedback, array $recalled = []): array;

    /**
     * Plain-text analysis of ONE fund. $context carries precomputed,
     * deterministic inputs so the model interprets numbers instead of
     * doing (and hallucinating) arithmetic.
     *
     * @param  array<string, mixed>  $fund
     * @param  array{signals?:array<string,string>,peers?:string,trend?:string,facts?:string}  $context
     */
    public function analyzeFund(array $fund, array $context = []): string;

    /**
     * One conversational turn about ONE fund. Same grounding contract as
     * analyzeFund; $history is the prior turns oldest-first.
     *
     * @param  array<string, mixed>  $fund
     * @param  array<string, mixed>  $context
     * @param  array<int, array{role: string, text: string}>  $history
     */
    public function chat(array $fund, array $context, array $history, string $question): string;
}
