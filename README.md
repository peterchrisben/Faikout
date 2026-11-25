# Faikout 4 Daikin

Symcon-Modul für Daikin-Klimageräte mit einem **Faikout**-Modul (früher **Faikin**). Der Faikout ist eine kleine ESP32-Platine, die an die Service-Schnittstelle des Innengeräts (S21 bzw. X50) gesteckt wird und das Klimagerät lokal über MQTT bereitstellt – ohne Daikin-Cloud.

**Aktuelle Version:** 2.0 (Build 5)

Nachfolger von [„Faikin 4 Daikin“](https://github.com/lorbetzki/net.lorbetzki.faikin4daikin) von Kris, der die Weiterentwicklung seiner Module 2025 beendet und zur Übernahme freigegeben hat ([Forum](https://community.symcon.de/t/supportende-meiner-module/141740)). Danke, Kris, für die Vorarbeit! Der Code ist neu geschrieben. Modul-ID, Befehle (`FAIKIN_…`) und Variablen sind dieselben – es ist ein Update von „Faikin 4 Daikin“, bestehende Instanzen, Variablen und Archive bleiben erhalten.

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Einrichtung](#3-einrichtung)
4. [Werte](#4-werte)
5. [PHP-Befehle](#5-php-befehle)
6. [Fehlersuche](#6-fehlersuche)
7. [Lizenz und Markenhinweis](#7-lizenz-und-markenhinweis)

## 1. Funktionsumfang

- Liest alle Werte, die der Faikout meldet: Ein/Aus, Betriebsart, Solltemperatur, Lüfterstufe, Lamellen, Sonderfunktionen (Eco, Powerful, Komfort, Leise, Streamer …), Raum-/Außen-/Kältemitteltemperatur, Luftfeuchtigkeit, Lüfterdrehzahl, Kompressor, Energieverbrauch, Faikout-Automatik, BLE-Sensor, Geräte- und Fehlermeldungen.
- Steuern aus Symcon (nach Freigabe): Ein/Aus, Betriebsart, Solltemperatur, Lüfter, Lamellen, Sonderfunktionen, Leistungsbegrenzung und Faikout-Automatik. Angezeigt wird immer, was das Gerät zurückmeldet; was es nicht übernimmt, meldet das Modul im Klartext.
- Eine Variable entsteht erst, wenn der Faikout den Wert meldet – nicht jedes Klimagerät hat jede Funktion.
- Jeder Wert lässt sich einzeln an- oder abwählen. Archivierte Werte bleiben beim Abwählen erhalten.
- Wertet die Nachrichten-Arten des Faikout getrennt aus (Gerätestatus, Klimagerät-Status, regelmäßiger Bericht, Einstellungen, Fehler). Werte für Home Assistant, die unter gleichem Namen etwas anderes bedeuten (z. B. „temp“ = Raumtemperatur), werden nicht verwechselt.
- Erkennt den Faikout am Hostnamen und an seiner Geräte-ID.
- Fragt den Status jede Minute an, falls der Faikout ihn nicht von selbst schickt.
- Meldet, wenn 5 Minuten lang nichts mehr kommt.
- Einstellungen des Faikout (Meldeintervall, Live-Status, Home Assistant, LED) und Neustart aus dem Formular.

## 2. Voraussetzungen

- Symcon ab 8.0
- Ein Faikout/Faikin-Modul mit aktueller Firmware ([Projekt auf Codeberg](https://codeberg.org/RevK/ESP32-Faikout))
- Eine MQTT-Server-Instanz in Symcon (Splitter „MQTT Server“)

## 3. Einrichtung

1. **Faikout:** In der Weboberfläche des Faikout einen Hostnamen vergeben (z. B. `Wohnzimmer`). Als MQTT-Server die IP-Adresse von Symcon eintragen, Port 1883 (falls nicht geändert), dazu Benutzer und Passwort der MQTT-Server-Instanz in Symcon.
2. **Symcon:** Im Module Store **„Faikout 4 Daikin“** (früher „Faikin 4 Daikin“) installieren – oder in der Modulverwaltung die URL `https://github.com/peterchrisben/Faikout` hinzufügen.
3. Instanz **„Faikout Daikin“** anlegen. Als Gateway den MQTT-Server wählen.
4. Im Formular den **Hostnamen** des Faikout eintragen, „Änderungen übernehmen“.
5. Nach wenigen Sekunden erscheinen die Werte. Im Formular steht „Letzte Nachricht …“.

Pro Klimagerät eine Instanz.

**Wer „Faikin 4 Daikin“ schon hat**, aktualisiert einfach im Store – eine zweite Installation über GitHub daneben geht nicht (gleiche Modul-ID). Wer die Testversion (Build 1/2) über GitHub installiert hatte: Testinstanzen und die GitHub-Bibliothek löschen, dann über den Store aktualisieren.

### Einstellungen

| Einstellung | Bedeutung |
|---|---|
| Hostname des Faikout | Wie in der Weboberfläche des Faikout eingestellt. Die Geräte-ID lernt das Modul selbst. |
| Steuerung freigeben | Erst mit Haken lässt sich das Klimagerät aus Symcon schalten (Visualisierung, Ablaufpläne, Skripte). Ohne Haken sind alle Werte nur Anzeige. |
| Werte auswählen | Haken = Wert anzeigen. Gespeichert wird erst mit „Änderungen übernehmen“. Haken weg löscht die Variable samt Verknüpfungen – außer sie wird archiviert, dann bleibt sie. Wieder anhaken legt sie neu an (mit neuer ID und Position). |

### Einstellungen des Faikout

Unter „Einstellungen des Faikout“ im Formular (unten) lassen sich Meldeintervall, **Live-Status** und Meldungen für Home Assistant direkt im Faikout ändern; dazu der Knopf „Faikout neu starten“ (das Klimagerät läuft weiter). Tipp: Mit Live-Status kommen Änderungen sofort, ohne fragt das Modul jede Minute nach. Unter „Status jetzt anfordern“ zeigt das Formular, wie der Faikout eingestellt ist.

## 4. Werte

| Gruppe | Werte (Ident) |
|---|---|
| Betrieb | Eingeschaltet (`status_power`), Betriebsart (`status_mode`: 1 Heizen, 2 Kühlen, 3 Automatik, 4 Lüften, 5 Entfeuchten), Solltemperatur (`status_temp`), Lüfterstufe (`status_fan`: -1 Nacht, 0 Automatik, 1–5), Lamellen vertikal/horizontal (`status_swingv`, `status_swingh`), Eco (`status_econo`), Powerful (`status_powerful`), Komfort-Luftstrom (`status_comfort`), Leises Außengerät (`status_quiet`), Präsenzsensor (`status_sensor`), Streamer (`status_streamer`), LED (`status_led`), Leistungsbegrenzung (`status_demand`), Beim Einschalten Nachtmodus (`silentModeOnStart`, Option des Moduls, nur mit Freigabe), Heizbetrieb (`status_heat`), Klimagerät erreichbar (`status_online`), Externe Regelung (`status_control`, aus), Heizen/Kühlen gesperrt (`status_slave`, aus), Frostschutz (`status_antifreeze`) |
| Messwerte | Raum- (`status_home`), Außen- (`status_outside`), Ansaug- (`status_inlet`), Kältemitteltemperatur (`status_liquid`), Luftfeuchtigkeit (`status_hum`), Lüfterdrehzahl (`status_fanrpm`), Kompressor-Frequenz (`status_comp`) |
| Energie | aktuelle Leistung in W (`status_consumption`), Energiezähler Außengerät (`status_Wh` – bei Multisplit für alle Innengeräte gemeinsam, jedes Innengerät meldet ihn mit), Heizen (`status_Whheating`), Kühlen (`status_Whcooling`) – in kWh |
| Faikout-Automatik | freigegeben (`status_autoe`), automatisch ein/aus (`status_autop`), Solltemperatur (`status_autot`), Bereich ± (`status_autor`), Ein-/Ausschaltzeit (`status_autoon`, `status_autooff`, „–“ = keine), Referenztemperatur (`status_env`) |
| BLE-Sensor | Name (`status_autob`), Temperatur, Luftfeuchtigkeit, Batterie, Spannung (aus) |
| Gerät | Faikout verbunden (`state_up`), Modell, IP-Adresse, Firmware-Version, WLAN-Netz und -Signal (aus), LED am Faikout aus (`setting_dark`) |
| Fehler | Letzte Fehlermeldung (`error_description`), zuletzt abgelehnte Änderung (`error_failed_set`) |

„(aus)“ = standardmäßig nicht angehakt.

Im regelmäßigen Bericht des Faikout stehen Werte, die sich im Berichtszeitraum geändert haben, als Minimum/Mittel/Maximum – das Modul nimmt den Mittelwert. Schaltzustände, die sich im Zeitraum geändert haben, übernimmt es aus dem Bericht nicht, sondern aus der nächsten Statusmeldung.

### Steuern

Mit „Steuerung freigeben“ werden die Werte der Gruppe Betrieb und der Faikout-Automatik schaltbar: Schalter, Auswahl für Betriebsart und Lüfter, Schieberegler für Solltemperatur (16–32 °C, 0,5er-Schritte), Leistungsbegrenzung (30–100 %, 5er-Schritte) und Automatik-Bereich (0–5 K).

- Die Variable wird **nicht vorab** umgestellt. Sie zeigt den neuen Wert, sobald das Klimagerät ihn bestätigt (meist nach 1–2 Sekunden).
- Lehnt das Gerät ab oder kommt nach 20 Sekunden keine Bestätigung, steht das im Formular unter „Status jetzt anfordern“ und im Meldungsfenster, z. B. „Eco-Modus: nicht übernommen – Setting cannot be controlled“.
- Eine neue Betriebsart schaltet das Gerät nicht ein (anders als bei „Faikin 4 Daikin“). Zum Einschalten „Eingeschaltet“ setzen.
- Ist die Faikout-Automatik aktiv (freigegeben und Bereich > 0), wird mit der Solltemperatur auch die Automatik-Solltemperatur geändert – sonst würde die Automatik sie gleich wieder zurückstellen.
- Ein-/Ausschaltzeiten sind vorerst nur Anzeige.

Aus Skripten: `RequestAction($VariablenID, $Wert);` – z. B. `RequestAction(12345, 22.5);` für die Solltemperatur.

### Umstieg von „Faikin 4 Daikin“

Das Update kommt über den Store-Eintrag von „Faikin 4 Daikin“ (von Kris übergeben). Die Variablen bleiben mit ihren IDs erhalten, ebenso die Befehle `FAIKIN_…` und die Option „Beim Einschalten Nachtmodus“. Ersetzt werden nur:

- `status_auto0`/`status_auto1` (Zeitstempel) → `status_autooff`/`status_autoon` (Text „hh:mm“)
- `status_ipv4` (doppelt) → `state_ipv4`
- `info_version` → `state_version` (Firmware-Version), `info_up_to_date` entfällt

Die alten werden gelöscht – außer sie werden archiviert.

Übernommene Instanzen **bleiben schaltbar**, auch ohne Haken bei „Steuerung freigeben“, damit Ablaufpläne und Skripte weiterlaufen. Im Formular steht dann ein Hinweis mit dem Knopf „Freigabe zurücknehmen“.

## 5. PHP-Befehle

```php
FAIKIN_RequestStatus(int $InstanzID): bool;               // Status und Einstellungen beim Faikout anfordern
FAIKIN_ReloadSettings(int $InstanzID);                    // nur die Einstellungen anfordern
FAIKIN_SetFaikinLed(int $InstanzID, bool $Dunkel);        // true = LED am Faikout aus (wie bei Kris)
FAIKIN_SetSilentModeOnStart(int $InstanzID, bool $An);    // Option: beim Einschalten Lüfter auf Nacht
FAIKIN_RestartDevice(int $InstanzID);                     // Faikout neu starten
FAIKIN_SelectAllValues(int $InstanzID, bool $Show);       // im offenen Formular alle Werte an-/abhaken
```

## 6. Fehlersuche

- **Ganz unten im Formular** steht die installierte Version, z. B. „Faikout 4 Daikin 2.0 (Build 5)“ – so sieht man, ob ein Update angekommen ist. Nach einem Update offene Formulare schließen und neu öffnen.
- **„Noch keine Nachricht“:** MQTT-Einstellungen im Faikout prüfen (Server = IP von Symcon, Benutzer/Passwort der MQTT-Server-Instanz). In der Weboberfläche des Faikout muss MQTT verbunden sein.
- **Debug:** Im Debug-Fenster der Instanz steht jede Nachricht mit Topic und Inhalt. Bei Problemen bitte einen Ausschnitt davon schicken.

## 7. Lizenz und Markenhinweis

Lizenz: [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/) – Peter Chrisben.

Daikin, Faikout und Faikin sind Marken ihres jeweiligen Inhabers. Faikout 4 Daikin ist ein privates Projekt und steht in keiner Verbindung zu den Herstellern.
