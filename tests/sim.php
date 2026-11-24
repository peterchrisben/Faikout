<?php

declare(strict_types=1);

/*
 * Simulation of the Faikout module without Symcon: php tests/sim.php
 * Messages are modelled on the Faikout firmware (Faikout.c, March 2026) and on Kris' module.
 */

require __DIR__ . '/SymconStubs.php';

$dir = dirname(__DIR__) . '/FaikoutDaikin';
Stub::$echoDebug = in_array('-v', $argv, true);
Stub::LoadLocale($dir);
$class = Stub::LoadModule($dir);

$failed = 0;
$passed = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "FEHLER: $what" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
}
function vid(int $inst, string $ident): int
{
    foreach (Stub::$objects as $id => $o) {
        if ($o['type'] === 'variable' && $o['parent'] === $inst && ($o['ident'] ?? '') === $ident) {
            return $id;
        }
    }
    return 0;
}
function val(int $inst, string $ident)
{
    $id = vid($inst, $ident);
    return $id ? Stub::$objects[$id]['value'] : '(fehlt)';
}
function receive(int $inst, string $topic, $payload): void
{
    $json = json_encode([
        'DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => is_string($payload) ? $payload : json_encode($payload)
    ]);
    Stub::$instances[$inst]->ReceiveData($json);
}
function findItem(array $items, string $name): ?array
{
    foreach ($items as $item) {
        if (($item['name'] ?? '') === $name) {
            return $item;
        }
        if (isset($item['items']) && ($found = findItem($item['items'], $name)) !== null) {
            return $found;
        }
    }
    return null;
}
function sentFull(int $inst): array
{
    $ref = new ReflectionProperty(IPSModule::class, 'sentToParent');
    $list = $ref->getValue(Stub::$instances[$inst]);
    $ref->setValue(Stub::$instances[$inst], []);
    return array_map(fn($j) => [json_decode($j, true)['Topic'], json_decode($j, true)['Payload']], $list);
}
/** Sent commands as [topic, decoded payload] – independent of how json_encode writes 22.0 */
function commands(int $inst): array
{
    return array_map(fn($c) => [$c[0], json_decode($c[1], true)], sentFull($inst));
}
function sent(int $inst): array
{
    $ref = new ReflectionProperty(IPSModule::class, 'sentToParent');
    $list = $ref->getValue(Stub::$instances[$inst]);
    $ref->setValue(Stub::$instances[$inst], []);
    return array_map(fn($j) => json_decode($j, true)['Topic'], $list);
}
function attr(int $inst, string $name)
{
    $ref = new ReflectionProperty(IPSModule::class, 'attrs');
    return $ref->getValue(Stub::$instances[$inst])[$name] ?? null;
}
function setAttr(int $inst, string $name, $value): void
{
    $ref = new ReflectionProperty(IPSModule::class, 'attrs');
    $a = $ref->getValue(Stub::$instances[$inst]);
    $a[$name] = $value;
    $ref->setValue(Stub::$instances[$inst], $a);
}
// Stub has no sentToParent property visible by default – make sure it exists
if (!property_exists(IPSModule::class, 'sentToParent')) {
    echo "Stub ohne sentToParent\n";
    exit(1);
}

$realStatus = [
    'online' => true, 'model' => 'FTXM25R', 'home' => 21.5, 'heat' => true, 'fanrpm' => 840, 'comp' => 32,
    'outside' => 4.5, 'liquid' => 35.0, 'hum' => 48.0, 'Whoutside' => 123456, 'Whheating' => 100000, 'Whcooling' => 23456,
    'power' => true, 'mode' => 'H', 'temp' => 22.0, 'demand' => 100, 'fan' => 'Q',
    'swingh' => false, 'swingv' => true, 'econo' => false, 'powerful' => false, 'comfort' => false,
    'streamer' => true, 'sensor' => false, 'quiet' => false,
    'ble' => ['temp' => 21.12, 'hum' => 50.5, 'bat' => 87, 'volt' => 2.981], 'autob' => 'ATC_123456',
    'autor' => 1.0, 'autot' => 21.0, 'auto0' => '00:00', 'auto1' => '07:30', 'autop' => false, 'autoe' => true
];

