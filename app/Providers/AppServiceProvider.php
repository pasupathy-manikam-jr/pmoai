<?php

namespace App\Providers;

use App\Services\ClaudeCliService;
use App\Services\ClaudeService;
use App\Services\Llm;
use App\Services\OpenAiService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Llm::class, fn () => match (config('ai.llm_provider')) {
            'anthropic' => new ClaudeService,
            'openai' => new OpenAiService,
            'claude-cli' => new ClaudeCliService,
            default => new ClaudeCliService,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
