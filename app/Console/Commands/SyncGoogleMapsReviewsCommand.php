<?php

namespace App\Console\Commands;

use App\Jobs\SyncLocationReviewsJob;
use App\Models\TenantLocation;
use Illuminate\Console\Command;

class SyncGoogleMapsReviewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sada:sync-google-reviews {--location= : Specific location ID to sync} {--limit=20 : Maximum reviews per location}';

    /**
     * The console command description.
     */
    protected $description = 'Dispatch review sync jobs for monitored Google Maps locations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $locationId = $this->option('location');
        $limit = (int) $this->option('limit');

        if ($locationId) {
            $location = TenantLocation::withoutGlobalScopes()->find($locationId);
            if (!$location) {
                $this->error("Location #{$locationId} not found.");
                return self::FAILURE;
            }

            SyncLocationReviewsJob::dispatch($location->id, $limit);
            $this->info("Dispatched review sync for Location #{$location->id} ({$location->business_name}).");
            return self::SUCCESS;
        }

        $query = TenantLocation::withoutGlobalScopes();
        $total = $query->count();

        $this->info("Dispatching review sync jobs for {$total} locations...");

        $query->chunk(50, function ($locations) use ($limit) {
            foreach ($locations as $loc) {
                SyncLocationReviewsJob::dispatch($loc->id, $limit);
            }
        });

        $this->info("All review sync jobs have been queued successfully.");
        return self::SUCCESS;
    }
}
