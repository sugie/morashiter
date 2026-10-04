<?php

namespace App\Services\Crawling;

use JsonException;
use RuntimeException;

class PythonArticleExtractor
{
    private const MAX_INPUT_BYTES = 2 * 1024 * 1024;

    private const MAX_OUTPUT_BYTES = 1024 * 1024;

    private const MAX_ERROR_BYTES = 64 * 1024;

    /**
     * @return array{adapter_code: string, extraction_version: string, url: string, title: string, published_on: ?string, body_text: string}
     */
    public function extract(string $adapterCode, string $html, string $url): array
    {
        $input = json_encode(['adapter_code' => $adapterCode, 'html' => $html, 'url' => $url], JSON_THROW_ON_ERROR);
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Extractor input exceeds 2 MiB.');
        }

        $pipes = [];
        $process = proc_open(
            ['/opt/msnc-venv/bin/python', '/opt/msnc/src/extractor.py'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin', 'HOME' => '/tmp', 'PYTHONDONTWRITEBYTECODE' => '1'],
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Extractor process could not start.');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $stdout = '';
        $stderr = '';
        $inputOffset = 0;
        $startedAt = microtime(true);

        try {
            while (true) {
                if (microtime(true) - $startedAt >= 15) {
                    throw new RuntimeException('Extractor timed out.');
                }
                $read = [];
                if (! feof($pipes[1])) {
                    $read[] = $pipes[1];
                }
                if (! feof($pipes[2])) {
                    $read[] = $pipes[2];
                }
                $write = [];
                if (is_resource($pipes[0])) {
                    if ($inputOffset < strlen($input)) {
                        $write[] = $pipes[0];
                    } else {
                        fclose($pipes[0]);
                    }
                }
                if ($read === [] && $write === []) {
                    break;
                }
                $except = null;
                if (stream_select($read, $write, $except, 0, 100000) === false) {
                    throw new RuntimeException('Extractor I/O failed.');
                }
                foreach ($write as $pipe) {
                    $written = fwrite($pipe, substr($input, $inputOffset, 8192));
                    if ($written === false) {
                        throw new RuntimeException('Extractor input write failed.');
                    }
                    $inputOffset += $written;
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Extractor output read failed.');
                    }
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                        if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
                            throw new RuntimeException('Extractor output exceeds 1 MiB.');
                        }
                    } else {
                        $stderr .= $chunk;
                        if (strlen($stderr) > self::MAX_ERROR_BYTES) {
                            throw new RuntimeException('Extractor diagnostic output is too large.');
                        }
                    }
                }
            }
            do {
                $status = proc_get_status($process);
                if ($status['running']) {
                    usleep(10000);
                }
                if (microtime(true) - $startedAt >= 15) {
                    throw new RuntimeException('Extractor timed out.');
                }
            } while ($status['running']);
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Extractor returned a failure.');
            }
        } catch (\Throwable $exception) {
            proc_terminate($process);
            throw $exception;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }

        try {
            $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Extractor returned invalid JSON.');
        }
        if (! is_array($result)
            || ($result['adapter_code'] ?? null) !== $adapterCode
            || ($result['url'] ?? null) !== $url
            || ! is_string($result['title'] ?? null)
            || trim($result['title']) === ''
            || ! is_string($result['body_text'] ?? null)
            || trim($result['body_text']) === ''
            || ! is_string($result['extraction_version'] ?? null)
            || ! array_key_exists('published_on', $result)
            || (! is_null($result['published_on']) && ! is_string($result['published_on']))) {
            throw new RuntimeException('Extractor returned an invalid result.');
        }

        return $result;
    }
}
