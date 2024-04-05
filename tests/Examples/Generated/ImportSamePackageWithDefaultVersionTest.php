<?php

namespace Castor\Tests\Examples\Generated;

use Castor\Tests\TaskTestCase;

class ImportSamePackageWithDefaultVersionTest extends TaskTestCase
{
    // no task
    public function test(): void
    {
        $process = $this->runTask([], '{{ base }}/tests/Examples/fixtures/valid/import-same-package-with-default-version', needRemote: true);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringEqualsFile(__FILE__ . '.output.txt', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }
}
