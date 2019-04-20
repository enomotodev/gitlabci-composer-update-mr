<?php

namespace Enomotodev\GitLabCIComposerUpdateMr\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Enomotodev\GitLabCIComposerUpdateMr\Command;

class CommandTest extends TestCase
{
    public function testCreateMergeRequestDescription()
    {
        $object = new Command();
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod('createMergeRequestDescription');
        $method->setAccessible(true);

        $expected = <<<TEXT
### changes
- foo: [`v1.0...v1.1`](https://github.com/example/foo/compare/v1.0...v1.1)

### changes-dev
- bar: `9.9.9...REMOVED`
- baz: `NEW...0.0.1`


TEXT;

        $this->assertSame($expected, $method->invoke($object, [
            'changes' => [
                'foo' => ['v1.0', 'v1.1', 'https://github.com/example/foo/compare/v1.0...v1.1'],
            ],
            'changes-dev' => [
                'bar' => ['9.9.9', 'REMOVED', ''],
                'baz' => ['NEW', '0.0.1', ''],
            ],
        ]));
    }
}
