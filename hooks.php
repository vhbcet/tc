<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

use WHMCS\Database\Capsule;

/** -------- Settings Loader -------- */
function tcknver_setting($key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $rows = Capsule::table('tbladdonmodules')
            ->where('module', 'tcknverifier')->get(['setting','value']);
        $cache = [];
        foreach ($rows as $r) { $cache[$r->setting] = $r->value; }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function tcknver_field_id()
{
    $fieldId = (int) tcknver_setting('FieldId', 0);
    if ($fieldId > 0) { return $fieldId; }
    $name = (string) tcknver_setting('FieldName', 'TC Kimlik No');

    // Try multiple variants (with/without dots)
    $candidates = [$name, 'T.C Kimlik No', 'T.C. Kimlik No', 'TC Kimlik No', 'TCKN', 'T.C No'];
    $id = Capsule::table('tblcustomfields')
        ->where('type','client')
        ->whereIn('fieldname', $candidates)
        ->value('id');
    return (int) $id;
}

/** -------- Helpers -------- */
function tcknver_normalize($s)
{
    return preg_replace('/\D+/', '', (string) $s);
}

function tcknver_is_valid($t)
{
    $t = tcknver_normalize($t);
    if (!preg_match('/^[1-9][0-9]{10}$/', $t)) { return false; }
    $d = array_map('intval', str_split($t));
    $odd  = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $even = $d[1] + $d[3] + $d[5] + $d[7];
    $d10  = (($odd * 7) - $even) % 10; if ($d10 < 0) $d10 += 10;
    if ($d[9] !== $d10) return false;
    $d11 = (array_sum(array_slice($d, 0, 10))) % 10;
    return $d[10] === $d11;
}

function tcknver_get_value_from_hook_vars(array $vars, int $fieldId)
{
    foreach (['customfields','customfield'] as $k) {
        if (!empty($vars[$k]) && is_array($vars[$k])) {
            if (isset($vars[$k][$fieldId])) return (string) $vars[$k][$fieldId];
        }
    }
    $post = $_POST ?? [];
    foreach (['customfields','customfield'] as $k) {
        if (!empty($post[$k][$fieldId])) return (string) $post[$k][$fieldId];
    }
    return '';
}

function tcknver_get_existing_value(int $clientId, int $fieldId)
{
    $row = Capsule::table('tblcustomfieldsvalues')
        ->where('relid', $clientId)
        ->where('fieldid', $fieldId)
        ->first(['value']);
    return $row ? tcknver_normalize((string) $row->value) : '';
}

function tcknver_exists_on_another(int $fieldId, string $value, int $currentClientId = 0)
{
    $value = tcknver_normalize($value);

    $rows = Capsule::table('tblcustomfieldsvalues AS v')
        ->join('tblcustomfields AS f', 'f.id', '=', 'v.fieldid')
        ->join('tblclients AS c', 'c.id', '=', 'v.relid')
        ->where('f.type', 'client')
        ->where('v.fieldid', $fieldId)
        ->when($currentClientId > 0, function($q) use ($currentClientId) {
            $q->where('c.id', '!=', $currentClientId);
        })
        ->get(['c.id as client_id','v.value']);

    foreach ($rows as $row) {
        if ($value !== '' && tcknver_normalize($row->value) == $value) {
            if (tcknver_setting('DebugLog')) {
                if (function_exists('logActivity')) {
                    logActivity('TCKN duplicate hit on client #'.$row->client_id.' for '.$value);
                }
            }
            return true;
        }
    }
    return false;
}

/** -------- Validation Hooks -------- */
add_hook('ClientDetailsValidation', 1, function ($vars) {
    return tcknver_validate($vars, 'client');
});

add_hook('ClientEditValidation', 1, function ($vars) {
    return tcknver_validate($vars, 'admin');
});

function tcknver_validate(array $vars, string $context = 'client')
{
    $errors = [];
    $fieldId = tcknver_field_id();
    if (!$fieldId) return $errors; // field yoksa sessizce geç

    $valNew = tcknver_normalize(tcknver_get_value_from_hook_vars($vars, $fieldId));

    // Required
    if ($valNew === '') {
        $prefix = (tcknver_setting('FieldName') ?: 'TC Kimlik No');
        // mirror user label if they used dots
        $label = in_array($prefix, ['T.C Kimlik No','T.C. Kimlik No']) ? $prefix : 'TC Kimlik No';
        $errors[] = $label . ': Bu alan zorunludur.';
        return $errors;
    }

    // Immutable check
    $immutable   = (bool) tcknver_setting('ImmutableAfterCreate', 'on');
    $allowClient = (bool) tcknver_setting('AllowClientEdit', '');
    $allowAdmin  = (bool) tcknver_setting('AllowAdminEdit', 'on');

    $cid = 0;
    if (!empty($vars['userid'])) { $cid = (int) $vars['userid']; }
    elseif (!empty($vars['clientid'])) { $cid = (int) $vars['clientid']; }

    if ($immutable && $cid) {
        $old = tcknver_get_existing_value($cid, $fieldId);
        if ($old !== '' && $old !== $valNew) {
            if ($context === 'client' && !$allowClient) {
                $errors[] = 'TC Kimlik değiştirilemez. Değişiklik için destek ekibi ile iletişime geçin.';
                return $errors;
            }
            if ($context === 'admin' && !$allowAdmin) {
                $errors[] = 'TC Kimlik değiştirilemez. Değişiklik için destek ekibi ile iletişime geçin.';
                return $errors;
            }
        }
    }

    // Algorithm
    if (!tcknver_is_valid($valNew)) {
        $errors[] = 'TC Kimlik numarası geçersiz!';
        return $errors;
    }

    // Uniqueness
    if (tcknver_setting('EnforceUnique', 'on')) {
        if (tcknver_exists_on_another($fieldId, $valNew, (int) $cid)) {
            $errors[] = 'TC Kimlik No: Zaten başka bir müşteri kaydında mevcut.';
            return $errors;
        }
    }

    return $errors;
}

/** -------- Client Area UI (Badge + Readonly + Info) -------- */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (!tcknver_setting('EnableUiBadge', 'on')) return '';
    $css = '<style>.tckn-ok{display:inline-flex;align-items:center;gap:6px;padding:2px 8px;border-radius:6px;background:#e6ffed;color:#137333;font-weight:600;font-size:12px;margin-left:8px} .tckn-info{font-size:12px;color:#5f6368;margin-top:6px}</style>';
    return $css;
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!tcknver_setting('EnableUiBadge', 'on')) return '';
    $tpl = $vars['templatefile'] ?? '';
    if ($tpl !== 'clientareadetails') return '';

    $fieldId = tcknver_field_id();
    if (!$fieldId) return '';

    $ro = (tcknver_setting('ImmutableAfterCreate','on') && !tcknver_setting('AllowClientEdit','')) ? 'true' : 'false';
    $info = 'TC Kimlik değiştirilemez. Değişiklik için destek ekibi ile iletişime geçin.';
    $info = addslashes($info);

    return <<<HTML
