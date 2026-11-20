<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Support\ArrayReader;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class ArrayReaderTest extends TestCase
{
    public function test_a_null_key_returns_the_whole_array(): void
    {
        $array = ['profile' => ['name' => 'Ada']];

        self::assertSame($array, ArrayReader::get($array, null));
    }

    public function test_it_reads_a_top_level_key(): void
    {
        self::assertSame('Ada', ArrayReader::get(['name' => 'Ada'], 'name'));
    }

    public function test_it_reads_a_nested_dot_path(): void
    {
        self::assertSame('octocat', ArrayReader::get([
            'profile' => ['social' => ['github' => 'octocat']],
        ], 'profile.social.github'));
    }

    public function test_a_missing_top_level_key_returns_the_default(): void
    {
        self::assertSame('N/A', ArrayReader::get(['name' => 'Ada'], 'email', 'N/A'));
    }

    public function test_a_missing_intermediate_segment_returns_the_default(): void
    {
        self::assertSame('N/A', ArrayReader::get(['profile' => []], 'profile.social.github', 'N/A'));
    }

    public function test_a_non_array_intermediate_value_returns_the_default(): void
    {
        self::assertSame('N/A', ArrayReader::get(['profile' => 'Ada'], 'profile.social.github', 'N/A'));
    }

    public function test_it_reads_a_numeric_list_index_from_a_dot_path(): void
    {
        self::assertSame(42, ArrayReader::get([
            'results' => [['id' => 42]],
        ], 'results.0.id'));
    }

    public function test_it_reads_a_numeric_list_index_from_an_int_key(): void
    {
        self::assertSame('first', ArrayReader::get(['first', 'second'], 0));
    }

    public function test_a_literal_dotted_key_wins_over_traversal(): void
    {
        $array = [
            'a.b' => 'literal',
            'a' => ['b' => 'nested'],
        ];

        self::assertSame('literal', ArrayReader::get($array, 'a.b'));
    }

    public function test_a_stored_null_is_a_hit_and_returns_null(): void
    {
        self::assertNull(ArrayReader::get(['name' => null], 'name', 'N/A'));
        self::assertNull(ArrayReader::get(['profile' => ['name' => null]], 'profile.name', 'N/A'));
    }

    public function test_an_empty_string_key_returns_the_default_when_absent(): void
    {
        self::assertSame('N/A', ArrayReader::get(['name' => 'Ada'], '', 'N/A'));
    }

    public function test_an_empty_string_key_reads_the_empty_string_key(): void
    {
        self::assertSame('value', ArrayReader::get(['' => 'value'], ''));
    }

    public function test_has_reads_nested_paths(): void
    {
        self::assertTrue(ArrayReader::has([
            'profile' => ['social' => ['github' => 'octocat']],
        ], 'profile.social.github'));
    }

    public function test_has_supports_numeric_indexes(): void
    {
        $array = ['results' => [['id' => 42]]];

        self::assertTrue(ArrayReader::has($array, 'results.0'));
        self::assertTrue(ArrayReader::has($array, 'results.0.id'));
    }

    public function test_has_returns_false_for_a_missing_segment(): void
    {
        self::assertFalse(ArrayReader::has(['profile' => ['social' => []]], 'profile.social.github'));
        self::assertFalse(ArrayReader::has(['profile' => 'Ada'], 'profile.social'));
    }

    public function test_has_treats_a_stored_null_as_present(): void
    {
        self::assertTrue(ArrayReader::has(['name' => null], 'name'));
    }
}
