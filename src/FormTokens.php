<?php

/**
 * Joomla form tokens on otherwise cacheable pages.
 *
 * A Joomla form carries a hidden input named after the visitor's CSRF token,
 * checked against their session on submit. A cached copy would hand one
 * visitor's token to everyone. Cached form pages therefore get the token
 * "hole-punched" out: the input keeps a placeholder name, and a small script
 * fetches the visitor's own token from the plugin's com_ajax endpoint the
 * first time they use a form. That request starts their session, so the
 * token matches when the form is submitted.
 *
 * Only forms we recognise (or that the admin lists by marker) are punched.
 * Any other form keeps the page uncached, as before.
 *
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache;

\defined('_JEXEC') or die;

class FormTokens
{
    /**
     * Name the token input gets in the cached copy. The script renames it to
     * the visitor's real token.
     */
    public const PLACEHOLDER = 'sgcache_token';

    /**
     * Form types that need nothing from the session but the token, and what
     * identifies each one inside its <form>.
     */
    private const TYPES = [
        'login'        => '#<input\b(?=[^>]*\bname=["\']task["\'])(?=[^>]*\bvalue=["\']user\.login["\'])[^>]*>#i',
        'rsform'       => '#\bname=["\']form\[formId\]["\']#',
        'convertforms' => '#\bname=["\']cf\[form_id\]["\']#',
    ];

    /**
     * Find the forms on a page that carry the token.
     *
     * @param   string    $body     Page HTML.
     * @param   string    $token    The current form token.
     * @param   string[]  $allowed  Type keys from TYPES that may be cached.
     * @param   string[]  $markers  Admin-supplied strings; a form containing
     *                              one of them may be cached too.
     *
     * @return  array{found: bool, blocked: string[], forms: string[]}
     *          blocked: labels of token forms that can't be cached.
     *          forms:   labels of every token form.
     */
    public static function analyse(string $body, string $token, array $allowed, array $markers): array
    {
        $result = ['found' => false, 'blocked' => [], 'forms' => []];

        if ($token === '' || !str_contains($body, $token)) {
            return $result;
        }

        $input = self::inputPattern($token);
        $outside = $body;

        foreach (self::forms($body) as $form) {
            if (!preg_match($input, $form)) {
                continue;
            }

            $outside = str_replace($form, '', $outside);
            $type    = self::type($form, $markers);
            $label   = self::label($form, $type);

            $result['found']   = true;
            $result['forms'][] = $label;

            if ($type === null || ($type !== 'marker' && !\in_array($type, $allowed, true))) {
                $result['blocked'][] = $label;
            }
        }

        // A token input outside any <form> (built by a script, or a broken
        // page): nothing to tell what it's for.
        if (preg_match($input, $outside)) {
            $result['found']     = true;
            $result['forms'][]   = 'token input outside a form';
            $result['blocked'][] = 'token input outside a form';
        }

        $result['forms']   = array_values(array_unique($result['forms']));
        $result['blocked'] = array_values(array_unique($result['blocked']));

        return $result;
    }

    /**
     * Strip the visitor's own token from a page about to be cached.
     *
     * Token inputs get the placeholder name; the token in Joomla's script
     * options ("csrf.token") is emptied. Neither works for anyone but the
     * visitor who rendered the page, so nothing changes for the others.
     *
     * @return  array{0: string, 1: bool}  New body, and whether any input was punched.
     */
    public static function scrub(string $body, string $token): array
    {
        if ($token === '' || !str_contains($body, $token)) {
            return [$body, false];
        }

        $count = 0;
        $body  = preg_replace_callback(
            self::inputPattern($token),
            static fn (array $m): string => preg_replace(
                '#\bname=(["\'])' . preg_quote($token, '#') . '\1#',
                'name="' . self::PLACEHOLDER . '" data-sgcache-token="1"',
                $m[0],
                1
            ),
            $body,
            -1,
            $count
        );

        $body = str_replace('"csrf.token":"' . $token . '"', '"csrf.token":""', $body);

        return [$body, $count > 0];
    }

    /**
     * The script that swaps the visitor's own token in. Fetches it the first
     * time the visitor focuses or touches a punched form; a click or submit
     * that comes before the token has arrived is held, then replayed.
     */
    public static function script(string $endpoint): string
    {
        $url = json_encode($endpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script>
(function () {
    var url = {$url}, token = null, request = null;
    function punchedForm(el) {
        var f = el && el.closest ? el.closest('form') : null;
        return f && f.querySelector('input[data-sgcache-token]') ? f : null;
    }
    function fetchToken() {
        if (!request) {
            request = fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && /^[0-9a-f]{32}$/.test(d.token)) {
                        token = d.token;
                        document.querySelectorAll('input[data-sgcache-token]').forEach(function (i) { i.name = token; });
                        if (window.Joomla && Joomla.loadOptions) { Joomla.loadOptions({ 'csrf.token': token }); }
                    }
                })
                .catch(function () {})
                .then(function () { if (token === null) { token = ''; } });
        }
        return request;
    }
    ['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach(function (type) {
        document.addEventListener(type, function (e) { if (punchedForm(e.target)) { fetchToken(); } }, { capture: true, passive: true });
    });
    document.addEventListener('click', function (e) {
        if (token !== null) { return; }
        var b = e.target && e.target.closest ? e.target.closest('button, input[type=submit], input[type=image], input[type=button]') : null;
        if (!b || !punchedForm(b)) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        fetchToken().then(function () { b.click(); });
    }, true);
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (token !== null || !f.querySelector || !f.querySelector('input[data-sgcache-token]')) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var s = e.submitter && e.submitter.form === f ? e.submitter : undefined;
        fetchToken().then(function () { f.requestSubmit ? f.requestSubmit(s) : f.submit(); });
    }, true);
})();
</script>
HTML;
    }

    /** @return string[] Every <form>...</form> in the body. */
    private static function forms(string $body): array
    {
        preg_match_all('#<form\b[^>]*>.*?</form>#is', $body, $m);

        return $m[0];
    }

    private static function inputPattern(string $token): string
    {
        return '#<input\b[^>]*\bname=(["\'])' . preg_quote($token, '#') . '\1[^>]*>#i';
    }

    private static function type(string $form, array $markers): ?string
    {
        foreach (self::TYPES as $type => $pattern) {
            if (preg_match($pattern, $form)) {
                return $type;
            }
        }

        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($form, $marker)) {
                return 'marker';
            }
        }

        return null;
    }

    /** Short description of a form for the log and the home page test. */
    private static function label(string $form, ?string $type): string
    {
        $name = match ($type) {
            'login'        => 'Login form',
            'rsform'       => 'RSForm! Pro form',
            'convertforms' => 'Convert Forms form',
            'marker'       => 'Form matching a marker',
            default        => 'Unrecognised form',
        };

        preg_match('#^<form\b[^>]*>#i', $form, $open);
        $parts = [];
        foreach (['id', 'name', 'action'] as $attr) {
            if (preg_match('#(?<![\w-])' . $attr . '=(["\'])(.*?)\1#i', $open[0] ?? '', $a) && $a[2] !== '') {
                // A form posting to the current page repeats the request's
                // query string in its action; keep only the path.
                $value = $attr === 'action' ? (string) strtok(html_entity_decode($a[2]), '?#') : $a[2];
                if ($value !== '') {
                    $parts[] = $attr . '="' . mb_substr($value, 0, 80) . '"';
                }
            }
        }

        return $parts ? $name . ' (' . implode(' ', $parts) . ')' : $name;
    }
}
