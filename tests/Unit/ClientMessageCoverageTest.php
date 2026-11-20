<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Http\ClientMessages;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Guards that every copy key used in the code has an entry in the catalog.
 */
final class ClientMessageCoverageTest extends TestCase
{
    public function test_every_copy_key_used_in_the_code_has_a_catalog_entry(): void
    {
        $keys = $this->copyKeysUsedInSource();

        self::assertNotSame([], $keys);

        foreach ($keys as $file => $fileKeys) {
            foreach ($fileKeys as $key) {
                self::assertTrue(ClientMessages::has($key), "{$file} → {$key}");
            }
        }
    }

    /**
     * Reads the copy keys referenced by the source, as string literals and as
     * `ClientMessages` constants.
     *
     * @return array<string, array<int, string>>
     */
    private function copyKeysUsedInSource(): array
    {
        $keys = [];

        foreach ($this->sourceFiles() as $file) {
            $tokens = $this->significantTokens((string) file_get_contents($file->getPathname()));
            $count = count($tokens);

            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];

                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                if ($token[1] === 'messageKey' && ($tokens[$index + 1] ?? null) === ':') {
                    $key = $this->literalArgument($tokens, $index + 2);

                    if ($key !== null) {
                        $keys[$file->getPathname()][] = $key;
                    }

                    continue;
                }

                if ($token[1] !== 'ClientMessages') {
                    continue;
                }

                $constant = $this->constantArgument($tokens, $index, $file);

                if ($constant !== null) {
                    $keys[$file->getPathname()][] = $constant;

                    continue;
                }

                $literal = $this->literalArgument($tokens, $index + 3);

                if ($literal !== null) {
                    $keys[$file->getPathname()][] = $literal;
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src')
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    private function significantTokens(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Resolves a `ClientMessages::CONSTANT` reference into its copy key.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function constantArgument(array $tokens, int $index, SplFileInfo $file): ?string
    {
        $doubleColon = $tokens[$index + 1] ?? null;
        $constant = $tokens[$index + 2] ?? null;

        if (!is_array($doubleColon) || $doubleColon[0] !== T_DOUBLE_COLON) {
            return null;
        }

        if (!is_array($constant) || $constant[0] !== T_STRING) {
            return null;
        }

        $class = ClientMessages::class;
        $name = $constant[1];
        $reference = "{$class}::{$name}";

        if (!defined($reference)) {
            return null;
        }

        try {
            return (string) constant($reference);
        } catch (Throwable $exception) {
            $path = $file->getPathname();
            $reason = $exception->getMessage();

            self::fail("{$path} references an unknown constant: {$reason}");
        }
    }

    /**
     * Reads a string literal argument.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function literalArgument(array $tokens, int $index): ?string
    {
        $token = $tokens[$index] ?? null;

        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return trim($token[1], "'\"");
    }
}
