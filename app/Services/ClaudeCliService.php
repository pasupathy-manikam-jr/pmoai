<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Claude Code CLI provider — shells out to `claude -p` (headless mode),
 * billed against the user's Claude subscription instead of API credits.
 *
 * The deep-reasoning surface (single-fund analysis) goes to Claude; the
 * mechanical extraction/screening calls delegate to GroqService so the
 * free tier keeps doing the bulk parsing work.
 *
 * Requires the `claude` binary logged in for the user PHP runs as.
 */
class ClaudeCliService implements Llm
{
    public function __construct(private GroqService $fallback = new GroqService()) {}

    public function extractFunds(string $rawText): array
    {
        return $this->fallback->extractFunds($rawText);
    }

    public function recommend(array $funds, string $feedback, array $recalled = []): array
    {
        return $this->fallback->recommend($funds, $feedback, $recalled);
    }

    public function analyzeFund(array $fund, array $context = []): string
    {
        // This provider runs with WebSearch/WebFetch enabled, so rule 5's
        // "no web access" is lifted — replaced by a cite-everything rule.
        $research = <<<'TXT'
OVERRIDE OF RULE 5 — you DO have WebSearch and WebFetch tools. Before writing
the analysis, run 2-4 targeted searches on the fund's actual exposure (its
market/country/sector from the data below — e.g. the stock index it tracks,
its top sectors, currency vs MYR, rate/policy news). Rules for live findings:
- Every external claim must carry its source and date inline, e.g.
  "(Reuters, 10 Jul 2026)". No source+date = do not write the claim.
- Live data supplements but never replaces the provided figures — the
  provided catalog/factsheet/signal numbers remain the only fund numbers.
- Add a section "**Market check (live)**" after Outlook: 2-4 bullets of what
  you found and what it implies for THIS fund. The Verdict may use it.
TXT;

        $prompt = FundAnalysisPrompt::system(! empty($context['position']))
            ."\n\n".$research
            ."\n\n---\n\n"
            .FundAnalysisPrompt::user($fund, $context);

        return $this->run($prompt);
    }

    public function chat(array $fund, array $context, array $history, string $question): string
    {
        $convo = '';
        foreach ($history as $m) {
            $who = ($m['role'] ?? '') === 'user' ? 'USER' : 'ASSISTANT';
            $convo .= $who.': '.($m['text'] ?? '')."\n\n";
        }

        $prompt = FundAnalysisPrompt::chatSystem(true)
            ."\n\n---\n\nFUND DATA:\n".FundAnalysisPrompt::user($fund, $context)
            .($convo !== '' ? "\n\n---\n\nCONVERSATION SO FAR:\n".$convo : '')
            ."\n\n---\n\nUSER QUESTION (answer this):\n".$question;

        return $this->run($prompt);
    }

    /** Run an arbitrary prompt through the CLI (web tools enabled). */
    public function raw(string $prompt): string
    {
        return $this->run($prompt);
    }

    private function run(string $prompt): string
    {
        // Web requests: MAMP's max_execution_time would kill a 60s+ CLI run.
        set_time_limit(300);

        $bin = config('ai.claude_bin');

        // PHP-FPM strips the login environment. The CLI needs HOME for its
        // config dir and USER/LOGNAME for macOS Keychain credential lookup —
        // without USER it reports "Not logged in".
        $pw = posix_getpwuid(posix_geteuid());
        $home = config('ai.claude_home') ?: ($pw['dir'] ?? getenv('HOME'));
        $user = $pw['name'] ?? basename((string) $home);

        $env = [
            'HOME'    => $home,
            'USER'    => $user,
            'LOGNAME' => $user,
            'PATH'    => dirname($bin).':/usr/bin:/bin',
        ];
        // PHP-FPM runs outside the macOS security session, so the CLI cannot
        // read its Keychain login there. A long-lived token from
        // `claude setup-token` bypasses the Keychain entirely.
        if ($token = config('ai.claude_token')) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;
        }

        $process = new Process(
            [$bin, '-p', '--output-format', 'text', '--allowedTools', 'WebSearch,WebFetch'],
            base_path(),
            $env,
            $prompt,
            timeout: 480,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'claude CLI failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $out = trim($process->getOutput());
        if ($out === '') {
            throw new RuntimeException('claude CLI returned empty output.');
        }

        return $out;
    }
}
