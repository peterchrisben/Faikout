<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FaikoutCatalog.php';
require_once __DIR__ . '/../libs/FaikoutVersion.php';

/**
 * Daikin air conditioner via a Faikout (formerly Faikin) ESP32 module, connected over MQTT.
 *
 * Topics of the Faikout firmware (hostname or device ID as <host>):
 *   state/<host>          state of the Faikout itself (false = offline), plus Home Assistant values
 *   state/<host>/status   all air-con values, on change (setting "livestatus") or on request
 *   Faikout/<host>        periodic report every "reporting" seconds, values may be [min, avg, max]
 *                         (older firmware: Faikin/<host>)
 *   setting/<host>        settings, sent back after an empty setting/<host>
 *   error/<host>/<what>   errors, e.g. comms, failed-set, control
 *   command/<host>/…      commands to the Faikout
 *
 * Control (Build 2): command/<host> with a JSON object {"field": value}, typed exactly as the firmware
 * expects (true/false, numbers, one letter for mode and fan). The variable is never set in advance:
 * the Faikout answers with state/<host>/status, a refusal comes as error/<host>/control or failed-set.
 */
class FaikoutDaikin extends IPSModule
{
    private const MQTT_SERVER  = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_TX      = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const ARCHIVE      = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const APP_TOPICS   = ['faikout', 'faikin'];
    private const REFRESH_MS   = 60000;  // check every minute
    private const STATUS_AGE   = 55;     // ask for state/<host>/status if older (seconds)
    private const SILENCE_MAX  = 300;    // no message for this long → status 201
    private const STATUS_SILENT = 201;
    private const STATUS_NO_PARENT = 202;
    private const VERIFY_MS    = 5000;   // check pending commands every 5 s
    private const VERIFY_ASK   = 5;      // no status after a command → ask for it (seconds)
    private const VERIFY_MAX   = 20;     // still not reported → not accepted (seconds)
    private const MODE_LETTERS = [1 => 'H', 2 => 'C', 3 => 'A', 4 => 'F', 5 => 'D'];
    private const MODE_TEXTS   = [1 => 'Heat', 2 => 'Cool', 3 => 'Auto', 4 => 'Fan', 5 => 'Dry'];
    private const FAN_TEXTS    = [-1 => 'Night (quiet)', 0 => 'Auto', 1 => 'Level 1', 2 => 'Level 2', 3 => 'Level 3',
        4 => 'Level 4', 5 => 'Level 5'];
    private const UNITS        = [
        'temp'     => [' °C', 1],
        'kelvin'   => [' K', 1],
        'humidity' => [' %', 0],
        'percent'  => [' %', 0],
        'rpm'      => [' rpm', 0],
        'hertz'    => [' Hz', 0],
        'watt'     => [' W', 0],
        'kwh'      => [' kWh', 2],
        'volt'     => [' V', 2],
        'dbm'      => [' dBm', 0]
    ];
    private const FAN_LETTERS  = [-1 => 'Q', 0 => 'A', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'];

    /** Selection read once per call (the property does not change during a call) */
    private ?array $selection = null;

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::MQTT_SERVER);

        $this->RegisterPropertyString('Hostname', '');
        $this->RegisterPropertyString('ValueSelection', '[]');
        $this->RegisterPropertyBoolean('AllowControl', false);

        // Same name as in Kris' module, so a known device ID is kept on update
        $this->RegisterAttributeString('state_id', '');
        $this->RegisterAttributeString('SeenValues', '[]');
        $this->RegisterAttributeString('DeviceSettings', '{}');
        $this->RegisterAttributeInteger('LastMessage', 0);
        $this->RegisterAttributeInteger('LastStatus', 0);
        // Taken over from "Faikin 4 Daikin": control stays allowed (see DetectLegacyControl)
        $this->RegisterAttributeBoolean('LegacyChecked', false);
        $this->RegisterAttributeBoolean('LegacyControl', false);
        $this->RegisterAttributeString('Pending', '{}');
        $this->RegisterAttributeString('LastControl', '');

