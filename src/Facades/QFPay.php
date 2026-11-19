<?php

namespace AjayDhakal\QFPay\Facades;

use AjayDhakal\QFPay\QFPay as Client;
use Illuminate\Support\Facades\Facade;

/** @mixin Client */
class QFPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
