<?php

namespace App\Services\Execution;

use App\Services\Execution\Contracts\Executor;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Runs every command in a throw-away container; nothing executes on the host or in the app container. */
class DockerExecutor implements Executor
{
    public function __construct(private readonly PathMapper $paths) {}

    public function run(ExecutionRequest $request): ExecutionResult
    {
        $cmd = [
            'docker', 'run', '--rm',
            '--name', 'factory-'.bin2hex(random_bytes(5)),
            '--memory', (string) config('factory.container.memory'),
            '--cpus', (string) config('factory.container.cpus'),
            '--network', (string) config('factory.container.network'),
            '-v', $this->paths->toHost($request->workspace).':/workspace',
            '-w', '/workspace',
        ];

        // Run as the app's own user so files written into the workspace stay owned by the app.
        if (function_exists('posix_getuid')) {
            array_push($cmd, '--user', posix_getuid().':'.posix_getgid());
        }

        foreach ($request->mounts as $local => $inContainer) {
            array_push($cmd, '-v', $this->paths->toHost($local).':'.$inContainer.':ro');
        }

        foreach ([...config('factory.container.env', []), ...$request->env] as $key => $value) {
            array_push($cmd, '-e', "{$key}={$value}");
        }

        // Override the image entrypoint so images like Sail's can't intercept the command.
        array_push($cmd, '--entrypoint', 'sh', $request->image, '-c', $request->command);

        $process = new Process($cmd, timeout: $request->timeout);
        $started = microtime(true);

        try {
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();
            $exit = $process->getExitCode() ?? 1;
        } catch (ProcessTimedOutException) {
            $output = $process->getOutput().$process->getErrorOutput()."\n[factory] Timed out after {$request->timeout}s.";
            $exit = 124;
        }

        return new ExecutionResult($exit, $output, (int) ((microtime(true) - $started) * 1000));
    }
}
