<?php
/* Eyüp Usta Tantuni - basit yönetim paneli (PHP 7.4+). Veritabanı gerekmez. */
$ROOT = __DIR__; $DATA = $ROOT . '/data';
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (!is_dir($DATA)) { @mkdir($DATA, 0755, true); }
$SESS = $DATA . '/sessions'; if (!is_dir($SESS)) { @mkdir($SESS, 0700, true); }
if (is_dir($SESS) && is_writable($SESS)) { session_save_path($SESS); }
session_name('eu_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
foreach ([$DATA . '/.htaccess' => "Require all denied\nDeny from all\n", $DATA . '/index.html' => ''] as $f => $c) { if (!file_exists($f)) { @file_put_contents($f, $c); } }
$AUTH = $DATA . '/auth.php'; $FAILS = $DATA . '/fails.php';

function out($a, $c = 200) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function body() { $r = file_get_contents('php://input'); $j = json_decode($r ?: '', true); return is_array($j) ? $j : []; }
function csrf() { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['csrf']; }
function need_auth() { if (empty($_SESSION['ok'])) { out(['error' => 'Giriş gerekli'], 401); } }
function need_csrf() { $t = $_SERVER['HTTP_X_CSRF'] ?? ''; if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) { out(['error' => 'Oturum süresi doldu, sayfayı yenileyin'], 403); } }
function atomic($path, $data) { $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp'; if (@file_put_contents($tmp, $data, LOCK_EX) === false) { out(['error' => 'Dosya yazılamadı. Klasör izinlerini (755/775) kontrol edin.'], 500); } @rename($tmp, $path); }
function hash_get() { global $AUTH; if (!is_file($AUTH)) { return null; } $p = explode("\n", (string)@file_get_contents($AUTH), 2); return isset($p[1]) && trim($p[1]) !== '' ? trim($p[1]) : null; }
function hash_set($h) { global $AUTH; atomic($AUTH, "<?php exit; ?>\n" . $h . "\n"); }
function ini_bytes($v) { $v = trim((string)$v); $l = strtolower(substr($v, -1)); $n = (float)$v; if ($l === 'g') { $n *= 1073741824; } elseif ($l === 'm') { $n *= 1048576; } elseif ($l === 'k') { $n *= 1024; } return (int)$n; }
function clean_text($s) {
    $s = (string)$s; if (function_exists('mb_substr')) { $s = mb_substr($s, 0, 2000, 'UTF-8'); } else { $s = substr($s, 0, 2000); }
    $s = strip_tags($s, '<br><em><small><strong>');
    return preg_replace('/<(br|em|small|strong)\b[^>]*>/i', '<$1>', $s);
}
function okpath($p) { return is_string($p) && preg_match('#^(uploads|videolar)/[A-Za-z0-9._-]+$#', $p) && strpos($p, '..') === false && is_file(__DIR__ . '/' . $p); }
function fails_read() { global $FAILS; if (!is_file($FAILS)) { return []; } $raw = (string)@file_get_contents($FAILS); $x = json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true); return is_array($x) ? $x : []; }
function too_many_fails() { $t = time(); return count(array_filter(fails_read(), function ($v) use ($t) { return $v > $t - 600; })) >= 8; }
function add_fail() { global $FAILS; $t = time(); $l = array_values(array_filter(fails_read(), function ($v) use ($t) { return $v > $t - 600; })); $l[] = $t; atomic($FAILS, "<?php exit; ?>\n" . json_encode($l)); }
function default_content() { return ['texts' => new stdClass(), 'photos' => [], 'videos' => [], 'settings' => ['phone1' => '+905414785399', 'phone2' => '+905354657506', 'wa' => '905414785399', 'maps' => 'https://maps.app.goo.gl/XBXMHPtPvEcF48g58?g_st=ipc']]; }

$a = $_GET['a'] ?? '';
$LANGS = ['tr', 'en', 'ar', 'uk', 'de', 'ru', 'fr', 'es', 'fa'];

