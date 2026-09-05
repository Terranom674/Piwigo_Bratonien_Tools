<?php
$piwigo_root = realpath(dirname(__DIR__, 2));
if ($piwigo_root === false)
{
  http_response_code(500);
  exit;
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  http_response_code(404);
  exit('Bratonien Tools ist nicht aktiv.');
}

require_once(BRATONIEN_TOOLS_PATH.'include/customer_qr_upload.inc.php');

$settings = bratonien_tools_customer_qr_settings();
if (empty($settings['enabled']))
{
  http_response_code(404);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR-Code Upload</title></head><body><main style="max-width:720px;margin:48px auto;padding:24px;font-family:system-ui,sans-serif"><h1>QR-Code Upload</h1><p>Dieses Formular ist derzeit nicht aktiviert.</p></main></body></html>';
  exit;
}

bratonien_tools_customer_qr_ensure_storage();

if ((string)($_GET['action'] ?? '') === 'check')
{
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');

  try
  {
    $year = bratonien_tools_customer_qr_year($_GET['year'] ?? '');
    $number = bratonien_tools_customer_qr_number($_GET['number'] ?? '');
    $exists = bratonien_tools_customer_qr_exists($year, $number);
    echo json_encode(array(
      'ok' => true,
      'available' => !$exists,
      'year' => $year,
      'number' => $number,
      'message' => $exists
        ? 'Diese QR-Code-Nummer ist für '.$year.' bereits vorhanden.'
        : 'Nummer ist für '.$year.' frei.',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  catch (Throwable $e)
  {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
  $results = array();
  $year = bratonien_tools_customer_qr_default_year();

  try
  {
    if (function_exists('check_pwg_token'))
    {
      check_pwg_token();
    }

    $year = bratonien_tools_customer_qr_year($_POST['upload_year'] ?? '');

    if (
      empty($_FILES['qr_files'])
      && empty($_POST)
      && !empty($_SERVER['CONTENT_LENGTH'])
    )
    {
      throw new RuntimeException('Der Upload überschreitet das PHP-Limit post_max_size ('.ini_get('post_max_size').').');
    }

    if (empty($_FILES['qr_files']))
    {
      throw new RuntimeException('Es wurden keine QR-Code-Dateien ausgewählt.');
    }

    $numbers = isset($_POST['qr_numbers']) && is_array($_POST['qr_numbers'])
      ? array_values($_POST['qr_numbers'])
      : array();

    $results = bratonien_tools_customer_qr_process_uploads($year, $_FILES['qr_files'], $numbers);
  }
  catch (Throwable $e)
  {
    $results[] = array(
      'status' => 'error',
      'file' => '',
      'year' => $year,
      'number' => '',
      'message' => $e->getMessage(),
    );
  }

  $_SESSION['bratonien_customer_qr_flash'] = array(
    'year' => $year,
    'results' => $results,
  );

  $target = strtok($_SERVER['REQUEST_URI'], '?');
  header('Location: '.$target, true, 303);
  exit;
}

$flash = array();
if (!empty($_SESSION['bratonien_customer_qr_flash']) && is_array($_SESSION['bratonien_customer_qr_flash']))
{
  $flash = $_SESSION['bratonien_customer_qr_flash'];
  unset($_SESSION['bratonien_customer_qr_flash']);
}

$selected_year = isset($flash['year']) ? (int)$flash['year'] : bratonien_tools_customer_qr_default_year();
$results = isset($flash['results']) && is_array($flash['results']) ? $flash['results'] : array();
$token = function_exists('get_pwg_token') ? get_pwg_token() : '';
$max_files = max(1, (int)ini_get('max_file_uploads'));
$endpoint = htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?'), ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>QR-Code Upload</title>
  <style>
    :root{color-scheme:light dark;--bg:#111214;--card:#1a1c20;--text:#f2f2f2;--muted:#a9adb5;--border:#383c44;--accent:#d99a43;--ok:#67b779;--bad:#d46b6b;--wait:#c7a65a}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    main{max-width:860px;margin:0 auto;padding:34px 18px 60px}
    h1{font-size:clamp(1.8rem,5vw,2.6rem);margin:0 0 8px}
    h2{font-size:1.12rem;margin:0 0 12px}
    p{margin:0 0 16px}.muted{color:var(--muted)}
    .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;margin-top:18px}
    .grid{display:grid;grid-template-columns:minmax(0,180px) minmax(0,1fr);gap:14px 18px;align-items:center}
    label{font-weight:650}.control{min-width:0}
    select,input[type="text"],input[type="file"]{width:100%;font:inherit;color:inherit;background:#121418;border:1px solid var(--border);border-radius:9px;padding:11px 12px}
    input[type="file"]{padding:9px}
    input:focus,select:focus,button:focus{outline:2px solid var(--accent);outline-offset:2px}
    .file-list{display:grid;gap:10px;margin-top:16px}
    .file-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(120px,180px) minmax(170px,220px);gap:10px;align-items:center;padding:12px;border:1px solid var(--border);border-radius:10px;background:#15171b}
    .file-name{overflow-wrap:anywhere}.status{font-size:.92rem;color:var(--muted)}.status.ok{color:var(--ok)}.status.bad{color:var(--bad)}.status.wait{color:var(--wait)}
    .actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:18px}
    button{border:0;border-radius:9px;padding:12px 18px;background:var(--accent);color:#1a140d;font:inherit;font-weight:750;cursor:pointer}
    button:disabled{opacity:.45;cursor:not-allowed}
    .result{padding:12px 14px;border-radius:9px;margin-top:8px;border:1px solid var(--border)}
    .result.ok{border-color:color-mix(in srgb,var(--ok),transparent 45%)}.result.duplicate,.result.error{border-color:color-mix(in srgb,var(--bad),transparent 45%)}
    .result strong{display:block}.meta{color:var(--muted);font-size:.92rem}
    @media(max-width:680px){.grid{grid-template-columns:1fr}.file-row{grid-template-columns:1fr}.card{padding:16px}}
  </style>
</head>
<body>
<main>
  <header>
    <h1>QR-Code Upload</h1>
    <p class="muted">Lade einen oder mehrere QR-Codes hoch und ordne jedem Code eine eindeutige Nummer zu.</p>
  </header>

  <?php if (!empty($results)): ?>
    <section class="card" aria-labelledby="upload-result-title">
      <h2 id="upload-result-title">Ergebnis</h2>
      <?php foreach ($results as $result): ?>
        <?php $status = in_array(($result['status'] ?? ''), array('ok','duplicate','error'), true) ? $result['status'] : 'error'; ?>
        <div class="result <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
          <strong><?php echo $status === 'ok' ? 'Gespeichert' : ($status === 'duplicate' ? 'Nicht gespeichert: Nummer bereits vergeben' : 'Nicht gespeichert'); ?></strong>
          <?php if (!empty($result['file'])): ?><div><?php echo htmlspecialchars($result['file'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
          <?php if (!empty($result['number'])): ?><div class="meta">Jahr <?php echo (int)$result['year']; ?> · QR-Code #<?php echo htmlspecialchars($result['number'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
          <div class="meta"><?php echo htmlspecialchars((string)($result['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <form class="card" method="post" enctype="multipart/form-data" id="qr-upload-form" novalidate>
    <input type="hidden" name="pwg_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="grid">
      <label for="upload-year">Jahr</label>
      <div class="control">
        <select id="upload-year" name="upload_year" required>
          <?php for ($year = 2023; $year <= 2048; $year++): ?>
            <option value="<?php echo $year; ?>"<?php echo $year === $selected_year ? ' selected' : ''; ?>><?php echo $year; ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <label for="qr-files">QR-Code-Datei(en)</label>
      <div class="control">
        <input id="qr-files" name="qr_files[]" type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple required>
        <div class="muted" style="margin-top:6px">PNG, JPG, WEBP oder GIF. Batch-Upload bis zum Serverlimit von <?php echo $max_files; ?> Dateien pro Anfrage.</div>
      </div>
    </div>

    <div id="file-list" class="file-list" aria-live="polite"></div>

    <div class="actions">
      <button id="submit-button" type="submit" disabled>QR-Code(s) hochladen</button>
      <span class="muted">Eine Nummer kann innerhalb eines Jahres nur einmal verwendet werden.</span>
    </div>
  </form>
</main>

<script>
(function(){
  'use strict';
  var form=document.getElementById('qr-upload-form');
  var fileInput=document.getElementById('qr-files');
  var yearInput=document.getElementById('upload-year');
  var list=document.getElementById('file-list');
  var submit=document.getElementById('submit-button');
  var endpoint=<?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  var timers=new WeakMap();

  function canonical(value){
    value=String(value||'').trim();
    if(!/^\d{1,32}$/.test(value))return '';
    value=value.replace(/^0+(?=\d)/,'');
    return value;
  }

  function rows(){return Array.prototype.slice.call(list.querySelectorAll('.file-row'));}

  function setStatus(row,text,kind){
    var node=row.querySelector('.status');
    node.textContent=text;
    node.className='status'+(kind?' '+kind:'');
    row.dataset.valid=kind==='ok'?'1':'0';
    updateSubmit();
  }

  function updateSubmit(){
    var all=rows();
    submit.disabled=!all.length||all.some(function(row){return row.dataset.valid!=='1';});
  }

  function markBatchDuplicates(){
    var grouped={};
    rows().forEach(function(row){
      var input=row.querySelector('input[name="qr_numbers[]"]');
      var value=canonical(input.value);
      if(!value)return;
      if(!grouped[value])grouped[value]=[];
      grouped[value].push(row);
    });
    Object.keys(grouped).forEach(function(key){
      if(grouped[key].length>1){
        grouped[key].forEach(function(row){setStatus(row,'Nummer ist in diesem Batch doppelt.','bad');});
      }
    });
    return grouped;
  }

  function checkRow(row){
    var input=row.querySelector('input[name="qr_numbers[]"]');
    var value=canonical(input.value);
    if(!value){setStatus(row,'Bitte eine Nummer aus 1 bis 32 Ziffern eingeben.','bad');return;}

    var grouped=markBatchDuplicates();
    if(grouped[value]&&grouped[value].length>1)return;

    setStatus(row,'Nummer wird geprüft …','wait');
    var url=endpoint+'?action=check&year='+encodeURIComponent(yearInput.value)+'&number='+encodeURIComponent(value)+'&_='+Date.now();
    fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
      .then(function(response){return response.json().then(function(data){return {response:response,data:data};});})
      .then(function(result){
        if(!result.response.ok||!result.data.ok)throw new Error(result.data.message||'Prüfung fehlgeschlagen.');
        if(result.data.available)setStatus(row,result.data.message,'ok');
        else setStatus(row,result.data.message,'bad');
      })
      .catch(function(error){setStatus(row,error.message||'Prüfung fehlgeschlagen.','bad');});
  }

  function scheduleCheck(row){
    var old=timers.get(row);if(old)window.clearTimeout(old);
    timers.set(row,window.setTimeout(function(){checkRow(row);},250));
  }

  function rebuild(){
    list.textContent='';
    Array.prototype.slice.call(fileInput.files||[]).forEach(function(file,index){
      var row=document.createElement('div');row.className='file-row';row.dataset.valid='0';
      var name=document.createElement('div');name.className='file-name';name.textContent=file.name;
      var input=document.createElement('input');input.type='text';input.name='qr_numbers[]';input.inputMode='numeric';input.autocomplete='off';input.placeholder='QR-Nummer';input.setAttribute('aria-label','QR-Code-Nummer für '+file.name);input.maxLength=32;input.required=true;
      var status=document.createElement('div');status.className='status';status.textContent='Nummer fehlt.';
      row.appendChild(name);row.appendChild(input);row.appendChild(status);list.appendChild(row);
      input.addEventListener('input',function(){scheduleCheck(row);});
      if(index===0)window.setTimeout(function(){input.focus();},0);
    });
    updateSubmit();
  }

  fileInput.addEventListener('change',rebuild);
  yearInput.addEventListener('change',function(){rows().forEach(function(row){scheduleCheck(row);});});
  form.addEventListener('submit',function(event){
    var invalid=rows().some(function(row){return row.dataset.valid!=='1';});
    if(invalid){event.preventDefault();rows().forEach(function(row){if(row.dataset.valid!=='1')checkRow(row);});}
    else{submit.disabled=true;submit.textContent='Upload läuft …';}
  });
})();
</script>
</body>
</html>
