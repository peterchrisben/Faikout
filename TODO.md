# Tests am echten Gerät

| Was | Wie prüfen | Wann | Status |
|---|---|---|---|
| Nachrichten kommen an | Instanz anlegen, Hostname eintragen → „Letzte Nachricht …“, Debug zeigt `state/<host>`, `Faikout/<host>` | Build 1 | offen |
| Geräte-ID erkannt | Formular unter „Status jetzt anfordern“: „Geräte-ID …“ | Build 1 | offen |
| Werte plausibel | Solltemperatur ≠ Raumtemperatur, Betriebsart/Lüfter als Text, Energie in kWh | Build 1 | offen |
| Status ohne Live-Status | Live-Status im Faikout aus → Werte trotzdem spätestens nach 1 min aktuell | Build 1 | offen |
| Faikout offline | Faikout vom Strom → „Faikout verbunden“ = Nein; nach 5 min Status 201 | Build 1 | offen |
| Kompressor-Einheit | Wert von `status_comp` mit Weboberfläche vergleichen (Hz oder %?) | Build 1 | offen |
| BLE-Batterie | `status_ble_bat` = Prozent? (Kris hatte mV) | Build 1, nur mit BLE-Sensor | offen |
| Darstellung in der App | Variablen in der Symcon-App (iOS/Android) ansehen, nicht nur im Browser | Build 1 | offen |
| Darstellung ersetzt altes Profil | Nach Übernahme einer Kris-Instanz: Variable zeigt Darstellung, nicht mehr FAIKIN_-Profil | bei Store-Übergabe | offen |
| Befehls-Topics | Kris' Modul: Ein/Aus, Eco, Swing kommen an (Detlev 22.11.); Firmware-Quelltext bestätigt Format | vor Build 2 | ok |
| Steuern mit Freigabe | Haken setzen → Ein/Aus, Betriebsart, Soll, Lüfter, Lamellen in der Visu schalten; Variable folgt nach 1–2 s | Build 2 | offen |
| Ablehnung | Funktion schalten, die das Gerät nicht hat → Meldung im Formular/Meldungsfenster | Build 2 | offen |
| Aktuelle Leistung | `status_consumption` = W aus der Weboberfläche | Build 2 | offen |
| Schieberegler/Aufzählung in der App | Solltemperatur, Betriebsart, Lüfter in der Symcon-App ansehen | Build 2 | offen |
| Übernahme Kris-Instanz | Store-Update auf Beta: Variablen bleiben, Steuern geht weiter, Hinweis + Knopf „Freigabe zurücknehmen“, `FAIKIN_…`-Skripte laufen | Build 3 (Beta) | offen |
| Übernahme von Stable 1.5.2 | ein Nutzer mit 1.5.2 aktualisiert (später über Stable) | nach Beta | offen |
| Nachtmodus beim Einschalten | Option an → Einschalten → Lüfter steht auf Nacht | Build 3 | offen |
| LED am Faikout | „LED am Faikout aus“ schalten → LED geht aus, Variable folgt | Build 3 | offen |
| Faikout-Einstellungen | Live-Status im Formular einschalten und senden → Weboberfläche zeigt es | Build 3 | offen |
