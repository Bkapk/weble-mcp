<?php
// Run: php tests/tree-test.php  — checks the pure logic (tree ops, merge, code guard, colors, lint, previews) without WordPress.
define('ABSPATH', __DIR__);
function add_action() {}
function add_filter() {}
function register_deactivation_hook() {}
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_salt() { return 'test-salt'; }
function is_serialized($d) { return is_string($d) && preg_match('/^(a|s|i|b|d|O):/', $d); }
require __DIR__ . '/../weble-mcp/weble-mcp.php';

$w = function ($id, $title) { return ['id' => $id, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => $title], 'elements' => []]; };
$tree = [['id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [$w('h1', 'One'), $w('h2', 'Two')]]];
$ids = function ($els) { return array_map(function ($e) { return $e['id'] ?? null; }, $els); };

// insert into parent at position, and after an anchor
$t = wbmcp_el_place($tree, ['parent' => 'c1', 'position' => 1], [$w('x', 'X')]);
assert($ids($t[0]['elements']) === ['h1', 'x', 'h2']);
$t = wbmcp_el_place($tree, ['after' => 'h2'], [$w('y', 'Y')]);
assert($ids($t[0]['elements']) === ['h1', 'h2', 'y']);
$t = wbmcp_el_place($tree, [], [$w('root', 'R')]);
assert($ids($t) === ['c1', 'root']);

// delete, duplicate (copy has no id), move to root
$found = false;
$t = wbmcp_el_walk($tree, 'h1', function () { return []; }, $found);
assert($found && $ids($t[0]['elements']) === ['h2']);
$t = wbmcp_el_walk($tree, 'h2', function ($e) { return [$e, wbmcp_el_strip_ids($e)]; });
assert($ids($t[0]['elements']) === ['h1', 'h2', null]);
$moved = null;
$t = wbmcp_el_walk($tree, 'h2', function ($e) use (&$moved) { $moved = $e; return []; });
$t = wbmcp_el_place($t, ['before' => 'c1'], [$moved]);
assert($ids($t) === ['h2', 'c1'] && $ids($t[1]['elements']) === ['h1']);

// missing anchor fails
try { wbmcp_el_place($tree, ['parent' => 'nope'], [$w('z', 'Z')]); assert(false); } catch (RuntimeException $e) {}

// merge: null deletes, plain value drops conflicting global and dynamic tag, globals/dynamic merge per key
$s = ['title_color' => '#000', 'align' => 'left', '__globals__' => ['title_color' => 'globals/colors?id=primary', 'typography_typography' => 'globals/typography?id=primary']];
$m = wbmcp_el_merge($s, ['align' => null, 'title_color' => '#fff']);
assert(!isset($m['align']) && $m['title_color'] === '#fff' && !isset($m['__globals__']['title_color']) && isset($m['__globals__']['typography_typography']));
$m = wbmcp_el_merge($s, ['__globals__' => ['typography_typography' => null, 'title_color' => 'globals/colors?id=accent']]);
assert($m['__globals__'] === ['title_color' => 'globals/colors?id=accent']);
$m = wbmcp_el_merge(['__dynamic__' => ['title' => 'x', 'link' => 'y']], ['title' => 'Static', '__dynamic__' => ['link' => null]]);
assert($m['title'] === 'Static' && $m['__dynamic__'] === []);

// dynamic tag encoding matches Elementor's stored form
assert(wbmcp_el_tag_text('acf-text', ['key' => 'field_1:hero'], 'abc1234') === '[elementor-tag id="abc1234" name="acf-text" settings="%7B%22key%22%3A%22field_1%3Ahero%22%7D"]');
assert(wbmcp_el_tag_text('post-title', [], 'abc1234') === '[elementor-tag id="abc1234" name="post-title" settings="%7B%7D"]');

// outline shows ids, text, nesting, mobile direction and dynamic tags
$o = wbmcp_el_outline($tree);
assert(strpos($o, "- container #c1\n  - heading #h1 title=\"One\"\n") === 0);
assert(wbmcp_el_types($tree) === ['heading']);
$o = wbmcp_el_outline([['id' => 'r', 'elType' => 'container', 'settings' => ['flex_direction' => 'row', 'flex_direction_mobile' => 'column', 'hide_mobile' => 'hidden-mobile', '__dynamic__' => ['title' => '[elementor-tag id="1" name="post-title" settings="%7B%7D"]']]]]);
assert(strpos($o, 'dir=row/mobile:column') && strpos($o, 'title=@post-title') && strpos($o, 'hidden:mobile') && strpos($o, 'responsive=1'));

// custom code guard: executable code is caught, markup, CSS and prose are not
$code = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg/onload=alert(1)>', '<a href="&#106;avascript:alert(1)">x</a>',
    "<a href=\"java\tscript:alert(1)\">x</a>", '<iframe src="https://example.com"></iframe>', '<form action="https://evil.test">', '</style><script>x</script>',
    '<img src="x"onerror=alert(1)>', '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', '.x{background:url(javascript:alert(1))}'];
foreach ($code as $c) assert(wbmcp_is_code($c), "should be code: $c");
$safe = ['<p class="lead">Hello <strong>world</strong></p>', '.a > .b { color: red; }', 'Learn JavaScript: the good parts', '<style>.x{color:red}</style>',
    'We use <formula> tags', 'Turn on=off', '<a href="https://example.com/?a=1&b=2">x</a>'];
foreach ($safe as $c) assert(!wbmcp_is_code($c), "should be safe: $c");
assert(wbmcp_is_code('javascript:alert(1)', 'url') && !wbmcp_is_code('JavaScript: a guide', 'title'));
assert(wbmcp_is_code("data-x|1\nonclick|alert(1)", '_attributes') && !wbmcp_is_code("online|offline", 'title'));
// bypasses from the pre-release audit: ">" inside a quoted attribute, entities without ";", URL-encoded payloads
assert(wbmcp_is_code('<img alt=">" src=x onerror=alert(1)>'));
assert(wbmcp_is_code('<a href="&#106avascript:alert(1)">x</a>') && wbmcp_is_code('<a href=&#x6A&#x61vascript:alert(1)>x</a>'));
assert(wbmcp_is_code('[elementor-tag settings="%3Cscript%3Ealert(1)%3C%2Fscript%3E"]'));
assert(!wbmcp_is_code('<p class="a">Join us online = fun, 50% off</p>') && !wbmcp_is_code('<img alt="1 > 0" src="/a.png">'));

// meta keys: denylist is case-insensitive and ASCII-only, because MySQL compares meta_key loosely
assert(!wbmcp_meta_key_ok('_ELEMENTOR_DATA') && !wbmcp_meta_key_ok('_Wbmcp_Snapshots') && !wbmcp_meta_key_ok("_élementor_data") && !wbmcp_meta_key_ok('_elementor_data '));
assert(wbmcp_meta_key_ok('_yoast_wpseo_metadesc') && wbmcp_meta_key_ok('price'));

// audit value scrubbing
$sc = wbmcp_audit_scrub("define('X','sk_live_abcdefghijklmnop'); https://user:pa55@host.tld/x AKIAABCDEFGHIJKLMNOP re_abcdefghijklmnopqrstuv");
assert(strpos($sc, 'sk_live_') === false && strpos($sc, 'pa55') === false && strpos($sc, 'AKIA') === false && strpos($sc, 're_abc') === false && strpos($sc, 'https://[redacted]@host.tld') !== false);
try { wbmcp_code_guard(['elementor_data' => [['settings' => ['html' => '<script>x</script>']]]]); assert(false); } catch (RuntimeException $e) { assert(strpos($e->getMessage(), "'html'") === 0); }
wbmcp_code_guard(['title' => 'Plain <b>bold</b>', 'n' => 5, 'deep' => [['text' => 'ok']]]);
try { wbmcp_code_guard(['meta' => ['<img src=x onerror=alert(1)>' => 'v']]); assert(false); } catch (RuntimeException $e) {}

// colors and values
assert(wbmcp_rgba('#FFF') === [255, 255, 255, 1] && wbmcp_rgba('rgba(0, 0, 0, 0.5)') === [0, 0, 0, 0.5] && wbmcp_rgba('red') === null);
assert(wbmcp_hex(wbmcp_rgba('#ABCDEF')) === '#abcdef' && wbmcp_hex(wbmcp_rgba('rgba(0,0,0,0.5)')) === '#00000080');
assert(round(wbmcp_contrast([0, 0, 0, 1], [255, 255, 255, 1])) == 21);
assert(wbmcp_px(['unit' => 'rem', 'size' => 3]) == 48 && wbmcp_px(['unit' => 'vw', 'size' => 5]) == 0);
assert(wbmcp_val(['unit' => 'px', 'size' => 24]) === '24px' && wbmcp_val(['unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10']) === '10px');
assert(wbmcp_val(['unit' => 'px', 'top' => '100', 'right' => '0', 'bottom' => '100', 'left' => '0']) === '100 0 100 0px' && wbmcp_gid('globals/colors?id=a1b2') === 'a1b2');

// lint: stuck row, big text, kit color fix, contrast, alt, dead button, h1 count
$colors = ['primary' => ['title' => 'Primary', 'rgba' => wbmcp_rgba('#112233')], 'white' => ['title' => 'White', 'rgba' => wbmcp_rgba('#ffffff')]];
$page = [['id' => 'sec', 'elType' => 'container', 'settings' => ['flex_direction' => 'row', 'background_background' => 'classic', 'background_color' => '#FFFFFF'], 'elements' => [
    ['id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [
        ['id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['header_size' => 'h1', 'title_color' => '#112233', 'typography_font_size' => ['unit' => 'px', 'size' => 64]]],
        ['id' => 'pale', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['text_color' => '#bbbbbb']],
    ]],
    ['id' => 'b', 'elType' => 'container', 'settings' => [], 'elements' => [
        ['id' => 'h1b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['header_size' => 'h1']],
        ['id' => 'img', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => ['id' => 7, 'url' => 'x.jpg']]],
        ['id' => 'btn', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => 'Go']],
    ]],
]]];
$ctx = ['colors' => $colors, 'palette' => ['#112233' => 'primary', '#ffffff' => 'white'], 'fonts' => [], 'fix' => true, 'issues' => [], 'off' => [], 'kit_hard' => [], 'headings' => [],
    'global_ok' => function ($e, $k) { return true; }, 'alt' => function ($id) { return ''; }];
