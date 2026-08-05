<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange;

use Illuminate\Support\ServiceProvider;
use PhilipRehberger\Interchange\Queue\QueueTracePropagator;
use PhilipRehberger\Interchange\Signing\StandardWebhooksScheme;

class InterchangeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/interchange.php', 'interchange');

        $this->app->singleton(StandardWebhooksScheme::class, fn () => new StandardWebhooksScheme(
            (int) config('interchange.signing.tolerance_seconds', StandardWebhooksScheme::DEFAULT_TOLERANCE_SECONDS),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/interchange.php' => config_path('interchange.php'),
        ], 'interchange-config');

        if (config('interchange.tracing.propagate_through_queue', true)) {
            QueueTracePropagator::register();
        }
    }
}
