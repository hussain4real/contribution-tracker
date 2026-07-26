<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\PendingCommand;
use Mockery\MockInterface;
use RuntimeException;

/**
 * Test-case surface that Pest exposes inside test closures.
 */
abstract class PestStaticAnalysisTestCase extends TestCase
{
    /**
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     */
    public function artisan($command, $parameters = []): PendingCommand
    {
        $pendingCommand = parent::artisan($command, $parameters);

        if (! $pendingCommand instanceof PendingCommand) {
            throw new RuntimeException('Expected artisan command to return a pending command.');
        }

        return $pendingCommand;
    }

    /**
     * @param  class-string  $abstract
     */
    public function mock($abstract, ?Closure $mock = null): MockInterface
    {
        return parent::mock($abstract, $mock);
    }

    /**
     * @param  class-string  $abstract
     */
    public function partialMock($abstract, ?Closure $mock = null): MockInterface
    {
        return parent::partialMock($abstract, $mock);
    }

    /**
     * @param  class-string  $abstract
     */
    public function spy($abstract, ?Closure $mock = null): MockInterface
    {
        return parent::spy($abstract, $mock);
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     */
    public function assertDatabaseHas($table, array $data = [], mixed $connection = null): static
    {
        parent::assertDatabaseHas($table, $data, $connection);

        return $this;
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     */
    public function assertDatabaseMissing($table, array $data = [], mixed $connection = null): static
    {
        parent::assertDatabaseMissing($table, $data, $connection);

        return $this;
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     */
    public function assertDatabaseCount($table, int $count, mixed $connection = null): static
    {
        parent::assertDatabaseCount($table, $count, $connection);

        return $this;
    }
}
