<?php

namespace App\Providers;

use App\Models\Etablissement;
use App\Models\User;
use App\Policies\EtablissementPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Etablissement::class => EtablissementPolicy::class,
        User::class => UserPolicy::class,
    ];



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
        //
    }
}