if ($a === 'status') {
    out(['setup' => hash_get() === null, 'auth' => !empty($_SESSION['ok']), 'csrf' => csrf(), 'maxUpload' => min(ini_bytes(ini_get('upload_max_filesize')), ini_bytes(ini_get('post_max_size')))]);
}
if ($a === 'setup') {
    if (hash_get() !== null) { out(['error' => 'Şifre zaten belirlenmiş'], 403); }
    $b = body(); $p = (string)($b['password'] ?? '');
    if (strlen($p) < 8) { out(['error' => 'Şifre en az 8 karakter olmalı'], 400); }
    hash_set(password_hash($p, PASSWORD_DEFAULT)); session_regenerate_id(true); $_SESSION['ok'] = 1; out(['ok' => true, 'csrf' => csrf()]);
}
if ($a === 'login') {
    if (too_many_fails()) { out(['error' => 'Çok fazla hatalı deneme. 10 dakika sonra tekrar deneyin.'], 429); }
    $b = body(); $h = hash_get();
    if ($h && password_verify((string)($b['password'] ?? ''), $h)) { session_regenerate_id(true); $_SESSION['ok'] = 1; out(['ok' => true, 'csrf' => csrf()]); }
    add_fail(); usleep(400000); out(['error' => 'Şifre hatalı'], 401);
}
if ($a === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }
if ($a === 'passwd') {
    need_auth(); need_csrf(); $b = body(); $h = hash_get();
    if (!$h || !password_verify((string)($b['old'] ?? ''), $h)) { out(['error' => 'Mevcut şifre hatalı'], 401); }
    $n = (string)($b['new'] ?? ''); if (strlen($n) < 8) { out(['error' => 'Yeni şifre en az 8 karakter olmalı'], 400); }
    hash_set(password_hash($n, PASSWORD_DEFAULT)); out(['ok' => true]);
}
if ($a === 'load') {
    need_auth(); $f = $ROOT . '/content.json'; $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($c)) { $c = default_content(); } out($c);
}
if ($a === 'save') {
    need_auth(); need_csrf(); $b = body(); $c = default_content(); $c['texts'] = [];
    $oldf = $ROOT . '/content.json'; $old = is_file($oldf) ? json_decode((string)file_get_contents($oldf), true) : null;
    if (is_array($old) && isset($old['settings']) && is_array($old['settings'])) { $c['settings'] = array_merge($c['settings'], $old['settings']); }
    if (isset($b['texts']) && is_array($b['texts'])) {
        foreach ($b['texts'] as $l => $o) {
            if (!in_array($l, $LANGS, true) || !is_array($o)) { continue; }
            foreach ($o as $k => $v) { if (is_string($v) && preg_match('/^(k\d{1,3}|_wt|_tt)$/', (string)$k)) { $c['texts'][$l][$k] = clean_text($v); } }
        }
    }
    if (empty($c['texts'])) { $c['texts'] = new stdClass(); }
    $c['photos'] = [];
    foreach (array_slice((array)($b['photos'] ?? []), 0, 60) as $p) {
        if (!is_array($p) || !okpath($p['src'] ?? null)) { continue; }
        $it = ['src' => $p['src']];
        if (!empty($p['k']) && preg_match('/^k\d{1,3}$/', (string)$p['k'])) { $it['k'] = $p['k']; }
        elseif (!empty($p['cap'])) { $it['cap'] = trim(strip_tags(function_exists('mb_substr') ? mb_substr((string)$p['cap'], 0, 160, 'UTF-8') : substr((string)$p['cap'], 0, 160))); }
        if (!empty($p['cut'])) { $it['cut'] = true; }
        $c['photos'][] = $it;
    }
    $c['videos'] = [];
    foreach (array_slice((array)($b['videos'] ?? []), 0, 30) as $v) {
        if (!is_array($v) || !okpath($v['src'] ?? null)) { continue; }
        $it = ['src' => $v['src']]; if (!empty($v['poster']) && okpath($v['poster'])) { $it['poster'] = $v['poster']; } $c['videos'][] = $it;
    }
    $s = (array)($b['settings'] ?? []);
    foreach (['phone1', 'phone2'] as $k) { $x = preg_replace('/[^0-9+]/', '', (string)($s[$k] ?? '')); if (strlen($x) >= 10 && strlen($x) <= 15) { $c['settings'][$k] = $x; } }
    $x = preg_replace('/\D/', '', (string)($s['wa'] ?? '')); if (strlen($x) >= 10 && strlen($x) <= 15) { $c['settings']['wa'] = $x; }
    $m = trim((string)($s['maps'] ?? '')); if (strlen($m) <= 300 && preg_match('#^https://#', $m) && filter_var($m, FILTER_VALIDATE_URL)) { $c['settings']['maps'] = $m; }
    atomic($ROOT . '/content.json', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    // yönetici tarafından yüklenip artık kullanılmayan dosyaları temizle
    $used = []; foreach (array_merge($c['photos'], $c['videos']) as $it) { $used[$it['src']] = 1; if (!empty($it['poster'])) { $used[$it['poster']] = 1; } }
    foreach (['uploads', 'videolar'] as $d) { foreach ((array)glob($ROOT . '/' . $d . '/u_*') as $f) { if (!isset($used[$d . '/' . basename($f)])) { @unlink($f); } } }
    out(['ok' => true]);
}
if ($a === 'upload') {
    need_auth(); need_csrf();
    if (empty($_FILES['file']) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { out(['error' => 'Dosya çok büyük (sunucu sınırı: ' . ini_get('post_max_size') . ')'], 413); }
    $f = $_FILES['file'] ?? null; if (!$f) { out(['error' => 'Dosya seçilmedi'], 400); }
    if ($f['error'] !== UPLOAD_ERR_OK) { out(['error' => ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) ? 'Dosya sunucu sınırını aşıyor (' . ini_get('upload_max_filesize') . ')' : 'Yükleme hatası (kod ' . $f['error'] . ')'], 400); }
    $type = $_POST['type'] ?? ''; if (!in_array($type, ['photo', 'poster', 'video'], true)) { out(['error' => 'Geçersiz tür'], 400); }
    $h = (string)file_get_contents($f['tmp_name'], false, null, 0, 16); $ext = '';
    if (substr($h, 0, 3) === "\xFF\xD8\xFF") { $ext = 'jpg'; }
    elseif (substr($h, 0, 4) === "\x89PNG") { $ext = 'png'; }
    elseif (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') { $ext = 'webp'; }
    elseif (substr($h, 4, 4) === 'ftyp') { $ext = 'mp4'; }
    elseif (substr($h, 0, 4) === "\x1A\x45\xDF\xA3") { $ext = 'webm'; }
    $img = in_array($ext, ['jpg', 'png', 'webp'], true);
    if ($type === 'video' ? !in_array($ext, ['mp4', 'webm'], true) : !$img) { out(['error' => $type === 'video' ? 'Sadece MP4 veya WEBM video yüklenebilir' : 'Sadece JPG, PNG veya WEBP resim yüklenebilir'], 400); }
    if ($img && @getimagesize($f['tmp_name']) === false) { out(['error' => 'Geçerli bir resim değil'], 400); }
    $max = $type === 'video' ? 300 * 1048576 : ($type === 'poster' ? 3 * 1048576 : 12 * 1048576);
    if ($f['size'] > $max) { out(['error' => 'Dosya çok büyük (en fazla ' . round($max / 1048576) . ' MB)'], 400); }
    $dir = $type === 'photo' ? 'uploads' : 'videolar'; $dd = $ROOT . '/' . $dir;
    if (!is_dir($dd)) { @mkdir($dd, 0755, true); }
    $name = 'u_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dd . '/' . $name)) { out(['error' => 'Dosya kaydedilemedi. "' . $dir . '" klasörünün yazma iznini kontrol edin.'], 500); }
    @chmod($dd . '/' . $name, 0644); out(['path' => $dir . '/' . $name]);
}
if ($a !== '') { out(['error' => 'Bilinmeyen işlem'], 404); }
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Yönetim Paneli · Eyüp Usta Tantuni</title>
<style>
:root{--bg:#0b0a08;--card:#16130e;--line:#2c261b;--fg:#f6f1e6;--mut:#a39a89;--y:#ffb800;--red:#ef5350;--ok:#43a047}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
button,input,select,textarea{font:inherit;color:inherit}
.top{position:sticky;top:0;z-index:20;background:rgba(11,10,8,.95);backdrop-filter:blur(8px);border-bottom:1px solid var(--line);padding:10px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.top h1{font-size:17px;margin:0}.top h1 small{display:block;color:var(--mut);font-weight:400;font-size:12px}
.btn{background:var(--y);color:#1a1200;border:0;border-radius:10px;padding:9px 16px;font-weight:700;cursor:pointer}.btn.g{background:transparent;color:var(--fg);border:1px solid var(--line);font-weight:600}.btn.r{background:transparent;color:var(--red);border:1px solid var(--red)}.btn.s{padding:6px 10px;font-size:13px;border-radius:8px}.btn:disabled{opacity:.5;cursor:default}
.tabs{display:flex;gap:6px;padding:12px 16px 0;max-width:980px;margin:0 auto;overflow-x:auto}.tab{padding:9px 14px;border-radius:10px 10px 0 0;border:1px solid var(--line);border-bottom:0;background:var(--card);cursor:pointer;white-space:nowrap;color:var(--mut)}.tab.on{color:#1a1200;background:var(--y);border-color:var(--y);font-weight:700}
main{max-width:980px;margin:0 auto;padding:16px}.panel{background:var(--card);border:1px solid var(--line);border-radius:0 14px 14px 14px;padding:16px}
h2{font-size:15px;margin:22px 0 10px;color:var(--y);letter-spacing:.04em;text-transform:uppercase}h2:first-child{margin-top:0}
.f{position:relative;margin-bottom:14px}.f label{display:block;font-size:12px;color:var(--mut);margin-bottom:4px}
.f textarea,.f input,.sel{width:100%;background:#0e0c09;border:1px solid var(--line);border-radius:10px;padding:10px;resize:vertical}.f textarea:focus,.f input:focus{outline:2px solid var(--y)}
.hint{font-size:12px;color:var(--mut);margin-top:4px}.f.mod label::after{content:" ●";color:var(--y)}.rs{position:absolute;right:6px;top:0;background:none;border:0;color:var(--mut);cursor:pointer;font-size:13px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}.card{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#0e0c09}.card .im{aspect-ratio:4/3;background:#000 center/cover no-repeat;display:grid;place-items:center;color:var(--mut);font-size:13px}.card .im.v{aspect-ratio:9/16}.card .cb{padding:10px;display:flex;flex-direction:column;gap:8px}.card .row{display:flex;gap:6px;flex-wrap:wrap}
.note{background:#1f1a10;border:1px solid #4a3b12;border-radius:10px;padding:10px 12px;font-size:13px;color:#e8d9a8;margin-bottom:14px}
.login{max-width:380px;margin:12vh auto;padding:0 16px}.login .panel{border-radius:16px}.login h1{margin:0 0 4px;font-size:20px}.login p{color:var(--mut);margin:0 0 16px}
#toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#222;border:1px solid var(--line);padding:10px 16px;border-radius:12px;z-index:50;max-width:92%;display:none}#toast.ok{border-color:var(--ok)}#toast.err{border-color:var(--red)}
.bar{height:6px;background:#0e0c09;border-radius:3px;overflow:hidden;margin-top:6px}.bar i{display:block;height:100%;background:var(--y);width:0}
@media(max-width:560px){main{padding:10px}.panel{padding:12px}}
</style></head><body>
<div id="app"></div><div id="toast"></div>
<script>
const $=s=>document.querySelector(s),LANGS=[['tr','Türkçe'],['en','English'],['ar','العربية'],['uk','Українська'],['de','Deutsch'],['ru','Русский'],['fr','Français'],['es','Español'],['fa','فارسی']];
const GR=[['Menü',[0,1,2,3,4]],['Giriş bölümü (en üst)',[5,6,7,8,9,10]],['Rozetler',[13,14,15,16]],['Video şeridi',[11,12]],['Hakkımızda',[17,18,19,20]],['Dürüm animasyonu',[40,41,42,43,44,45,46]],['Galeri',[21,22,23,24,25]],['Videolar başlığı',[47]],['Hizmetler',[26,27,28,29,30,31,32]],['İletişim',[33,34,35,36,37,38,39]],['Diğer',['_wt','_tt']]];
const NAMES={_wt:'WhatsApp hazır mesajı (müşteri yazmaya başlarken)',_tt:'Tarayıcı sekmesi başlığı'};
let S={csrf:'',c:null,defs:null,lang:'tr',tab:'texts',dirty:false,max:0,busy:false};
const h=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function toast(m,t){const e=$('#toast');e.textContent=m;e.className=t||'';e.style.display='block';clearTimeout(toast.t);toast.t=setTimeout(()=>e.style.display='none',t==='err'?6000:2600)}
async function api(a,body){const o={method:body?'POST':'GET',headers:{'X-CSRF':S.csrf},credentials:'same-origin'};if(body){o.headers['Content-Type']='application/json';o.body=JSON.stringify(body)}
 const r=await fetch('admin.php?a='+a,o);let j={};try{j=await r.json()}catch(e){}if(!r.ok)throw new Error(j.error||('Hata '+r.status));return j}
function up(file,type,cb){return new Promise((res,rej)=>{const x=new XMLHttpRequest();x.open('POST','admin.php?a=upload');x.setRequestHeader('X-CSRF',S.csrf);x.upload.onprogress=e=>{if(e.lengthComputable&&cb)cb(e.loaded/e.total)};
 x.onload=()=>{let j={};try{j=JSON.parse(x.responseText)}catch(e){}x.status==200&&j.path?res(j.path):rej(new Error(j.error||('Yükleme hatası ('+x.status+')')))};x.onerror=()=>rej(new Error('Bağlantı hatası'));
 const f=new FormData();f.append('type',type);f.append('file',file,file.name);x.send(f)})}
const dirty=()=>{S.dirty=true;const b=$('#save');if(b){b.disabled=false;b.textContent='Kaydet ●'}};
window.onbeforeunload=e=>{if(S.dirty){e.preventDefault();e.returnValue=''}};

async function boot(){const st=await api('status');S.csrf=st.csrf;S.max=st.maxUpload;
 if(st.auth){await enter()}else login(st.setup)}
function login(setup){$('#app').innerHTML=`<div class="login"><div class="panel"><h1>🌯 Eyüp Usta</h1><p>${setup?'İlk kurulum: yönetim şifrenizi belirleyin (en az 8 karakter).':'Yönetim paneli girişi'}</p>
<div class="f"><label>Şifre</label><input id="pw" type="password" autocomplete="${setup?'new-password':'current-password'}"></div>${setup?'<div class="f"><label>Şifre (tekrar)</label><input id="pw2" type="password" autocomplete="new-password"></div>':''}
<button class="btn" id="go" style="width:100%">${setup?'Şifreyi kaydet ve gir':'Giriş yap'}</button></div></div>`;
 const go=async()=>{try{const p=$('#pw').value;if(setup&&p!==$('#pw2').value)return toast('Şifreler aynı değil','err');const r=await api(setup?'setup':'login',{password:p});S.csrf=r.csrf;await enter()}catch(e){toast(e.message,'err')}};
 $('#go').onclick=go;$('#app').onkeydown=e=>{if(e.key==='Enter')go()};$('#pw').focus()}
async function enter(){const [c,d]=await Promise.all([api('load'),fetch('defaults.json?'+Date.now()).then(r=>r.json())]);
 c.texts=(c.texts&&!Array.isArray(c.texts))?c.texts:{};c.photos=c.photos||[];c.videos=c.videos||[];c.settings=c.settings||{};S.c=c;S.defs=d;S.dirty=false;shell()}
function shell(){$('#app').innerHTML=`<div class="top"><h1>🌯 Yönetim Paneli<small>Eyüp Usta Tantuni</small></h1><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn g s" href="./" target="_blank" style="text-decoration:none;display:inline-flex;align-items:center">Siteyi aç ↗</a><button class="btn s" id="save" disabled>Kaydet</button><button class="btn g s" id="out">Çıkış</button></div></div>
<div class="tabs">${[['texts','Yazılar'],['photos','Fotoğraflar'],['videos','Videolar'],['set','Ayarlar']].map(t=>`<div class="tab${S.tab===t[0]?' on':''}" data-t="${t[0]}">${t[1]}</div>`).join('')}</div><main><div class="panel" id="pn"></div></main>`;
 document.querySelectorAll('.tab').forEach(t=>t.onclick=()=>{S.tab=t.dataset.t;shell()});
 $('#save').onclick=save;$('#out').onclick=async()=>{if(S.dirty&&!confirm('Kaydedilmemiş değişiklikler var. Çıkılsın mı?'))return;S.dirty=false;await api('logout',{});location.reload()};
 if(S.dirty){$('#save').disabled=false;$('#save').textContent='Kaydet ●'}
 ({texts:rTexts,photos:rPhotos,videos:rVideos,set:rSet})[S.tab]()}
async function save(){const b=$('#save');b.disabled=true;b.textContent='Kaydediliyor…';try{await api('save',S.c);S.dirty=false;b.textContent='Kaydet';toast('Kaydedildi ✓ Değişiklikler sitede yayında','ok');await reload()}catch(e){b.disabled=false;b.textContent='Kaydet ●';toast(e.message,'err')}}
async function reload(){const c=await api('load');c.texts=(c.texts&&!Array.isArray(c.texts))?c.texts:{};S.c=c;shell()}

const defv=(l,k)=>(S.defs[l]||{})[k]||'',val=(l,k)=>(S.c.texts[l]&&S.c.texts[l][k]!=null)?S.c.texts[l][k]:defv(l,k),isMod=(l,k)=>!!(S.c.texts[l]&&S.c.texts[l][k]!=null);
function rTexts(){const l=S.lang,rtl=(l==='ar'||l==='fa');
 let o=`<div class="f"><label>Düzenlenecek dil</label><select class="sel" id="lang">${LANGS.map(x=>`<option value="${x[0]}"${x[0]===l?' selected':''}>${x[1]}</option>`).join('')}</select></div>
<div class="note">Metinleri değiştirin ve <b>Kaydet</b>'e basın. <code>&lt;br&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;small&gt;</code> işaretleri satır başı ve vurgu içindir, silmeyin. Sarı nokta (●) o metnin değiştirildiğini gösterir; ↺ ile eski haline dönebilirsiniz.</div>`;
 for(const [g,ks] of GR){o+=`<h2>${g}</h2>`;for(const n of ks){const k=typeof n==='number'?'k'+n:n,v=val(l,k);if(!(k in(S.defs[l]||{})))continue;
  o+=`<div class="f${isMod(l,k)?' mod':''}"><label>${h(NAMES[k]||('Metin · '+(defv('tr',k)||'').replace(/<[^>]*>/g,' ').slice(0,38)))}</label><textarea data-k="${k}" dir="${rtl?'rtl':'ltr'}" rows="${Math.max(1,Math.min(6,Math.ceil(v.length/58)))}">${h(v)}</textarea>${l!=='tr'?`<div class="hint">Türkçe: ${h(defv('tr',k)).slice(0,140)}</div>`:''}${isMod(l,k)?`<button class="rs" data-r="${k}" title="Eski haline dön">↺ sıfırla</button>`:''}</div>`}}
 $('#pn').innerHTML=o;$('#lang').onchange=e=>{S.lang=e.target.value;rTexts()};
 document.querySelectorAll('#pn textarea').forEach(t=>t.oninput=()=>{const k=t.dataset.k;S.c.texts[l]=S.c.texts[l]||{};if(t.value===defv(l,k)){delete S.c.texts[l][k]}else S.c.texts[l][k]=t.value;t.closest('.f').classList.toggle('mod',isMod(l,k));dirty()});
 document.querySelectorAll('[data-r]').forEach(b=>b.onclick=()=>{if(S.c.texts[l])delete S.c.texts[l][b.dataset.r];dirty();const y=scrollY;rTexts();scrollTo(0,y)})}

function mv(a,i,d){const j=i+d;if(j<0||j>=a.length)return;[a[i],a[j]]=[a[j],a[i]];dirty()}
function rPhotos(){const P=S.c.photos;let o=`<div class="note">Fotoğraf ekleyin veya silin, ◀ ▶ ile sırasını değiştirin. İşiniz bitince <b>Kaydet</b>'e basın. Büyük fotoğraflar otomatik küçültülür.</div>
<div style="margin-bottom:14px"><button class="btn" id="addp">＋ Fotoğraf ekle</button><input type="file" id="fp" accept="image/*" multiple hidden><div class="bar" id="pb" style="display:none"><i></i></div></div><div class="grid">`;
 P.forEach((p,i)=>{o+=`<div class="card"><div class="im" style="background-image:url('${h(p.src)}');${p.cut?'background-size:contain;background-color:#1a140d':''}"></div><div class="cb">${p.k?`<div class="hint">Başlık: "Yazılar" sekmesi → Galeri</div>`:`<input data-c="${i}" placeholder="Başlık (isteğe bağlı)" value="${h(p.cap||'')}" maxlength="160" style="width:100%;background:#0e0c09;border:1px solid var(--line);border-radius:8px;padding:7px">`}
 <div class="row"><button class="btn g s" data-m="${i},-1">◀</button><button class="btn g s" data-m="${i},1">▶</button><button class="btn r s" data-d="${i}">Sil</button></div></div></div>`});
 o+=`</div>${P.length?'':'<p class="hint">Henüz fotoğraf yok.</p>'}`;$('#pn').innerHTML=o;
 $('#addp').onclick=()=>$('#fp').click();$('#fp').onchange=async e=>{const fs=[...e.target.files];if(!fs.length)return;const pb=$('#pb');pb.style.display='block';let n=0,ok=0;
  for(const f of fs){try{n++;toast(`Yükleniyor ${n}/${fs.length}…`);const b=await shrink(f);const path=await up(b,'photo',p=>pb.firstChild.style.width=Math.round(p*100)+'%');S.c.photos.push({src:path});ok++;dirty()}catch(x){toast(f.name+': '+x.message,'err')}}
  const last=$('#toast').textContent,wasErr=$('#toast').className==='err';rPhotos();if(ok)toast('Yüklendi. Kaydet\'e basmayı unutmayın','ok');else if(wasErr)toast(last,'err')};
 document.querySelectorAll('[data-c]').forEach(i=>i.oninput=()=>{P[+i.dataset.c].cap=i.value;dirty()});
 document.querySelectorAll('[data-m]').forEach(b=>b.onclick=()=>{const[i,d]=b.dataset.m.split(',').map(Number);mv(P,i,d);rPhotos()});
 document.querySelectorAll('[data-d]').forEach(b=>b.onclick=()=>{if(confirm('Bu fotoğraf silinsin mi?')){P.splice(+b.dataset.d,1);dirty();rPhotos()}})}
function shrink(f){return new Promise((res,rej)=>{if(!/^image\/(jpeg|png|webp)$/.test(f.type))return rej(new Error('JPG, PNG veya WEBP olmalı'));const im=new Image(),u=URL.createObjectURL(f);im.onerror=()=>rej(new Error('Resim okunamadı'));
 im.onload=()=>{URL.revokeObjectURL(u);const m=1600,s=Math.min(1,m/Math.max(im.width,im.height));if(s===1&&f.size<1.5e6)return res(f);const c=document.createElement('canvas');c.width=Math.round(im.width*s);c.height=Math.round(im.height*s);c.getContext('2d').drawImage(im,0,0,c.width,c.height);
  const png=f.type==='image/png';c.toBlob(b=>b?res(new File([b],'foto.'+(png?'png':'jpg'),{type:b.type})):rej(new Error('Küçültülemedi')),png?'image/png':'image/jpeg',.86)};im.src=u})}

function rVideos(){const V=S.c.videos,mb=Math.floor(S.max/1048576);let o=`<div class="note">Video ekleyin veya silin. Önerilen: <b>MP4</b>, kısa (30 sn civarı), dikey, 720p veya altı. Sunucunuzun tek dosya sınırı: <b>${mb||'?'} MB</b>. İşiniz bitince <b>Kaydet</b>'e basın.</div>
<div style="margin-bottom:14px"><button class="btn" id="addv">＋ Video ekle</button><input type="file" id="fv" accept="video/mp4,video/webm" hidden><div class="bar" id="vb" style="display:none"><i></i></div></div><div class="grid">`;
 V.forEach((v,i)=>{o+=`<div class="card"><div class="im v" style="${v.poster?`background-image:url('${h(v.poster)}')`:''}">${v.poster?'':'Önizleme yok'}</div><div class="cb"><div class="row"><button class="btn g s" data-m="${i},-1">◀</button><button class="btn g s" data-m="${i},1">▶</button><button class="btn r s" data-d="${i}">Sil</button></div></div></div>`});
 o+=`</div>${V.length?'':'<p class="hint">Henüz video yok. Videolar bölümü sitede gizlenir.</p>'}`;$('#pn').innerHTML=o;
 $('#addv').onclick=()=>$('#fv').click();$('#fv').onchange=async e=>{const f=e.target.files[0];if(!f)return;if(S.max&&f.size>S.max)return toast(`Dosya çok büyük (${Math.round(f.size/1048576)} MB). Sunucu sınırı ${mb} MB. Videoyu küçültüp tekrar deneyin.`,'err');
  const vb=$('#vb');vb.style.display='block';try{toast('Önizleme hazırlanıyor…');const pf=await poster(f);let pp='';if(pf){try{pp=await up(pf,'poster')}catch(x){}}
   toast('Video yükleniyor…');const path=await up(f,'video',p=>vb.firstChild.style.width=Math.round(p*100)+'%');const it={src:path};if(pp)it.poster=pp;S.c.videos.push(it);dirty();rVideos();toast('Yüklendi. Kaydet\'e basmayı unutmayın','ok')}catch(x){vb.style.display='none';toast(x.message,'err')}};
 document.querySelectorAll('[data-m]').forEach(b=>b.onclick=()=>{const[i,d]=b.dataset.m.split(',').map(Number);mv(V,i,d);rVideos()});
 document.querySelectorAll('[data-d]').forEach(b=>b.onclick=()=>{if(confirm('Bu video silinsin mi?')){V.splice(+b.dataset.d,1);dirty();rVideos()}})}
function poster(f){return new Promise(res=>{const v=document.createElement('video'),u=URL.createObjectURL(f);v.muted=true;v.playsInline=true;v.preload='auto';let done=false;const fin=r=>{if(done)return;done=true;URL.revokeObjectURL(u);res(r)};setTimeout(()=>fin(null),7000);v.onerror=()=>fin(null);
 v.onloadeddata=()=>{v.currentTime=Math.min(1.2,(v.duration||2)/3)};v.onseeked=()=>{try{const w=360,c=document.createElement('canvas');c.width=w;c.height=Math.round(w*v.videoHeight/v.videoWidth)||640;c.getContext('2d').drawImage(v,0,0,c.width,c.height);c.toBlob(b=>fin(b?new File([b],'p.jpg',{type:'image/jpeg'}):null),'image/jpeg',.8)}catch(e){fin(null)}};v.src=u})}

function rSet(){const s=S.c.settings;$('#pn').innerHTML=`<h2>İletişim</h2><div class="f"><label>Ana telefon (arama düğmeleri, örn. +905414785399)</label><input data-s="phone1" value="${h(s.phone1||'')}" inputmode="tel"></div>
<div class="f"><label>İkinci telefon (iletişim bölümü)</label><input data-s="phone2" value="${h(s.phone2||'')}" inputmode="tel"></div>
<div class="f"><label>WhatsApp numarası (başında + olmadan, örn. 905414785399)</label><input data-s="wa" value="${h(s.wa||'')}" inputmode="numeric"></div>
<div class="f"><label>Harita bağlantısı (https://… Google Maps linki)</label><input data-s="maps" value="${h(s.maps||'')}"></div>
<h2>Şifre değiştir</h2><div class="f"><label>Mevcut şifre</label><input id="op" type="password" autocomplete="current-password"></div><div class="f"><label>Yeni şifre (en az 8 karakter)</label><input id="np" type="password" autocomplete="new-password"></div><button class="btn g" id="cp">Şifreyi değiştir</button>`;
 document.querySelectorAll('[data-s]').forEach(i=>i.oninput=()=>{s[i.dataset.s]=i.value.trim();dirty()});
 $('#cp').onclick=async()=>{try{await api('passwd',{old:$('#op').value,new:$('#np').value});$('#op').value=$('#np').value='';toast('Şifre değiştirildi ✓','ok')}catch(e){toast(e.message,'err')}}}
boot().catch(e=>{$('#app').innerHTML='<div class="login"><div class="panel"><h1>Hata</h1><p>'+h(e.message)+'</p></div></div>'});
</script></body></html>
