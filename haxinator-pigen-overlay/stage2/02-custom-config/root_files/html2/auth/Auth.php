<?php
/**
 * Simple session-based authentication used by Control Panel.
 *
 * – Single username/password pair supplied at construction (or via env/constants).
 * – Sets $_SESSION['logged_in']=true on successful login.
 * – Provides renderLoginPage() which outputs the classic coloured-matrix canvas UI.
 *
 * NOTE: No CSRF validation here – login happens before a token exists. All
 *       subsequent pages already rely on the app's csrf() helper.
 */
declare(strict_types=1);

class Auth
{
    private string $user;
    private string $pass;
    private string $error = '';

    public function __construct(string $user, string $pass)
    {
        $this->user = $user;
        $this->pass = $pass;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Handle logout via ?logout
        if (isset($_GET['logout'])) {
            session_unset();
            session_destroy();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        // Handle login POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_username'], $_POST['login_password'])) {
            if ($_POST['login_username'] === $this->user && $_POST['login_password'] === $this->pass) {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            $this->error = 'Invalid username or password';
        }
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['logged_in']);
    }

    public function getLoginError(): string
    {
        return $this->error;
    }

    /**
     * Return full HTML for the login page (matrix backdrop + bootstrap card)
     */
    public static function renderLoginPage(string $errorMsg = ''): string
    {
        ob_start();
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Control Panel – Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/login.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="height:100vh;">
    <canvas id="c"></canvas>
    <div class="login-container">
        <div class="card shadow-sm p-4" style="min-width:300px;">
            <h4 class="mb-3">Control Panel Login</h4>
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="login_username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="login_password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>

<script>
// Matrix animation (ported verbatim from the old login page)
var c = document.getElementById('c');
var ctx = c.getContext('2d');
function resize() {
  c.height = window.innerHeight;
  c.width  = window.innerWidth;
}
resize();
window.addEventListener('resize', resize);
var phrases = [
  'Haxinate!', 'Hack the Planet!', '0x41414141',
  'All Your WiFi', 'sudo make me a sandwich',
  'rm -rf /', 'hack all the things!', 'grep -R lol'
];
var colors = ['#65ddb7', '#3fcdbd', '#22d3ee', '#1cb8f6', '#3a9fbd', '#3a7cbd'];
var font_size = 10;
var columns;
var drops;
function initDrops() {
  columns = Math.floor(c.width / font_size);
  drops = [];
  for (var x = 0; x < columns; x++) {
    drops[x] = { y: 1, p: Math.floor(Math.random() * phrases.length), c: 0 };
  }
}
initDrops();
function draw() {
  ctx.fillStyle = 'rgba(0, 0, 0, 0.04)';
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.font = font_size + 'px monospace';
  for (var i = 0; i < drops.length; i++) {
    var d = drops[i];
    var phrase = phrases[d.p];
    var char = phrase.charAt(d.c);
    ctx.fillStyle = colors[i % colors.length];
    ctx.fillText(char, i * font_size, d.y * font_size);
    d.y++;
    d.c++;
    if (d.c >= phrase.length) { d.c = 0; }
    if (d.y * font_size > c.height && Math.random() > 0.97) {
      d.y = 0;
      d.p = Math.floor(Math.random() * phrases.length);
      d.c = 0;
    }
  }
}
setInterval(draw, 35);
</script>
</body>
</html>
<?php
        return ob_get_clean();
    }
} 