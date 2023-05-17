<?php

namespace Castor\Example;

use Castor\Attribute\Arg;
use Castor\Attribute\Task;
use function Castor\exec;

#[Task(description: "This a task with arguments")]
function args(
    #[Arg(description: 'This is a required argument')] string $required,
    #[Arg(name: 'optional', description: 'This is an optional test argument')] int $test2 = 1
) {
    exec(["echo", $required, $test2]);
}
