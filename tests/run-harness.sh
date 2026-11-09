#!/bin/bash
# Runs every scenario through the real plugin class (tests/harness/router.php)
# and checks the result. Needs PHP 8.1+ with zlib on PATH and curl.
#
#   bash tests/run-harness.sh
#
# Each row: what the visitor asked for, the expected X-Cache-Enabled, what
# came back, and the extras that matter (cookie, content type, punched form).
cd "$(dirname "$0")/harness" || exit 1
rm -rf root
php -d session.save_path="$(pwd)" -S 127.0.0.1:8187 router.php > server.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null' EXIT
for _ in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8187/ && break; sleep 0.2; done

PASS=0; FAIL=0
PROBE=$(php -r 'echo hash_hmac("sha256", "sgcache-probe", "harness-secret");')

# check <label> <scenario> <path> <expected X-Cache-Enabled> [body must contain] [body must not contain] [extra curl args]
check() {
  local sep='?'; [[ "$3" == *\?* ]] && sep='&'
  local hdr body
  hdr=$(mktemp); body=$(mktemp)
  curl -s --compressed -D "$hdr" -o "$body" ${7:+-H "$7"} "http://127.0.0.1:8187$3${sep}s=$2"
  local xc ck ct dec ok=1
  xc=$(grep -i '^X-Cache-Enabled:' "$hdr" | tr -d '\r' | awk '{print $2}')
  ck=$(grep -qi '^Set-Cookie:' "$hdr" && echo cookie || echo -)
  ct=$(grep -i '^Content-Type:' "$hdr" | tr -d '\r' | cut -d' ' -f2 | cut -d';' -f1)
  dec=$(grep -i '^X-SGCache-Decision:' "$hdr" | tr -d '\r' | cut -d' ' -f2-)
  [[ "${xc:-none}" == "$4" ]] || ok=0
  [[ -z "$5" ]] || grep -qF -- "$5" "$body" || ok=0
  [[ -z "$6" ]] || ! grep -qF -- "$6" "$body" || ok=0
  # A claimed page must never carry a cookie or the real token.
  if [[ "$xc" == "True" ]]; then
    [[ "$ck" == "-" ]] || ok=0
    ! grep -q 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4' "$body" || ok=0
  fi
  if (( ok )); then PASS=$((PASS+1)); r=PASS; else FAIL=$((FAIL+1)); r=FAIL; fi
  printf "%-4s %-44s want=%-5s got=%-5s %-6s %-20s %s\n" "$r" "$1" "$4" "${xc:-none}" "$ck" "${ct:--}" "${dec:+[$dec]}"
  rm -f "$hdr" "$body"
}

T=a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4

echo "--- the #3 test list ---"
check "plain home page"                     default  "/"                                   True
check "article"                             default  "/blog/my-article"                    True
check "home + tracking only"                default  "/?fbclid=IwAR0abc&utm_source=fb"     True
check "feed + fbclid (the AutoHebdo bug)"   default  "/?fbclid=IwAR0abc&format=feed&type=atom" False
check "feed + utm_source"                   default  "/?utm_source=x&format=feed&type=rss" False
check "plain feed"                          default  "/?format=feed&type=rss"              False
check "SEF suffix feed, no query"           default  "/blog.feed"                          False
check "format=json"                         default  "/?format=json"                       False
check "raw document, no query"              raw      "/x"                                  False
check "tmpl=component"                      default  "/blog/my-article?tmpl=component"     False
check "status 404"                          status404 "/missing"                           False
check "app Content-Type json"               json_header "/api-ish"                         False
check "logged in"                           logged_in "/"                                  False

