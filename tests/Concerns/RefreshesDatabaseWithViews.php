<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Drop-in replacement for `use RefreshDatabase;` everywhere in this test
 * suite — overriding migrateFreshUsing() from a parent TestCase doesn't
 * work here, because trait methods (RefreshDatabase pulls in
 * CanConfigureMigrationCommands::migrateFreshUsing()) take precedence over
 * inherited parent-class methods in PHP's resolution order. Declaring the
 * override directly in a trait that itself composes RefreshDatabase makes
 * this version win instead, which is what actually makes `--drop-views`
 * apply.
 *
 * `--drop-views` matters because this schema's 16 views aren't dropped by
 * plain `migrate:fresh` (it only knows about tables) — without this, a
 * second fresh migration in the same test run collides with "already
 * exists" on the very first CREATE VIEW.
 */
trait RefreshesDatabaseWithViews
{
    use RefreshDatabase;

    protected function migrateFreshUsing()
    {
        return [
            '--drop-views' => true,
            '--seed' => $this->shouldSeed(),
        ];
    }
}
