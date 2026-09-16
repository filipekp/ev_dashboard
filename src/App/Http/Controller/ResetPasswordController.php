<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Dokončení obnovy hesla jednorázovým, hashovaným tokenem.
 */
final class ResetPasswordController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $pdo = $this->app->pdo();
        $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        $row = null;
        $error = '';

        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $query = $pdo->prepare(
                'SELECT prt.id,prt.user_id,u.email '
                . 'FROM password_reset_tokens prt '
                . 'JOIN users u ON u.id=prt.user_id '
                . 'WHERE prt.token_hash=? AND prt.used_at IS NULL '
                . 'AND prt.expires_at>NOW() AND u.active=1 LIMIT 1'
            );
            $query->execute([hash('sha256', $token)]);
            $row = $query->fetch();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->app->session()->verifyCsrf();
                if (!$row) {
                    throw new RuntimeException('Odkaz je neplatný nebo již vypršel.');
                }

                $password = (string)($_POST['password'] ?? '');
                $passwordAgain = (string)($_POST['password_again'] ?? '');
                $minimumLength = max(8, (int)$this->app->config()->get('security.minimum_password_length', 12));
                if (strlen($password) < $minimumLength) {
                    throw new RuntimeException('Heslo musí mít alespoň ' . $minimumLength . ' znaků.');
                }
                if ($password !== $passwordAgain) {
                    throw new RuntimeException('Hesla se neshodují.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
                    password_hash($password, PASSWORD_DEFAULT),
                    (int)$row['user_id'],
                ]);
                $pdo->prepare(
                    'UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL'
                )->execute([(int)$row['user_id']]);
                $pdo->commit();

                session_regenerate_id(true);
                $this->app->session()->rotateCsrf();
                $_SESSION['_last_regenerated'] = time();
                $this->app->session()->flash('Heslo bylo změněno. Nyní se můžete přihlásit.');
                Http::redirect('login.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }

        $this->app->template()->render('reset-password', [
            'app' => $this->app,
            'token' => $token,
            'row' => $row,
            'error' => $error,
        ]);
    }
}
