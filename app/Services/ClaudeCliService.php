<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Claude Code CLI provider — shells out to `claude -p` (headless mode),
 * billed against the user's Claude subscription instead of API credits.
 *
 * Every surface runs through the CLI. There is no per-token cost here, so
 * unlike the HTTP providers there is no payload trimming for rate limits;
 * the screener still uses the shared shortlist so recommendations stay
 * comparable across providers.
 *
 * Requires the `claude` binary logged in for the user PHP runs as.
 */
class ClaudeCliService implements Llm
{
    public function extractFunds(string $rawText): array
    {
        // No tool-calling over the CLI's text interface, so the schema is
        // described in the prompt and the JSON is parsed back out.
        $prompt = FundAnalysisPrompt::EXTRACT_SYSTEM
            ."\n\nReturn ONLY a JSON object, no prose, no code fences, exactly:\n"
            .FundAnalysisPrompt::EXTRACT_SHAPE
            ."\n\n---\n\nPAGE TEXT:\n".$rawText;

        $out = $this->run($prompt, webTools: false);

        if (preg_match('/\{.*\}/s', $out, $m)) {
            $out = $m[0];
        }
        $data = json_decode(trim($out), true);

        return is_array($data['funds'] ?? null) ? $data['funds'] : [];
    }

    public function recommend(array $funds, string $feedback, array $recalled = []): array
    {
        $recalledTxt = $recalled ? "\n\nPrior stated preferences:\n- ".implode("\n- ", $recalled) : '';

        $prompt = FundAnalysisPrompt::screenerSystem(webTools: true)
            ."\n\n---\n\nUser feedback / goals:\n{$feedback}{$recalledTxt}"
            ."\n\nFunds:\n".FundAnalysisPrompt::screenerLines($funds);

        return FundAnalysisPrompt::parseRecommendations($this->run($prompt));
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

    /**
     * Run an arbitrary prompt through the CLI.
     *
     * $webTools is off for mechanical work (extraction) so the model cannot
     * wander into searches that add latency without improving a parse.
     */
    private function run(string $prompt, bool $webTools = true): string
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
            'HOME' => $home,
            'USER' => $user,
            'LOGNAME' => $user,
            'PATH' => dirname($bin).':/usr/bin:/bin',
        ];
        // PHP-FPM runs outside the macOS security session, so the CLI cannot
        // read its Keychain login there. A long-lived token from
        // `claude setup-token` bypasses the Keychain entirely.
        if ($token = config('ai.claude_token')) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;
        }

        $command = [$bin, '-p', '--output-format', 'text'];
        if ($webTools) {
            $command[] = '--allowedTools';
            $command[] = 'WebSearch,WebFetch';
        }

        $process = new Process(
            $command,
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
