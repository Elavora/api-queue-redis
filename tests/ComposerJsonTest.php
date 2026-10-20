<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ComposerJsonTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $composer;

    protected function setUp(): void
    {
        $this->composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function testRequiresPhp83(): void
    {
        self::assertSame('>=8.3', $this->composer['require']['php'] ?? null);
    }

    public function testRequiresExtRedis(): void
    {
        self::assertSame('*', $this->composer['require']['ext-redis'] ?? null);
    }

    public function testRequiresFramework2(): void
    {
        self::assertSame('^2.0', $this->composer['require']['elavora/api-framework'] ?? null);
    }

    public function testRequiresApiRedis1(): void
    {
        self::assertSame('^1.0', $this->composer['require']['elavora/api-redis'] ?? null);
    }

    public function testBranchAliasIs10Dev(): void
    {
        self::assertSame('1.0-dev', $this->composer['extra']['branch-alias']['dev-main'] ?? null);
    }

    public function testAutoloadIsUnchanged(): void
    {
        self::assertSame(
            ['Elavora\\Api\\Extension\\QueueRedis\\' => 'src/'],
            $this->composer['autoload']['psr-4'] ?? null,
        );
    }

    public function testDoesNotCommitPathRepository(): void
    {
        foreach ($this->composer['repositories'] ?? [] as $repository) {
            self::assertNotSame('path', is_array($repository) ? ($repository['type'] ?? null) : null);
        }
        $this->addToAssertionCount(1);
    }
}
