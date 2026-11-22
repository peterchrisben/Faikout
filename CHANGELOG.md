# Changelog

## Version 1.0

### Build 2 (22.11.2026)

#### Neu
- Steuern: Ein/Aus, Betriebsart, Solltemperatur, Lüfterstufe, Lamellen, Eco, Powerful, Komfort, Leise, Präsenzsensor, Streamer, LED, Leistungsbegrenzung sowie Faikout-Automatik (freigeben, automatisch ein/aus, Soll, Bereich). Befehl über `command/<host>` mit JSON, so wie die Firmware ihn erwartet (true/false, Zahl, ein Buchstabe für Betriebsart und Lüfter).
- Häkchen „Steuerung freigeben“ (Standard aus). Ohne Freigabe sind alle Werte nur Anzeige.
- Instanzen, die von „Faikin 4 Daikin“ übernommen werden, bleiben schaltbar (einmalige Erkennung an vorhandenen Variablen mit Aktion), mit Knopf „Freigabe zurücknehmen“.
- Der Wert wird nicht vorab gesetzt, sondern aus der Rückmeldung des Geräts übernommen. Kommt keine Rückmeldung, fragt das Modul nach 5 s nach; nach 20 s oder bei einer Ablehnung (`error/<host>/control`, `failed-set`) steht eine Meldung im Formular und im Meldungsfenster.
- Bei aktiver Faikout-Automatik wird mit der Solltemperatur auch die Automatik-Solltemperatur gesetzt (wie bei Home Assistant).
- Aktuelle Leistung in W (`status_consumption`).
- Firmware-Version mit Build-Datum, z. B. „5298aa2d (19.11.2026)“.

#### Geändert
- Leistungsbegrenzung ist standardmäßig angehakt.

### Build 1 (22.11.2026)

#### Neu
- Erste Testversion, nur lesen: Daikin-Klimageräte über Faikout/Faikin per MQTT (Symcon-MQTT-Server).
- Topics getrennt ausgewertet: `state/<host>` (Faikout selbst, offline = `false`), `state/<host>/status` (Klimagerät), `Faikout/<host>` bzw. `Faikin/<host>` (regelmäßiger Bericht, Mittelwert aus Min/Mittel/Max), `setting/<host>`, `error/<host>/…`. Home-Assistant-Werte in `state/<host>` werden nicht in die Klimagerät-Variablen übernommen.
- Faikout wird am Hostnamen und an der Geräte-ID erkannt (Groß-/Kleinschreibung egal).
- Werte werden vor dem Schreiben auf den Variablentyp geprüft; unbrauchbare Werte werden übersprungen.
- Werte auswählen (Liste mit Haken), Variable erst, wenn der Wert gemeldet wird; archivierte Werte bleiben beim Abwählen.
- Darstellungen statt Variablenprofilen.
- Status jede Minute anfordern, wenn der Faikout ihn nicht von selbst schickt; Status 201 nach 5 Minuten ohne Nachricht, 202 wenn der MQTT-Server nicht aktiv ist.
- Idents wie in „Faikin 4 Daikin“ von Kris (Übernahme später möglich); ersetzte Variablen (`status_auto0`, `status_auto1`, `status_ipv4`) werden gelöscht, außer sie werden archiviert.
- Installierte Version ganz unten im Formular.
- Texte englisch im Code, Deutsch in `locale.json`.
