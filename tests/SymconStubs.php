<?php

declare(strict_types=1);

/*
 * SymconStubs.php – minimale Nachbildung der Symcon-Modul-Umgebung für Tests per PHP-CLI.
 *
 * Verwendung (siehe references/tests.md):
 *   require __DIR__ . '/SymconStubs.php';
 *   Stub::LoadModule('<Repo>/<Modul>');          // lädt module.php und erzeugt PREFIX_…-Funktionen
 *   $id = Stub::CreateInstance('<Klassenname>', ['Host' => '1.2.3.4']);
 *   Stub::Call($id, 'Update');
 *   Stub::Dump($id);
 *
 * Bewusst einfach: kein Datenfluss-Routing zwischen echten Instanzen, keine echten Timer.
 * Timer können mit Stub::FireTimer($id, 'Name') ausgelöst werden.
 * HTTP lässt sich mit Stub::$httpMock = function(string $url, array $opts) { return [200, '{...}']; } simulieren,
 * sofern das Modul seine HTTP-Aufrufe über eine eigene Methode kapselt, die im Test überschrieben wird.
 */

// ---------------------------------------------------------------- Konstanten
foreach ([
    'KR_CREATE' => 10101, 'KR_INIT' => 10102, 'KR_READY' => 10103, 'KR_UNINIT' => 10104, 'KR_SHUTDOWN' => 10105,
    'IPS_KERNELSTARTED' => 10001, 'IPS_KERNELSHUTDOWN' => 10002,
    'IM_CHANGESTATUS' => 10505, 'IM_CHANGESETTINGS' => 10506,
    'VM_UPDATE' => 10603, 'VM_DELETE' => 10602,
    'KL_MESSAGE' => 10201, 'KL_SUCCESS' => 10202, 'KL_NOTIFY' => 10203, 'KL_WARNING' => 10204, 'KL_ERROR' => 10206, 'KL_DEBUG' => 10207,
    'VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3,
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
    'VARIABLE_PRESENTATION_ENUMERATION' => '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}',
    'VARIABLE_PRESENTATION_SWITCH' => '{60AE6B26-B3E2-BDB1-A3A9-BE232C2D1D8F}',
    'VARIABLE_PRESENTATION_SLIDER' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}',
    'VARIABLE_PRESENTATION_WEB_CONTENT' => '{9DE1D610-5106-97FB-714D-1AADEDF8377A}',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value); // GUID-Werte der Darstellungen sind Platzhalter, nur für Tests
    }
}

// ---------------------------------------------------------------- Speicher
final class Stub
{
    public static array $objects = [];      // id => ['type'=>'instance'|'variable', ...]
    public static array $instances = [];    // id => Modulobjekt
    public static int $nextID = 10000;
    public static array $log = [];
    public static bool $echoDebug = true;
    public static $httpMock = null;
    public static array $translations = [];   // English => German (empty = English console)
    public static array $missingTranslations = [];
    public static bool $parentActive = true;
    public static array $archived = [];         // variable id => true

