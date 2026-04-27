<?php
declare(strict_types=1);
/**
 * passkey_register.php — WebAuthn credential registration (POST-only JSON)
 *
 * action=get_challenge : returns PublicKeyCredentialCreationOptions JSON
 * action=complete      : verifies attestation and stores credential
 *
 * Requires: session auth (require_login), CSRF on every POST.
 */

require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method']);
    exit;
}

require_login();

csrf_require();

if (!(bool)to_int(ipam_setting('mfa.passkeys_enabled', false))) {
    http_response_code(403);
    echo json_encode(['error' => 'disabled']);
    exit;
}

$cur    = current_user();
$userId = to_int($cur['id']);
$action = to_str($_POST['action'] ?? '');

// ── action: get_challenge ────────────────────────────────────────────────────
if ($action === 'get_challenge') {
    $uStmt = $db->prepare("SELECT username, name, email FROM users WHERE id = :id");
    $uStmt->execute([':id' => $userId]);
    /** @var array<string,mixed>|false $uData */
    $uData = $uStmt->fetch(PDO::FETCH_ASSOC);

    $username    = $uData ? to_str($uData['username']) : to_str($cur['username']);
    $displayName = ($uData && to_str($uData['name']) !== '') ? to_str($uData['name']) : $username;

    // Load lbuchs autoloader before any ByteBuffer / WebAuthn usage.
    $webAuthn      = ipam_passkey_webauthn();
    $existingCreds = ipam_passkey_get_credentials($db, $userId);
    $excludeIds    = [];
    foreach ($existingCreds as $ec) {
        $excludeIds[] = new \lbuchs\WebAuthn\Binary\ByteBuffer(to_str($ec['credential_id']));
    }

    // requireResidentKey='preferred' makes the credential discoverable, which
    // lets password managers (LastPass, 1Password, Bitwarden) save it to their
    // vaults. Hardware keys / platform authenticators that don't support
    // resident keys still register normally because 'preferred' is non-binding.
    $createArgs = $webAuthn->getCreateArgs(
        \base64_encode((string)$userId),
        $username,
        $displayName,
        60,
        'preferred',
        false,
        null,
        $excludeIds
    );

    // Request 'none' attestation so authenticators (especially password
    // manager passkey providers like LastPass) don't include attestation
    // statements. Avoids "no signature found" failures when LastPass emits
    // a malformed packed self-attestation. We don't pin a CA root, so
    // there's no security loss here — we only verify origin / rpIdHash /
    // user-presence flags, which work identically with attestation=none.
    $createArgs->publicKey->attestation = 'none';

    $challengeBin = $webAuthn->getChallenge()->getBinaryString();
    $_SESSION['passkey_reg_challenge'] = $challengeBin;

    // Extract the publicKey sub-object and re-encode ByteBuffer fields as plain
    // base64url so the browser's WebAuthn API can consume them directly (lbuchs
    // ByteBuffer serialises to MIME RFC-2047 encoded-word format by default).
    $pk            = $createArgs->publicKey;
    $pk->challenge = rtrim(strtr(base64_encode($challengeBin), '+/', '-_'), '=');
    if ($pk->user->id instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
        $pk->user->id = rtrim(strtr(base64_encode($pk->user->id->getBinaryString()), '+/', '-_'), '=');
    }
    if (!empty($pk->excludeCredentials)) {
        foreach ($pk->excludeCredentials as &$ec) {
            if (isset($ec->id) && ($ec->id instanceof \lbuchs\WebAuthn\Binary\ByteBuffer)) {
                $ec->id = rtrim(strtr(base64_encode($ec->id->getBinaryString()), '+/', '-_'), '=');
            }
        }
        unset($ec);
    }

    echo json_encode(['ok' => true, 'options' => $pk]);
    exit;
}

// ── action: complete ─────────────────────────────────────────────────────────
if ($action === 'complete') {
    if (empty($_SESSION['passkey_reg_challenge'])) {
        http_response_code(400);
        echo json_encode(['error' => 'no_challenge']);
        exit;
    }

    // JS sends ArrayBuffer values as base64url; lbuchs processCreate expects raw binary.
    $clientDataJSONRaw    = base64_decode(strtr(to_str($_POST['clientDataJSON']    ?? ''), '-_', '+/'));
    $attestationObjectRaw = base64_decode(strtr(to_str($_POST['attestationObject'] ?? ''), '-_', '+/'));
    $credentialName       = mb_substr(trim(to_str($_POST['name'] ?? '')), 0, 100) ?: 'Passkey';

    if ($clientDataJSONRaw === '' || $attestationObjectRaw === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_fields']);
        exit;
    }

    try {
        $challenge  = new \lbuchs\WebAuthn\Binary\ByteBuffer($_SESSION['passkey_reg_challenge']);
        $webAuthn   = ipam_passkey_webauthn();
        $credential = $webAuthn->processCreate(
            $clientDataJSONRaw,
            $attestationObjectRaw,
            $challenge,
            false,
            true,
            false
        );
    } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
        unset($_SESSION['passkey_reg_challenge']);
        http_response_code(400);
        echo json_encode(['error' => 'verification_failed', 'detail' => $e->getMessage()]);
        audit($db, 'mfa.passkey.register_fail', 'user', $userId, substr($e->getMessage(), 0, 200));
        exit;
    }

    unset($_SESSION['passkey_reg_challenge']);

    $credIdBin = $credential->credentialId;  // raw binary string from lbuchs
    $publicKey = $credential->credentialPublicKey;
    $signCount = (int)($credential->signatureCounter ?? 0);

    $st = $db->prepare(
        "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, name)
         VALUES (:uid, :cid, :pk, :sc, :nm)"
    );
    $st->bindValue(':uid', $userId, PDO::PARAM_INT);
    $st->bindValue(':cid', $credIdBin, PDO::PARAM_LOB);
    $st->bindValue(':pk',  $publicKey, PDO::PARAM_STR);
    $st->bindValue(':sc',  $signCount, PDO::PARAM_INT);
    $st->bindValue(':nm',  $credentialName, PDO::PARAM_STR);
    $st->execute();
    $newId = (int)$db->lastInsertId();

    audit($db, 'mfa.passkey.register', 'user', $userId, "name={$credentialName}");

    echo json_encode(['ok' => true, 'id' => $newId, 'name' => htmlspecialchars($credentialName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown_action']);
