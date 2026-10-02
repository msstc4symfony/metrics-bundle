<?php

declare(strict_types=1);

// Minimal RESP server for reconnect tests: an empty Redis that accepts every write; appends
// "COMMAND arg1 arg2" lines to the log file. Usage: php resp-server.php <port> <log-file>

[, $port, $log] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, $errorMessage . PHP_EOL);

    exit(1);
}

$clients = [];
$buffers = [];
// A server orphaned by a crashed test run must not live forever.
$deadline = time() + 60;
while (time() < $deadline) {
    $read = [$server, ...$clients];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 1) < 1) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server);
            if ($client !== false) {
                $clients[(int) $client] = $client;
            }

            continue;
        }

        $data = fread($socket, 65536);
        if ($data === '' || $data === false) {
            fclose($socket);
            unset($clients[(int) $socket], $buffers[(int) $socket]);

            continue;
        }

        // A request may arrive split over several reads: only complete frames are answered.
        $buffer = ($buffers[(int) $socket] ?? '') . $data;
        $replies = '';
        $frameStart = 0;
        while (($frame = parseFrame($buffer, $frameStart)) !== null) {
            [$arguments, $frameStart] = $frame;
            $command = strtoupper((string) array_shift($arguments));
            // EVAL carries the whole Lua script; its name is enough for the log.
            $logged = $command === 'EVAL' ? [] : $arguments;
            file_put_contents($log, trim($command . ' ' . implode(' ', $logged)) . PHP_EOL, FILE_APPEND);
            $replies .= reply($command, \count($arguments));
        }

        $buffers[(int) $socket] = substr($buffer, $frameStart);
        fwrite($socket, $replies);
    }
}

/**
 * @return array{list<string>, int}|null arguments and the offset after the frame, null when incomplete
 */
function parseFrame(string $buffer, int $offset): ?array
{
    if (preg_match('/\G\*(\d+)\r\n/', $buffer, $header, 0, $offset) !== 1) {
        return null;
    }

    $offset += \strlen($header[0]);
    $arguments = [];
    for ($i = 0; $i < (int) $header[1]; $i++) {
        if (preg_match('/\G\$(\d+)\r\n/', $buffer, $length, 0, $offset) !== 1) {
            return null;
        }

        $offset += \strlen($length[0]);
        if (\strlen($buffer) < $offset + (int) $length[1] + 2) {
            return null;
        }

        $arguments[] = substr($buffer, $offset, (int) $length[1]);
        $offset += (int) $length[1] + 2;
    }

    return [$arguments, $offset];
}

function reply(string $command, int $argumentCount): string
{
    return match ($command) {
        'PING' => "+PONG\r\n",
        'GET' => "\$-1\r\n",
        'MGET' => '*' . $argumentCount . "\r\n" . str_repeat("\$-1\r\n", $argumentCount),
        'SMEMBERS', 'KEYS', 'HGETALL' => "*0\r\n",
        'EVAL', 'EVALSHA', 'EXISTS', 'DEL', 'UNLINK' => ":1\r\n",
        default => "+OK\r\n",
    };
}
