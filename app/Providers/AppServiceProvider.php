<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ContentFilter;
use App\Services\OpenAi\Client;
use App\Services\OpenAi\Service;
use App\Services\AssistantDataService; // <-- Make sure AssistantDataService is imported
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void // Added return typehint
    {
        // Binding for the custom OpenAI Client wrapper
        $this->app->singleton(Client::class, function ($app) {
            // Ensure config keys match what Client expects (services.openai.*)
            return new Client(
                config('services.openai.api_key'),
                config('services.openai.base_url')
            );
        });

        // Binding for the main OpenAI Service
        $this->app->singleton(Service::class, function ($app) {
            // *** CORRECTED LINE ***
            // Now resolve both Client and AssistantDataService and pass them
            return new Service(
                $app->make(Client::class),
                $app->make(AssistantDataService::class) // <-- Pass the second dependency
            );
        });

        // You might not need to explicitly bind AssistantDataService if it has no constructor dependencies
        // Laravel can often auto-resolve it. But explicitly making it here is also fine.
        // $this->app->singleton(AssistantDataService::class);

    }

    public function boot(): void // Added return typehint
    {
        // Alias middleware
        $this->app['router']->aliasMiddleware('content.filter', ContentFilter::class);
    }
}