$fixed = wbmcp_lint_walk($page, $ctx);
$r = wbmcp_lint_report($ctx, false);
assert(strpos($r, '#sec row of 2 columns stays side by side') !== false, $r);
assert(strpos($r, '#h 64px text has no phone size') !== false);
assert(strpos($r, '#pale text_color #bbbbbb on #ffffff') !== false);
assert(strpos($r, '#img image has no alt text') !== false && strpos($r, '#btn button has no link') !== false);
assert(strpos($r, '2 h1 headings') !== false && strpos($r, 'FIXED: 2 hardcoded colors') !== false);
assert(strpos($r, 'off-palette #bbbbbb') !== false);
$hs = $fixed[0]['elements'][0]['elements'][0]['settings'];
assert(!isset($hs['title_color']) && $hs['__globals__']['title_color'] === 'globals/colors?id=primary');
assert($fixed[0]['settings']['__globals__']['background_color'] === 'globals/colors?id=white');
// a sibling's background must not leak: text in a later sibling without a known background gets no contrast check
$ctx2 = ['colors' => $colors, 'palette' => [], 'fonts' => [], 'fix' => false, 'issues' => [], 'off' => [], 'kit_hard' => [], 'headings' => [],
    'global_ok' => function () { return true; }, 'alt' => function () { return null; }];
