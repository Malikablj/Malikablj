<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\RequestContext;
use App\Core\Settings;
use PHPUnit\Framework\TestCase as BaseTestCase;

/** Basis semua test: mengembalikan state statis ke kondisi awal. */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze(null);
        RequestContext::reset();
        Auth::reset();
        Gate::flush();
        Settings::flush();
        I18n::setLocale('id');
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        parent::tearDown();
    }
}
