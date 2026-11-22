<?php

declare(strict_types=1);

/**
 * All values the module knows, and where they come from.
 *
 * The idents are the same as in Kris' module "Faikin 4 Daikin" (status_…, state_…, error_…),
 * so existing variables, archives and links can be taken over later.
 * Field names and meanings follow the Faikout firmware (acfields.m, accontrols.m, Faikout.c, March 2026).
 */
final class FaikoutCatalog
{
    // Groups (shown in the value selection, English; German in locale.json)
    public const GROUP_OPERATION = 'Operation';
    public const GROUP_MEASURE   = 'Measurements';
    public const GROUP_ENERGY    = 'Energy';
    public const GROUP_AUTO      = 'Faikout auto';
    public const GROUP_BLE       = 'BLE sensor';
    public const GROUP_DEVICE    = 'Device';
    public const GROUP_ERROR     = 'Errors';

    /**
     * ident => [group, name, variable type, display kind, position, shown by default]
     * Display kinds: see FaikoutDaikin::Presentation().
     */
    public const VALUES = [
        'status_power'      => [self::GROUP_OPERATION, 'Power', VARIABLETYPE_BOOLEAN, 'onoff', 10, true],
        'status_mode'       => [self::GROUP_OPERATION, 'Mode', VARIABLETYPE_INTEGER, 'mode', 11, true],
        'status_temp'       => [self::GROUP_OPERATION, 'Target temperature', VARIABLETYPE_FLOAT, 'temp', 12, true],
        'status_fan'        => [self::GROUP_OPERATION, 'Fan speed', VARIABLETYPE_INTEGER, 'fan', 13, true],
        'status_swingv'     => [self::GROUP_OPERATION, 'Vertical louvre swing', VARIABLETYPE_BOOLEAN, 'onoff', 14, true],
        'status_swingh'     => [self::GROUP_OPERATION, 'Horizontal louvre swing', VARIABLETYPE_BOOLEAN, 'onoff', 15, true],
        'status_econo'      => [self::GROUP_OPERATION, 'Econo mode', VARIABLETYPE_BOOLEAN, 'onoff', 16, true],
        'status_powerful'   => [self::GROUP_OPERATION, 'Powerful mode', VARIABLETYPE_BOOLEAN, 'onoff', 17, true],
        'status_comfort'    => [self::GROUP_OPERATION, 'Comfort airflow', VARIABLETYPE_BOOLEAN, 'onoff', 18, true],
        'status_quiet'      => [self::GROUP_OPERATION, 'Quiet outdoor unit', VARIABLETYPE_BOOLEAN, 'onoff', 19, true],
        'status_sensor'     => [self::GROUP_OPERATION, 'Intelligent eye sensor', VARIABLETYPE_BOOLEAN, 'onoff', 20, true],
        'status_streamer'   => [self::GROUP_OPERATION, 'Streamer', VARIABLETYPE_BOOLEAN, 'onoff', 21, true],
        'status_led'        => [self::GROUP_OPERATION, 'LED on the indoor unit', VARIABLETYPE_BOOLEAN, 'onoff', 22, true],
        'status_demand'     => [self::GROUP_OPERATION, 'Demand limit', VARIABLETYPE_INTEGER, 'percent', 23, false],
        'status_heat'       => [self::GROUP_OPERATION, 'Heating', VARIABLETYPE_BOOLEAN, 'yesno', 24, true],
        'status_online'     => [self::GROUP_OPERATION, 'Air conditioner reachable', VARIABLETYPE_BOOLEAN, 'yesno', 25, true],
        'status_control'    => [self::GROUP_OPERATION, 'External control active', VARIABLETYPE_BOOLEAN, 'yesno', 26, false],
        'status_slave'      => [self::GROUP_OPERATION, 'Heat/cool locked by another unit', VARIABLETYPE_BOOLEAN, 'yesno', 27, false],
        'status_antifreeze' => [self::GROUP_OPERATION, 'Antifreeze active', VARIABLETYPE_BOOLEAN, 'yesno', 28, true],

        'status_home'       => [self::GROUP_MEASURE, 'Room temperature', VARIABLETYPE_FLOAT, 'temp', 30, true],
        'status_outside'    => [self::GROUP_MEASURE, 'Outside temperature', VARIABLETYPE_FLOAT, 'temp', 31, true],
        'status_inlet'      => [self::GROUP_MEASURE, 'Inlet temperature', VARIABLETYPE_FLOAT, 'temp', 32, true],
        'status_liquid'     => [self::GROUP_MEASURE, 'Liquid coolant temperature', VARIABLETYPE_FLOAT, 'temp', 33, true],
        'status_hum'        => [self::GROUP_MEASURE, 'Humidity', VARIABLETYPE_FLOAT, 'humidity', 34, true],
        'status_fanrpm'     => [self::GROUP_MEASURE, 'Fan speed (rpm)', VARIABLETYPE_INTEGER, 'rpm', 35, true],
        'status_comp'       => [self::GROUP_MEASURE, 'Compressor frequency', VARIABLETYPE_INTEGER, 'hertz', 36, true],

        'status_Wh'         => [self::GROUP_ENERGY, 'Energy total', VARIABLETYPE_FLOAT, 'kwh', 40, true],
        'status_Whheating'  => [self::GROUP_ENERGY, 'Energy heating', VARIABLETYPE_FLOAT, 'kwh', 41, true],
        'status_Whcooling'  => [self::GROUP_ENERGY, 'Energy cooling', VARIABLETYPE_FLOAT, 'kwh', 42, true],

        'status_autoe'      => [self::GROUP_AUTO, 'Timer and automatic power enabled', VARIABLETYPE_BOOLEAN, 'onoff', 50, true],
        'status_autop'      => [self::GROUP_AUTO, 'Automatic power on/off', VARIABLETYPE_BOOLEAN, 'onoff', 51, true],
        'status_autot'      => [self::GROUP_AUTO, 'Faikout auto target temperature', VARIABLETYPE_FLOAT, 'temp', 52, true],
        'status_autor'      => [self::GROUP_AUTO, 'Faikout auto range (±)', VARIABLETYPE_FLOAT, 'kelvin', 53, true],
        'status_autoon'     => [self::GROUP_AUTO, 'Switch-on time', VARIABLETYPE_STRING, 'text', 54, true],
        'status_autooff'    => [self::GROUP_AUTO, 'Switch-off time', VARIABLETYPE_STRING, 'text', 55, true],
        'status_env'        => [self::GROUP_AUTO, 'Reference temperature', VARIABLETYPE_FLOAT, 'temp', 56, true],

        'status_autob'      => [self::GROUP_BLE, 'BLE sensor', VARIABLETYPE_STRING, 'text', 60, true],
        'status_ble_temp'   => [self::GROUP_BLE, 'BLE sensor temperature', VARIABLETYPE_FLOAT, 'temp', 61, true],
        'status_ble_hum'    => [self::GROUP_BLE, 'BLE sensor humidity', VARIABLETYPE_FLOAT, 'humidity', 62, true],
        'status_ble_bat'    => [self::GROUP_BLE, 'BLE sensor battery', VARIABLETYPE_INTEGER, 'percent', 63, true],
        'status_ble_volt'   => [self::GROUP_BLE, 'BLE sensor voltage', VARIABLETYPE_FLOAT, 'volt', 64, false],

        'state_up'          => [self::GROUP_DEVICE, 'Faikout connected', VARIABLETYPE_BOOLEAN, 'yesno', 80, true],
        'status_model'      => [self::GROUP_DEVICE, 'Model', VARIABLETYPE_STRING, 'text', 81, true],
        'state_ipv4'        => [self::GROUP_DEVICE, 'IP address', VARIABLETYPE_STRING, 'text', 82, true],
        'state_version'     => [self::GROUP_DEVICE, 'Firmware version', VARIABLETYPE_STRING, 'text', 83, true],
        'state_ssid'        => [self::GROUP_DEVICE, 'WiFi network', VARIABLETYPE_STRING, 'text', 84, false],
        'state_rssi'        => [self::GROUP_DEVICE, 'WiFi signal', VARIABLETYPE_INTEGER, 'dbm', 85, false],

        'error_description' => [self::GROUP_ERROR, 'Last error', VARIABLETYPE_STRING, 'text', 90, true],
        'error_failed_set'  => [self::GROUP_ERROR, 'Last rejected change', VARIABLETYPE_STRING, 'text', 91, true]
    ];

