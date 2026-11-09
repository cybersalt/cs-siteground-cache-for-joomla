<?php
/**
 * Test harness: runs the REAL SgCache plugin class through Joomla 5/6's
 * render -> compress -> onBeforeRespond -> respond -> onAfterRespond order,
 * with minimal stubs, under `php -S`, so the real HTTP headers and body can
 * be inspected. Pretends to be on SiteGround. Not shipped in the package.
 *
 * Query: s=<scenario> plus whatever query string the "visitor" sent.
 * The harness-only parameter s is removed before the plugin sees $_GET.
 * Run it with tests/run-harness.sh.
 */
namespace {
    define('_JEXEC', 1);
    define('JPATH_ROOT', __DIR__ . '/root');
    define('JPATH_PLUGINS', JPATH_ROOT . '/plugins');
    define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
    if (!is_dir(JPATH_ROOT)) mkdir(JPATH_ROOT, 0777, true);

    $GLOBALS['H_S'] = $_GET['s'] ?? 'default';
    unset($_GET['s']);
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    $GLOBALS['H_TOKEN'] = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
}

namespace Joomla\Event {
    interface SubscriberInterface { public static function getSubscribedEvents(): array; }
    final class Priority { public const MIN = -3; public const LOW = -2; public const NORMAL = 0; public const MAX = 3; }
    class Event {
        public function __construct(private string $n = '', private array $a = []) {}
        public function getName() { return $this->n; }
        public function getArguments() { return $this->a; }
        public function getArgument($k, $d = null) { return $this->a[$k] ?? $d; }
        public function setArgument($k, $v) { $this->a[$k] = $v; }
    }
}

namespace Joomla\CMS\Uri {
    class Uri {
        public static function getInstance() { return new self; }
        public function toString(array $parts = []) { return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); }
        public function getQuery($asArray = false) { return $asArray ? $_GET : $_SERVER['QUERY_STRING']; }
        public function getHost() { return 'localhost'; }
        public static function root($pathonly = false) { return $pathonly ? '' : 'http://localhost/'; }
        public static function base() { return '/'; }
    }
}

namespace Joomla\CMS\Language { class Text { public static function _($s) { return $s; } public static function sprintf($s, ...$a) { return $s . ':' . implode(',', $a); } } }
namespace Joomla\CMS\Session { class Session { public static function getFormToken() { return $GLOBALS['H_TOKEN']; } } }
namespace Joomla\CMS\Router { class Route { public const TLS_IGNORE = 0; } }
namespace Joomla\Database { interface DatabaseInterface {} }
namespace Joomla\CMS {
    class Factory {
        public static function getApplication() { return $GLOBALS['H_APP']; }
    }
}

namespace Joomla\CMS\Plugin {
    class CMSPlugin {
        public $params;
        protected $app;
        protected $_name = 'sgcache';
        public function __construct($app, array $params) { $this->app = $app; $this->params = new \HarnessParams($params); }
        public function getApplication() { return $this->app; }
        public function loadLanguage() {}
    }
}

namespace Cybersalt\Plugin\System\SgCache {
    // Pretend we are on SiteGround (the real class checks for the SG socket)
    class SiteToolsClient {
        public static function isSiteGround(): bool { return true; }
        public static function flushDynamicCache($h, $p) { return true; }
    }
}