    /** Loads the "de" part of locale.json as system language (Chris = German) */
    public static function LoadLocale(string $dir, bool $german = true): void
    {
        self::$translations = [];
        if ($german && is_file($dir . '/locale.json')) {
            $l = json_decode(file_get_contents($dir . '/locale.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$translations = $l['translations']['de'] ?? [];
        }
    }

    /** module.php laden und die Symcon-Funktionen PREFIX_Methode($id, …) erzeugen */
    public static function LoadModule(string $dir): string
    {
        $info = json_decode(file_get_contents($dir . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        require_once $dir . '/module.php';
        $class = $info['name'];
        $prefix = $info['prefix'];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $class || str_starts_with($m->getName(), '__')) {
                continue;
            }
            $fn = $prefix . '_' . $m->getName();
            foreach ($m->getParameters() as $par) {
                if (!$par->hasType()) {
                    Stub::Log("[WARNUNG] $class::{$m->getName()}: Parameter \${$par->getName()} ohne Typ");
                }
            }
            if (!$m->hasReturnType() && !in_array($m->getName(), ['Create', 'Destroy', 'ApplyChanges', 'MessageSink', 'RequestAction', 'ReceiveData', 'ForwardData', 'GetConfigurationForm'], true)) {
                Stub::Log("[WARNUNG] $class::{$m->getName()}: ohne Rückgabetyp");
            }
            if (!function_exists($fn)) {
                eval("function $fn(int \$id, ...\$args) { return Stub::\$instances[\$id]->{$m->getName()}(...\$args); }");
            }
        }
        return $class;
    }

    /** Throws like Symcon on unknown sub parameters (ShellyFWCheck Build 13) */
    public static function CheckPresentation(string $ident, array $p): void
    {
        foreach (['OPTIONS', 'INTERVALS'] as $k) {
            if (isset($p[$k]) && !is_string($p[$k])) {
                throw new Exception("Darstellung $ident: $k muss ein JSON-String sein");
            }
        }
        $optionKeys = [
            VARIABLE_PRESENTATION_VALUE_PRESENTATION => ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue'],
            VARIABLE_PRESENTATION_ENUMERATION => ['Value', 'Caption', 'IconActive', 'IconValue', 'Color'],
        ];
        if (isset($p['OPTIONS'], $optionKeys[$p['PRESENTATION']])) {
            foreach (json_decode($p['OPTIONS'], true) as $o) {
                $keys = array_keys($o);
                sort($keys);
                $want = $optionKeys[$p['PRESENTATION']];
                sort($want);
                if ($keys !== $want) {
                    throw new Exception("Darstellung $ident: OPTIONS-Schlüssel " . implode(',', $keys) . ' statt ' . implode(',', $want));
                }
            }
        }
        if (isset($p['INTERVALS'])) {
            $want = ['IntervalMinValue', 'IntervalMaxValue', 'ConstantActive', 'ConstantValue', 'ConversionFactor', 'PrefixActive', 'PrefixValue',
                'SuffixActive', 'SuffixValue', 'DigitsActive', 'DigitsValue', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue',
                'ContentColorActive', 'ContentColorValue'];
            sort($want);
            foreach (json_decode($p['INTERVALS'], true) as $i) {
                $keys = array_keys($i);
                sort($keys);
                if ($keys !== $want) {
                    throw new Exception("Darstellung $ident: Intervall-Schlüssel unvollständig");
                }
            }
        }
    }

    public static function NewID(): int
    {
        return ++self::$nextID;
    }

    public static function CreateInstance(string $class, array $properties = [], int $parentID = 0): int
    {
        $id = self::NewID();
        self::$objects[$id] = ['type' => 'instance', 'class' => $class, 'parent' => $parentID, 'name' => $class, 'status' => 102];
        $module = new $class($id);
        self::$instances[$id] = $module;
        $module->Create();
        foreach ($properties as $k => $v) {
            IPS_SetProperty($id, $k, $v);
        }
        $module->ApplyChanges();
        return $id;
    }

    public static function Call(int $id, string $method, ...$args)
    {
        return self::$instances[$id]->$method(...$args);
    }

    public static function FireTimer(int $id, string $name): void
    {
        $script = self::$instances[$id]->__timers()[$name]['script'] ?? '';
        $_IPS = ['TARGET' => $id, 'SENDER' => 'TimerEvent'];
        eval($script);
    }

    public static function Log(string $text): void
    {
        self::$log[] = $text;
        if (self::$echoDebug) {
            echo $text, PHP_EOL;
        }
    }

    public static function Dump(int $id): void
    {
        echo "--- Instanz $id (" . self::$objects[$id]['class'] . "), Status " . self::$objects[$id]['status'] . PHP_EOL;
        foreach (self::$objects as $vid => $o) {
            if ($o['type'] === 'variable' && $o['parent'] === $id) {
                printf("  %-20s %-25s = %s%s\n", $o['ident'], $o['name'], var_export($o['value'], true), $o['action'] ? '  [Aktion]' : '');
            }
        }
    }
}

// ---------------------------------------------------------------- Globale Funktionen
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetInstanceListByModuleID(string $guid): array { return $guid === '{43192F0B-135B-4CE7-A0A7-1475603F3060}' ? [1] : []; }
function AC_GetLoggingStatus(int $archive, int $id): bool { return Stub::$archived[$id] ?? false; }
function IPS_ObjectExists(int $id): bool { return isset(Stub::$objects[$id]); }
function IPS_VariableExists(int $id): bool { return (Stub::$objects[$id]['type'] ?? '') === 'variable'; }
function IPS_InstanceExists(int $id): bool { return (Stub::$objects[$id]['type'] ?? '') === 'instance'; }
function IPS_GetName(int $id): string { return Stub::$objects[$id]['name'] ?? ''; }
function IPS_SetName(int $id, string $name): bool { Stub::$objects[$id]['name'] = $name; return true; }
function IPS_GetParent(int $id): int { return Stub::$objects[$id]['parent'] ?? 0; }
function IPS_GetObject(int $id): array
{
    $o = Stub::$objects[$id];
    return ['ObjectID' => $id, 'ObjectName' => $o['name'], 'ParentID' => $o['parent'], 'ObjectIdent' => $o['ident'] ?? '',
        'ObjectType' => $o['type'] === 'variable' ? 2 : 1, 'ChildrenIDs' => array_keys(array_filter(Stub::$objects, fn($c) => $c['parent'] === $id))];
}
function IPS_GetVariable(int $id): array
{
    $o = Stub::$objects[$id];
    return ['VariableID' => $id, 'VariableType' => $o['vtype'], 'VariableAction' => $o['action'], 'VariableCustomAction' => 0,
        'VariableProfile' => is_string($o['profile']) ? $o['profile'] : '', 'VariableCustomProfile' => '',
        'VariableChanged' => $o['changed'], 'VariableUpdated' => $o['updated']];
}
function IPS_GetInstance(int $id): array
{
    $o = Stub::$objects[$id];
    return ['InstanceID' => $id, 'InstanceStatus' => $o['status'], 'ConnectionID' => $o['parent'], 'ModuleInfo' => ['ModuleName' => $o['class']]];
}
function IPS_GetProperty(int $id, string $name) { return Stub::$instances[$id]->__props()[$name] ?? null; }
function IPS_SetProperty(int $id, string $name, $value): bool { Stub::$instances[$id]->__setProp($name, $value); return true; }
function IPS_ApplyChanges(int $id): bool { Stub::$instances[$id]->ApplyChanges(); return true; }
function IPS_GetConfiguration(int $id): string { return json_encode(Stub::$instances[$id]->__props()); }
function IPS_SemaphoreEnter(string $name, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $name): bool { return true; }
function IPS_LogMessage(string $sender, string $msg): bool { Stub::Log("[LOG $sender] $msg"); return true; }
function IPS_GetKernelVersion(): string { return '9.0'; }
function IPS_Sleep(int $ms): void {}
function IPS_RunScriptText(string $text): bool { return true; }

function _stub_setvalue(int $id, $value): bool
{
    $o = &Stub::$objects[$id];
    $old = $o['value'];
    $o['value'] = $value;
    $o['updated'] = time();
    if ($old !== $value) {
        $o['changed'] = time();
    }
    // VM_UPDATE an registrierte Empfänger
    foreach (Stub::$instances as $iid => $mod) {
        if (in_array(VM_UPDATE, $mod->GetMessageList()[$id] ?? [], true)) {
            $mod->MessageSink(time(), $id, VM_UPDATE, [$value, $old !== $value, $old, time()]);
        }
    }
    return true;
}
function SetValue(int $id, $v): bool { return _stub_setvalue($id, $v); }
function SetValueBoolean(int $id, bool $v): bool { return _stub_setvalue($id, $v); }
function SetValueInteger(int $id, int $v): bool { return _stub_setvalue($id, $v); }
function SetValueFloat(int $id, float $v): bool { return _stub_setvalue($id, $v); }
function SetValueString(int $id, string $v): bool { return _stub_setvalue($id, $v); }
function GetValue(int $id) { return Stub::$objects[$id]['value']; }
function GetValueBoolean(int $id): bool { return (bool) GetValue($id); }
function GetValueInteger(int $id): int { return (int) GetValue($id); }
function GetValueFloat(int $id): float { return (float) GetValue($id); }
function GetValueString(int $id): string { return (string) GetValue($id); }
function GetValueFormatted(int $id): string { return (string) GetValue($id); }
function RequestAction(int $id, $value): bool
{
    $o = Stub::$objects[$id];
    if ($o['action'] > 0 && isset(Stub::$instances[$o['action']])) {
        Stub::$instances[$o['action']]->RequestAction($o['ident'], $value);
        return true;
    }
    return _stub_setvalue($id, $value);
}
function IPS_RequestAction(int $id, string $ident, $value): bool { Stub::$instances[$id]->RequestAction($ident, $value); return true; }

/** Freie Testvariable anlegen (z. B. als Quelle/Ziel für Properties) */
function Stub_CreateVariable(int $type, $value, string $name = 'Testvariable', int $actionInstance = 0): int
{
    $id = Stub::NewID();
    Stub::$objects[$id] = ['type' => 'variable', 'parent' => 0, 'ident' => '', 'name' => $name, 'vtype' => $type,
        'value' => $value, 'profile' => '', 'action' => $actionInstance, 'changed' => 0, 'updated' => 0];
    return $id;
}

// ---------------------------------------------------------------- Basisklasse
class IPSModule
{
    protected int $InstanceID;
    private array $props = [];
    private array $attrs = [];
    private array $buffers = [];
    private array $timers = [];
    private array $messages = [];
    private array $references = [];
    private array $formFields = [];

    public function __construct($InstanceID) { $this->InstanceID = (int) $InstanceID; }
    public function Create() {}
    public function Destroy() {}
    public function ApplyChanges() {}
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {}
    public function RequestAction($Ident, $Value) {}
    public function GetConfigurationForm() { return '{}'; }

    // Test-Helfer
    public function __props(): array { return $this->props; }
    public function __setProp(string $n, $v): void { $this->props[$n] = $v; }
    public function __timers(): array { return $this->timers; }
    public function __formFields(): array { return $this->formFields; }

    // Properties / Attribute
    protected function RegisterPropertyString($n, $d) { $this->props[$n] ??= (string) $d; }
    protected function RegisterPropertyInteger($n, $d) { $this->props[$n] ??= (int) $d; }
    protected function RegisterPropertyFloat($n, $d) { $this->props[$n] ??= (float) $d; }
    protected function RegisterPropertyBoolean($n, $d) { $this->props[$n] ??= (bool) $d; }
    protected function ReadPropertyString($n) { return (string) $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return (int) $this->props[$n]; }
    protected function ReadPropertyFloat($n) { return (float) $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return (bool) $this->props[$n]; }
    protected function RegisterAttributeString($n, $d) { $this->attrs[$n] ??= (string) $d; }
    protected function RegisterAttributeInteger($n, $d) { $this->attrs[$n] ??= (int) $d; }
    protected function RegisterAttributeFloat($n, $d) { $this->attrs[$n] ??= (float) $d; }
    protected function RegisterAttributeBoolean($n, $d) { $this->attrs[$n] ??= (bool) $d; }
    protected function ReadAttributeString($n) { return (string) $this->attrs[$n]; }
    protected function ReadAttributeInteger($n) { return (int) $this->attrs[$n]; }
    protected function ReadAttributeFloat($n) { return (float) $this->attrs[$n]; }
    protected function ReadAttributeBoolean($n) { return (bool) $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = (string) $v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = (int) $v; }
    protected function WriteAttributeFloat($n, $v) { $this->attrs[$n] = (float) $v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = (bool) $v; }

    // Buffer
    protected function SetBuffer($n, $v)
    {
        if (strlen((string) $v) > 256 * 1024) {
            Stub::Log("[WARNUNG] Buffer '$n' > 256 kB (" . strlen((string) $v) . ' Bytes)');
        }
        $this->buffers[$n] = (string) $v;
    }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function GetBufferList() { return array_keys($this->buffers); }

    // Variablen
    private function findVar(string $ident): int
    {
        foreach (Stub::$objects as $id => $o) {
            if ($o['type'] === 'variable' && $o['parent'] === $this->InstanceID && $o['ident'] === $ident) {
                return $id;
            }
        }
        return 0;
    }
    private function registerVar(string $ident, string $name, int $type, $profile, int $pos): int
    {
        $id = $this->findVar($ident);
        if ($id === 0) {
            $id = Stub::NewID();
            $default = [false, 0, 0.0, ''][$type];
            Stub::$objects[$id] = ['type' => 'variable', 'parent' => $this->InstanceID, 'ident' => $ident, 'name' => $name,
                'vtype' => $type, 'value' => $default, 'profile' => $profile, 'action' => 0, 'changed' => 0, 'updated' => 0, 'pos' => $pos];
        } else {
            Stub::$objects[$id]['profile'] = $profile;
            if (Stub::$objects[$id]['vtype'] !== $type) {
                throw new Exception("Variable $ident existiert mit anderem Typ");
            }
        }
        if (is_array($profile)) {
            Stub::CheckPresentation($ident, $profile);
        }
        return $id;
    }
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { return $this->registerVar($i, $n, 0, $p, $pos); }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { return $this->registerVar($i, $n, 1, $p, $pos); }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { return $this->registerVar($i, $n, 2, $p, $pos); }
    protected function RegisterVariableString($i, $n, $p = '', $pos = 0) { return $this->registerVar($i, $n, 3, $p, $pos); }
    protected function MaintainVariable($i, $n, $t, $p, $pos, $keep)
    {
        if ($keep) {
            $this->registerVar($i, $n, $t, $p, $pos);
        } elseif ($id = $this->findVar($i)) {
            unset(Stub::$objects[$id]);
        }
        return true;
    }
    protected function UnregisterVariable($i) { if ($id = $this->findVar($i)) { unset(Stub::$objects[$id]); } }
    protected function EnableAction($i) { $id = $this->findVar($i); if (!$id) { throw new Exception("EnableAction: $i fehlt"); } Stub::$objects[$id]['action'] = $this->InstanceID; }
    protected function DisableAction($i) { if ($id = $this->findVar($i)) { Stub::$objects[$id]['action'] = 0; } }
    protected function MaintainAction($i, $enable) { $enable ? $this->EnableAction($i) : $this->DisableAction($i); }
    protected function GetIDForIdent($i)
    {
        $id = $this->findVar($i);
        if (!$id) {
            trigger_error("Ident $i nicht gefunden", E_USER_WARNING);
            return false;
        }
        return $id;
    }
    protected function SetValue($i, $v)
    {
        $id = $this->findVar($i);
        if (!$id) {
            throw new Exception("SetValue: Variable $i existiert nicht");
        }
        $v = match (Stub::$objects[$id]['vtype']) { 0 => (bool) $v, 1 => (int) $v, 2 => (float) $v, default => (string) $v };
        return _stub_setvalue($id, $v);
    }
    protected function GetValue($i) { return Stub::$objects[$this->findVar($i)]['value'] ?? null; }

    // Timer
    protected function RegisterTimer($n, $ms, $script) { $this->timers[$n] = ['interval' => $ms, 'script' => $script]; }
    protected function SetTimerInterval($n, $ms)
    {
        if (!isset($this->timers[$n])) {
            throw new Exception("Timer $n nicht registriert");
        }
        $this->timers[$n]['interval'] = $ms;
    }
    protected function GetTimerInterval($n) { return $this->timers[$n]['interval'] ?? 0; }

    // Nachrichten, Referenzen
    protected function RegisterMessage($s, $m) { if (!in_array($m, $this->messages[$s] ?? [], true)) { $this->messages[$s][] = $m; } }
    protected function UnregisterMessage($s, $m) { $this->messages[$s] = array_values(array_diff($this->messages[$s] ?? [], [$m])); if (!$this->messages[$s]) { unset($this->messages[$s]); } }
    public function GetMessageList() { return $this->messages; }
    protected function RegisterReference($id) { $this->references[$id] = true; }
    protected function UnregisterReference($id) { unset($this->references[$id]); }
    protected function GetReferenceList() { return array_keys($this->references); }

    // Status, Debug, Log
    protected function SetStatus($s) { Stub::$objects[$this->InstanceID]['status'] = $s; }
    protected function GetStatus() { return Stub::$objects[$this->InstanceID]['status']; }
    protected function SendDebug($title, $msg, $fmt) { Stub::Log("[DEBUG {$this->InstanceID}] $title: " . (is_string($msg) ? $msg : json_encode($msg))); }
    protected function LogMessage($msg, $type) { Stub::Log("[LOG {$this->InstanceID}] $msg"); }

    // Formular
    protected function UpdateFormField($field, $param, $value) { $this->formFields[$field][$param] = $value; }
    protected function ReloadForm() {}

    // Datenfluss (nur Aufzeichnung)
    public array $sentToParent = [];
    public array $sentToChildren = [];
    protected function ConnectParent($guid) {}
    protected function RequireParent($guid) {}
    protected function ForceParent($guid) {}
    protected function HasActiveParent() { return Stub::$parentActive; }
    protected function Translate($text)
    {
        if (Stub::$translations === []) {
            return $text;
        }
        if (!isset(Stub::$translations[$text])) {
            Stub::$missingTranslations[$text] = true;
            return $text;
        }
        return Stub::$translations[$text];
    }
    protected function SetReceiveDataFilter($f) {}
    protected function SetForwardDataFilter($f) {}
    protected function SendDataToParent($json) { $this->sentToParent[] = $json; Stub::Log("[→PARENT] $json"); return ''; }
    protected function SendDataToChildren($json) { $this->sentToChildren[] = $json; Stub::Log("[→CHILDREN] $json"); }
    protected function RegisterHook($hook) {}
    protected function SetVisualizationType($t) {}
    protected function UpdateVisualizationValue($v) { Stub::Log('[VISU] ' . (is_string($v) ? $v : json_encode($v))); }
}

class IPSModuleStrict extends IPSModule
{
}