    /** Fields of state/<host>/status and of the periodic report <app>/<host> => ident */
    public const STATUS_FIELDS = [
        'power'       => 'status_power',
        'mode'        => 'status_mode',
        'temp'        => 'status_temp',
        'fan'         => 'status_fan',
        'swingv'      => 'status_swingv',
        'swingh'      => 'status_swingh',
        'econo'       => 'status_econo',
        'powerful'    => 'status_powerful',
        'comfort'     => 'status_comfort',
        'quiet'       => 'status_quiet',
        'sensor'      => 'status_sensor',
        'streamer'    => 'status_streamer',
        'led'         => 'status_led',
        'demand'      => 'status_demand',
        'heat'        => 'status_heat',
        'online'      => 'status_online',
        'control'     => 'status_control',
        'slave'       => 'status_slave',
        'antifreeze'  => 'status_antifreeze',
        'model'       => 'status_model',
        'home'        => 'status_home',
        'outside'     => 'status_outside',
        'inlet'       => 'status_inlet',
        'liquid'      => 'status_liquid',
        'hum'         => 'status_hum',
        'fanrpm'      => 'status_fanrpm',
        'comp'        => 'status_comp',
        'Whoutside'   => 'status_Wh',
        'Wh'          => 'status_Wh', // older firmware
        'Whheating'   => 'status_Whheating',
        'Whcooling'   => 'status_Whcooling',
        'autoe'       => 'status_autoe',
        'autop'       => 'status_autop',
        'autot'       => 'status_autot',
        'autor'       => 'status_autor',
        'auto1'       => 'status_autoon',
        'auto0'       => 'status_autooff',
        'env'         => 'status_env',
        'autob'       => 'status_autob'
    ];

