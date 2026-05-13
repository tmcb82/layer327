<?php
declare(strict_types=1);
ob_start();

// ══════════════════════════════════════════════════════════════════════════════
//  ★  Customisation
// ══════════════════════════════════════════════════════════════════════════════
const CUSTOM_LOGO    = '';      // e.g. '/images/logo.png'
const CUSTOM_FAVICON = '';      // e.g. '/favicon.ico'
const LOGO_ALT       = 'Logo';
const SESSION_TIMEOUT = 7200;

// ══════════════════════════════════════════════════════════════════════════════
//  Secure session
// ══════════════════════════════════════════════════════════════════════════════
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (isset($_SESSION['u'])) {
    if (isset($_SESSION['_last_active']) && (time() - $_SESSION['_last_active']) > SESSION_TIMEOUT) {
        session_unset(); session_destroy(); session_start();
        $_SESSION['_timed_out'] = true;
    } else {
        $_SESSION['_last_active'] = time();
    }
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

// ══════════════════════════════════════════════════════════════════════════════
//  VoIP.ms API
// ══════════════════════════════════════════════════════════════════════════════
class VoipMS {
    private const BASE = 'https://voip.ms/api/v1/rest.php';
    private const UA   = 'Mozilla/5.0 (compatible; VoipMSManager/2.0)';
    private const OPTS = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '',
    ];
    public function __construct(private readonly string $user, private readonly string $pass) {}
    private function url(string $method, array $extra = []): string {
        return self::BASE . '?' . http_build_query(['api_username'=>$this->user,'api_password'=>$this->pass,'method'=>$method] + $extra);
    }
    private function req(string $method, array $extra = []): array {
        $ch = curl_init($this->url($method, $extra));
        curl_setopt_array($ch, self::OPTS + [CURLOPT_USERAGENT => self::UA]);
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if (!$raw) return ['status'=>'error','message'=>'cURL: '.$err];
        return $this->decode($raw);
    }
    public function multi(array $batch): array {
        $mh = curl_multi_init(); $handles = [];
        foreach ($batch as $key => [$method, $extra]) {
            $ch = curl_init($this->url($method, $extra));
            curl_setopt_array($ch, self::OPTS + [CURLOPT_USERAGENT => self::UA]);
            curl_multi_add_handle($mh, $ch); $handles[$key] = $ch;
        }
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
        $results = [];
        foreach ($handles as $key => $ch) {
            $results[$key] = $this->decode((string)curl_multi_getcontent($ch));
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }
    private function decode(string $raw): array {
        $d = json_decode($raw, true);
        if (!is_array($d)) return ['status'=>'error','message'=>'Non-JSON: '.substr(preg_replace('/\s+/',' ',$raw),0,100)];
        return $d;
    }
    public function getVoicemails(): array { return $this->req('getVoicemails'); }
    public function getMessages(string $mb, string $folder): array {
        return $this->req('getVoicemailMessages', ['mailbox'=>$mb,'folder'=>$folder]);
    }
    public function getFile(string $mb, string $folder, string $num): array {
        return $this->req('getVoicemailMessageFile', ['mailbox'=>$mb,'folder'=>$folder,'message_num'=>$num]);
    }
    public function delete(string $mb, string $folder, string $num): array {
        return $this->req('delMessages', ['mailbox'=>$mb,'folder'=>$folder,'message_num'=>$num]);
    }
    public function markListened(string $mb, string $folder, string $num): array {
        return $this->req('markListenedVoicemailMessage', ['mailbox'=>$mb,'folder'=>$folder,'message_num'=>$num,'listened'=>'yes']);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  Session cache
// ══════════════════════════════════════════════════════════════════════════════
function scGet(string $key): ?array {
    if (!isset($_SESSION['_sc'][$key])) return null;
    ['d'=>$data,'t'=>$ts,'ttl'=>$ttl] = $_SESSION['_sc'][$key];
    if ((time()-$ts)>$ttl) { unset($_SESSION['_sc'][$key]); return null; }
    return $data;
}
function scSet(string $key, array $data, int $ttl): void {
    $_SESSION['_sc'][$key] = ['d'=>$data,'t'=>time(),'ttl'=>$ttl];
}
function scDel(string ...$keys): void { foreach ($keys as $k) unset($_SESSION['_sc'][$k]); }
function scFlushMessages(): void {
    foreach (array_keys($_SESSION['_sc'] ?? []) as $k)
        if (str_starts_with($k,'msgs_') || $k==='unread_counts') unset($_SESSION['_sc'][$k]);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Audio disk cache
// ══════════════════════════════════════════════════════════════════════════════
const AUDIO_CACHE_TTL = 86400;
function audioCachePath(string $user, string $mb, string $folder, string $num): string {
    return sys_get_temp_dir().'/voipms_'.md5("$user:$mb:$folder:$num").'.mp3';
}
function audioCacheGet(string $path): ?string {
    if (!is_file($path)) return null;
    if ((time()-(int)filemtime($path))>AUDIO_CACHE_TTL) { @unlink($path); return null; }
    return file_get_contents($path) ?: null;
}
function audioCacheSet(string $path, string $data): void { file_put_contents($path,$data,LOCK_EX); }
function audioCachePurge(string $user, string $mb, string $folder, string $num): void {
    @unlink(audioCachePath($user,$mb,$folder,$num));
}

// ══════════════════════════════════════════════════════════════════════════════
//  Helpers
// ══════════════════════════════════════════════════════════════════════════════
function voip(): ?VoipMS {
    static $v = null;
    if ($v===null && isset($_SESSION['u'],$_SESSION['p'])) $v = new VoipMS($_SESSION['u'],$_SESSION['p']);
    return $v;
}
function esc(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function redir(string $url): never { header('Location: '.$url); exit; }
function csrfOk(): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$_POST['csrf']??''); }
function parseCID(string $cid): array {
    if (preg_match('/^"?([^"<]*?)"?\s*<([^>]*)>/',trim($cid),$m))
        return ['name'=>trim($m[1])?:$m[2],'num'=>$m[2]];
    return ['name'=>$cid?:'Unknown','num'=>$cid];
}
function fmtDur(string|int $s): string { $s=(int)$s; return sprintf('%d:%02d',intdiv($s,60),$s%60); }
function fmtDate(string $d): string {
    try { return (new DateTimeImmutable($d))->format('M j, Y g:i A'); } catch (Throwable) { return $d; }
}
function isListened(array $msg): bool {
    $v = strtolower((string)($msg['listened']??'no'));
    return $v==='yes'||$v==='1'||$v==='true';
}
function canShellExec(): bool {
    if (!function_exists('shell_exec')) return false;
    return !in_array('shell_exec',array_map('trim',explode(',',ini_get('disable_functions'))),true);
}
function audioToMp3(string $wavBin): ?string {
    $tmp = tempnam(sys_get_temp_dir(),'vmx_'); if (!$tmp) return null;
    $wf="$tmp.wav"; $mf="$tmp.mp3"; file_put_contents($wf,$wavBin); $mp3=null;
    foreach (['ffmpeg','sox'] as $tool) {
        $path=trim((string)@shell_exec("which $tool 2>/dev/null")); if (!$path) continue;
        if ($tool==='ffmpeg')
            @shell_exec(escapeshellarg($path).' -y -i '.escapeshellarg($wf).' -ar 8000 -ab 32k -f mp3 '.escapeshellarg($mf).' 2>/dev/null');
        else
            @shell_exec(escapeshellarg($path).' '.escapeshellarg($wf).' --rate 8000 -C 32 '.escapeshellarg($mf).' 2>/dev/null');
        if (is_file($mf)&&filesize($mf)>0) { $mp3=file_get_contents($mf); break; }
    }
    @unlink($wf); @unlink($mf); @unlink($tmp);
    return $mp3?:null;
}
function icon(string $name, string $cls='w-5 h-5'): string {
    $paths=[
        'voicemail'=>['fill','<path d="M5.5 8a2.5 2.5 0 1 0 0 5h13a2.5 2.5 0 0 0 0-5H5.5zM2 10.5a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0zm13 0a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0zm-4.5 3h3v1h-3v-1z"/>'],
        'play'     =>['fill','<path d="M8 5v14l11-7z"/>'],
        'pause'    =>['fill','<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>'],
        'trash'    =>['stroke','<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6h14zM10 11v6M14 11v6"/>'],
        'check'    =>['stroke','<path d="M20 6L9 17l-5-5"/>'],
        'logout'   =>['stroke','<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>'],
        'empty'    =>['stroke','<path d="M22 12h-6l-2 3H10L8 12H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'],
        'download' =>['stroke','<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>'],
        'refresh'  =>['stroke','<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>'],
        'menu'     =>['stroke','<path d="M4 6h16M4 12h16M4 18h16"/>'],
        'x'        =>['stroke','<path d="M18 6L6 18M6 6l12 12"/>'],
        'spin'     =>['stroke','<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>'],
    ];
    [$type,$body]=$paths[$name]??['fill',''];
    $attr=$type==='stroke'?'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"':'fill="currentColor"';
    return sprintf('<svg xmlns="http://www.w3.org/2000/svg" class="%s" viewBox="0 0 24 24" %s>%s</svg>',esc($cls),$attr,$body);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Routing
// ══════════════════════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Login ─────────────────────────────────────────────────────────────────────
if ($action==='login' && $_SERVER['REQUEST_METHOD']==='POST') {
    $u=trim($_POST['u']??''); $p=trim($_POST['p']??'');
    if ($u&&$p) {
        $t=(new VoipMS($u,$p))->getVoicemails();
        if (($t['status']??'')==='success') {
            session_regenerate_id(true);
            $_SESSION['u']=$u; $_SESSION['p']=$p; $_SESSION['_last_active']=time();
            $_SESSION['csrf']=bin2hex(random_bytes(32));
            scSet('mailboxes',$t,300); redir('?');
        }
        $loginErr=ucfirst($t['message']??'Authentication failed');
    } else { $loginErr='Email and password are required.'; }
}

// ── Logout ────────────────────────────────────────────────────────────────────
if ($action==='logout') { session_destroy(); redir('?'); }

// ── Refresh ───────────────────────────────────────────────────────────────────
if ($action==='refresh' && voip()) {
    scFlushMessages();
    redir('?'.http_build_query(array_filter(['mb'=>$_GET['mb']??'','folder'=>$_GET['folder']??''])));
}

// ── Audio stream ──────────────────────────────────────────────────────────────
if ($action==='audio') {
    @ini_set('display_errors','0'); error_reporting(0); ob_clean();
    $die=function(int $c,string $m):never{
        http_response_code($c); header('Content-Type: application/json'); header('Cache-Control: no-store');
        echo json_encode(['error'=>$m]); exit;
    };
    try {
        if (!voip()) $die(401,'Not authenticated');
        $mb=$_GET['mb']??''; $fld=$_GET['folder']??'INBOX'; $num=$_GET['num']??'';
        if ($mb===''||$num==='') $die(400,'Missing mb or num');
        $cachePath=audioCachePath($_SESSION['u'],$mb,$fld,$num);
        $cached=audioCacheGet($cachePath);
        if ($cached!==null) {
            $etag='"'.md5($cachePath.(string)filemtime($cachePath)).'"';
            if (($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
            header('Content-Type: audio/mpeg'); header('Content-Length: '.strlen($cached));
            header('Cache-Control: private, max-age=86400'); header('ETag: '.$etag); echo $cached; exit;
        }
        $r=voip()->getFile($mb,$fld,$num);
        if (($r['status']??'')!=='success') $die(502,'VoIP.ms: '.($r['message']??'unknown'));
        $raw='';
        if (is_array($r['message']??null)&&isset($r['message']['data'])) $raw=(string)$r['message']['data'];
        elseif (is_string($r['message']??null)) $raw=$r['message'];
        if (!$raw) $die(502,'Audio field empty. Keys: '.implode(', ',array_keys((array)($r['message']??$r))));
        $bin=base64_decode(preg_replace('/\s+/','',$raw),true);
        if (!$bin||strlen($bin)<16) $die(502,'base64 decode failed ('.strlen((string)$bin).' bytes)');
        $mp3=canShellExec()?audioToMp3($bin):null;
        ob_end_clean();
        if ($mp3) {
            audioCacheSet($cachePath,$mp3);
            $etag='"'.md5($cachePath.(string)time()).'"';
            header('Content-Type: audio/mpeg'); header('Content-Length: '.strlen($mp3));
            header('Cache-Control: private, max-age=86400'); header('ETag: '.$etag); echo $mp3;
        } else {
            header('Content-Type: audio/wav'); header('Content-Length: '.strlen($bin));
            header('Cache-Control: no-store'); header('X-Audio-Codec: gsm-wav-fallback'); echo $bin;
        }
    } catch (Throwable $e) { $die(500,'Exception: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()); }
    exit;
}

// ── AJAX: delete ──────────────────────────────────────────────────────────────
if ($action==='delete' && $_SERVER['REQUEST_METHOD']==='POST' && voip()) {
    ob_end_clean(); header('Content-Type: application/json');
    if (!csrfOk()) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Invalid request token']); exit; }
    $mb=$_POST['mb']??''; $fld=$_POST['folder']??'INBOX'; $num=$_POST['num']??'';
    $r=voip()->delete($mb,$fld,$num); $st=$r['status']??''; $ok=$st==='success';
    if ($ok) { scDel("msgs_{$mb}_{$fld}",'unread_counts'); audioCachePurge($_SESSION['u'],$mb,$fld,$num); }
    $errMsg=$r['message']??'';
    if (!$ok&&$errMsg==='') $errMsg="API status: \"$st\" (mailbox=$mb folder=$fld num=$num)";
    echo json_encode(['ok'=>$ok,'err'=>$errMsg]); exit;
}

// ── AJAX: mark listened ───────────────────────────────────────────────────────
if ($action==='listened' && $_SERVER['REQUEST_METHOD']==='POST' && voip()) {
    ob_end_clean(); header('Content-Type: application/json');
    if (!csrfOk()) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Invalid request token']); exit; }
    $mb=$_POST['mb']??''; $fld=$_POST['folder']??'INBOX'; $num=$_POST['num']??'';
    $r=voip()->markListened($mb,$fld,$num); $ok=($r['status']??'')==='success';
    if ($ok) scDel("msgs_{$mb}_{$fld}",'unread_counts');
    echo json_encode(['ok'=>$ok,'err'=>$r['message']??'']); exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  App state
// ══════════════════════════════════════════════════════════════════════════════
$loggedIn=$mailboxes=$messages=[];
$loggedIn  = voip()!==null;
$mailboxes = $messages = [];
$curMB     = $_GET['mb']??null;
$curFolder = $_GET['folder']??'INBOX';
$dataErr   = null; $curMBData=null;
$FOLDERS   = ['INBOX'=>'Inbox','Old'=>'Old'];

if ($loggedIn) {
    $mbKey='mailboxes'; $msgKey=$curMB?"msgs_{$curMB}_{$curFolder}":null;
    $needMB=scGet($mbKey)===null; $needMsg=$curMB!==null&&scGet($msgKey)===null;
    if ($needMB&&$needMsg) {
        $res=voip()->multi(['mb'=>['getVoicemails',[]],'msg'=>['getVoicemailMessages',['mailbox'=>$curMB,'folder'=>$curFolder]]]);
        if (($res['mb']['status']??'')==='success')    scSet($mbKey,$res['mb'],300);
        if (($res['msg']['status']??'')==='success')   scSet($msgKey,$res['msg'],60);
        if (($res['msg']['status']??'')==='no_messages') scSet($msgKey,['status'=>'no_messages','messages'=>[]],60);
    } elseif ($needMB) {
        $r=voip()->getVoicemails(); if (($r['status']??'')==='success') scSet($mbKey,$r,300);
    } elseif ($needMsg) {
        $r=voip()->getMessages($curMB,$curFolder); $st=$r['status']??'';
        if ($st==='success') scSet($msgKey,$r,60);
        elseif ($st==='no_messages') scSet($msgKey,['status'=>'no_messages','messages'=>[]],60);
    }
    $mbData=scGet($mbKey);
    if ($mbData&&($mbData['status']??'')==='success') $mailboxes=$mbData['voicemails']??[];
    else $dataErr=$mbData['message']??'Could not load mailboxes';

    $unreadMap=scGet('unread_counts');
    if ($unreadMap===null&&!empty($mailboxes)) {
        $unreadMap=[]; $batch=[];
        foreach ($mailboxes as $m) {
            $n=$m['mailbox']; $cached=scGet("msgs_{$n}_INBOX");
            if ($cached!==null) $unreadMap[$n]=count(array_filter($cached['messages']??[],fn($x)=>!isListened($x)));
            else $batch[$n]=['getVoicemailMessages',['mailbox'=>$n,'folder'=>'INBOX']];
        }
        if (!empty($batch)) {
            $fetched=voip()->multi($batch);
            foreach ($fetched as $n=>$r) {
                $msgs=($r['status']??'')==='success'?($r['messages']??[]):[];
                $unreadMap[$n]=count(array_filter($msgs,fn($x)=>!isListened($x)));
                if (($r['status']??'')==='success') scSet("msgs_{$n}_INBOX",$r,60);
                elseif (($r['status']??'')==='no_messages') scSet("msgs_{$n}_INBOX",['status'=>'no_messages','messages'=>[]],60);
            }
        }
        scSet('unread_counts',$unreadMap,60);
    }
    $unreadMap=$unreadMap??[];

    if ($curMB!==null) {
        foreach ($mailboxes as $m) if ($m['mailbox']===$curMB){$curMBData=$m;break;}
        $msgData=scGet($msgKey); $st=$msgData['status']??'';
        if ($st==='success') {
            $messages=$msgData['messages']??[];
            usort($messages,fn($a,$b)=>strcmp($b['date']??'',$a['date']??''));
        } elseif ($st!=='no_messages') $dataErr=$msgData['message']??'Could not load messages';
    }
}
$unread=count(array_filter($messages,fn($m)=>!isListened($m)));
$timedOut=!empty($_SESSION['_timed_out']); unset($_SESSION['_timed_out']);

?><!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= esc($CSRF) ?>">
<title>Voicemail Manager</title>
<?php if (CUSTOM_FAVICON!==''): ?>
<link rel="icon" href="<?= esc(CUSTOM_FAVICON) ?>">
<?php else: ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%230284c7'/><path fill='white' d='M5.5 8a2.5 2.5 0 1 0 0 5h13a2.5 2.5 0 0 0 0-5H5.5zM2 10.5a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0zm13 0a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0zm-4.5 3h3v1h-3v-1z'/></svg>">
<?php endif; ?>
<link rel="preconnect" href="https://cdn.tailwindcss.com">
<link rel="dns-prefetch" href="https://voip.ms">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{brand:{50:'#f0f9ff',100:'#e0f2fe',400:'#38bdf8',500:'#0ea5e9',600:'#0284c7',700:'#0369a1',800:'#075985',900:'#0c4a6e'}}}}}</script>
<style>
  ::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:#374151;border-radius:3px}
  #sidebar{transition:transform .3s ease}
  @media(max-width:767px){
    #sidebar{position:fixed;inset:0 auto 0 0;z-index:40;transform:translateX(-100%)}
    #sidebar.sidebar-open{transform:translateX(0);box-shadow:4px 0 24px rgba(0,0,0,.6)}
    #backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:39}
    #backdrop.backdrop-open{display:block}
  }
  .msg-card{transition:opacity .25s,transform .25s}
  .msg-card.removing{opacity:0;transform:translateX(-16px)}
  .player-wrap{transition:max-height .3s ease,opacity .3s ease;max-height:0;opacity:0;overflow:hidden}
  .player-wrap.open{max-height:130px;opacity:1}
</style>
</head>
<body class="h-full bg-gray-950 text-gray-100 antialiased">

<?php if (!$loggedIn): ?>
<!-- ════════ LOGIN ════════ -->
<div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-gray-950 via-gray-900 to-gray-950">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <?php if (CUSTOM_LOGO!==''): ?>
      <img src="<?= esc(CUSTOM_LOGO) ?>" alt="<?= esc(LOGO_ALT) ?>" class="h-16 mx-auto mb-4 object-contain">
      <?php else: ?>
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-600 mb-4 shadow-lg shadow-brand-900/50">
        <?= icon('voicemail','w-8 h-8 text-white') ?>
      </div>
      <?php endif; ?>
      <h1 class="text-3xl font-bold text-white tracking-tight">Voicemail Manager</h1>
    </div>
    <div class="bg-gray-900 rounded-2xl p-6 sm:p-8 shadow-2xl border border-gray-800">
      <?php if (!empty($loginErr)): ?>
      <div class="mb-5 flex items-start gap-3 p-3.5 rounded-xl bg-red-950/50 border border-red-800/60 text-red-300 text-sm">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        <span><?= esc($loginErr) ?></span>
      </div>
      <?php elseif ($timedOut): ?>
      <div class="mb-5 flex items-start gap-3 p-3.5 rounded-xl bg-yellow-950/50 border border-yellow-800/60 text-yellow-300 text-sm">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
        <span>Signed out after 2 hours of inactivity.</span>
      </div>
      <?php endif; ?>
      <form method="POST" action="?action=login">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-300 mb-1.5">Email</label>
          <input type="email" name="u" value="<?= esc($_POST['u']??'') ?>" required autocomplete="email"
                 class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white placeholder-gray-600 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 transition text-base"
                 placeholder="account@example.com">
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-300 mb-1.5">Password</label>
          <input type="password" name="p" required autocomplete="current-password"
                 class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white placeholder-gray-600 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 transition text-base"
                 placeholder="***************">
        </div>
        <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 active:bg-brand-700 text-white font-semibold py-3 rounded-xl transition shadow-lg shadow-brand-900/40 text-base">
          Sign In
        </button>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ════════ MAIN APP ════════ -->
<div id="backdrop" onclick="closeSidebar()"></div>
<div class="flex h-screen overflow-hidden">

  <!-- SIDEBAR -->
  <aside id="sidebar" class="w-72 flex-shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col select-none">
    <div class="px-4 py-4 border-b border-gray-800 flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-brand-600 flex items-center justify-center flex-shrink-0">
        <?= icon('voicemail','w-5 h-5 text-white') ?>
      </div>
      <div class="flex-1 min-w-0">
        <div class="font-bold text-white text-sm leading-tight">Voicemail Manager</div>
        <div class="text-xs text-gray-500 truncate"><?= esc($_SESSION['u']) ?></div>
      </div>
      <button onclick="closeSidebar()" class="md:hidden w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 hover:text-white hover:bg-gray-800 transition flex-shrink-0">
        <span class="pointer-events-none"><?= icon('x','w-4 h-4') ?></span>
      </button>
    </div>
    <nav class="flex-1 overflow-y-auto py-3 px-2">
      <div class="px-2 mb-2 flex items-center justify-between">
        <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-widest">Mailboxes</span>
        <span class="text-[11px] text-gray-600"><?= count($mailboxes) ?></span>
      </div>
      <?php if (!empty($dataErr)&&empty($mailboxes)): ?>
      <div class="px-2 py-3 text-xs text-red-400 bg-red-950/40 rounded-lg border border-red-900/50 mx-1"><?= esc($dataErr) ?></div>
      <?php endif; ?>
      <?php foreach ($mailboxes as $mb):
        $active=$curMB===$mb['mailbox']; $mbUnread=$unreadMap[$mb['mailbox']]??0; ?>
      <a href="?mb=<?= esc($mb['mailbox']) ?>&folder=INBOX" onclick="closeSidebar()"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-0.5 transition-all border <?= $active?'bg-brand-600/15 border-brand-600/30 text-brand-300':'text-gray-400 hover:bg-gray-800 hover:text-white border-transparent' ?>">
        <div class="w-10 h-10 rounded-lg flex-shrink-0 flex items-center justify-center text-sm font-bold <?= $active?'bg-brand-600 text-white':'bg-gray-800 text-gray-400' ?>">
          <?= esc(strtoupper(mb_substr((string)($mb['name']??$mb['mailbox']),0,2))) ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium truncate"><?= esc($mb['name']?:'Voicemail '.$mb['mailbox']) ?></div>
          <div class="text-[11px] mt-0.5 <?= $active?'text-brand-400/70':'text-gray-600' ?>">Voicemail <?= esc($mb['mailbox']) ?></div>
        </div>
        <?php if ($mbUnread>0): ?>
        <span class="text-[11px] bg-brand-600 text-white font-bold px-2 py-0.5 rounded-full flex-shrink-0"><?= $mbUnread ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="px-2 py-3 border-t border-gray-800">
      <a href="?action=logout" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-gray-500 hover:text-white hover:bg-gray-800 transition text-sm">
        <span class="pointer-events-none"><?= icon('logout','w-4 h-4') ?></span><span>Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-gray-950">

    <?php if ($curMB===null): ?>
    <div class="flex-1 flex flex-col items-center justify-center text-center p-8">
      <button onclick="openSidebar()" class="md:hidden mb-6 flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-sm font-semibold transition">
        <span class="pointer-events-none"><?= icon('menu','w-4 h-4') ?></span>
        <span class="pointer-events-none">Select Mailbox</span>
      </button>
      <div class="w-20 h-20 rounded-3xl bg-gray-900 border border-gray-800 flex items-center justify-center mb-6">
        <?= icon('voicemail','w-10 h-10 text-gray-600') ?>
      </div>
      <h2 class="text-xl font-bold text-gray-300 mb-2">Select a Voicemail Box</h2>
      <p class="text-gray-600 text-sm max-w-xs">Choose a mailbox from the sidebar to view and manage your messages.</p>
    </div>

    <?php else: ?>
    <header class="flex-shrink-0 px-4 py-3 bg-gray-900/60 border-b border-gray-800 flex items-center gap-3 backdrop-blur-sm">
      <button onclick="openSidebar()" class="md:hidden w-10 h-10 flex-shrink-0 flex items-center justify-center rounded-xl text-gray-400 hover:text-white hover:bg-gray-800 transition">
        <span class="pointer-events-none"><?= icon('menu','w-5 h-5') ?></span>
      </button>
      <div class="flex-1 min-w-0">
        <h1 class="text-base sm:text-lg font-bold text-white truncate"><?= esc($curMBData['name']??'Voicemail '.$curMB) ?></h1>
        <div class="flex items-center gap-2">
          <span class="text-xs text-gray-500">Voicemail <?= esc($curMB) ?></span>
          <?php if ($unread>0): ?>
          <span class="text-gray-700">·</span>
          <span class="text-xs font-semibold text-brand-400"><?= $unread ?> unread</span>
          <?php endif; ?>
        </div>
      </div>
      <a href="?action=refresh&mb=<?= esc($curMB) ?>&folder=<?= esc($curFolder) ?>"
         class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-xl text-gray-500 hover:text-white hover:bg-gray-800 transition" title="Refresh">
        <span class="pointer-events-none"><?= icon('refresh','w-4 h-4') ?></span>
      </a>
    </header>

    <div class="flex-shrink-0 border-b border-gray-800 bg-gray-900/30 flex items-end overflow-x-auto px-2">
      <?php foreach ($FOLDERS as $fVal=>$fLabel): $tabActive=$curFolder===$fVal; ?>
      <a href="?mb=<?= esc($curMB) ?>&folder=<?= esc($fVal) ?>"
         class="px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors <?= $tabActive?'border-brand-500 text-brand-400':'border-transparent text-gray-500 hover:text-gray-300 hover:border-gray-600' ?>">
        <?= esc($fLabel) ?>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if ($dataErr): ?>
    <div class="mx-4 mt-4 p-3.5 rounded-xl bg-red-950/50 border border-red-800/60 text-red-300 text-sm"><?= esc($dataErr) ?></div>
    <?php endif; ?>

    <div class="flex-1 overflow-y-auto p-3 sm:p-5">
      <?php if (empty($messages)&&!$dataErr): ?>
      <div class="flex flex-col items-center justify-center h-full text-center py-16">
        <div class="w-16 h-16 rounded-2xl bg-gray-900 border border-gray-800 flex items-center justify-center mb-4">
          <?= icon('empty','w-8 h-8 text-gray-700') ?>
        </div>
        <p class="text-gray-400 font-medium">No messages in <?= esc($FOLDERS[$curFolder]??$curFolder) ?></p>
        <p class="text-gray-600 text-sm mt-1">New voicemails will appear here.</p>
      </div>
      <?php else: ?>
      <?php if (count($messages)>0): ?>
      <div class="flex items-center justify-between mb-3">
        <span class="text-sm text-gray-500"><?= count($messages) ?> message<?= count($messages)!==1?'s':'' ?></span>
        <button data-action="mark-all" class="text-xs px-3 py-2 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-white transition flex items-center gap-1.5">
          <span class="pointer-events-none"><?= icon('check','w-3.5 h-3.5') ?></span>
          <span class="pointer-events-none">Mark all read</span>
        </button>
      </div>
      <?php endif; ?>

      <div class="space-y-2" id="msg-list">
        <?php foreach ($messages as $msg):
          $listened=isListened($msg); $cid=parseCID($msg['callerid']??'');
          $num=$msg['message_num']??'';
          $initials=strtoupper(mb_substr($cid['name'],0,1)?:'?');
          $colors=['bg-purple-800','bg-blue-800','bg-emerald-800','bg-amber-800','bg-rose-800','bg-cyan-800','bg-indigo-800'];
          $avatarBg=$listened?'bg-gray-800 text-gray-500':($colors[ord($initials)%count($colors)].' text-white');
        ?>
        <div class="msg-card bg-gray-900 border rounded-2xl overflow-hidden <?= $listened?'border-gray-800':'border-gray-700' ?>"
             id="msg-<?= esc($num) ?>" data-mb="<?= esc($curMB) ?>" data-folder="<?= esc($curFolder) ?>" data-num="<?= esc($num) ?>">
          <div class="flex items-center gap-2 sm:gap-3 px-3 sm:px-4 py-3 sm:py-3.5">
            <div class="flex-shrink-0 unread-dot" style="<?= $listened?'opacity:0':'' ?>">
              <div class="w-2 h-2 rounded-full bg-brand-500"></div>
            </div>
            <div class="w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-sm <?= $avatarBg ?>">
              <?= esc($initials) ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-sm truncate caller-name <?= $listened?'text-gray-400':'text-white' ?>"><?= esc($cid['name']) ?></div>
              <div class="text-xs text-gray-600 truncate mt-0.5 flex items-center gap-1.5">
                <?php if ($cid['num']!==$cid['name']): ?><span class="text-gray-500"><?= esc($cid['num']) ?></span><span class="text-gray-700">·</span><?php endif; ?>
                <span><?= esc(fmtDate($msg['date']??'')) ?></span>
              </div>
            </div>
            <div class="hidden sm:flex flex-shrink-0 text-xs font-medium px-2.5 py-1 rounded-lg <?= $listened?'bg-gray-800/50 text-gray-600':'bg-gray-800 text-gray-300' ?>">
              <?= esc(fmtDur($msg['duration']??0)) ?>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
              <button data-action="play"
                      class="play-btn w-10 h-10 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center bg-brand-600 hover:bg-brand-500 active:bg-brand-700 text-white transition shadow-sm" title="Play / Pause">
                <span class="play-icon pointer-events-none"><?= icon('play','w-4 h-4') ?></span>
                <span class="pause-icon pointer-events-none hidden"><?= icon('pause','w-4 h-4') ?></span>
              </button>
              <a href="?action=audio&mb=<?= esc($curMB) ?>&folder=<?= esc($curFolder) ?>&num=<?= esc($num) ?>"
                 download="voicemail-<?= esc($num) ?>.mp3"
                 class="hidden sm:flex w-9 h-9 rounded-xl items-center justify-center bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-white transition" title="Download">
                <span class="pointer-events-none"><?= icon('download','w-4 h-4') ?></span>
              </a>
              <button data-action="mark-listened"
                      class="mark-btn w-10 h-10 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center bg-gray-800 hover:bg-emerald-900/60 text-gray-400 hover:text-emerald-400 transition <?= $listened?'opacity-30 pointer-events-none':'' ?>"
                      title="Mark as listened" <?= $listened?'disabled':'' ?>>
                <span class="pointer-events-none"><?= icon('check','w-4 h-4') ?></span>
              </button>
              <button data-action="delete"
                      class="del-btn w-10 h-10 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center bg-gray-800 hover:bg-red-950/60 text-gray-400 hover:text-red-400 transition" title="Delete">
                <span class="pointer-events-none"><?= icon('trash','w-4 h-4') ?></span>
              </button>
            </div>
          </div>
          <div class="sm:hidden px-3 pb-2 -mt-1">
            <span class="text-[11px] <?= $listened?'text-gray-700':'text-gray-500' ?>"><?= esc(fmtDur($msg['duration']??0)) ?></span>
          </div>
          <div class="player-wrap px-3 sm:px-4" id="player-<?= esc($num) ?>">
            <div class="pb-3 border-t border-gray-800/60 pt-3">
              <audio id="audio-<?= esc($num) ?>" controls preload="none" class="w-full h-10 rounded-lg" style="accent-color:#0ea5e9"></audio>
              <p class="mt-1.5 text-xs text-gray-600 audio-status">Click play to load…</p>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </main>
</div>
<?php endif; ?>

<div id="toast-wrap" class="fixed bottom-5 right-4 z-50 flex flex-col gap-2 items-end pointer-events-none"></div>

<script>
var CSRF = (document.querySelector('meta[name="csrf-token"]')||{}).content||'';

function openSidebar()  { document.getElementById('sidebar').classList.add('sidebar-open');    document.getElementById('backdrop').classList.add('backdrop-open'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('sidebar-open'); document.getElementById('backdrop').classList.remove('backdrop-open'); }

function handleExpired() {
  var el=document.createElement('div');
  el.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;z-index:9999;color:#fde68a;font-size:.95rem;font-family:sans-serif';
  el.textContent='Session expired — redirecting…';
  document.body.appendChild(el);
  setTimeout(function(){ window.location.href='?'; },1800);
}

function toast(msg,type) {
  var c={info:'bg-gray-800 text-white',success:'bg-emerald-800 text-emerald-100',error:'bg-red-900 text-red-100'};
  var t=document.createElement('div');
  t.className='pointer-events-auto px-4 py-2.5 rounded-xl text-sm shadow-xl border border-white/10 max-w-xs '+(c[type]||c.info);
  t.textContent=msg;
  document.getElementById('toast-wrap').appendChild(t);
  setTimeout(function(){ t.style.transition='opacity .4s'; t.style.opacity='0'; setTimeout(function(){ t.remove(); },400); },3500);
}

function post(url,data) {
  data.csrf=CSRF;
  return fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data).toString()})
    .then(function(r){
      if (r.status===401){handleExpired();return Promise.reject(new Error('expired'));}
      if (r.status===403){toast('Request blocked — please reload the page.','error');return Promise.reject(new Error('csrf'));}
      return r.json();
    });
}

var activeCard=null, blobUrlCache={};

function togglePlay(card) {
  var num=card.dataset.num,mb=card.dataset.mb,folder=card.dataset.folder;
  var btn=card.querySelector('.play-btn');
  var wrap=document.getElementById('player-'+num);
  var audio=document.getElementById('audio-'+num);
  var status=wrap.querySelector('.audio-status');

  if (activeCard&&activeCard!==card) {
    var pn=activeCard.dataset.num;
    var pa=document.getElementById('audio-'+pn);
    var pw=document.getElementById('player-'+pn);
    var pb=activeCard.querySelector('.play-btn');
    if(pa)pa.pause(); if(pw)pw.classList.remove('open'); if(pb)setPlay(pb,true);
  }

  if (!wrap.classList.contains('open')) {
    wrap.classList.add('open'); activeCard=card;
    if (!audio.src) {
      if (blobUrlCache[num]) { audio.src=blobUrlCache[num]; audio.load(); audio.play().catch(function(){}); status.textContent=''; }
      else { status.textContent='Loading…'; status.style.color=''; loadAudio(audio,status,mb,folder,num); }
    } else { audio.play().catch(function(){}); }
    setPlay(btn,false); autoMarkListened(card,null);
  } else {
    if (audio.paused){audio.play().catch(function(){});setPlay(btn,false);}
    else{audio.pause();setPlay(btn,true);}
  }
  audio.onplay =function(){setPlay(btn,false);};
  audio.onpause=function(){setPlay(btn,true);};
  audio.onended=function(){setPlay(btn,true);wrap.classList.remove('open');activeCard=null;};
  audio.onerror=function(){status.textContent='⚠ Error playing audio.';status.style.color='#f87171';setPlay(btn,true);};
}

function setPlay(btn,showPlay) {
  btn.querySelector('.play-icon').classList.toggle('hidden',!showPlay);
  btn.querySelector('.pause-icon').classList.toggle('hidden',showPlay);
}

function loadAudio(audio,status,mb,folder,num) {
  var url='?action=audio&mb='+encodeURIComponent(mb)+'&folder='+encodeURIComponent(folder)+'&num='+encodeURIComponent(num);
  fetch(url).then(function(res){
    if(res.status===401){handleExpired();throw new Error('expired');}
    if(!res.ok) return res.text().then(function(b){
      var d='HTTP '+res.status; try{d=JSON.parse(b).error||d;}catch(e){if(b)d+=': '+b.slice(0,120);}
      throw new Error(d);
    });
    if(res.headers.get('X-Audio-Codec')==='gsm-wav-fallback'){status.textContent='⚠ GSM WAV — install ffmpeg for better compatibility.';status.style.color='#fbbf24';}
    else{status.textContent='';}
    return res.blob();
  }).then(function(blob){
    var objUrl=URL.createObjectURL(blob); blobUrlCache[num]=objUrl;
    audio.src=objUrl; audio.load(); audio.play().catch(function(){});
  }).catch(function(err){
    if(err.message==='expired') return;
    status.textContent='⚠ '+(err.message||'Failed to load audio'); status.style.color='#f87171';
  });
}

function autoMarkListened(card,btn) {
  btn=btn||card.querySelector('.mark-btn');
  if(!btn||btn.disabled) return;
  var orig=btn.innerHTML; btn.disabled=true;
  btn.innerHTML='<span class="pointer-events-none animate-spin inline-block"><?= icon('spin','w-4 h-4') ?></span>';
  post('?action=listened',{mb:card.dataset.mb,folder:card.dataset.folder,num:card.dataset.num}).then(function(d){
    if(d.ok){
      var dot=card.querySelector('.unread-dot'),name=card.querySelector('.caller-name');
      if(dot){dot.style.transition='opacity .3s';dot.style.opacity='0';}
      if(name){name.style.color='#9ca3af';}
      btn.style.opacity='0.3'; btn.style.pointerEvents='none'; btn.innerHTML=orig;
      toast('Marked as listened','success');
    } else { btn.disabled=false; btn.innerHTML=orig; toast('Failed: '+(d.err||'unknown'),'error'); }
  }).catch(function(e){ if(e.message==='expired'||e.message==='csrf') return; btn.disabled=false; btn.innerHTML=orig; toast('Network error: '+(e.message||'unknown'),'error'); });
}

function deleteMsg(card,btn) {
  if(!confirm('Delete this voicemail? This cannot be undone.')) return;
  btn.disabled=true;
  post('?action=delete',{mb:card.dataset.mb,folder:card.dataset.folder,num:card.dataset.num}).then(function(d){
    if(d.ok){
      var num=card.dataset.num;
      if(blobUrlCache[num]){URL.revokeObjectURL(blobUrlCache[num]);delete blobUrlCache[num];}
      card.classList.add('removing'); setTimeout(function(){card.remove();},280); toast('Message deleted','success');
    } else { btn.disabled=false; toast('Delete failed: '+(d.err||'unknown'),'error'); }
  }).catch(function(e){ if(e.message==='expired'||e.message==='csrf') return; btn.disabled=false; toast('Network error: '+(e.message||'unknown'),'error'); });
}

var list=document.getElementById('msg-list');
if(list) list.addEventListener('click',function(e){
  var p=e.target.closest('[data-action="play"]');       if(p){togglePlay(p.closest('.msg-card'));return;}
  var m=e.target.closest('[data-action="mark-listened"]'); if(m&&!m.disabled){autoMarkListened(m.closest('.msg-card'),m);return;}
  var d=e.target.closest('[data-action="delete"]');     if(d){deleteMsg(d.closest('.msg-card'),d);return;}
});
var ma=document.querySelector('[data-action="mark-all"]');
if(ma) ma.addEventListener('click',function(){
  document.querySelectorAll('[data-action="mark-listened"]:not([disabled])').forEach(function(b){autoMarkListened(b.closest('.msg-card'),b);});
});
</script>
</body>
</html>