        $this->RegisterTimer('Refresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Refresh\', \'\');');
        $this->RegisterTimer('Verify', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Verify\', \'\');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->selection = null;
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        if (!$this->ReadAttributeBoolean('LegacyChecked')) {
            $this->DetectLegacyControl(); // before MaintainValues, which sets or removes the actions
        }
        $host = $this->Hostname();
        $this->SetReceiveDataFilter($this->ReceiveFilter($host, $this->DeviceID()));
        $this->MaintainValues();

        if ($host === '') {
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatusIfChanged(104);
            return;
        }
        $this->SetTimerInterval('Refresh', self::REFRESH_MS);
        $this->SetStatusIfChanged($this->HasActiveParent() ? 102 : self::STATUS_NO_PARENT);
        $this->RequestStatus();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Refresh':
                $this->Refresh();
                return;
            case 'RequestStatus':
                $this->RequestStatus();
                return;
            case 'SelectAll':
                $this->SelectAllValues((bool) $Value);
                return;
            case 'Verify':
                $this->VerifyPending();
                return;
            case 'RevokeLegacy':
                $this->RevokeLegacyControl();
                return;
        }
        if (isset(FaikoutCatalog::CONTROLS[$Ident])) {
            $this->Control($Ident, $Value);
            return;
        }
        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $form['elements'] = $this->FillForm($form['elements']);
        $form['actions'] = $this->FillForm($form['actions']);
        return json_encode(FaikoutVersion::Append($form));
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Topic'])) {
            return '';
        }
        $topic = (string) $data['Topic'];
        $raw = (string) ($data['Payload'] ?? '');
        $parts = explode('/', $topic);
        if (count($parts) < 2 || !$this->IsOwnTarget($parts[1])) {
            return '';
        }
        $this->SendDebug('MQTT ' . $topic, $raw, 0);

        $prefix = strtolower($parts[0]);
        $suffix = implode('/', array_slice($parts, 2));
        $payload = $this->DecodePayload($raw);

        if ($prefix === 'command') {
            return ''; // our own commands, mirrored by the broker
        }
        $this->WriteAttributeInteger('LastMessage', time());
        if ($this->GetStatus() === self::STATUS_SILENT) {
            $this->SetStatus(102);
        }

        if ($prefix === 'state' && $suffix === '') {
            $this->HandleDeviceState($payload);
        } elseif ($prefix === 'state' && $suffix === 'status') {
            $this->WriteAttributeInteger('LastStatus', time());
            $this->HandleValues($payload, false);
        } elseif (in_array($prefix, self::APP_TOPICS, true) && $suffix === '') {
            $this->HandleValues($payload, true);
        } elseif ($prefix === 'setting' && $suffix === '') {
            $this->HandleSettings($payload);
        } elseif ($prefix === 'error') {
            $this->HandleError($suffix, $payload, $raw);
        }
        return '';
    }

    /** Asks the Faikout for a complete status report and its settings. */
    public function RequestStatus(): bool
    {
        $host = $this->Hostname();
        if ($host === '' || !$this->HasActiveParent()) {
            return false;
        }
        $this->Publish('command/' . $host . '/status', '');
        $this->Publish('setting/' . $host, '');
        return true;
    }

    /** Ticks or unticks every value in the value selection (takes effect after "Apply changes"). */
    public function SelectAllValues(bool $show): void
    {
        $rows = $this->SelectionRows();
        foreach ($rows as &$row) {
            $row['Show'] = $show;
        }
        unset($row);
        $this->UpdateFormField('ValueSelection', 'values', json_encode($rows));
    }

    // ------------------------------------------------------------ receiving

    /** state/<host>: false = Faikout offline (last will), otherwise its own state. */
    private function HandleDeviceState($payload): void
    {
        if ($payload === false || $payload === 'false') {
            $this->StoreValue('state_up', false);
            return;
        }
        if (!is_array($payload)) {
            return;
        }
        $this->StoreValue('state_up', true);
        $id = $payload['id'] ?? '';
        if (is_string($id) && preg_match('/^[0-9A-Fa-f]{12}$/', $id) && $id !== $this->DeviceID()) {
            $this->WriteAttributeString('state_id', strtoupper($id));
            $this->SetReceiveDataFilter($this->ReceiveFilter($this->Hostname(), strtoupper($id)));
            $this->SendDebug('Device', 'Geräte-ID ' . $id . ' übernommen', 0);
        }
        foreach (FaikoutCatalog::DEVICE_FIELDS as $field => $ident) {
            if (array_key_exists($field, $payload)) {
                $this->StoreValue($ident, $payload[$field]);
            }
        }
        if (isset($payload['version']) && is_scalar($payload['version'])) {
            // "5298aa2d" alone says little – with the build date one sees how old the firmware is
            $version = (string) $payload['version'];
            $built = isset($payload['build']) ? strtotime((string) $payload['build']) : false;
            $this->StoreValue('state_version', $built ? $version . ' (' . date('d.m.Y', $built) . ')' : $version);
        }
    }

    /**
     * state/<host>/status or the periodic report.
     * In the report, a value that changed during the period comes as [min, avg, max] (numbers)
     * or as a share 0…1 (booleans). Then the average is used, a boolean share is skipped
     * (the current state is unknown, state/<host>/status brings it).
     */
    private function HandleValues($payload, bool $report): void
    {
        if (!is_array($payload)) {
            return;
        }
        foreach (FaikoutCatalog::STATUS_FIELDS as $field => $ident) {
            if (array_key_exists($field, $payload)) {
                $this->StoreValue($ident, $payload[$field], $report);
            }
        }
        if (isset($payload['ble']) && is_array($payload['ble'])) {
            foreach (FaikoutCatalog::BLE_FIELDS as $field => $ident) {
                if (array_key_exists($field, $payload['ble'])) {
                    $this->StoreValue($ident, $payload['ble'][$field], $report);
                }
            }
        }
        if ($this->PendingList() !== []) {
            $this->VerifyPending();
        }
    }

    private function HandleSettings($payload): void
    {
        if (!is_array($payload) || $payload === []) {
            return;
        }
        $known = json_decode($this->ReadAttributeString('DeviceSettings'), true);
        $known = is_array($known) ? $known : [];
        foreach (['reporting', 'livestatus', 'haenable', 'ha', 'fixstatus', 'autoe'] as $key) {
            if (array_key_exists($key, $payload)) {
                $known[$key === 'ha' ? 'haenable' : $key] = $payload[$key];
            }
        }
        $this->WriteAttributeString('DeviceSettings', json_encode($known));
        $this->UpdateFormField('DeviceInfo', 'caption', $this->DeviceInfoText());
    }

    private function HandleError(string $what, $payload, string $raw): void
    {
        if (is_array($payload)) {
            $this->RefusedByDevice($what, $payload);
        }
        if ($what === 'failed-set') {
            $this->StoreValue('error_failed_set', $this->ShortText($payload, $raw));
            return;
        }
        $text = $raw;
        if (is_array($payload)) {
            $text = (string) ($payload['description'] ?? $payload['error'] ?? json_encode($payload));
            if (isset($payload['field'])) {
                $text = $payload['field'] . ': ' . $text;
            }
        }
        $this->StoreValue('error_description', ($what !== '' ? $what . ': ' : '') . $text);
    }

    // ------------------------------------------------------------ values

    /** Converts a received value to the variable type and writes it, if the value is selected. */
    private function StoreValue(string $ident, $raw, bool $report = false): void
    {
        $value = $this->ConvertValue($ident, $raw, $report);
        if ($value === null) {
            return;
        }
        $this->MarkSeen($ident);
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            if (!$this->IsShown($ident)) {
                return;
            }
            $this->RegisterValue($ident);
            $id = $this->GetIDForIdent($ident);
        }
        if (IPS_GetVariable($id)['VariableUpdated'] === 0 || $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    /** Returns null if the value cannot be used. */
    private function ConvertValue(string $ident, $raw, bool $report)
    {
        [, , $type, $kind] = FaikoutCatalog::VALUES[$ident];
        if (is_array($raw)) {
            // [min, avg, max] of the periodic report
            if (count($raw) !== 3 || !is_numeric($raw[1])) {
                return null;
            }
            $raw = $raw[1];
        }
        if ($raw === null) {
            return null;
        }
        switch ($kind) {
            case 'mode':
                return FaikoutCatalog::MODES[strtoupper((string) $raw)] ?? null;
            case 'fan':
                return FaikoutCatalog::FANS[strtoupper((string) $raw)] ?? null;
            case 'kwh':
                return is_numeric($raw) ? round((float) $raw / 1000, 3) : null;
        }
        if (in_array($ident, ['status_autoon', 'status_autooff'], true)) {
            $time = (string) $raw;
            return preg_match('/^\d{1,2}:\d{2}$/', $time) ? ($time === '00:00' ? '–' : $time) : null;
        }
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                return $this->ToBool($raw, $report);
            case VARIABLETYPE_INTEGER:
                return is_numeric($raw) ? (int) round((float) $raw) : null;
            case VARIABLETYPE_FLOAT:
                return is_numeric($raw) ? round((float) $raw, 2) : null;
            default:
                return is_scalar($raw) ? (string) $raw : null;
        }
    }

    private function ToBool($raw, bool $report): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            $share = (float) $raw;
            if ($report && $share > 0 && $share < 1) {
                return null; // changed during the report period
            }
            return $share >= 0.5;
        }
        $text = strtolower(trim((string) $raw));
        if (in_array($text, ['true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($text, ['false', 'off', 'no'], true)) {
            return false;
        }
        return null;
    }

    /** Creates, updates or removes the variables according to the selection. */
    private function MaintainValues(): void
    {
        foreach (FaikoutCatalog::VALUES as $ident => $def) {
            $exists = @$this->GetIDForIdent($ident) !== false;
            if ($this->IsShown($ident) && ($exists || $this->IsSeen($ident))) {
                $this->RegisterValue($ident);
            } elseif ($exists && !$this->IsArchived($ident)) {
                $this->UnregisterVariable($ident);
            }
        }
        foreach (FaikoutCatalog::LEGACY as $ident) {
            if (@$this->GetIDForIdent($ident) !== false && !$this->IsArchived($ident)) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    private function RegisterValue(string $ident): void
    {
        [, $name, $type, $kind, $position] = FaikoutCatalog::VALUES[$ident];
        $controllable = $this->IsControllable($ident);
        $presentation = $this->Presentation($kind, $controllable ? $ident : null);
        $this->MaintainVariable($ident, $this->Translate($name), $type, $presentation, $position, true);
        if ($controllable) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
    }

    /** Display of a value; with $controlIdent the display for a variable with action. */
    private function Presentation(string $kind, ?string $controlIdent = null): array
    {
        if ($controlIdent !== null) {
            switch ($kind) {
                case 'onoff':
                    return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
                case 'mode':
                    return $this->Enumeration(self::MODE_TEXTS);
                case 'fan':
                    return $this->Enumeration(self::FAN_TEXTS);
            }
            [, , $min, $max, $step] = FaikoutCatalog::CONTROLS[$controlIdent];
            [$suffix, $digits] = self::UNITS[$kind] ?? ['', 0];
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => $min,
                'MAX'          => $max,
                'STEP_SIZE'    => $step,
                'DIGITS'       => $digits,
                'SUFFIX'       => $suffix,
                'USAGE_TYPE'   => $kind === 'temp' ? 0 : 5 // 0 = temperature, 5 = none
            ];
        }
        switch ($kind) {
            case 'onoff':
                return $this->BoolPresentation('Off', 'On');
            case 'yesno':
                return $this->BoolPresentation('No', 'Yes');
            case 'mode':
                return $this->TextIntervals(self::MODE_TEXTS);
            case 'fan':
                return $this->TextIntervals(self::FAN_TEXTS);
            case 'text':
                return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        }
        [$suffix, $digits] = self::UNITS[$kind] ?? ['', 0];
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => $suffix,
            'DIGITS'       => $digits
        ];
    }

    /** Integer with action: one option per value (an enumeration option only knows these five keys). */
    private function Enumeration(array $texts): array
    {
        $options = [];
        foreach ($texts as $value => $text) {
            $options[] = ['Value' => $value, 'Caption' => $this->Translate($text), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)];
    }

    private function BoolPresentation(string $false, string $true): array
    {
        $options = [];
        foreach ([false => $false, true => $true] as $value => $caption) {
            $options[] = [
                'Value' => (bool) $value, 'Caption' => $this->Translate($caption),
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1
            ];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode($options)];
    }

    /** Integer without action: one interval per value with a fixed text (shown as text, not as number). */
    private function TextIntervals(array $texts): array
    {
        $intervals = [];
        foreach ($texts as $value => $text) {
            $intervals[] = [
                'IntervalMinValue' => $value, 'IntervalMaxValue' => $value,
                'ConstantActive' => true, 'ConstantValue' => $this->Translate($text),
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1
            ];
        }
        return [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode($intervals)
        ];
    }

    // ------------------------------------------------------------ control

    private function ControlAllowed(): bool
    {
        return $this->ReadPropertyBoolean('AllowControl') || $this->ReadAttributeBoolean('LegacyControl');
    }

    private function IsControllable(string $ident): bool
    {
        return isset(FaikoutCatalog::CONTROLS[$ident]) && $this->ControlAllowed();
    }

    /**
     * Instances of "Faikin 4 Daikin" (same module ID after the Store takeover) could always switch.
     * They are recognised once by a value variable with an action – this module never sets one
     * without "Allow control". Then control stays allowed, so schedules and scripts keep working.
     */
    private function DetectLegacyControl(): void
    {
        foreach (array_keys(FaikoutCatalog::CONTROLS) as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && IPS_GetVariable($id)['VariableAction'] === $this->InstanceID) {
                $this->WriteAttributeBoolean('LegacyControl', true);
                $this->LogMessage($this->Translate('Taken over from "Faikin 4 Daikin": control stays allowed.'), KL_NOTIFY);
                break;
            }
        }
        $this->WriteAttributeBoolean('LegacyChecked', true);
    }

    private function RevokeLegacyControl(): void
    {
        $this->WriteAttributeBoolean('LegacyControl', false);
        $this->MaintainValues();
        $this->ReloadForm();
    }

    /** Sends one change to the Faikout. The variable follows the answer of the device, it is not set in advance. */
    private function Control(string $ident, $value): void
    {
        if (!$this->ControlAllowed()) {
            throw new Exception($this->Translate('Control is not allowed. Tick "Allow control" in the instance.'));
        }
        $host = $this->Hostname();
        if ($host === '' || !$this->HasActiveParent()) {
            throw new Exception($this->Translate('The Faikout cannot be reached: hostname missing or MQTT server not active.'));
        }
        [$field] = FaikoutCatalog::CONTROLS[$ident];
        $command = [$field => $this->CommandValue($ident, $value)];
        if ($ident === 'status_temp' && $this->AutoActive()) {
            // Faikout auto sets the target itself from autot → move autot along (as Home Assistant does)
            $command['autot'] = $command['temp'];
        }
        $this->Publish('command/' . $host, json_encode($command));
        $expected = $this->ExpectedValue($ident, $command[$field]);
        $this->AddPending($ident, $expected);
        $this->ShowControlResult(sprintf($this->Translate('Sent: %s → %s'), $this->ValueName($ident), $this->FormatValue($ident, $expected)));
    }

    /** Value in the form the firmware accepts: true/false, a number, or one letter. */
    private function CommandValue(string $ident, $value)
    {
        $control = FaikoutCatalog::CONTROLS[$ident];
        switch ($control[1]) {
            case 'bool':
                return (bool) $value;
            case 'mode':
                if (!isset(self::MODE_LETTERS[(int) $value])) {
                    throw new Exception(sprintf($this->Translate('Invalid value for %s: %s'), $this->ValueName($ident), (string) $value));
                }
                return self::MODE_LETTERS[(int) $value];
            case 'fan':
                if (!isset(self::FAN_LETTERS[(int) $value])) {
                    throw new Exception(sprintf($this->Translate('Invalid value for %s: %s'), $this->ValueName($ident), (string) $value));
                }
                return self::FAN_LETTERS[(int) $value];
        }
        [, , $min, $max, $step] = $control;
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new Exception(sprintf($this->Translate('%s must be between %s and %s.'), $this->ValueName($ident), (string) $min, (string) $max));
        }
        $number = round((float) $value / $step) * $step;
        return FaikoutCatalog::VALUES[$ident][2] === VARIABLETYPE_INTEGER ? (int) round($number) : round($number, 1);
    }

    /** What the variable will show once the Faikout has taken the command. */
    private function ExpectedValue(string $ident, $sent)
    {
        switch (FaikoutCatalog::CONTROLS[$ident][1]) {
            case 'mode':
                return array_search($sent, self::MODE_LETTERS, true);
            case 'fan':
                return array_search($sent, self::FAN_LETTERS, true);
        }
        return $sent;
    }

    private function AutoActive(): bool
    {
        $enabled = @$this->GetIDForIdent('status_autoe');
        $range = @$this->GetIDForIdent('status_autor');
        return $enabled !== false && $range !== false && GetValue($enabled) === true && GetValue($range) > 0;
    }

    private function PendingList(): array
    {
        $pending = json_decode($this->ReadAttributeString('Pending'), true);
        return is_array($pending) ? $pending : [];
    }

    private function SavePending(array $pending): void
    {
        $this->WriteAttributeString('Pending', json_encode((object) $pending));
        $this->SetTimerInterval('Verify', $pending === [] ? 0 : self::VERIFY_MS);
    }

    private function AddPending(string $ident, $expected): void
    {
        $pending = $this->PendingList();
        $pending[$ident] = ['value' => $expected, 'at' => time(), 'asked' => false];
        $this->SavePending($pending);
    }

    /**
     * Compares pending commands with the reported values: reported → done; no status after a few seconds
     * → ask for it once; still different after VERIFY_MAX → not accepted (message + log).
     */
    private function VerifyPending(): void
    {
        $pending = $this->PendingList();
        $ask = false;
        foreach ($pending as $ident => $entry) {
            $id = @$this->GetIDForIdent($ident);
            if ($id === false) {
                unset($pending[$ident]); // value not selected – nothing to compare with
                continue;
            }
            $current = GetValue($id);
            if ($this->SameValue($current, $entry['value'])) {
                $this->ShowControlResult(sprintf($this->Translate('Accepted: %s → %s'), $this->ValueName($ident), $this->FormatValue($ident, $current)));
                unset($pending[$ident]);
                continue;
            }
            $age = time() - (int) $entry['at'];
            if ($age >= self::VERIFY_MAX) {
                $this->ReportRefusal(sprintf($this->Translate('%s: the air conditioner did not take %s, it reports %s.'),
                    $this->ValueName($ident), $this->FormatValue($ident, $entry['value']), $this->FormatValue($ident, $current)));
                unset($pending[$ident]);
            } elseif ($age >= self::VERIFY_ASK && !$entry['asked'] && $this->ReadAttributeInteger('LastStatus') < (int) $entry['at']) {
                $pending[$ident]['asked'] = true;
                $ask = true;
            }
        }
        if ($ask && $this->Hostname() !== '' && $this->HasActiveParent()) {
            $this->Publish('command/' . $this->Hostname() . '/status', '');
        }
        $this->SavePending($pending);
    }

    /** error/<host>/control {"field", "error"} or failed-set {field: value} for a pending command. */
    private function RefusedByDevice(string $what, array $payload): void
    {
        $pending = $this->PendingList();
        if ($pending === []) {
            return;
        }
        foreach (array_keys($pending) as $ident) {
            $field = FaikoutCatalog::CONTROLS[$ident][0];
            $hit = ($what === 'control' && ($payload['field'] ?? '') === $field) || ($what === 'failed-set' && array_key_exists($field, $payload));
            if ($hit) {
                $reason = (string) ($payload['error'] ?? $this->Translate('the air conditioner refused the change'));
                $this->ReportRefusal(sprintf($this->Translate('%s: not accepted – %s'), $this->ValueName($ident), $reason));
                unset($pending[$ident]);
            }
        }
        $this->SavePending($pending);
    }

    private function ReportRefusal(string $text): void
    {
        $this->LogMessage($text, KL_WARNING);
        $this->ShowControlResult($text);
    }

    private function ShowControlResult(string $text): void
    {
        $this->SendDebug('Steuern', $text, 0);
        $this->WriteAttributeString('LastControl', date('H:i:s') . ' ' . $text);
        $this->UpdateFormField('ControlResult', 'caption', $this->ReadAttributeString('LastControl'));
    }

    private function SameValue($a, $b): bool
    {
        if (is_float($a) || is_float($b)) {
            return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.05;
        }
        return $a === $b;
    }

    private function ValueName(string $ident): string
    {
        return $this->Translate(FaikoutCatalog::VALUES[$ident][1]);
    }

    private function FormatValue(string $ident, $value): string
    {
        $kind = FaikoutCatalog::VALUES[$ident][3];
        if (is_bool($value)) {
            return $this->Translate($kind === 'yesno' ? ($value ? 'Yes' : 'No') : ($value ? 'On' : 'Off'));
        }
        if ($kind === 'mode') {
            return $this->Translate(self::MODE_TEXTS[(int) $value] ?? (string) $value);
        }
        if ($kind === 'fan') {
            return $this->Translate(self::FAN_TEXTS[(int) $value] ?? (string) $value);
        }
        if (is_int($value) || is_float($value)) {
            [$suffix, $digits] = self::UNITS[$kind] ?? ['', 0];
            return number_format((float) $value, $digits, ',', '.') . $suffix;
        }
        return (string) $value;
    }

    // ------------------------------------------------------------ selection

    /** Saved selection per ident (missing = default of the catalog). */
    private function Selection(): array
    {
        if ($this->selection !== null) {
            return $this->selection;
        }
        $saved = json_decode($this->ReadPropertyString('ValueSelection'), true);
        $selection = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (isset($row['Ident'], FaikoutCatalog::VALUES[$row['Ident']])) {
                $selection[$row['Ident']] = (bool) ($row['Show'] ?? false);
            }
        }
        return $this->selection = $selection;
    }

    private function IsShown(string $ident): bool
    {
        return $this->Selection()[$ident] ?? FaikoutCatalog::VALUES[$ident][5];
    }

    private function IsSeen(string $ident): bool
    {
        return in_array($ident, $this->SeenList(), true);
    }

    private function SeenList(): array
    {
        $seen = json_decode($this->ReadAttributeString('SeenValues'), true);
        return is_array($seen) ? $seen : [];
    }

    private function MarkSeen(string $ident): void
    {
        $seen = $this->SeenList();
        if (!in_array($ident, $seen, true)) {
            $seen[] = $ident;
            $this->WriteAttributeString('SeenValues', json_encode($seen));
        }
    }

    private function IsArchived(string $ident): bool
    {
        $id = @$this->GetIDForIdent($ident);
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE);
        return $id !== false && $archives !== [] && AC_GetLoggingStatus($archives[0], $id);
    }

    private function SelectionRows(): array
    {
        $rows = [];
        foreach (FaikoutCatalog::VALUES as $ident => [$group, $name]) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) {
                $value = GetValueFormatted($id);
            } else {
                $value = $this->IsSeen($ident) ? '' : $this->Translate('not reported yet');
            }
            $rows[] = [
                'Show'    => $this->IsShown($ident),
                'Group'   => $this->Translate($group),
                'Name'    => $this->Translate($name),
                'Value'   => $value,
                'Archive' => $this->IsArchived($ident) ? $this->Translate('archived – stays') : '',
                'Ident'   => $ident
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------ form

    private function FillForm(array $items): array
    {
        foreach ($items as &$item) {
            if (isset($item['items'])) {
                $item['items'] = $this->FillForm($item['items']);
            }
            switch ($item['name'] ?? '') {
                case 'ValueSelection':
                    $item['values'] = $this->SelectionRows();
                    break;
                case 'Overview':
                    $item['caption'] = $this->OverviewText();
                    break;
                case 'DeviceInfo':
                    $item['caption'] = $this->DeviceInfoText();
                    break;
                case 'ControlResult':
                    $item['caption'] = $this->ReadAttributeString('LastControl');
                    break;
                case 'LegacyInfo':
                case 'RevokeLegacy':
                    $item['visible'] = $this->ReadAttributeBoolean('LegacyControl');
                    break;
            }
        }
        unset($item);
        return $items;
    }

    private function OverviewText(): string
    {
        if ($this->Hostname() === '') {
            return $this->Translate('Enter the hostname of the Faikout to start.');
        }
        if (!$this->HasActiveParent()) {
            return $this->Translate('The MQTT server is not active. Select or open the MQTT server via "Change gateway" at the top.');
        }
        $last = $this->ReadAttributeInteger('LastMessage');
        if ($last === 0) {
            return sprintf($this->Translate('No message from "%s" yet. Check the MQTT settings in the web interface of the Faikout (server = IP address of Symcon).'), $this->Hostname());
        }
        return sprintf($this->Translate('Last message: %s'), date('d.m.Y H:i:s', $last));
    }

    private function DeviceInfoText(): string
    {
        $parts = [];
        $id = $this->DeviceID();
        if ($id !== '') {
            $parts[] = sprintf($this->Translate('Device ID %s'), $id);
        }
        $settings = json_decode($this->ReadAttributeString('DeviceSettings'), true);
        if (is_array($settings) && isset($settings['reporting'])) {
            $parts[] = sprintf($this->Translate('report every %d s'), (int) $settings['reporting']);
        }
        if (is_array($settings) && array_key_exists('livestatus', $settings)) {
            $parts[] = $settings['livestatus'] ? $this->Translate('live status on') : $this->Translate('live status off');
        }
        return $parts === [] ? '' : implode(' · ', $parts);
    }

    // ------------------------------------------------------------ helpers

    private function Refresh(): void
    {
        if ($this->Hostname() === '') {
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->SetStatusIfChanged(self::STATUS_NO_PARENT);
            return;
        }
        $last = $this->ReadAttributeInteger('LastMessage');
        if ($last > 0 && time() - $last > self::SILENCE_MAX) {
            $this->SetStatusIfChanged(self::STATUS_SILENT);
        } elseif ($this->GetStatus() === self::STATUS_NO_PARENT) {
            $this->SetStatus(102);
        }
        // Without "livestatus" the Faikout sends state/<host>/status only on mode changes → ask for it
        if (time() - $this->ReadAttributeInteger('LastStatus') >= self::STATUS_AGE) {
            $this->Publish('command/' . $this->Hostname() . '/status', '');
        }
    }

    private function Publish(string $topic, string $payload): void
    {
        $this->SendDebug('Sende ' . $topic, $payload, 0);
        $this->SendDataToParent(json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => $payload
        ]));
    }

    private function DecodePayload(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $value = json_decode($raw, true);
        if ($value === null && strtolower($raw) !== 'null') {
            $value = json_decode(mb_convert_encoding($raw, 'ISO-8859-1', 'UTF-8'), true);
        }
        return $value ?? $raw;
    }

    private function ShortText($payload, string $raw): string
    {
        if (!is_array($payload)) {
            return $raw;
        }
        $parts = [];
        foreach ($payload as $key => $value) {
            $parts[] = $key . ' = ' . (is_scalar($value) ? var_export($value, true) : json_encode($value));
        }
        return implode(', ', $parts);
    }

    /** Topic part matches the hostname or the device ID (case-insensitive like the firmware's MAC). */
    private function IsOwnTarget(string $target): bool
    {
        $host = $this->Hostname();
        $id = $this->DeviceID();
        return ($host !== '' && strcasecmp($target, $host) === 0) || ($id !== '' && strcasecmp($target, $id) === 0);
    }

    /** Lets through messages whose topic contains the hostname or device ID; the exact check is done in ReceiveData. */
    private function ReceiveFilter(string $host, string $id): string
    {
        $names = array_filter([$host, $id], static function (string $name): bool {
            return $name !== '';
        });
        if ($names === []) {
            return '.*"Topic":"-nothing-".*';
        }
        $variants = [];
        foreach ($names as $name) {
            // The firmware may write the device ID in upper or lower case
            $variants[] = preg_quote($name, '/');
            $variants[] = preg_quote(strtolower($name), '/');
            $variants[] = preg_quote(strtoupper($name), '/');
        }
        return '.*(' . implode('|', array_unique($variants)) . ').*';
    }

    private function Hostname(): string
    {
        return trim($this->ReadPropertyString('Hostname'));
    }

    private function DeviceID(): string
    {
        $id = $this->ReadAttributeString('state_id');
        return preg_match('/^[0-9A-Fa-f]{12}$/', $id) ? strtoupper($id) : '';
    }

    private function SetStatusIfChanged(int $status): void
    {
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }
}
