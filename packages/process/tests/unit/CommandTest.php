<?php

declare(strict_types=1);

namespace Psl\Process\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psl\Env;
use Psl\Filesystem;
use Psl\IO;
use Psl\OS;
use Psl\Process\Command;
use Psl\Process\Exception;
use Psl\Process\Stdio;
use ReflectionClass;

use function basename;
use function chr;
use function dirname;
use function explode;
use function fclose;
use function file_exists;
use function file_put_contents;
use function fwrite;
use function is_executable;
use function json_decode;
use function pack;
use function preg_replace;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function unlink;
use function unpack;
use function usleep;
use function var_export;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class CommandTest extends TestCase
{
    /**
     * Create a PHP command.
     *
     * When pcov is loaded alongside opcache, PHP emits a JIT incompatibility
     * warning to stdout which corrupts output assertions.
     */
    private static function phpCommand(): Command
    {
        return Command::create(PHP_BINARY)->withArgument('-dopcache.enable=0');
    }

    /**
     * Runs the given PHP command and returns the environment the child process saw.
     *
     * @return array<string, string>
     */
    private static function childEnvironment(Command $command): array
    {
        $output = $command->withArgument('-r')->withArgument('echo json_encode(getenv());')->output();

        static::assertTrue($output->status->isSuccessful(), $output->stderr);

        /** @var array<string, string> */
        return json_decode($output->stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testCreate(): void
    {
        $command = Command::create('git');

        static::assertSame('git', $command->getProgram());
        static::assertSame([], $command->getArguments());
        static::assertSame([], $command->getEnvironmentVariables());
        static::assertNull($command->getWorkingDirectory());
    }

    public function testShell(): void
    {
        $command = Command::shell('echo hello');

        static::assertSame('echo hello', $command->getProgram());
        static::assertSame([], $command->getArguments());
    }

    public function testCreatePassesArgumentsSeparately(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo $argv[1];')
            ->withArgument('hello world & echo injected')
            ->output();

        static::assertSame('hello world & echo injected', $output->stdout);
    }

    public function testShellInterpretsPipesAndOperators(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Shell syntax test uses Unix shell features.');
        }

        // With shell(), the command is interpreted by /bin/sh -c.
        $output = Command::shell('echo hello && echo world')->output();

        static::assertStringContainsString('hello', $output->stdout);
        static::assertStringContainsString('world', $output->stdout);
    }

    public function testWithArgument(): void
    {
        $command = Command::create('git')->withArgument('status');

        static::assertSame(['status'], $command->getArguments());
    }

    public function testWithArgumentIsAdditive(): void
    {
        $command = Command::create('git')->withArgument('log')->withArgument('--oneline');

        static::assertSame(['log', '--oneline'], $command->getArguments());
    }

    public function testWithArgumentAdditiveExecution(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo implode(",", array_slice($argv, 1));')
            ->withArgument('--')
            ->withArgument('first')
            ->withArgument('second')
            ->withArgument('third')
            ->output();

        static::assertSame('first,second,third', $output->stdout);
    }

    public function testWithArguments(): void
    {
        $command = Command::create('git')->withArguments(['log', '--oneline']);

        static::assertSame(['log', '--oneline'], $command->getArguments());
    }

    public function testWithArgumentsIsAdditive(): void
    {
        $command = Command::create('git')->withArgument('log')->withArguments(['--oneline', '-n', '10']);

        static::assertSame(['log', '--oneline', '-n', '10'], $command->getArguments());
    }

    public function testWithEnvironmentVariable(): void
    {
        $command = Command::create('env')->withEnvironmentVariable('FOO', 'bar');

        static::assertSame(['FOO' => 'bar'], $command->getEnvironmentVariables());
    }

    public function testWithEnvironmentVariableExecution(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getenv("MY_TEST_VAR");')
            ->withEnvironmentVariable('MY_TEST_VAR', 'from_with_env_var')
            ->output();

        static::assertSame('from_with_env_var', $output->stdout);
    }

    public function testWithEnvironmentVariables(): void
    {
        $command = Command::create('env')->withEnvironmentVariables(['FOO' => 'bar', 'BAZ' => 'qux']);

        static::assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $command->getEnvironmentVariables());
    }

    public function testWithEnvironmentVariablesMerges(): void
    {
        $command = Command::create('env')
            ->withEnvironmentVariable('FOO', 'bar')
            ->withEnvironmentVariables(['BAZ' => 'qux']);

        static::assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $command->getEnvironmentVariables());
    }

    public function testWithoutEnvironmentVariable(): void
    {
        $command = Command::create('env')
            ->withEnvironmentVariables(['FOO' => 'bar', 'BAZ' => 'qux'])
            ->withoutEnvironmentVariable('FOO');

        static::assertSame(['BAZ' => 'qux'], $command->getEnvironmentVariables());
    }

    public function testWithClearedEnvironment(): void
    {
        $command = Command::create('env')->withEnvironmentVariables(['FOO' => 'bar'])->withClearedEnvironment();

        static::assertSame([], $command->getEnvironmentVariables());
    }

    public function testChildInheritsParentEnvironmentByDefault(): void
    {
        Env\set_var('PSL_PROCESS_PARENT_VAR', 'inherited');

        try {
            $environment = self::childEnvironment(self::phpCommand());
        } finally {
            Env\remove_var('PSL_PROCESS_PARENT_VAR');
        }

        static::assertSame('inherited', $environment['PSL_PROCESS_PARENT_VAR'] ?? null);
    }

    public function testWithClearedEnvironmentDoesNotInheritParentEnvironment(): void
    {
        Env\set_var('PSL_PROCESS_PARENT_VAR', 'inherited');

        $command = self::phpCommand()
            ->withEnvironmentVariable('PSL_PROCESS_DROPPED_VAR', 'dropped')
            ->withClearedEnvironment()
            ->withEnvironmentVariable('PSL_PROCESS_CHILD_VAR', 'set');

        if (OS\is_windows()) {
            // Windows programs, PHP included, can fail to start without SystemRoot.
            $command = $command->withEnvironmentVariable('SystemRoot', Env\get_var('SystemRoot') ?? 'C:\\Windows');
        }

        try {
            $environment = self::childEnvironment($command);
        } finally {
            Env\remove_var('PSL_PROCESS_PARENT_VAR');
        }

        static::assertArrayNotHasKey('PSL_PROCESS_PARENT_VAR', $environment);
        static::assertArrayNotHasKey('PSL_PROCESS_DROPPED_VAR', $environment);
        static::assertSame('set', $environment['PSL_PROCESS_CHILD_VAR'] ?? null);

        if (!OS\is_windows()) {
            // macOS' CoreFoundation, which PHP links against, sets this variable in every process it loads into.
            unset($environment['__CF_USER_TEXT_ENCODING']);

            static::assertSame(['PSL_PROCESS_CHILD_VAR' => 'set'], $environment);
        }
    }

    public function testWithoutEnvironmentVariableRemovesInheritedVariable(): void
    {
        Env\set_var('PSL_PROCESS_PARENT_VAR', 'inherited');
        Env\set_var('PSL_PROCESS_KEPT_VAR', 'kept');

        try {
            $environment = self::childEnvironment(
                self::phpCommand()->withoutEnvironmentVariable('PSL_PROCESS_PARENT_VAR'),
            );
        } finally {
            Env\remove_var('PSL_PROCESS_PARENT_VAR');
            Env\remove_var('PSL_PROCESS_KEPT_VAR');
        }

        static::assertArrayNotHasKey('PSL_PROCESS_PARENT_VAR', $environment);
        static::assertSame('kept', $environment['PSL_PROCESS_KEPT_VAR'] ?? null);
    }

    public function testWithEnvironmentVariableAfterRemovalOverridesInheritedVariable(): void
    {
        Env\set_var('PSL_PROCESS_PARENT_VAR', 'inherited');

        try {
            $environment = self::childEnvironment(
                self::phpCommand()
                    ->withoutEnvironmentVariable('PSL_PROCESS_PARENT_VAR')
                    ->withEnvironmentVariable('PSL_PROCESS_PARENT_VAR', 'overridden'),
            );
        } finally {
            Env\remove_var('PSL_PROCESS_PARENT_VAR');
        }

        static::assertSame('overridden', $environment['PSL_PROCESS_PARENT_VAR'] ?? null);
    }

    public function testEmptyEnvironmentVariableValueIsPassed(): void
    {
        Env\set_var('PSL_PROCESS_PARENT_VAR', 'inherited');

        try {
            $environment = self::childEnvironment(
                self::phpCommand()
                    ->withEnvironmentVariable('PSL_PROCESS_EMPTY_VAR', '')
                    ->withEnvironmentVariable('PSL_PROCESS_PARENT_VAR', ''),
            );
        } finally {
            Env\remove_var('PSL_PROCESS_PARENT_VAR');
        }

        static::assertSame('', $environment['PSL_PROCESS_EMPTY_VAR'] ?? null);
        static::assertSame('', $environment['PSL_PROCESS_PARENT_VAR'] ?? null);
    }

    public function testEmptyInheritedEnvironmentVariableIsPassed(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Setting a variable to an empty value removes it on Windows.');
        }

        Env\set_var('PSL_PROCESS_PARENT_VAR', '');

        try {
            $environment = self::childEnvironment(self::phpCommand());
        } finally {
            Env\remove_var('PSL_PROCESS_PARENT_VAR');
        }

        static::assertSame('', $environment['PSL_PROCESS_PARENT_VAR'] ?? null);
    }

    public function testInheritedEnvironmentVariableWithDotInNameIsPassed(): void
    {
        // getenv() does not list a name containing ".", so the child only gets it when the
        // parent's environment is passed through as is.
        Env\set_var('PSL.PROCESS_PARENT_VAR', 'inherited');

        try {
            $output = self::phpCommand()
                ->withArgument('-r')
                ->withArgument('echo getenv("PSL.PROCESS_PARENT_VAR");')
                ->output();
        } finally {
            Env\remove_var('PSL.PROCESS_PARENT_VAR');
        }

        static::assertTrue($output->status->isSuccessful(), $output->stderr);
        static::assertSame('inherited', $output->stdout);
    }

    public function testChildInheritsFastCgiParametersUnderPhpCgi(): void
    {
        // Under php-fpm and php-cgi, getenv() can also list the request's FastCGI parameters, which
        // the worker's own environment does not hold; the child gets them whether or not the
        // command changes its environment.
        if (OS\is_windows()) {
            static::markTestSkipped('This test runs php-cgi on a Unix socket.');
        }

        $phpCgi = dirname(PHP_BINARY) . '/' . preg_replace('/^php/', 'php-cgi', basename(PHP_BINARY));
        if (!is_executable($phpCgi)) {
            static::markTestSkipped('php-cgi is not installed next to the PHP binary.');
        }

        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $script = Filesystem\create_temporary_file();
        $socket = $script . '.sock';
        file_put_contents(
            $script,
            '<?php require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . <<<'PHP'
            $command = Psl\Process\Command::create('printenv')->withArgument('PSL_PROCESS_FASTCGI_PARAM');
            echo 'unchanged: ', $command->output()->stdout;
            echo 'changed: ', $command->withEnvironmentVariable('PSL_PROCESS_SET_VAR', 'set')->output()->stdout;
            PHP,
        );

        // php-cgi only lists the FastCGI parameters in getenv() when variables_order includes "E";
        // php-fpm always lists them.
        $server = Command::create($phpCgi)
            ->withArguments(['-dopcache.enable=0', '-dcgi.force_redirect=0', '-dvariables_order=EGPCS', '-b', $socket])
            ->withEnvironmentVariable('PHP_FCGI_MAX_REQUESTS', '1')
            ->withStdin(Stdio::null())
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        try {
            $response = self::fastCgiRequest($socket, [
                'SCRIPT_FILENAME' => $script,
                'REQUEST_METHOD' => 'GET',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'GATEWAY_INTERFACE' => 'CGI/1.1',
                'PSL_PROCESS_FASTCGI_PARAM' => 'from-request',
            ]);
        } finally {
            $server->kill();
            $server->wait();
            Filesystem\delete_file($script);
            if (file_exists($socket)) {
                unlink($socket);
            }
        }

        $body = explode("\r\n\r\n", $response, 2)[1] ?? $response;

        static::assertSame("unchanged: from-request\nchanged: from-request\n", $body);
    }

    /**
     * Sends a FastCGI request to the server listening on the given Unix socket and returns its output.
     *
     * @param array<non-empty-string, string> $parameters
     */
    private static function fastCgiRequest(string $socket, array $parameters): string
    {
        $client = false;
        for ($attempt = 0; $attempt < 100 && false === $client; $attempt++) {
            $client = @stream_socket_client('unix://' . $socket, $errorCode, $errorMessage, 1.0);
            if (false === $client) {
                usleep(50_000);
            }
        }

        static::assertNotFalse($client, 'Could not connect to php-cgi.');
        stream_set_timeout($client, 30);

        $record = static fn(int $type, string $content): string => (
            pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content
        );
        $length = static fn(string $value): string => strlen($value) < 128
            ? chr(strlen($value))
            : pack('N', strlen($value) | 0x8000_0000);

        $pairs = '';
        foreach ($parameters as $name => $value) {
            $pairs .= $length($name) . $length($value) . $name . $value;
        }

        // FCGI_BEGIN_REQUEST (responder role), FCGI_PARAMS, then an empty FCGI_STDIN.
        fwrite($client, $record(1, pack('nCx5', 1, 0)) . $record(4, $pairs) . $record(4, '') . $record(5, ''));

        $output = '';
        while (true) {
            $header = stream_get_contents($client, 8);
            if (false === $header || 8 !== strlen($header)) {
                break;
            }

            /** @var array{type: int, length: int, padding: int} $fields */
            $fields = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $header);
            $content = $fields['length'] > 0 ? (string) stream_get_contents($client, $fields['length']) : '';
            if ($fields['padding'] > 0) {
                stream_get_contents($client, $fields['padding']);
            }

            if (6 === $fields['type']) {
                $output .= $content;
            }

            if (3 === $fields['type']) {
                break;
            }
        }

        fclose($client);

        return $output;
    }

    public function testIntegerLikeEnvironmentVariableNameIsPassed(): void
    {
        $command = self::phpCommand()
            ->withEnvironmentVariable('123', 'first')
            ->withEnvironmentVariables(['456' => 'second'])
            ->withEnvironmentVariable('PSL_PROCESS_VAR', 'third');

        static::assertSame(
            ['123' => 'first', '456' => 'second', 'PSL_PROCESS_VAR' => 'third'],
            $command->getEnvironmentVariables(),
        );

        $environment = self::childEnvironment($command);

        static::assertSame('first', $environment['123'] ?? null);
        static::assertSame('second', $environment['456'] ?? null);
        static::assertSame('third', $environment['PSL_PROCESS_VAR'] ?? null);
    }

    public function testWithWorkingDirectory(): void
    {
        $command = Command::create('ls')->withWorkingDirectory('/tmp');

        static::assertSame('/tmp', $command->getWorkingDirectory());
    }

    public function testWorkingDirectoryIsUsed(): void
    {
        $tempDir = Env\temp_dir();

        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getcwd();')
            ->withWorkingDirectory($tempDir)
            ->output();

        static::assertSame(Filesystem\canonicalize($tempDir), Filesystem\canonicalize($output->stdout));
    }

    public function testImmutability(): void
    {
        $original = Command::create('git');
        $modified = $original->withArgument('status');

        static::assertSame([], $original->getArguments());
        static::assertSame(['status'], $modified->getArguments());
    }

    public function testSpawnInvalidWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory does not exist.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory('/nonexistent/path/that/does/not/exist')
            ->spawn();
    }

    public function testOutputInvalidWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory('/nonexistent/path/that/does/not/exist')
            ->output();
    }

    public function testStatusInvalidWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory('/nonexistent/path/that/does/not/exist')
            ->status();
    }

    public function testEmptyWorkingDirectoryThrows(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory does not exist.');

        self::phpCommand()->withArgument('-r')->withArgument('echo "hello";')->withWorkingDirectory('')->spawn();
    }

    public function testNullByteInCommand(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Command line contains NULL bytes.');

        Command::create("test\0command")->spawn();
    }

    public function testNullByteInArgument(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Command line contains NULL bytes.');

        self::phpCommand()->withArgument('-r')->withArgument("echo\0injected;")->spawn();
    }

    public function testNullByteInEnvironmentVariableValue(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getenv("PSL_PROCESS_VAR");')
            ->withEnvironmentVariable('PSL_PROCESS_VAR', "visible\0hidden")
            ->spawn();
    }

    public function testNullByteInEnvironmentVariableName(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withEnvironmentVariable("PSL_PROCESS_VAR\0HIDDEN", 'value')
            ->output();
    }

    public function testNullByteInEnvironmentVariablesValue(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withEnvironmentVariables(['PSL_PROCESS_OK' => 'ok', 'PSL_PROCESS_VAR' => "\0"])
            ->status();
    }

    public function testEqualsSignInEnvironmentVariableName(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable name is empty or contains "=".');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getenv("PSL_PROCESS_VAR");')
            ->withEnvironmentVariable('PSL_PROCESS_VAR=injected', 'value')
            ->spawn();
    }

    public function testEmptyEnvironmentVariableName(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable name is empty or contains "=".');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withEnvironmentVariable('', 'value')
            ->spawn();
    }

    public function testInvalidNameInWithoutEnvironmentVariable(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable name is empty or contains "=".');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withoutEnvironmentVariable('PSL_PROCESS_VAR=injected')
            ->spawn();
    }

    public function testIntegerLikeEnvironmentVariableNameIsAccepted(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withEnvironmentVariable('123', 'value')
            ->output();

        static::assertTrue($output->status->isSuccessful());
        static::assertSame('hello', $output->stdout);
    }

    public function testNullByteInWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory(Env\temp_dir() . "\0")
            ->spawn();
    }

    public function testOutputNullByteInWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory(Env\temp_dir() . "\0suffix")
            ->output();
    }

    public function testStatusNullByteInWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory contains NULL bytes.');

        self::phpCommand()->withArgument('-r')->withArgument('echo "hello";')->withWorkingDirectory("\0")->status();
    }

    public function testStdinPiped(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo fgets(STDIN);')
            ->withStdin(Stdio::piped())
            ->spawn();

        $child->getStdin()->writeAll("hello\n");
        $child->getStdin()->close();

        $output = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame("hello\n", $output);
    }

    public function testStdoutPipedCapturesOutput(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "from_stdout";')
            ->withStdout(Stdio::piped())
            ->withStderr(Stdio::null())
            ->spawn();

        $stdout = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame('from_stdout', $stdout);
    }

    public function testStderrPipedCapturesError(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDERR, "from_stderr");')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::piped())
            ->spawn();

        $stderr = $child->getStderr()->readAll();
        $child->wait();

        static::assertSame('from_stderr', $stderr);
    }

    public function testStdoutAndStderrSeparation(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDOUT, "OUT"); fwrite(STDERR, "ERR");')
            ->output();

        static::assertSame('OUT', $output->stdout);
        static::assertSame('ERR', $output->stderr);
    }

    public function testStdoutNull(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "test";')
            ->withStdout(Stdio::null())
            ->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStdout();
    }

    public function testStderrNull(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "test";')
            ->withStderr(Stdio::null())
            ->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStderr();
    }

    public function testDescriptorStdinNullIsDevNull(): void
    {
        // With Stdio::null() for stdin, the child reads EOF immediately.
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('$data = fread(STDIN, 1024); echo strlen($data);')
            ->output();

        static::assertSame('0', $output->stdout);
    }

    public function testHandleStdio(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped(
                'IO\pipe() uses TCP sockets on Windows which are not inheritable by child processes.',
            );
        }

        [$read, $write] = IO\pipe();
        $write->writeAll("handle_input\n");
        $write->close();

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo trim(fgets(STDIN));')
            ->withStdin(Stdio::fromStreamHandle($read))
            ->spawn();

        $stdout = $child->getStdout()->readAll();
        $child->wait();
        $read->close();

        static::assertSame('handle_input', $stdout);
    }

    public function testHandleStdioClosedThrows(): void
    {
        [$read, $write] = IO\pipe();
        $read->close();
        $write->close();

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('The stream handle is closed.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "test";')
            ->withStdin(Stdio::fromStreamHandle($read))
            ->spawn();
    }

    public function testOutputForcesStdinNullAndPipedStdoutStderr(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDOUT, "out"); fwrite(STDERR, "err");')
            ->withStdin(Stdio::piped())
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->output();

        static::assertSame('out', $output->stdout);
        static::assertSame('err', $output->stderr);
    }

    public function testStatusForcesAllNull(): void
    {
        $status = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(42);')
            ->withStdout(Stdio::piped())
            ->withStderr(Stdio::piped())
            ->status();

        static::assertFalse($status->isSuccessful());
        static::assertSame(42, $status->getCode());
    }

    public function testSpawnUsesConfiguredStdio(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "spawned";')
            ->withStdout(Stdio::piped())
            ->withStderr(Stdio::null())
            ->spawn();

        $stdout = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame('spawned', $stdout);

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStderr();
    }

    public function testTtyStdioOnWindows(): void
    {
        if (!OS\is_windows()) {
            static::markTestSkipped('Test is for Windows TTY behavior.');
        }

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('TTY is not supported on Windows.');

        self::phpCommand()->withArgument('-r')->withArgument('echo "test";')->withStdout(Stdio::tty())->spawn();
    }
}