// ---------------------------------------------------------------- 1 without hostname
$id = Stub::CreateInstance($class);
check('ohne Hostname Status 104', Stub::$objects[$id]['status'] === 104);
check('ohne Hostname keine Variablen', count(array_filter(Stub::$objects, fn($o) => ($o['parent'] ?? 0) === $id)) === 0);
check('ohne Hostname nichts gesendet', sent($id) === []);
receive($id, 'state/GuestAC/status', $realStatus);
check('ohne Hostname wird nichts ausgewertet', vid($id, 'status_power') === 0);

// ---------------------------------------------------------------- 2 hostname set
IPS_SetProperty($id, 'Hostname', 'GuestAC');
IPS_ApplyChanges($id);
check('mit Hostname Status 102', Stub::$objects[$id]['status'] === 102);
check('Status und Einstellungen angefordert', sent($id) === ['command/GuestAC/status', 'setting/GuestAC']);

// ---------------------------------------------------------------- 3 state/<host> with HA extras
receive($id, 'state/GuestAC', ['up' => true, 'id' => '30AEA4CAFE01', 'ipv4' => '192.168.2.77', 'rssi' => -61, 'ssid' => 'Heim',
    'version' => '2026-03-09', 'online' => true, 'power' => true, 'target' => 22.0, 'temp' => 21.6, 'mode' => 'heat', 'fan' => 'auto']);
check('Geräte-ID übernommen', attr($id, 'state_id') === '30AEA4CAFE01');
check('Faikout verbunden', val($id, 'state_up') === true);
check('IP-Adresse', val($id, 'state_ipv4') === '192.168.2.77');
check('Klimagerät erreichbar aus state', val($id, 'status_online') === true);
check('HA-"temp" (Raumtemperatur) landet NICHT in der Solltemperatur', vid($id, 'status_temp') === 0);
check('HA-"mode" (Text) landet NICHT in der Betriebsart', vid($id, 'status_mode') === 0);
check('WLAN-Signal standardmäßig nicht angelegt', vid($id, 'state_rssi') === 0);
check('Firmware-Version', val($id, 'state_version') === '2026-03-09');

// ---------------------------------------------------------------- 4 full status
receive($id, 'state/GuestAC/status', $realStatus);
check('Eingeschaltet', val($id, 'status_power') === true);
check('Betriebsart H → 1', val($id, 'status_mode') === 1);
check('Solltemperatur', val($id, 'status_temp') === 22.0);
check('Lüfter Q → -1', val($id, 'status_fan') === -1);
check('Raumtemperatur', val($id, 'status_home') === 21.5);
check('Energie in kWh', val($id, 'status_Wh') === 123.456);
check('Kompressor', val($id, 'status_comp') === 32);
check('Ausschaltzeit 00:00 → –', val($id, 'status_autooff') === '–');
check('Einschaltzeit', val($id, 'status_autoon') === '07:30');
check('BLE Temperatur', val($id, 'status_ble_temp') === 21.12);
check('BLE Batterie', val($id, 'status_ble_bat') === 87);
check('BLE Spannung standardmäßig nicht angelegt', vid($id, 'status_ble_volt') === 0);
check('Leistungsbegrenzung standardmäßig angelegt', val($id, 'status_demand') === 100);
check('nicht gemeldetes Feld nicht angelegt (Ansaugtemperatur)', vid($id, 'status_inlet') === 0);
check('Variablen ohne Aktion', Stub::$objects[vid($id, 'status_power')]['action'] === 0);
check('Name deutsch', Stub::$objects[vid($id, 'status_temp')]['name'] === 'Solltemperatur');
$p = Stub::$objects[vid($id, 'status_mode')]['profile'];
check('Betriebsart als Text-Intervalle', is_array($p) && ($p['INTERVALS_ACTIVE'] ?? false) === true
    && json_decode($p['INTERVALS'], true)[0]['ConstantValue'] === 'Heizen');