namespace {
    class HarnessParams {
        public function __construct(private array $p) {}
        public function get($k, $d = null) { return array_key_exists($k, $this->p) ? $this->p[$k] : $d; }
    }
    class HarnessServer { public function getString($k, $d = '') { return (string) ($_SERVER[$k] ?? $d); } }
    class HarnessCookie { public function getString($k, $d = '') { return (string) ($_COOKIE[$k] ?? $d); } }
    class HarnessSession { public function getName() { return '7574fe4a1884189c1e5b41dea8210c1f'; } }
    class HarnessInput {
        public $server;
        public $cookie;
        public function __construct() { $this->server = new HarnessServer; $this->cookie = new HarnessCookie; }
        public function getMethod() { return $_SERVER['REQUEST_METHOD']; }
        public function get($k, $d = null) { return $_GET[$k] ?? $d; }
    }
    class HarnessUser { public function __construct(public bool $guest) {} }
    class HarnessDoc { public function __construct(private string $t) {} public function getType() { return $this->t; } }
    class HarnessResponse { public function getStatusCode() { return 200; } }
    class HarnessMenu { public function __construct(private ?int $id) {} public function getActive() { return $this->id ? (object) ['id' => $this->id] : null; } }
    class HarnessApp {
        public $input;
        public $mimeType = 'text/html';
        private array $headers = [];
        private string $body = '';
        private ?HarnessDoc $doc;
        public function __construct(private bool $guest, string $docType, private ?int $menuId = 101) { $this->input = new HarnessInput; $this->doc = new HarnessDoc($docType); }
        public function isClient($c) { return $c === 'site'; }
        public function getIdentity() { return new HarnessUser($this->guest); }
        public function getDocument() { return $this->doc; }
        public function getResponse() { return new HarnessResponse; }
        public function getMenu() { return new HarnessMenu($this->menuId); }
        public function getSession() { return new HarnessSession; }
        public array $queue = [];
        public function getMessageQueue($clear = false) { return $this->queue; }
        public function get($k, $d = null) { return ['secret' => 'harness-secret', 'tmp_path' => JPATH_ROOT . '/tmp', 'log_path' => JPATH_ROOT . '/administrator/logs'][$k] ?? $d; }
        public function getBody() { return $this->body; }
        public function setBody($b) { $this->body = (string) $b; }
        public function setHeader($name, $value, $replace = false) {
            if ($replace) $this->headers = array_values(array_filter($this->headers, fn($h) => strtolower($h['name']) !== strtolower($name)));
            $this->headers[] = ['name' => $name, 'value' => (string) $value];
        }
        public function getHeaders() { return $this->headers; }
        private bool $cacheable = false;
        public function allowCache($allow = null) { if ($allow !== null) $this->cacheable = (bool) $allow; return $this->cacheable; }
        private function has($n) { foreach ($this->headers as $h) if (strtolower($h['name']) === strtolower($n)) return true; return false; }
        // Mirrors AbstractWebApplication::compress() with gzip
        public function compress() {
            $this->body = gzencode($this->body, 4, FORCE_GZIP);
            $this->setHeader('Content-Encoding', 'gzip');
            $this->setHeader('Vary', 'Accept-Encoding');
        }
        // Mirrors AbstractWebApplication::respond() + sendHeaders()
        public function respond() {
            if (!$this->has('Content-Type')) $this->setHeader('Content-Type', $this->mimeType . '; charset=utf-8');
            if (!$this->cacheable) {
                $this->setHeader('Expires', 'Wed, 17 Aug 2005 00:00:00 GMT', true);
                $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0', false);
                $this->setHeader('Pragma', 'no-cache');
            } elseif (!$this->has('Expires')) {
                $this->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + 900) . ' GMT');
            }
            if (!headers_sent()) foreach ($this->headers as $h) {
                if (strtolower($h['name']) === 'status') http_response_code((int) $h['value']);
                else header($h['name'] . ': ' . $h['value']);
            }
            echo $this->body;
        }
    }

    $s    = $GLOBALS['H_S'];
    $repo = dirname(__DIR__, 2);
    foreach (['Logger', 'Decisions', 'FormTokens', 'PageProbe'] as $c) require $repo . '/src/' . $c . '.php';
    require $repo . '/src/Extension/SgCache.php';

    // What Joomla would render for this request.
    // The SEF router turns a .feed / .json suffix into the format.
    $format  = $_GET['format'] ?? (preg_match('/\.(feed|json)$/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m) ? $m[1] : 'html');
    $docType = match ($s) { 'raw' => 'raw', default => in_array($format, ['feed', 'json', 'raw'], true) ? $format : 'html' };

    $params = ['enable_logging' => 1, 'verbose_logging' => 1, 'log_file' => 'sg-' . $s . '.log',
        'excluded_urls' => "/members\n/shop/*/checkout\n/about$\n/search?q=",
        'excluded_menu_items' => ['205'],
        'excluded_user_agents' => "Feedly\nUptimeRobot",
        'cacheable_query_params' => "start\nlimitstart\nformat",
        'form_markers' => 'id="custom-newsletter"',
    ];
    if ($s === 'suppress_off')    $params['suppress_session_cookie'] = 0;
    if ($s === 'forms_off')       $params['cache_form_pages'] = 0;
    if ($s === 'rsform_unticked') $params['form_types'] = 'login,convertforms';

    $GLOBALS['H_APP'] = $app = new HarnessApp($s !== 'logged_in', $docType, $s === 'menu_excluded' ? 205 : 101);
    $plugin = new \Cybersalt\Plugin\System\SgCache\Extension\SgCache($app, $params);

    $tok   = $GLOBALS['H_TOKEN'];
    $input = '<input type="hidden" name="' . $tok . '" value="1">';
    $opts  = '<script type="application/json" class="joomla-script-options new">{"csrf.token":"' . $tok . '","system.paths":{}}</script>';
    $forms = [
        'convertforms' => '<div class="convertforms"><form name="cf1" id="cf1" method="post" action="#"><input name="cf[fields][email]"><input type="hidden" name="cf[form_id]" value="1">' . $input . '</form></div>',
        'rsform'       => '<form method="post" id="newsletter" action="/"><input name="form[email]"><input type="hidden" name="form[formId]" value="19"/>' . $input . '<button type="submit">Go</button></form>',
        'login'        => '<form id="login-form-16" action="/" method="post"><input name="username"><input type="hidden" name="option" value="com_users"><input type="hidden" name="task" value="user.login">' . $input . '</form>',
        'unknown'      => '<form id="vote" action="/article" method="post"><input type="hidden" name="task" value="article.vote">' . $input . '</form>',
        'marker'       => '<form id="custom-newsletter" method="post" action="/subscribe">' . $input . '</form>',
    ];

    ob_start();
    // Joomla starts the guest session long before render
    session_name('7574fe4a1884189c1e5b41dea8210c1f');
    session_start();

    $plugin->onAfterInitialise();

    $page = static fn (string $inner) => '<html><head>' . $opts . '</head><body>' . $inner . '</body></html>';

    // Document::render() sets the app mime type from the document
    switch ($docType) {
        case 'feed': $app->mimeType = 'application/rss+xml'; $app->setBody('<?xml version="1.0"?><rss version="2.0"><channel><title>Site</title></channel></rss>'); break;
        case 'json': $app->mimeType = 'application/json'; $app->setBody('{"data":[]}'); break;
        case 'raw':  $app->setBody('raw output'); break;
        default:
            $app->setBody($page(match ($s) {
                'form', 'forms_off', 'unknown_form', 'gzip_form' => $forms[$s === 'unknown_form' ? 'unknown' : 'convertforms'] . ($s === 'unknown_form' ? $forms['rsform'] : ''),
                'rsform', 'rsform_unticked' => $forms['rsform'],
                'login_form'   => $forms['login'],
                'marker_form'  => $forms['marker'],
                'three_forms'  => $forms['convertforms'] . $forms['rsform'] . $forms['login'],
                'stray_token'  => '<div>' . $input . '</div>',
                default        => '<p>page</p>',
            }));
    }
    if ($s === 'message') $app->queue = [['message' => 'Thank you for subscribing', 'type' => 'message']];
    if ($s === 'shows_ip') $app->setBody($page('<p>Your IP: ' . $_SERVER['REMOTE_ADDR'] . '</p>'));
    if ($s === 'status404') $app->setHeader('status', '404', true);
    if ($s === 'json_header') $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

    $plugin->onAfterRender();

    // CMSApplication: compress() + onAfterCompress run before onBeforeRespond
    if (str_starts_with($s, 'gzip')) $app->compress();

    if ($s === 'headers_sent') { ob_end_flush(); flush(); ob_start(); }
    if ($s === 'reemit_after_render') session_regenerate_id(true);
    if ($s === 'app_header_cookie') $app->setHeader('Set-Cookie', 'consent=1; path=/');

    // onBeforeRespond (our handler is Priority::MIN, so it runs last)
    $plugin->onBeforeRespond();

    if ($s === 'late_cookie') setcookie('late', '1');
    ob_end_clean();
    $app->respond();
    $plugin->onAfterRespond();
    return true;
}
