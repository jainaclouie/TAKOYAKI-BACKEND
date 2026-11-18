<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('database.default') === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');

            if ($dbPath && !file_exists($dbPath)) {
                touch($dbPath);
            }

            if (!Schema::hasTable('users')) {
                Artisan::call('migrate', ['--force' => true, '--seed' => true]);
            }
        }
    }
}
