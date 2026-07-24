<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the reference/RBAC/demo data after each RefreshDatabase migration
     * so every feature test starts from the demo storyline.
     */
    protected bool $seed = true;
}
