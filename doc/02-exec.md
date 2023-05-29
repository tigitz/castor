## Exec function

Castor provides a `Castor\exec()` function to execute commands. It allows to run a
sub process and execute whatever you want:

```php
<?php

use Castor\Attribute\AsTask;
use function Castor\exec;

#[AsTask]
function foo(): void
{
    exec('echo "bar"');
    exec(['echo', 'bar']);
}
```

You can pass a string or an array of string for this command. When passing a
string, arguments will not be escaped - use it carefully.

### Process object

Under the hood, Castor uses the
[`Symfony\Component\Process\Process`](https://github.com/symfony/symfony/blob/6.3/src/Symfony/Component/Process/Process.php)
object to execute the command. The `exec()` function will return this object. So
you can use the API of this class to interact with the process:

```php
#[AsTask]
function foo(): void
{
    $process = exec('echo "bar"');
    $process->isSuccessful(); // will return true
}
```

### Failure

By default, Castor will throw an exception if the command fails. You can disable
that by setting the `allowFailure` option to `true`:

```php
#[AsTask]
function foo(): void
{
    exec('a_command_that_does_not_exist', allowFailure: true);
}
```

### Working directory

By default, Castor will execute the command in the same directory as
the `.castor.php` file. You can change that by setting the `path` argument. It
can be either a relative or an absolute path:

```php
#[AsTask]
function foo(): void
{
    exec('pwd', path: '../'); // execute the command in the parent directory of the .castor.php file
    exec('pwd', path: '/tmp'); // execute the command in the /tmp directory
}
```

### Environment variables

By default, Castor will use the same environment variables as the current
process. You can add or override environment variables by setting
the `environment` argument:

```php
#[AsTask]
function foo(): void
{
    exec('echo $FOO', environment: ['FOO' => 'bar']); // will print "bar"
}
```

### Processing the output

By default, Castor will forward the stdout and stderr to the current terminal.
If you do not want to print the output of the command you can set the `quiet`
option to `true`:

```php
#[AsTask]
function foo(): void
{
    exec('echo "bar"', quiet: true); // will not print anything
}
```

You can also fetch the output of the command by using the API of
the `Symfony\Component\Process\Process` object:

```php
#[AsTask]
function foo(): void
{
    $process = exec('echo "bar"', quiet: true); // will not print anything
    $output = $process->getOutput(); // will return "bar\n"
}
```

### PTY & TTY

By default, Castor will use a pseudo terminal (PTY) to execute the command,
which allows to have nice output in most cases.
For some commands you may want to disable the PTY and use a TTY instead. You can
do that by setting the `tty` option to `true`:

```php
#[AsTask]
function foo(): void
{
    exec('echo "bar"', tty: true);
}
```

> **Warning**
> When using a TTY, the output of the command is empty in the process object
> (when using `getOutput()` or `getErrorOutput()`).

You can also disable the pty by setting the `pty` option to `false`. If `pty`
and `tty` are both set to `false`, the standard input will not be forwarded to
the command:

```php

#[AsTask]
function foo(): void
{
    exec('echo "bar"', pty: false);
}
```