// ---------------------------------------------------------------- 5 periodic report
receive($id, 'Faikout/GuestAC', ['ts' => '2026-11-22T10:00:00Z', 'home' => [21.0, 21.4, 21.9], 'power' => 0.5,
    'mode' => 'H', 'temp' => 22.0, 'fanrpm' => [700, 812, 900], 'heat' => 1, 'Whoutside' => 123500, 'swingv' => false]);
check('Bericht: Mittelwert', val($id, 'status_home') === 21.4);
check('Bericht: Anteil 0.5 überschreibt den Schaltzustand nicht', val($id, 'status_power') === true);
check('Bericht: ganzzahliger Mittelwert', val($id, 'status_fanrpm') === 812);
check('Bericht: false bleibt false', val($id, 'status_swingv') === false);
check('Bericht: Energie', val($id, 'status_Wh') === 123.5);

receive($id, 'Faikin/GuestAC', ['home' => 20.5]);
check('alte Firmware Faikin/<host>', val($id, 'status_home') === 20.5);

// ---------------------------------------------------------------- 6 device ID in topic, foreign devices
receive($id, 'state/30aea4cafe01/status', ['home' => 19.5]);
check('Topic mit Geräte-ID (klein)', val($id, 'status_home') === 19.5);
receive($id, 'state/OtherAC/status', ['home' => 30.0]);
check('fremdes Gerät ignoriert', val($id, 'status_home') === 19.5);
receive($id, 'state/GuestACX/status', ['home' => 30.0]);
check('ähnlicher Name ignoriert', val($id, 'status_home') === 19.5);
receive($id, 'homeassistant/climate/30AEA4CAFE01/config', ['name' => 'x']);
check('HA-Konfiguration ignoriert', val($id, 'status_home') === 19.5);
receive($id, 'command/GuestAC/status', '');
check('eigenes Kommando ignoriert', true);

// Receive filter (Symcon matches it against the JSON string)
$f = Stub::$instances[$id];
$m = new ReflectionMethod($f, 'ReceiveFilter');
$regex = '/' . $m->invoke($f, 'GuestAC', '30AEA4CAFE01') . '/';
check('Filter passt auf Hostname', preg_match($regex, json_encode(['Topic' => 'state/GuestAC/status'])) === 1);
check('Filter passt auf Geräte-ID klein', preg_match($regex, json_encode(['Topic' => 'setting/30aea4cafe01'])) === 1);
check('Filter lässt anderes nicht durch', preg_match($regex, json_encode(['Topic' => 'state/Kitchen/status'])) === 0);

// ---------------------------------------------------------------- 7 unusable values
receive($id, 'state/GuestAC/status', ['temp' => null, 'mode' => 'X', 'fan' => '', 'home' => 'abc', 'power' => 'maybe', 'comp' => [1, 2]]);
check('null ändert nichts', val($id, 'status_temp') === 22.0);
check('unbekannte Betriebsart ändert nichts', val($id, 'status_mode') === 1);
check('Text statt Zahl ändert nichts', val($id, 'status_home') === 19.5);
check('unklarer Schaltzustand ändert nichts', val($id, 'status_power') === true);
receive($id, 'state/GuestAC/status', 'kein json');
receive($id, 'state/GuestAC/status', '');
receive($id, 'state/GuestAC/status', '42');
check('kaputte Nachrichten ohne Absturz', true);

