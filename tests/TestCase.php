<?php

namespace AjayDhakal\QFPay\Tests;

use AjayDhakal\QFPay\QFPayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [QFPayServiceProvider::class];
    }
}