wbmcp_lint_walk([
    ['id' => 'dark', 'elType' => 'container', 'settings' => ['background_background' => 'classic', 'background_color' => '#000000'], 'elements' => []],
    ['id' => 't', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['text_color' => '#111111']],
], $ctx2);
assert(!preg_grep('/contrast/', $ctx2['issues']), implode("\n", $ctx2['issues']));

// preview tokens: bound to one post, expire, reject junk
$exp = time() + 600;
$tok = $exp . '.' . wbmcp_preview_sig(42, $exp);
assert(wbmcp_preview_valid($tok, 42) && !wbmcp_preview_valid($tok, 43));
$old = time() - 1;
assert(!wbmcp_preview_valid($old . '.' . wbmcp_preview_sig(42, $old), 42));
assert(!wbmcp_preview_valid('', 42) && !wbmcp_preview_valid('abc.def', 42) && !wbmcp_preview_valid($exp . '.', 42));

// audit redaction: secret keys at any depth, inside serialized and JSON strings; public IDs stay; long values cut
$r = wbmcp_audit_redact(['api_key' => 'abc', 'cfg' => serialize(['pass' => 'x', 'pixel_id' => 'P1']), 'j' => '{"auth":{"user":"u"},"gtm_id":"GTM-1"}', 'color' => 'red', 'empty_token' => '', 'long' => str_repeat('a', 5000)]);
assert($r['api_key'] === '[redacted, 3 chars]' && $r['cfg']['pass'] === '[redacted, 1 chars]' && $r['cfg']['pixel_id'] === 'P1');
assert($r['j']['auth']['user'] === '[redacted, 1 chars]' && $r['j']['gtm_id'] === 'GTM-1' && $r['color'] === 'red' && $r['empty_token'] === '');
assert(strpos($r['long'], '[truncated, 5000 chars]') !== false && mb_strlen($r['long']) < 4100);
// audit size cap: big responses are cut to ~200 KB with a note
$big = wbmcp_audit_fit(['rows' => array_fill(0, 100, str_repeat('b', 3900))]);
assert(isset($big['note'], $big['rows']['_truncated']) && strlen(json_encode($big)) < 205000);
assert(wbmcp_audit_fit(['a' => 1]) === ['a' => 1]);