// ---------------------------------------------------------------- 8 offline, errors, settings
receive($id, 'state/GuestAC', 'false');
check('Faikout offline (false)', val($id, 'state_up') === false);
receive($id, 'state/GuestAC', '');
check('leere Nachricht ist kein offline/online', val($id, 'state_up') === false);
receive($id, 'error/GuestAC/failed-set', ['temp' => 21.5]);
check('abgelehnte Änderung', val($id, 'error_failed_set') === 'temp = 21.5');
receive($id, 'error/GuestAC/control', ['field' => 'mode', 'error' => 'Expecting string']);
check('Fehler mit Feld', val($id, 'error_description') === 'control: mode: Expecting string');
receive($id, 'error/GuestAC/not-responding', '');
check('Fehler ohne Inhalt', val($id, 'error_description') === 'not-responding: ');
receive($id, 'setting/GuestAC', ['reporting' => 60, 'livestatus' => false, 'ha' => true]);
$info = json_decode(attr($id, 'DeviceSettings'), true);
check('Einstellungen gelesen', $info === ['reporting' => 60, 'livestatus' => false, 'haenable' => true]);
check('Geräte-Info im Formular', (Stub::$instances[$id]->__formFields()['DeviceInfo']['caption'] ?? '') === 'Geräte-ID 30AEA4CAFE01 · Meldung alle 60 s · Live-Status aus');

// ---------------------------------------------------------------- 9 selection and archive
$outside = vid($id, 'status_outside');
Stub::$archived[$outside] = true;
IPS_SetProperty($id, 'ValueSelection', json_encode([
    ['Show' => false, 'Ident' => 'status_home'],
    ['Show' => false, 'Ident' => 'status_outside'],
    ['Show' => true, 'Ident' => 'status_ble_volt'],
    ['Show' => true, 'Ident' => 'gibtsnicht']
]));
IPS_ApplyChanges($id);
sent($id);
check('abgewählt → gelöscht', vid($id, 'status_home') === 0);
check('abgewählt, aber archiviert → bleibt', vid($id, 'status_outside') === $outside);
check('angehakt und schon gemeldet → angelegt', vid($id, 'status_ble_volt') > 0);
receive($id, 'state/GuestAC/status', ['home' => 23.0, 'outside' => 3.0, 'demand' => 80]);
check('abgewählt → kommt nicht wieder', vid($id, 'status_home') === 0);
check('archiviert → wird weiter aktualisiert', val($id, 'status_outside') === 3.0);
check('Leistungsbegrenzung', val($id, 'status_demand') === 80);

// SelectAllValues
Stub::Call($id, 'SelectAllValues', true);
$rows = json_decode(Stub::$instances[$id]->__formFields()['ValueSelection']['values'], true);
check('Alle anhaken', count(array_filter($rows, fn($r) => !$r['Show'])) === 0 && count($rows) === count(FaikoutCatalog::VALUES));

// ---------------------------------------------------------------- 10 form
$form = json_decode(Stub::$instances[$id]->GetConfigurationForm(), true);
check('Formular ist JSON', is_array($form));
$last = end($form['actions']);
check('Versionszeile zuletzt', ($last['type'] ?? '') === 'Label' && str_starts_with($last['caption'], 'Faikout 4 Daikin 2.0 (Build '));
$list = findItem($form['elements'], 'ValueSelection');
check('Werteliste vollständig', count($list['values']) === count(FaikoutCatalog::VALUES));
$row = array_values(array_filter($list['values'], fn($r) => $r['Ident'] === 'status_outside'))[0];
check('Archiv-Hinweis in der Liste', $row['Archive'] === 'archiviert – bleibt erhalten' && $row['Show'] === false);
$row = array_values(array_filter($list['values'], fn($r) => $r['Ident'] === 'status_inlet'))[0];
check('nie gemeldet → Hinweis', $row['Value'] === 'noch nicht gemeldet');
check('Überblick mit letzter Nachricht', str_starts_with($form['elements'][0]['caption'], 'Letzte Nachricht: '));
check('Freigabe-Hinweis ohne Übernahme unsichtbar', findItem($form['elements'], 'LegacyInfo')['visible'] === false);