<script>
(function(){
  var sel = '[name="customfield[{$fieldId}]"], [name="customfields[{$fieldId}]"], [id*="customfield{$fieldId}"]';
  var el = document.querySelector(sel);
  if(!el) return;

  function isValid(v){
    v = (v||'').replace(/\D+/g,'');
    if(!/^[1-9][0-9]{10}$/.test(v)) return false;
    var d=v.split('').map(Number), odd=d[0]+d[2]+d[4]+d[6]+d[8], even=d[1]+d[3]+d[5]+d[7];
    var d10=((odd*7)-even)%10; if(d10<0)d10+=10; if(d[9]!==d10) return false;
    var d11=(d.slice(0,10).reduce((a,b)=>a+b,0))%10; return d[10]===d11;
  }

  var val = (el.value||'').trim();
  if(isValid(val)){
    var badge = document.createElement('span');
    badge.className='tckn-ok';
    badge.textContent='✔️ Kayıtlı';
    if(el.parentElement){ el.parentElement.appendChild(badge); }
  }

  if({$ro}){
    el.setAttribute('readonly','readonly');
    el.style.background='#f8f9fa';
    var info = document.createElement('div');
    info.className='tckn-info';
    info.textContent='{$info}';
    if(el.parentElement){ el.parentElement.appendChild(info); }
  }
})();
</script>
HTML;
});
