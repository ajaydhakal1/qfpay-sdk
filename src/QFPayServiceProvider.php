<?php

namespace AjayDhakal\QFPay;

use Illuminate\Http\Client\Factory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class QFPayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('qfpay-sdk')->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(QFPay::class, fn ($app) => new QFPay(
            $app->make(Factory::class),
            $app['config']->get('qfpay-sdk', []),
        ));
        $this->app->singleton(EcrTerminal::class, fn ($app) => new EcrTerminal(
            $app->make(Factory::class),
            $app['config']->get('qfpay-sdk.ecr', []),
        ));
    }
}
