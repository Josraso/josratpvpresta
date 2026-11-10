<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex,nofollow">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>TPV - {$jtp_shop_name|escape:'html':'UTF-8'}</title>
<link rel="icon" href="{$jtp_favicon|escape:'html':'UTF-8'}">
{foreach from=$jtp_css item=css}<link rel="stylesheet" href="{$css|escape:'html':'UTF-8'}">
{/foreach}
{foreach from=$jtp_addon_css item=css}<link rel="stylesheet" href="{$css|escape:'html':'UTF-8'}">
{/foreach}
</head>
<body>
<div id="jtp-app" class="jtp-app"><div class="jtp-loading">Cargando TPV…</div></div>
<div id="jtp-print-area" aria-hidden="true"></div>
<script>window.JTP_CONFIG = {$jtp_config_json nofilter};</script>
{foreach from=$jtp_js item=js}<script src="{$js|escape:'html':'UTF-8'}"></script>
{/foreach}
{foreach from=$jtp_addon_js item=js}<script src="{$js|escape:'html':'UTF-8'}"></script>
{/foreach}
<script>JTP.start();</script>
</body>
</html>