// ---------------------------------------------------------------- 11 refresh and watchdog
setAttr($id, 'LastStatus', time() - 120);
Stub::FireTimer($id, 'Refresh');
check('Refresh fragt Status an, wenn alt', sent($id) === ['command/GuestAC/status']);
setAttr($id, 'LastStatus', time());
Stub::FireTimer($id, 'Refresh');
check('Refresh fragt nicht, wenn frisch', sent($id) === []);
setAttr($id, 'LastMessage', time() - 400);
Stub::FireTimer($id, 'Refresh');
check('Watchdog Status 201', Stub::$objects[$id]['status'] === 201);
receive($id, 'state/GuestAC/status', ['home' => 22.0]);
check('Nachricht → wieder 102', Stub::$objects[$id]['status'] === 102);
Stub::$parentActive = false;
Stub::FireTimer($id, 'Refresh');
check('MQTT-Server aus → 202', Stub::$objects[$id]['status'] === 202);
check('ohne Server nichts gesendet', sent($id) === []);
Stub::$parentActive = true;
Stub::FireTimer($id, 'Refresh');
check('MQTT-Server wieder da → 102', Stub::$objects[$id]['status'] === 102);
sent($id);

// ---------------------------------------------------------------- 12 control
check('ohne Freigabe keine Aktion', Stub::$objects[vid($id, 'status_power')]['action'] === 0);
try {
    IPS_RequestAction($id, 'status_power', false);
    check('ohne Freigabe: Fehler', false);
} catch (Exception $e) {
    check('ohne Freigabe: verständlicher Fehler', str_contains($e->getMessage(), 'nicht freigegeben'), $e->getMessage());
}
check('ohne Freigabe nichts gesendet', sent($id) === []);

