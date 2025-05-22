<?php
/**
 * Authentication handling
 * 
 * Moved from /var/www/html/auth.php to /var/www/html/auth/Auth.php
 * 
 * Usage (example in other files):
 *   require_once __DIR__ . '/auth/Auth.php';
 *   $auth = new Auth($config['username'], $config['password']);
 */

// Include security framework if not already included
if (!class_exists('CSRFProtection')) {
    require_once __DIR__ . '/../security/bootstrap.php';
}

class Auth
{
    private $username;
    private $password;
    private $login_error = '';

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;

        // Handle logout
        if (isset($_GET['logout'])) {
            session_unset();
            session_destroy();
            header("Location: {$_SERVER['PHP_SELF']}");
            exit;
        }

        // Handle login
        if (isset($_POST['login_username']) && isset($_POST['login_password'])) {
            // Skip CSRF validation for login requests only
            // This is because the login form is shown before the user has a session
            // and thus cannot generate a valid CSRF token
            
            if ($_POST['login_username'] === $this->username && $_POST['login_password'] === $this->password) {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                header("Location: {$_SERVER['PHP_SELF']}");
                exit;
            } else {
                $this->login_error = "Invalid username or password.";
            }
        }
    }

    public function isLoggedIn()
    {
        return !empty($_SESSION['logged_in']);
    }

    public function getLoginError()
    {
        return $this->login_error;
    }
}
