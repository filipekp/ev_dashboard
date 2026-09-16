<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Zajišťuje veřejnou registraci, ověření e-mailu a reCAPTCHA v3.
 *
 * Nově registrovaný účet vzniká jako neaktivní správce vozidel. Aktivuje se
 * až potvrzením jednorázového odkazu zaslaného na registrační e-mail.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class RegistrationService
{
    /** @var PDO */
    private $pdo;

    /** @var Config */
    private $config;

    public function __construct(PDO $pdo, Config $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * @return array{user_id:int,verification_url:string}
     */
    public function register(
        string $name,
        string $email,
        string $password,
        string $passwordAgain,
        string $recaptchaToken,
        ?string $remoteIp = null
    ): array {
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new RuntimeException('Zadejte jméno a příjmení.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new RuntimeException('Zadejte platný e-mail.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Heslo musí mít alespoň 8 znaků.');
        }
        if ($password !== $passwordAgain) {
            throw new RuntimeException('Zadaná hesla se neshodují.');
        }

        $this->verifyRecaptcha($recaptchaToken, $remoteIp);

        $parentAdminId = max(1, (int)$this->config->get('registration.parent_admin_id', 1));
        $admin = $this->findActiveAdmin($parentAdminId);
        if (!$admin) {
            throw new RuntimeException('Registrace nyní není dostupná. Kontaktujte administrátora aplikace.');
        }

        $existing = $this->pdo->prepare('SELECT id,active,email_verified_at FROM users WHERE email=? LIMIT 1');
        $existing->execute([$email]);
        $existingUser = $existing->fetch();
        if ($existingUser) {
            throw new RuntimeException('Účet s tímto e-mailem již existuje.');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $validMinutes = max(15, (int)$this->config->get('registration.verification_minutes', 1440));
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . $validMinutes . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO users(name,email,password_hash,role,parent_user_id,active,email_verified_at) '
                . 'VALUES(?,?,?,?,?,0,NULL)'
            );
            $insert->execute([
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'manager',
                $parentAdminId,
            ]);
            $userId = (int)$this->pdo->lastInsertId();

            $tokenInsert = $this->pdo->prepare(
                'INSERT INTO registration_verification_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)'
            );
            $tokenInsert->execute([$userId, $tokenHash, $expiresAt]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $verificationUrl = $this->verificationUrl($token);

        if (!$this->sendVerificationEmail($email, $name, $verificationUrl, $validMinutes)) {
            $this->deleteUnverifiedUser($userId);
            throw new RuntimeException('Potvrzovací e-mail se nepodařilo odeslat. Registrace nebyla dokončena.');
        }

        // Notifikace administrátorovi nesmí zablokovat úspěšnou registraci.
        $this->sendAdminNotification((string)$admin['email'], (string)$admin['name'], $name, $email);

        return [
            'user_id' => $userId,
            'verification_url' => $verificationUrl,
        ];
    }

    /** @return array<string,mixed> */
    public function verifyEmail(string $token): array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('Potvrzovací odkaz není platný.');
        }

        $hash = hash('sha256', $token);
        $q = $this->pdo->prepare(
            'SELECT rvt.id token_id,rvt.user_id,u.name,u.email,u.active,u.email_verified_at '
            . 'FROM registration_verification_tokens rvt '
            . 'JOIN users u ON u.id=rvt.user_id '
            . 'WHERE rvt.token_hash=? AND rvt.used_at IS NULL AND rvt.expires_at>=NOW() LIMIT 1'
        );
        $q->execute([$hash]);
        $row = $q->fetch();
        if (!$row) {
            throw new RuntimeException('Potvrzovací odkaz je neplatný nebo již vypršel.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE users SET active=1,email_verified_at=NOW() WHERE id=?'
            )->execute([(int)$row['user_id']]);
            $this->pdo->prepare(
                'UPDATE registration_verification_tokens SET used_at=NOW() WHERE id=?'
            )->execute([(int)$row['token_id']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    private function verifyRecaptcha(string $token, ?string $remoteIp): void
    {
        $siteKey = trim((string)$this->config->get('recaptcha.site_key', ''));
        $secretKey = trim((string)$this->config->get('recaptcha.secret_key', ''));
        if ($siteKey === '' || $secretKey === '') {
            throw new RuntimeException('Registrace není správně nakonfigurována – chybí reCAPTCHA klíče.');
        }
        if ($token === '') {
            throw new RuntimeException('Ověření reCAPTCHA se nepodařilo. Zkuste formulář odeslat znovu.');
        }

        $payload = [
            'secret' => $secretKey,
            'response' => $token,
        ];
        if ($remoteIp) {
            $payload['remoteip'] = $remoteIp;
        }

        $raw = $this->postForm('https://www.google.com/recaptcha/api/siteverify', $payload);
        $result = json_decode($raw, true);
        if (!is_array($result) || empty($result['success'])) {
            throw new RuntimeException('Ověření reCAPTCHA se nepodařilo. Zkuste to prosím znovu.');
        }

        if (($result['action'] ?? '') !== 'register') {
            throw new RuntimeException('Ověření reCAPTCHA nebylo platné pro registrační formulář.');
        }

        $minimumScore = (float)$this->config->get('recaptcha.minimum_score', 0.5);
        if ((float)($result['score'] ?? 0.0) < $minimumScore) {
            throw new RuntimeException('Registrace byla bezpečnostním filtrem vyhodnocena jako podezřelá. Zkuste to později.');
        }

        $expectedHostname = trim((string)$this->config->get('recaptcha.expected_hostname', ''));
        if ($expectedHostname !== '' && strcasecmp((string)($result['hostname'] ?? ''), $expectedHostname) !== 0) {
            throw new RuntimeException('Ověření reCAPTCHA proběhlo pro jinou doménu.');
        }
    }

    /** @param array<string,string> $payload */
    private function postForm(string $url, array $payload): string
    {
        $body = http_build_query($payload, '', '&');

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Nelze inicializovat ověření reCAPTCHA.');
            }
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if (!is_string($response) || $status < 200 || $status >= 300) {
                throw new RuntimeException('Služba reCAPTCHA je dočasně nedostupná.');
            }
            return $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Služba reCAPTCHA je dočasně nedostupná.');
        }
        return $response;
    }

    /** @return array<string,mixed>|null */
    private function findActiveAdmin(int $adminId): ?array
    {
        $q = $this->pdo->prepare('SELECT id,name,email FROM users WHERE id=? AND role=? AND active=1 LIMIT 1');
        $q->execute([$adminId, 'admin']);
        $admin = $q->fetch();
        return $admin ?: null;
    }

    private function verificationUrl(string $token): string
    {
        return $this->baseUrl() . '/verify-registration.php?token=' . rawurlencode($token);
    }

    private function baseUrl(): string
    {
        $base = rtrim((string)$this->config->get('app.base_url', ''), '/');
        if ($base !== '') {
            return $base;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return $scheme . '://' . $host . $path;
    }

    private function sendVerificationEmail(string $to, string $name, string $url, int $validMinutes): bool
    {
        $appName = (string)$this->config->get('app.name', 'EV Stats');
        $subject = 'Potvrzení registrace – ' . $appName;
        $hours = max(1, (int)ceil($validMinutes / 60));
        $body = "Dobrý den {$name},\n\n"
            . "děkujeme za registraci do {$appName}. Pro aktivaci účtu potvrďte svůj e-mail otevřením následujícího odkazu:\n\n"
            . "{$url}\n\n"
            . "Odkaz je platný přibližně {$hours} hodin. Pokud jste registraci nevytvořili vy, zprávu ignorujte.\n";

        return $this->sendMail($to, $subject, $body);
    }

    private function sendAdminNotification(
        string $adminEmail,
        string $adminName,
        string $registeredName,
        string $registeredEmail
    ): bool {
        $notifyEmail = trim((string)$this->config->get('registration.admin_notify_email', ''));
        $to = $notifyEmail !== '' ? $notifyEmail : $adminEmail;
        $appName = (string)$this->config->get('app.name', 'EV Stats');
        $subject = 'Nová registrace uživatele – ' . $appName;
        $body = "Dobrý den {$adminName},\n\n"
            . "v aplikaci {$appName} byla vytvořena nová registrace.\n\n"
            . "Jméno: {$registeredName}\n"
            . "E-mail: {$registeredEmail}\n"
            . "Role: Správce vozidel\n"
            . "Stav: čeká na potvrzení e-mailu\n";

        return $this->sendMail($to, $subject, $body);
    }

    private function sendMail(string $to, string $subject, string $body): bool
    {
        $from = trim((string)$this->config->get('mail.from', ''));
        if ($from === '') {
            return false;
        }

        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private function deleteUnverifiedUser(int $userId): void
    {
        try {
            $this->pdo->prepare('DELETE FROM users WHERE id=? AND active=0 AND email_verified_at IS NULL')->execute([$userId]);
        } catch (Throwable $e) {
            // Původní chyba o odeslání e-mailu je pro uživatele důležitější.
        }
    }
}
