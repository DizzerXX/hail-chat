<?php

namespace App\Contracts;

interface VirusScanner
{
    /**
     * Scan a file on disk.
     *
     * @return string|null  null when clean; the signature name when infected
     *
     * @throws \App\Exceptions\VirusScanException  when the scan cannot be completed
     */
    public function scan(string $path): ?string;
}