echo "--- forms (hole punching) ---"
check "Convert Forms page -> cached, punched" form "/contact-us" True  'data-sgcache-token' "name=\"$T\""
check "  script injected"                   form     "/contact-us"                         True  'action=token'
check "  csrf.token option emptied"         form     "/contact-us"                         True  '"csrf.token":""'
check "RSForm page"                         rsform   "/"                                   True  'name="sgcache_token" data-sgcache-token="1"'
check "login module page"                   login_form "/"                                 True  'data-sgcache-token'
check "marker-listed form"                  marker_form "/"                                True  'data-sgcache-token'
check "three known forms on one page"       three_forms "/"                                True  'data-sgcache-token'
check "unknown form -> uncached"            unknown_form "/"                               False "name=\"$T\"" 'data-sgcache-token'
check "RSForm not ticked -> uncached"       rsform_unticked "/"                            False "name=\"$T\""
check "Cache Pages with Forms off"          forms_off "/contact-us"                        False "name=\"$T\""
check "token input outside a form"          stray_token "/"                                False
check "gzip body + form -> still detected"  gzip_form "/contact-us"                        True  'data-sgcache-token'
check "gzip body, plain page"               gzip     "/"                                   True  '<p>page</p>'
check "no form: no script added"            default  "/"                                   True  ''  'action=token'

echo "--- exclusions ---"
check "URL prefix /members/x"               default  "/members/profile"                    False
check "URL wildcard /shop/*/checkout"       default  "/shop/bikes/checkout"               False
check "  wildcard non-match"                default  "/shop/bikes"                         True
check "URL exact /about$"                   default  "/about"                              False
check "  exact non-match /about/team"       default  "/about/team"                         True
check "URL with query /search?q="           default  "/search?q=bikes&utm_source=x"        False
check "menu item excluded"                  menu_excluded "/news"                          False
check "user agent excluded (Feedly)"        default  "/"                                   False '' '' 'User-Agent: Feedly/1.0'
check "allowed param ?start=10"             default  "/blog?start=10"                      True
check "allowed + tracking mixed"            default  "/blog?start=10&utm_source=x"         False
check "never param format even if listed"   default  "/blog?format=html"                   False
check "unlisted param ?page=2"              default  "/blog?page=2"                        False

echo "--- cookies ---"
check "suppress cookie off"                 suppress_off "/"                               False
check "cookie re-emitted after render"      reemit_after_render "/"                        True
check "cookie in app headers"               app_header_cookie "/"                          False

echo "--- the visitor's own things ---"
check "visitor arrives with a session cookie"   default "/"                                False '' '' 'Cookie: 7574fe4a1884189c1e5b41dea8210c1f=abc123'
check "  same, page with a form"                form    "/contact-us"                      False '' '' 'Cookie: 7574fe4a1884189c1e5b41dea8210c1f=abc123'
check "other cookies only (consent)"            default "/"                                True  '' '' 'Cookie: cookieconsent=yes'
check "message queued for the visitor"          message "/"                                False
check "page shows the visitor's IP"             shows_ip "/"                               False

echo "--- probe header ---"
check "probe: cached page reports decision" default  "/"                                   True  '' '' "X-SGCache-Probe: $PROBE"
check "probe: bypass reports reason"        unknown_form "/"                               False '' '' "X-SGCache-Probe: $PROBE"
check "wrong probe key: no decision header" default  "/"                                   True  '' '' "X-SGCache-Probe: nope"

echo
echo "--- log files ---"
# files <label> <condition>
files() { if eval "$2"; then PASS=$((PASS+1)); echo "PASS $1"; else FAIL=$((FAIL+1)); echo "FAIL $1"; fi; }
D=root/administrator/logs/sgcache_decisions.php
files "decisions file is .php in the log folder"     "[ -f $D ]"
files "decisions file starts with die()"             "head -1 $D | grep -qF \"#<?php die('Forbidden.'); ?>\""
files "plugin log is .php and starts with die()"     "head -1 root/administrator/logs/sg-default.log.php | grep -qF '<?php die'"
files "nothing written to /logs or /tmp"             "[ ! -e root/logs ] && [ -z \"\$(ls -A root/tmp 2>/dev/null)\" ]"
files "query_string decisions recorded"              "awk -F'\t' '\$2==\"query_string\"{f=1} END{exit !f}' $D"
files "  ...without the parameter names"             "awk -F'\t' '\$2==\"query_string\" && \$3!=\"\"{f=1} END{exit f}' $D"
files "no request query string in form labels"       "! grep -q 'action=\"[^\"]*?' $D"
files "no visitor IP stored"                         "! grep -q '127.0.0.1' $D"
echo "decisions file, last lines (time, reason, detail for fixable reasons only):"
tail -3 "$D"
echo
echo "PASS $PASS  FAIL $FAIL"
(( FAIL == 0 ))
