<?php

namespace Tests\Unit;

use App\Support\JsonExtractor;
use PHPUnit\Framework\TestCase;

class JsonExtractorTest extends TestCase
{
    public function test_plain_json(): void
    {
        $this->assertSame(['a' => 1], JsonExtractor::extract('{"a":1}'));
    }

    public function test_fenced_json(): void
    {
        $this->assertSame(['a' => 1], JsonExtractor::extract("Here you go:\n```json\n{\"a\":1}\n```\nDone."));
    }

    public function test_json_surrounded_by_prose(): void
    {
        $this->assertSame(['files' => []], JsonExtractor::extract('Sure! {"files":[]} hope that helps'));
    }

    public function test_garbage_returns_null(): void
    {
        $this->assertNull(JsonExtractor::extract('no json here'));
        $this->assertNull(JsonExtractor::extract(''));
    }
}
