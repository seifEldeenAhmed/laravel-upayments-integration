<?php

namespace Osama\Upayments\Facades;

use Illuminate\Support\Facades\Facade;

class Upayments extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'Upayments';
    }
}