    /** Fields of the BLE object ("ble": {...}) in state/<host>/status => ident */
    public const BLE_FIELDS = [
        'temp' => 'status_ble_temp',
        'hum'  => 'status_ble_hum',
        'bat'  => 'status_ble_bat',
        'volt' => 'status_ble_volt'
    ];

    /**
     * Fields of state/<host> (state of the Faikout itself) => ident.
     * This topic also carries Home Assistant values with other meanings ("temp" = room temperature,
     * "mode" = "heat"/"off" …). Those are deliberately not read here.
     */
    public const DEVICE_FIELDS = [
        'online'  => 'status_online',
        'ipv4'    => 'state_ipv4',
        'version' => 'state_version',
        'ssid'    => 'state_ssid',
        'rssi'    => 'state_rssi'
    ];

    /** Operating modes: letter of the firmware => value of status_mode (same numbers as Kris' module) */
    public const MODES = ['H' => 1, 'C' => 2, 'A' => 3, 'F' => 4, 'D' => 5];

    /** Fan speeds: letter of the firmware => value of status_fan (same numbers as Kris' module) */
    public const FANS = ['Q' => -1, 'B' => -1, 'A' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5];

    /** Variables of Kris' module that are replaced by other ones (deleted unless archived) */
    public const LEGACY = [
        'status_auto0', // now status_autooff (text instead of timestamp)
        'status_auto1', // now status_autoon
        'status_ipv4'   // duplicate of state_ipv4
    ];
}