// Additional CSS blocks: one per page, replaced in place, removed with '', other CSS untouched
$GLOBALS['css'] = "body { color: red; }";
if (!function_exists('current_user_can')) { function current_user_can() { return true; } }
if (!function_exists('is_wp_error')) { function is_wp_error() { return false; } }
if (!function_exists('wp_get_custom_css')) { function wp_get_custom_css() { return $GLOBALS['css']; } }
if (!function_exists('wp_update_custom_css_post')) { function wp_update_custom_css_post($c) { $GLOBALS['css'] = $c; return true; } }
assert(wbmcp_css_block('post-7', '#7 About', '.elementor-7 .a{x:1}') === '');
assert(wbmcp_css_block('post-8', '#8 Other*/', '.elementor-8 .b{y:2}') === '');
assert(wbmcp_css_block('post-7', '#7 About', '.elementor-7 .a{x:3}') === '.elementor-7 .a{x:1}');
assert(substr_count($GLOBALS['css'], 'weble-mcp:post-7 start') === 1 && strpos($GLOBALS['css'], 'x:3') !== false && strpos($GLOBALS['css'], 'x:1') === false);
assert(strpos($GLOBALS['css'], 'Other*/') === false && strpos($GLOBALS['css'], '.elementor-8 .b{y:2}') !== false);
assert(wbmcp_css_block('post-7', '', '') === '.elementor-7 .a{x:3}' && strpos($GLOBALS['css'], 'post-7') === false);
assert(wbmcp_css_block('post-8', '', '') !== '' && $GLOBALS['css'] === 'body { color: red; }');

// tool hints: reads are read-only, lint can fix so it is not
assert(!wbmcp_tool_writes('get_site_brief') && !wbmcp_tool_writes('elementor_get_content') && wbmcp_tool_writes('elementor_lint') && wbmcp_tool_writes('set_custom_css'));

// instructions stay under Claude Code's ~2KB cap
assert(strlen(wbmcp_instructions()) < 1800, strlen(wbmcp_instructions()));

echo "ok\n";
