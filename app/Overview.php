<?php
declare(strict_types=1);

namespace Centro;

/*
 * Panel de inicio: junta, en un solo pedido y en paralelo, lo importante de todas las apps conectadas
 * (y de la tienda "descargar") y lo deja en un formato unico. Solo lectura. No guarda nada.
 */
final class Overview
{
    /** Apps que el panel conoce por defecto: si hay clave compartida (ecosystem_key) se conectan solas. */
    public const DEFAULTS = [
        ['id' => 'descargar', 'name' => 'Descargar (tienda)', 'url' => 'https://descargar.estrategia.site/export.php', 'kind' => 'tienda'],
        ['id' => 'bookwat', 'name' => 'Bookwat', 'url' => 'https://readingjournal.estrategia.site/export.php', 'kind' => 'app'],
        ['id' => 'zuhause', 'name' => 'Zuhause', 'url' => 'https://zuhause.estrategia.site/export.php', 'kind' => 'app'],
        ['id' => 'gaku', 'name' => 'Gaku', 'url' => 'https://gaku.estrategia.site/export.php', 'kind' => 'app'],
    ];

    /** Agrega a $sources las apps por defecto que falten, usando la clave compartida. */
    public static function withDefaults(array $sources, string $ecoKey): array
    {
        if (strlen($ecoKey) < 32) return $sources;
        $hosts = [];
        foreach ($sources as $s) $hosts[strtolower((string)parse_url((string)($s['url'] ?? ''), PHP_URL_HOST))] = true;
        foreach (self::DEFAULTS as $d) {
            $host = strtolower((string)parse_url($d['url'], PHP_URL_HOST));
            if (isset($hosts[$host]) || isset($sources[$d['id']])) continue;
            $sources[$d['id']] = ['name' => $d['name'], 'url' => $d['url'], 'token' => $ecoKey, 'origen' => 'clave'];
        }
        return $sources;
    }

    public static function kindOf(string $id, array $src): string
    {
        $host = strtolower((string)parse_url((string)($src['url'] ?? ''), PHP_URL_HOST));
        return ($id === 'descargar' || str_starts_with($host, 'descargar.')) ? 'tienda' : 'app';
    }

