<?php

namespace App\Console\Commands;

use App\Models\Company;
use Database\Seeders\IntentWorkflowSeeder;
use Illuminate\Console\Command;

class CreateIntentWorkflows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflows:create-intent {company_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create intent-based routing workflows for existing companies. Safe for production - skips companies that already have the workflow.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $companyId = $this->argument('company_id');

        if ($companyId) {
            // Create for specific company
            $company = Company::find($companyId);
            if (!$company) {
                $this->error("Company with ID {$companyId} not found.");
                return Command::FAILURE;
            }

            $seeder = new IntentWorkflowSeeder();
            $workflow = $seeder->createWorkflow($company);

            if ($workflow) {
                $this->info("Intent workflow created for company: {$company->name} (ID: {$company->id})");
                $this->info("Workflow ID: {$workflow->id}");
                return Command::SUCCESS;
            }

            $this->info("Intent workflow already exists for company: {$company->name}");
            return Command::SUCCESS;
        }

        // Create for all companies
        $companies = Company::all();
        $this->info("Found {$companies->count()} companies.");

        $created = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            $seeder = new IntentWorkflowSeeder();
            $workflow = $seeder->createWorkflow($company);

            if ($workflow && $workflow->wasRecentlyCreated) {
                $created++;
                $this->info("✓ Created workflow for: {$company->name}");
            } else {
                $skipped++;
                $this->line("- Skipped (already exists): {$company->name}");
            }
        }

        $this->newLine();
        $this->info("Summary: {$created} created, {$skipped} skipped.");

        return Command::SUCCESS;
    }
}
