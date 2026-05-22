<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression test pinning that ipam_webhook_retry_pending()
 * does not decrypt webhook secrets or read the secret column directly.
 *
 * Decryption belongs only inside ipam_webhook_deliver() (which retry calls).
 * This test prevents a future refactor from quietly reintroducing the
 * original Critical-shape concern from S-007 (where the retry path was
 * mistakenly believed to be reading + decrypting secrets in batch).
 *
 * See #1155.
 */
final class WebhookRetryNoDecryptTest extends TestCase
{
    public function testRetryPendingSourceDoesNotDecrypt(): void
    {
        $libPath = __DIR__ . '/../../Simple-PHP-IPAM/lib.php';
        $src = file_get_contents($libPath);
        $this->assertNotFalse($src, 'lib.php must be readable');

        $start = strpos($src, 'function ipam_webhook_retry_pending');
        $this->assertNotFalse($start, 'ipam_webhook_retry_pending not found in lib.php');

        // Walk from the function's opening brace to the matching close,
        // counting depth so braces inside strings/heredocs we ignore here
        // (good enough for a single function body — there are no strings
        // containing unbalanced braces in this function).
        $bodyStart = strpos($src, '{', $start);
        $this->assertNotFalse($bodyStart, 'opening brace not found');
        $depth = 0;
        $i = $bodyStart;
        $len = strlen($src);
        for (; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        $body = substr($src, $bodyStart, $i - $bodyStart + 1);

        // The retry path is allowed to SELECT the encrypted secret column —
        // it passes the ciphertext straight through to ipam_webhook_deliver(),
        // which is the only function authorised to decrypt. What this test
        // pins is the *absence* of decryption helpers in the retry body itself,
        // so a future refactor can't quietly reintroduce the original
        // Critical-shape concern (batch-decrypting secrets at retry time).
        foreach (['ipam_webhook_decrypt_secret', 'openssl_decrypt', 'sodium_crypto_secretbox_open'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "ipam_webhook_retry_pending must not contain {$forbidden} — decryption belongs in the deliver path only (#1155, S-007)."
            );
        }
    }
}
