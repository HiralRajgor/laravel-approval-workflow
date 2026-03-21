<?php

namespace App\Providers;

use App\Events\DocumentTransitioned;
use App\Listeners\SendTransitionNotification;
use App\Models\Document;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Event → Listener map.
     *
     * Using a dedicated listener class (not a closure) means:
     *   - The listener can implement ShouldQueue cleanly
     *   - It can have its own retry/backoff configuration
     *   - It's discoverable and testable in isolation
     */
    protected $listen = [
        DocumentTransitioned::class => [
            SendTransitionNotification::class,
            // Add more listeners here without touching WorkflowService:
            // NotifySlackChannel::class,
            // DispatchWebhook::class,
            // UpdateSearchIndex::class,
        ],
    ];

    public function boot(): void
    {
        // Register the Document policy
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
