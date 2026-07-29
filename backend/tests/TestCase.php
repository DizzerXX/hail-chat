<?php

namespace Tests;

use App\Models\ChatSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may silently reach the network; fake what you trigger.
        Http::preventStrayRequests();

        // ChatSettings::current() is process-cached; clear it so a mutated
        // instance from one test never leaks past RefreshDatabase's rollback.
        ChatSettings::forgetCurrent();
    }
}
