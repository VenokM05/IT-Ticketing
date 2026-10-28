<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The public intake form's time-on-page bot defence is wall-clock based, and a
     * test mounts and submits in the same instant, so it is switched off for the
     * whole suite. The trap itself is asserted explicitly (config turned back on)
     * in the public intake tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['tickets.intake.min_fill_seconds' => 0]);
    }
}
