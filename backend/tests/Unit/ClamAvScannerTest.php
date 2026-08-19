<?php

namespace Tests\Unit;

use App\Exceptions\VirusScanException;
use App\Services\Antivirus\ClamAvScanner;
use PHPUnit\Framework\TestCase;

class ClamAvScannerTest extends TestCase
{
    private function scanner(): ClamAvScanner
    {
        return new ClamAvScanner('tcp://127.0.0.1:3310');
    }

    public function test_ok_reply_is_clean(): void
    {
        $this->assertNull($this->scanner()->interpretResponse("stream: OK\0"));
    }

    public function test_found_reply_returns_the_signature(): void
    {
        $this->assertSame(
            'Eicar-Test-Signature',
            $this->scanner()->interpretResponse("stream: Eicar-Test-Signature FOUND\0"),
        );
    }

    public function test_error_reply_throws(): void
    {
        $this->expectException(VirusScanException::class);
        $this->scanner()->interpretResponse('INSTREAM size limit exceeded. ERROR');
    }

    public function test_empty_reply_throws(): void
    {
        $this->expectException(VirusScanException::class);
        $this->scanner()->interpretResponse('');
    }
}
