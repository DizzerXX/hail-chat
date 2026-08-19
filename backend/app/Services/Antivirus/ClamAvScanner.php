<?php

namespace App\Services\Antivirus;

use App\Contracts\VirusScanner;
use App\Exceptions\VirusScanException;

/**
 * ClamAV scanner over the clamd INSTREAM protocol. Streams the file's bytes to
 * clamd (a tcp:// or unix:// socket), so there's no shared filesystem to arrange
 * and no extra Composer dependency.
 */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 8192;

    public function __construct(
        private string $socket,
        private int $timeout = 30,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('attachments.av.socket'),
            (int) config('attachments.av.timeout', 30),
        );
    }

    public function scan(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new VirusScanException("Cannot read file for scanning: {$path}");
        }

        $connection = @stream_socket_client($this->socket, $errno, $errstr, $this->timeout);
        if ($connection === false) {
            fclose($handle);
            throw new VirusScanException("Cannot reach clamd at {$this->socket}: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, $this->timeout);

        try {
            if (@fwrite($connection, "zINSTREAM\0") === false) {
                throw new VirusScanException('Failed to start a clamd INSTREAM.');
            }

            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);
                if ($chunk === false) {
                    throw new VirusScanException('Failed reading the file while scanning.');
                }
                if ($chunk === '') {
                    continue;
                }
                // Each chunk is a 4-byte big-endian length followed by the bytes.
                if (@fwrite($connection, pack('N', strlen($chunk)).$chunk) === false) {
                    throw new VirusScanException('Failed streaming the file to clamd.');
                }
            }

            // A zero-length chunk terminates the stream.
            @fwrite($connection, pack('N', 0));

            $response = '';
            while (! feof($connection)) {
                $buffer = fread($connection, 4096);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                $response .= $buffer;
            }

            if (! empty(stream_get_meta_data($connection)['timed_out'])) {
                throw new VirusScanException('clamd scan timed out.');
            }

            return $this->interpretResponse($response);
        } finally {
            fclose($handle);
            fclose($connection);
        }
    }

    /**
     * Map a clamd INSTREAM reply to a result.
     *
     * @return string|null  null = clean; the signature name when infected
     *
     * @throws VirusScanException  on an error reply
     */
    public function interpretResponse(string $response): ?string
    {
        $line = trim(str_replace("\0", '', $response));

        if ($line === '') {
            throw new VirusScanException('Empty response from clamd.');
        }

        if (str_ends_with($line, 'OK')) {
            return null;
        }

        if (str_ends_with($line, 'FOUND')) {
            // "stream: Eicar-Test-Signature FOUND" -> "Eicar-Test-Signature"
            $colon = strpos($line, ':');
            $signature = trim(substr($line, $colon !== false ? $colon + 1 : 0));
            $signature = trim(substr($signature, 0, -strlen('FOUND')));

            return $signature !== '' ? $signature : 'unknown';
        }

        throw new VirusScanException("clamd error: {$line}");
    }
}