IPS_SetProperty($id, 'Hostname', 'GuestAC');
IPS_SetProperty($id, 'AllowControl', true);
IPS_ApplyChanges($id);
sent($id);
check('mit Freigabe: Aktion', Stub::$objects[vid($id, 'status_power')]['action'] === $id);
check('mit Freigabe: Schalter', Stub::$objects[vid($id, 'status_power')]['profile']['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH);
$p = Stub::$objects[vid($id, 'status_temp')]['profile'];
check('Solltemperatur als Schieberegler 16–32', $p['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && $p['MIN'] === 16 && $p['MAX'] === 32 && $p['STEP_SIZE'] === 0.5);
$p = Stub::$objects[vid($id, 'status_mode')]['profile'];
check('Betriebsart als Aufzählung', $p['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION && json_decode($p['OPTIONS'], true)[1]['Caption'] === 'Kühlen');
check('Messwerte ohne Aktion', Stub::$objects[vid($id, 'status_outside')]['action'] === 0);

// switch off: sent typed, variable not set in advance
RequestAction(vid($id, 'status_power'), false);
check('Ausschalten gesendet', sentFull($id) === [['command/GuestAC', '{"power":false}']]);
check('Variable nicht vorab gesetzt', val($id, 'status_power') === true);
$timers = (new ReflectionProperty(IPSModule::class, 'timers'))->getValue(Stub::$instances[$id]);
check('Prüf-Timer läuft', $timers['Verify']['interval'] === 5000);
receive($id, 'state/GuestAC/status', ['power' => false]);
check('Rückmeldung übernommen', val($id, 'status_power') === false);
check('Ergebnis angezeigt', str_contains(attr($id, 'LastControl'), 'Übernommen: Eingeschaltet → Aus'), attr($id, 'LastControl'));
check('nichts mehr offen', json_decode(attr($id, 'Pending'), true) === []);

// mode and fan as letters, temperature rounded to 0.5
receive($id, 'state/GuestAC/status', ['autoe' => false]);
RequestAction(vid($id, 'status_mode'), 2);
RequestAction(vid($id, 'status_fan'), -1);
RequestAction(vid($id, 'status_temp'), 22.3);
RequestAction(vid($id, 'status_demand'), 72);
check('Befehle typgenau', commands($id) === [
    ['command/GuestAC', ['mode' => 'C']],
    ['command/GuestAC', ['fan' => 'Q']],
    ['command/GuestAC', ['temp' => 22.5]],
    ['command/GuestAC', ['demand' => 70]]
]);
foreach ([[15, 'status_temp'], [33, 'status_temp'], [9, 'status_mode'], ['x', 'status_demand']] as [$bad, $ident]) {
    try {
        RequestAction(vid($id, $ident), $bad);
        check("ungültiger Wert $bad abgelehnt", false);
    } catch (Exception $e) {
        check("ungültiger Wert $bad abgelehnt", sent($id) === [], $e->getMessage());
    }
}
receive($id, 'state/GuestAC/status', ['mode' => 'C', 'fan' => 'Q', 'temp' => 22.5, 'demand' => 70]);
check('alles übernommen', json_decode(attr($id, 'Pending'), true) === [] && val($id, 'status_temp') === 22.5);

// refused by the firmware
RequestAction(vid($id, 'status_econo'), true);
sent($id);
receive($id, 'error/GuestAC/control', ['field' => 'econo', 'error' => 'Setting cannot be controlled']);
check('Ablehnung gemeldet', str_contains(attr($id, 'LastControl'), 'Eco-Modus: nicht übernommen – Setting cannot be controlled'), attr($id, 'LastControl'));
check('Ablehnung im Log', count(array_filter(Stub::$log, fn($l) => str_starts_with($l, '[LOG') && str_contains($l, 'Eco-Modus: nicht übernommen'))) === 1);

// no answer: ask once, then report
RequestAction(vid($id, 'status_quiet'), true);
sent($id);
$pending = json_decode(attr($id, 'Pending'), true);
$pending['status_quiet']['at'] = time() - 6;
setAttr($id, 'Pending', json_encode($pending));
setAttr($id, 'LastStatus', time() - 100);
Stub::FireTimer($id, 'Verify');
check('keine Antwort → Status angefragt', sent($id) === ['command/GuestAC/status']);
Stub::FireTimer($id, 'Verify');
check('nur einmal angefragt', sent($id) === []);
$pending = json_decode(attr($id, 'Pending'), true);
$pending['status_quiet']['at'] = time() - 25;
setAttr($id, 'Pending', json_encode($pending));
Stub::FireTimer($id, 'Verify');
check('nach 20 s: nicht übernommen', str_contains(attr($id, 'LastControl'), 'Leises Außengerät: Das Klimagerät hat An nicht übernommen, es meldet Aus.'), attr($id, 'LastControl'));
check('danach nichts offen', json_decode(attr($id, 'Pending'), true) === []);

// Faikout auto active: target moves autot along
receive($id, 'state/GuestAC/status', ['autoe' => true, 'autor' => 1.0]);
RequestAction(vid($id, 'status_temp'), 21);
$c = commands($id);
check('Automatik aktiv: autot mit', count($c) === 1 && $c[0][0] === 'command/GuestAC' && $c[0][1] == ['temp' => 21, 'autot' => 21], json_encode($c));

// version with build date
receive($id, 'state/GuestAC', ['id' => '30AEA4CAFE01', 'version' => '5298aa2d', 'build' => '2026-11-19T12:23:17', 'consumption' => 760]);
check('Firmware mit Datum', val($id, 'state_version') === '5298aa2d (19.11.2026)');
check('HA-consumption in state/<host> nicht ausgewertet', vid($id, 'status_consumption') === 0);
receive($id, 'state/GuestAC/status', ['consumption' => 760]);
check('aktuelle Leistung', val($id, 'status_consumption') === 760);

// switching the approval off removes the actions
IPS_SetProperty($id, 'AllowControl', false);
IPS_ApplyChanges($id);
sent($id);
check('Freigabe weg → keine Aktion', Stub::$objects[vid($id, 'status_power')]['action'] === 0
    && Stub::$objects[vid($id, 'status_mode')]['profile']['INTERVALS_ACTIVE'] === true);

// ---------------------------------------------------------------- 13 migration from Kris' module
$old = Stub::CreateInstance($class);
// variables as Kris' module left them
$mk = function (int $inst, string $ident, int $type, $value, string $profile, bool $action) {
    $vid = Stub::NewID();
    Stub::$objects[$vid] = ['type' => 'variable', 'parent' => $inst, 'ident' => $ident, 'name' => $ident, 'vtype' => $type,
        'value' => $value, 'profile' => $profile, 'action' => $action ? $inst : 0, 'changed' => 1, 'updated' => 1, 'pos' => 0];
    return $vid;
};
$modeId = $mk($old, 'status_mode', 1, 2, 'FAIKIN_Mode', true);
$autoId = $mk($old, 'status_auto0', 1, 1700000000, '~UnixTimestampTime', true);
$ipId = $mk($old, 'status_ipv4', 3, '1.2.3.4', '', false);
$mk($old, 'info_version', 3, '5298aa2d', '', false);
$mk($old, 'info_up_to_date', 0, true, '~Switch', false);
$arcId = $mk($old, 'status_auto1', 1, 1700000000, '~UnixTimestampTime', true);
Stub::$archived[$arcId] = true;
setAttr($old, 'state_id', '30aea4beef02'); // Kris stored it as received
setAttr($old, 'LegacyChecked', false);     // new attribute after the module update
IPS_SetProperty($old, 'Hostname', 'LivingAC');
IPS_ApplyChanges($old);
sent($old);
check('alte Variable bleibt (gleiche ID)', vid($old, 'status_mode') === $modeId && Stub::$objects[$modeId]['value'] === 2);
check('übernommen: Freigabe erkannt', attr($old, 'LegacyControl') === true);
check('übernommen: Aktion bleibt', Stub::$objects[$modeId]['action'] === $old);
$form = json_decode(Stub::$instances[$old]->GetConfigurationForm(), true);
check('übernommen: Hinweis + Knopf sichtbar', findItem($form['elements'], 'LegacyInfo')['visible'] === true && findItem($form['elements'], 'RevokeLegacy')['visible'] === true);
RequestAction($modeId, 1);
check('übernommen: Steuern geht ohne Haken', sent($old) === ['command/LivingAC']);
IPS_RequestAction($old, 'RevokeLegacy', '');
check('Freigabe zurückgenommen → Aktion weg', Stub::$objects[$modeId]['action'] === 0 && attr($old, 'LegacyControl') === false);
IPS_ApplyChanges($old);
check('bleibt zurückgenommen (nur einmal erkannt)', attr($old, 'LegacyControl') === false);
check('alte Variable: Darstellung statt Profil', is_array(Stub::$objects[$modeId]['profile']));
check('ersetzte Variable gelöscht', vid($old, 'status_auto0') === 0 && vid($old, 'status_ipv4') === 0);
check('alte info_-Variablen gelöscht', vid($old, 'info_version') === 0 && vid($old, 'info_up_to_date') === 0);
check('ersetzte, aber archivierte Variable bleibt', vid($old, 'status_auto1') === $arcId);
receive($old, 'state/30AEA4BEEF02/status', ['mode' => 'C', 'auto1' => '06:15']);
check('alte Geräte-ID (klein gespeichert) erkannt', val($old, 'status_mode') === 2 && val($old, 'status_autoon') === '06:15');

// ---------------------------------------------------------------- 15 functions of Kris' module
$k = Stub::CreateInstance($class, ['Hostname' => 'KrisAC']);
sent($k);
check('ohne Freigabe kein Nachtmodus-Schalter', vid($k, 'silentModeOnStart') === 0);
try {
    Stub::Call($k, 'RestartDevice');
    check('Neustart ohne Freigabe abgelehnt', false);
} catch (Exception $e) {
    check('Neustart ohne Freigabe abgelehnt', sent($k) === []);
}
IPS_SetProperty($k, 'AllowControl', true);
IPS_ApplyChanges($k);
sent($k);
check('mit Freigabe: Nachtmodus-Schalter da', vid($k, 'silentModeOnStart') > 0 && val($k, 'silentModeOnStart') === false
    && Stub::$objects[vid($k, 'silentModeOnStart')]['action'] === $k);
receive($k, 'state/KrisAC/status', $realStatus);
RequestAction(vid($k, 'status_power'), true);
check('Einschalten ohne Nachtmodus', commands($k) === [['command/KrisAC', ['power' => true]]]);
RequestAction(vid($k, 'silentModeOnStart'), true);
check('Nachtmodus-Option gesetzt', val($k, 'silentModeOnStart') === true && attr($k, 'silentModeOnStart') === true && sent($k) === []);
RequestAction(vid($k, 'status_power'), true);
check('Einschalten mit Nachtmodus', commands($k) === [['command/KrisAC', ['power' => true, 'fan' => 'Q']]]);
Stub::Call($k, 'SetSilentModeOnStart', false);
check('SetSilentModeOnStart', val($k, 'silentModeOnStart') === false);

receive($k, 'setting/KrisAC', ['reporting' => 60, 'livestatus' => false, 'haenable' => true, 'dark' => false]);
check('LED-Einstellung gelesen', val($k, 'setting_dark') === false);
Stub::Call($k, 'SetFaikinLed', true);
check('SetFaikinLed schickt dark', commands($k) === [['setting/KrisAC', ['dark' => true]], ['setting/KrisAC', null]]);
receive($k, 'setting/KrisAC', ['dark' => true]);
check('LED bestätigt', val($k, 'setting_dark') === true && str_contains(attr($k, 'LastControl'), 'Übernommen: LED am Faikout aus → An'));
Stub::Call($k, 'RestartDevice');
check('Neustart', sent($k) === ['command/KrisAC/restart']);
IPS_RequestAction($k, 'manual_restart', '');
check('Kris-Ident manual_restart', sent($k) === ['command/KrisAC/restart']);
Stub::Call($k, 'ReloadSettings');
check('Einstellungen anfordern', sent($k) === ['setting/KrisAC']);
IPS_RequestAction($k, 'SendSettings', json_encode(['reporting' => 30, 'livestatus' => true, 'haenable' => false]));
check('Einstellungen senden', commands($k) === [['setting/KrisAC', ['reporting' => 30, 'livestatus' => true, 'haenable' => false]], ['setting/KrisAC', null]]);
$form = json_decode(Stub::$instances[$k]->GetConfigurationForm(), true);
check('Formular zeigt Geräte-Einstellungen', findItem($form['actions'], 'DevReporting')['value'] === 60 && findItem($form['actions'], 'DevHA')['value'] === true);

// Kris 1.8.2 instance: option attribute and variables are taken over
$kr = Stub::CreateInstance($class);
$silentId = $mk($kr, 'silentModeOnStart', 0, true, '~Switch', true);
$darkId = $mk($kr, 'setting_dark', 0, false, '~Switch', true);
setAttr($kr, 'silentModeOnStart', true);
setAttr($kr, 'LegacyChecked', false);
IPS_SetProperty($kr, 'Hostname', 'OldAC');
IPS_ApplyChanges($kr);
sent($kr);
check('Kris 1.8.2: Freigabe über Nachtmodus-Schalter erkannt', attr($kr, 'LegacyControl') === true);
check('Kris 1.8.2: Variablen bleiben mit Aktion', vid($kr, 'silentModeOnStart') === $silentId && Stub::$objects[$silentId]['action'] === $kr
    && Stub::$objects[$darkId]['action'] === $kr && val($kr, 'silentModeOnStart') === true);

// ---------------------------------------------------------------- 14 English console
Stub::LoadLocale($dir, false);
$en = Stub::CreateInstance($class, ['Hostname' => 'GuestAC']);
receive($en, 'state/GuestAC/status', $realStatus);
check('englisch: Name', Stub::$objects[vid($en, 'status_temp')]['name'] === 'Target temperature');
$form = json_decode(Stub::$instances[$en]->GetConfigurationForm(), true);
check('englisch: Formular', is_array($form));

// ---------------------------------------------------------------- result
check('keine fehlenden Übersetzungen', Stub::$missingTranslations === [], implode(' | ', array_keys(Stub::$missingTranslations)));
echo "$passed Prüfungen ok, $failed Fehler" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
