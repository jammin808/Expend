<?php

namespace Tests;

use App\Domain\Matching\OwnAccounts;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OwnAccounts::flush(); // a cache that lasts one request (or one queue job) in the app, but would last the whole run here
    }
}