    /** @return array<string,array{status:int,json:?array,raw_ok:bool}> clave "id|vista" */
    private static function fetchAll(array $jobs, int $timeout): array
    {
        $out = [];
        if (function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $hs = [];
            foreach ($jobs as $key => $j) {
                $ch = curl_init($j['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $j['token'], 'Accept: application/json'],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXFILESIZE => 5000000,
                ]);
                curl_multi_add_handle($mh, $ch);
                $hs[$key] = $ch;
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $st === CURLM_OK);
            foreach ($hs as $key => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $out[$key] = self::parse($code, is_string($body) ? $body : '');
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
            return $out;
        }
        foreach ($jobs as $key => $j) {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
                'header' => "Authorization: Bearer " . $j['token'] . "\r\nAccept: application/json\r\n"]]);
            $raw = @file_get_contents($j['url'], false, $ctx);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})(\s|$)/', $http_response_header[0], $m)) $code = (int)$m[1];
            $out[$key] = self::parse($code, $raw === false ? '' : $raw);
        }
        return $out;
    }

    private static function parse(int $code, string $body): array
    {
        $json = null;
        if ($body !== '' && strlen($body) <= 5000000) {
            try { $d = json_decode($body, true, 64, JSON_THROW_ON_ERROR); if (is_array($d)) $json = $d; } catch (\Throwable) {}
        }
        return ['status' => $code, 'json' => $json];
    }

    private static function reason(int $status): string
    {
        return match (true) {
            $status === 0 => 'no responde',
            $status === 401 => 'la clave no coincide',
            $status === 404 => 'la app todavia no tiene esta vista',
            $status === 429 => 'demasiados intentos, reintenta en un rato',
            $status === 503 => 'la app no tiene la clave configurada',
            default => 'error ' . $status,
        };
    }

    private static function n($v): int { return is_numeric($v) ? (int)$v : 0; }
    private static function f($v): float { return is_numeric($v) ? (float)$v : 0.0; }

    /** @param array<string,array> $sources */
    public static function build(array $sources): array
    {
        $jobs = [];
        foreach ($sources as $id => $src) {
            $tok = (string)($src['token'] ?? '');
            $url = (string)($src['url'] ?? '');
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $parts = parse_url($url);
            if (strlen($tok) < 32 || ($parts['scheme'] ?? '') !== 'https' || !str_ends_with($host, '.estrategia.site')) continue;
            $sep = str_contains($url, '?') ? '&' : '?';
            $kind = self::kindOf((string)$id, $src);
            $views = $kind === 'tienda' ? ['summary&d=30', 'sistema'] : ['summary&d=30', 'customers', 'sistema'];
            foreach ($views as $v) $jobs[$id . '|' . explode('&', $v)[0]] = ['url' => $url . $sep . 'view=' . $v, 'token' => $tok];
        }
        $res = self::fetchAll($jobs, 12);
        $apps = [];
        $tienda = null;
        foreach ($sources as $id => $src) {
            $kind = self::kindOf((string)$id, $src);
            $name = (string)($src['name'] ?? $id);
            $sum = $res[$id . '|summary'] ?? null;
            if ($sum === null) { $apps[] = self::broken((string)$id, $name, $kind, 'sin clave o direccion invalida'); continue; }
            if ($sum['json'] === null || $sum['status'] !== 200) { $x = self::broken((string)$id, $name, $kind, self::reason($sum['status'])); if ($kind === 'tienda') $tienda = $x; else $apps[] = $x; continue; }
            $sis = $res[$id . '|sistema']['json'] ?? null;
            if (($res[$id . '|sistema']['status'] ?? 0) !== 200) $sis = null;
            if ($kind === 'tienda') { $tienda = self::tienda((string)$id, $name, $sum['json'], $sis); continue; }
            $cus = $res[$id . '|customers']['json']['customers'] ?? null;
            if (($res[$id . '|customers']['status'] ?? 0) !== 200 || !is_array($cus)) $cus = [];
            $apps[] = self::app((string)$id, $name, $sum['json'], $cus, $sis);
        }
        $alerts = [];
        foreach (array_merge($apps, $tienda ? [$tienda] : []) as $a) foreach ($a['alertas'] as $al) $alerts[] = $al + ['app' => $a['name'], 'app_id' => $a['id']];
        usort($alerts, fn($x, $y) => ['rojo' => 0, 'amarillo' => 1, 'info' => 2][$x['nivel']] <=> ['rojo' => 0, 'amarillo' => 1, 'info' => 2][$y['nivel']]);
        return ['ok' => true, 'generado' => date('c'), 'apps' => $apps, 'tienda' => $tienda, 'alertas' => $alerts];
    }

    private static function broken(string $id, string $name, string $kind, string $why): array
    {
        return ['id' => $id, 'name' => $name, 'kind' => $kind, 'ok' => false, 'error' => $why,
            'alertas' => [['nivel' => 'rojo', 'texto' => 'No pude leer los datos: ' . $why]]];
    }

    private static function app(string $id, string $name, array $s, array $customers, ?array $sis): array
    {
        $L = is_array($s['landing'] ?? null) ? $s['landing'] : [];
        $T = is_array($L['totales'] ?? null) ? $L['totales'] : [];
        $vis30 = self::n($T['visitas'] ?? $s['landing_visitas_30d'] ?? 0);
        $precio = self::n($T['precio'] ?? $s['landing_llegaron_precio_30d'] ?? 0);
        $clicksSub = self::n($T['clicks_suscribirme'] ?? $s['landing_clicks_suscribirme_30d'] ?? 0);
        $clicksWa = self::n($T['clicks_whatsapp'] ?? $s['landing_clicks_whatsapp_30d'] ?? 0);
        $clicksApp = self::n($T['clicks_app'] ?? $s['landing_clicks_app_30d'] ?? 0);
        $hoy = self::n($L['hoy']['visitas'] ?? $s['landing_visitas_hoy'] ?? 0);
        $ultEv = $L['ultimo_evento'] ?? $s['landing_ultimo_evento'] ?? ($sis['landing_ultimo_evento'] ?? null);
        $tieneDatos = $ultEv !== null || $vis30 > 0;
        $cl = [
            'total' => self::n($s['cuentas_total'] ?? 0), 'con_acceso' => self::n($s['con_acceso'] ?? 0), 'pagando' => self::n($s['pagando'] ?? 0),
            'en_prueba' => self::n($s['en_prueba'] ?? 0), 'gracia' => self::n($s['gracia'] ?? 0), 'solo_lectura' => self::n($s['solo_lectura'] ?? 0),
            'bloqueados' => self::n($s['bloqueados'] ?? 0),
        ];
        $rec = [];
        foreach ($customers as $c) {
            if (!is_array($c)) continue;
            $rec[] = ['nombre' => (string)($c['nombre'] ?? ''), 'email' => (string)($c['email'] ?? ''), 'categoria' => (string)($c['categoria'] ?? ''),
                'alta' => (string)($c['alta'] ?? ''), 'ultimo_ingreso' => $c['ultimo_ingreso'] !== null ? (string)($c['ultimo_ingreso'] ?? '') : '',
                'vence' => $c['vence'] ?? null, 'dias_restantes' => $c['dias_restantes'] ?? null];
        }
        usort($rec, fn($a, $b) => strcmp($b['ultimo_ingreso'] ?: $b['alta'], $a['ultimo_ingreso'] ?: $a['alta']));
        $rec = array_slice($rec, 0, 6);
        $porDia = [];
        foreach (is_array($L['por_dia'] ?? null) ? $L['por_dia'] : [] as $d) if (is_array($d)) $porDia[] = ['dia' => (string)($d['dia'] ?? ''), 'visitas' => self::n($d['visitas'] ?? 0), 'clicks' => self::n($d['clicks'] ?? 0)];
        $origen = [];
        foreach (is_array($L['origen'] ?? null) ? $L['origen'] : [] as $o) if (is_array($o)) $origen[] = ['origen' => (string)($o['origen'] ?? ''), 'visitas' => self::n($o['visitas'] ?? 0), 'clicks' => self::n($o['clicks'] ?? 0)];
        $out = [
            'id' => $id, 'name' => $name, 'kind' => 'app', 'ok' => true, 'generado' => $s['generado'] ?? null,
            'clientes' => $cl, 'mrr_cent' => self::n($s['mrr_cent'] ?? 0),
            'iniciadas_30d' => self::n($s['subs_iniciadas'] ?? $s['suscripciones_iniciadas_30d'] ?? 0),
            'por_vencer_3d' => self::n($s['por_vencer_3d'] ?? 0),
            'landing' => ['visitas_hoy' => $hoy, 'visitas' => $vis30, 'precio' => $precio, 'clicks_sub' => $clicksSub, 'clicks_wa' => $clicksWa, 'clicks_app' => $clicksApp,
                'conv_pct' => $vis30 > 0 ? round($clicksSub * 100 / $vis30, 1) : null, 'ultimo_evento' => $ultEv, 'tiene_datos' => $tieneDatos],
            'por_dia' => $porDia, 'origen' => array_slice($origen, 0, 6), 'recientes' => $rec,
        ];
        $out['alertas'] = self::alertasApp($out, $s, $sis);
        return $out;
    }

    private static function alertasApp(array $a, array $s, ?array $sis): array
    {
        $al = [];
        $add = function (string $n, string $t) use (&$al) { $al[] = ['nivel' => $n, 'texto' => $t]; };
        $d = is_array($sis['diagnostico'] ?? null) ? $sis['diagnostico'] : [];
        $falta = [
            'mp_token_cargado' => ['rojo', 'Falta el token de Mercado Pago: no puede cobrar'],
            'mp_configurado' => ['rojo', 'Mercado Pago no esta configurado'],
            'link_suscripcion_ok' => ['rojo', 'El link de suscripcion de Mercado Pago no esta bien cargado'],
            'webhook_secret_cargado' => ['amarillo', 'Falta la clave secreta del webhook de Mercado Pago'],
            'paypal_configurado' => ['amarillo', 'PayPal no esta configurado'],
            'link_paypal_ok' => ['amarillo', 'El link de suscripcion de PayPal no esta bien cargado'],
            'https_ok' => ['amarillo', 'La direccion de la app no usa https'],
            'mail_real' => ['amarillo', 'Los mails estan en modo prueba: no le llegan a nadie'],
        ];
        foreach ($falta as $k => [$niv, $txt]) if (array_key_exists($k, $d) && $d[$k] === false) $add($niv, $txt);
        $e24 = self::n($sis['notificaciones_error_24h'] ?? $s['eventos_mp_con_error_24h'] ?? 0);
        if ($e24 > 0) $add('rojo', $e24 . ($e24 === 1 ? ' aviso de Mercado Pago con error' : ' avisos de Mercado Pago con error') . ' en las ultimas 24 h');
        $rech = self::n($s['cobros_rechazados_14d'] ?? 0);
        if ($rech > 0) $add('amarillo', $rech . ($rech === 1 ? ' cobro rechazado' : ' cobros rechazados') . ' en 14 dias');
        $sc = self::n($s['sin_cuenta_pagaron'] ?? 0);
        if ($sc > 0) $add('rojo', $sc . ($sc === 1 ? ' persona pago y no creo su cuenta' : ' personas pagaron y no crearon su cuenta'));
        $L = $a['landing'];
        if (!$L['tiene_datos']) $add('amarillo', 'La landing no esta midiendo visitas todavia');
        elseif (!empty($L['ultimo_evento']) && strtotime((string)$L['ultimo_evento']) < time() - 7 * 86400) $add('amarillo', 'La landing no registra visitas hace mas de 7 dias');
        if ($L['visitas'] >= 30 && $L['clicks_sub'] === 0) $add('amarillo', $L['visitas'] . ' visitas en 30 dias y ningun clic en suscribirme');
        elseif ($L['visitas'] >= 30 && $L['conv_pct'] !== null && $L['conv_pct'] < 1.5) $add('info', 'Pocos clics en suscribirme (' . $L['conv_pct'] . '% de las visitas)');
        if ($L['clicks_sub'] >= 5 && $a['iniciadas_30d'] === 0) $add('amarillo', $L['clicks_sub'] . ' clics en suscribirme y ninguna suscripcion iniciada: revisa el link de pago');
        if ($a['por_vencer_3d'] > 0) $add('info', $a['por_vencer_3d'] . ($a['por_vencer_3d'] === 1 ? ' prueba termina' : ' pruebas terminan') . ' en 3 dias');
        return $al;
    }

    private static function tienda(string $id, string $name, array $s, ?array $sis): array
    {
        $out = $s + ['id' => $id, 'name' => $name, 'kind' => 'tienda', 'ok' => true];
        $out['id'] = $id; $out['name'] = $name; $out['kind'] = 'tienda'; $out['ok'] = true;
        $al = [];
        $add = function (string $n, string $t) use (&$al) { $al[] = ['nivel' => $n, 'texto' => $t]; };
        foreach (is_array($sis['alertas'] ?? null) ? $sis['alertas'] : [] as $x) {
            if (is_array($x) && in_array($x['nivel'] ?? '', ['rojo', 'amarillo', 'info'], true) && is_string($x['texto'] ?? null)) $add($x['nivel'], $x['texto']);
        }
        $v = self::n($s['visitantes'] ?? 0);
        $ventas = self::n($s['ventas']['n'] ?? 0);
        if (empty($s['tiene_datos'])) $add('amarillo', 'La tienda todavia no registro visitas');
        elseif ($v >= 30 && $ventas === 0) $add('amarillo', $v . ' visitantes en 30 dias y ninguna venta');
        $out['alertas'] = $al;
        return $out;
    }
}
