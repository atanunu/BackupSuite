<?php
namespace Backup;

class Auth
{
    public static function form(string $error = ''): void
    {
        $csrf = Security::token();
        echo '<!doctype html><title>Login</title><style>
body{font:15px/1.4 system-ui,sans-serif;max-width:24rem;margin:5rem auto}
label{display:block;margin-top:1rem}input{width:100%;padding:.4rem}
button{margin-top:1rem;padding:.5rem 1.2rem;background:#0275d8;color:#fff;border:none;border-radius:4px}
.err{color:#c00}</style>';

        if ($error)
            echo "<p class=err>$error</p>";

        $stage = $_SESSION['stage'] ?? 'USER';
        echo "<form method=post>
              <input type=hidden name=csrf value=$csrf>";

        if ($stage === 'USER') {
            echo '<label>User<br><input name=user autocomplete=username required></label>';
        } elseif ($stage === 'PASS') {
            echo '<label>Password<br><input type=password name=pass autocomplete=current-password required></label>';
        } elseif ($stage === 'TOTP') {
            echo '<label>Authenticator Code<br><input name=totp pattern=\"\\d{6}\" required></label>';
        }

        echo '<button>Login</button></form>';
        exit;
    }

    public static function handlePost(): void
    {
        $users = Bootstrap::cfg('users');
        $stage = $_SESSION['stage'] ?? 'USER';

        if ($stage === 'USER') {
            $_SESSION['tmp_user'] = $_POST['user'] ?? '';
            $_SESSION['stage'] = 'PASS';
            self::form();  // re-render password form
        } elseif ($stage === 'PASS') {
            $u = $_SESSION['tmp_user'] ?? '';
            $pw = $_POST['pass'] ?? '';
            if (isset($users[$u]) && password_verify($pw, $users[$u]['pass_hash'])) {
                $_SESSION['user'] = $u;
                $_SESSION['role'] = $users[$u]['role'];
                if ($users[$u]['totp']) {
                    $_SESSION['stage'] = 'TOTP';
                    self::form();
                }
                $_SESSION['stage'] = 'OK';
                header('Location: ./');
                exit;
            }
            self::form('Invalid credentials');
        } elseif ($stage === 'TOTP') {
            $u = $_SESSION['user'];
            if (Security::totpVerify(Bootstrap::cfg('users')[$u]['totp'], $_POST['totp'] ?? '')) {
                $_SESSION['stage'] = 'OK';
                header('Location: ./');
                exit;
            }
            self::form('Bad code');
        }
    }
}
