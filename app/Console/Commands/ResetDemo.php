<?php

namespace App\Console\Commands;

use App\Support\Demo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Rebuilds the public demo: deletes uploaded files and recreates the whole
 * database from the seeder. Refuses to run unless SGMS_DEMO is on.
 */
class ResetDemo extends Command
{
    protected $signature = 'sgms:demo-reset {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe the database and uploads and reseed the public demo (SGMS_DEMO only)';

    public function handle(): int
    {
        if (! Demo::enabled()) {
            $this->error('SGMS_DEMO is off. This command erases every shop, so it only runs on the demo install.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Erase all data and uploads and reseed the demo?')) {
            return self::FAILURE;
        }

        // Uploaded files (photos, logos, receipts, Livewire temp uploads).
        foreach ([storage_path('app/public'), storage_path('app/private')] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::directories($root) as $dir) {
                File::deleteDirectory($dir);
            }
            foreach (File::files($root, true) as $file) {
                if ($file->getFilename() !== '.gitignore') {
                    File::delete($file->getPathname());
                }
            }
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->info('Demo rebuilt.');

        return self::SUCCESS;
    }
}
