<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SecurityNotificationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    SecurityNotificationServiceProvider::class,
];
