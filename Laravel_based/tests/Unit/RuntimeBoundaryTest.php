<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RuntimeBoundaryTest extends TestCase
{
    public function test_laravel_implementation_has_no_python_programs(): void
    {
        $root=dirname(__DIR__,2);
        foreach (['app','routes','config','scripts','bin'] as $part) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$part,\FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile()) continue;
                self::assertNotSame('py',$file->getExtension(),$file->getPathname());
                self::assertDoesNotMatchRegularExpression('/(?:python3?|Python_based|8765)/',file_get_contents($file->getPathname()),$file->getPathname());
            }
        }
    }
}
