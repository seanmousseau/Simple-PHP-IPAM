<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class WebhookTestFireAuditTest extends TestCase
{
    public function testAuditDetailsContainsHostOnly(): void
    {
        $detail = ipam_webhook_test_fire_audit_detail(42, 'https://hooks.example.com/path?token=secret&xss=<script>');
        $this->assertSame('id=42 host=hooks.example.com', $detail);
    }

    public function testInvalidUrlFallback(): void
    {
        $this->assertSame('id=7 host=(invalid)', ipam_webhook_test_fire_audit_detail(7, 'not a url'));
    }

    public function testHostLengthCapped(): void
    {
        $longHost = str_repeat('a', 200) . '.example.com';
        $detail = ipam_webhook_test_fire_audit_detail(1, "https://{$longHost}/path");
        $this->assertLessThanOrEqual(120, strlen($detail));
    }
}
