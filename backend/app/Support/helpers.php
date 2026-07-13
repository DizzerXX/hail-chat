<?php

use App\Services\License\LicenseService;

if (! function_exists('license')) {
    function license(): LicenseService
    {
        return app(LicenseService::class);
    }
}
