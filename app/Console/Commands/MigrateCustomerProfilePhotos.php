<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Media\ProfilePhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MigrateCustomerProfilePhotos extends Command
{
    protected $signature = 'customers:migrate-photos {--company-id= : Migrate photos for specific company only} {--limit=100 : Number of customers to process}';
    protected $description = 'Download and store customer profile photos from platform URLs to local storage';

    public function handle()
    {
        $profilePhotoService = new ProfilePhotoService();
        
        $query = Customer::whereNotNull('profile_photo_url')
            ->where('profile_photo_url', '!=', '');

        if ($this->option('company-id')) {
            $query->where('company_id', $this->option('company-id'));
        }

        $limit = $this->option('limit');
        $customers = $query->limit($limit)->get();

        $this->info("Found {$customers->count()} customers to process...");
        
        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            $this->line("Processing customer ID {$customer->id}: {$customer->name}");
            
            $currentUrl = $customer->profile_photo_url;

            // Skip if already a local URL
            if (str_contains($currentUrl, '/storage/')) {
                $this->comment("  - Already has local photo, skipping");
                $skipped++;
                continue;
            }

            // Download and store photo
            $localUrl = $profilePhotoService->downloadAndStore(
                $currentUrl,
                $customer->company_id,
                $customer->id
            );

            if ($localUrl) {
                $customer->profile_photo_url = $localUrl;
                $customer->save();
                
                $this->info("  ✓ Photo downloaded and stored");
                $migrated++;
            } else {
                $this->error("  ✗ Failed to download photo");
                $failed++;
            }
        }

        $this->newLine();
        $this->info('Migration complete!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Migrated', $migrated],
                ['Skipped', $skipped],
                ['Failed', $failed],
                ['Total', $customers->count()],
            ]
        );

        Log::info('Customer profile photo migration completed', [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => $customers->count(),
        ]);

        return Command::SUCCESS;
    }
}
