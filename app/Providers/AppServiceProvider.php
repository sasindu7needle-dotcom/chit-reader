<?php

namespace App\Providers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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
        try {
            if (Schema::hasTable('workspace_settings')) {
                $model = DB::table('workspace_settings')->where('key', 'model')->value('value');
                if ($model) {
                    config(['ocr.model' => $model]);
                }
            }
        } catch (QueryException $e) {
            // Installation and migration commands must work before a database is configured.
        }
    }
}
