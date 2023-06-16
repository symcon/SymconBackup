# Backup
Erstellt ein Backup über SFTP, FTP oder FTPS.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#5-php-befehlsreferenz)

### 1. Funktionsumfang

* Erstellt ein Backup über SFTP, FTP oder FTPS auf einem Server
* Aktualisiert ein Backup über SFTP, FTP oder FTPS auf einem Server

### 2. Voraussetzungen

- IP-Symcon ab Version 6.3

### 3. Software-Installation

* Über den Module Store das 'Backup (SFTP/FTP/FTPS)'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen: https://github.com/symcon/SymconBackup

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'Backup (SFTP/FTP/FTPS)'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name                            | Beschreibung
------------------------------- | ------------------
Verbindungstyp                  | Auswahl der Verbindungsart
Host                            | IP Adresse des Servers
Port                            | Port auf dem der Client sich auf den Server verbindet. Bei FTPS und FTP ist dies normalerweise 21. Bei SFTP ist dies der Port 22
Benutzername                    | Benutzernamen für die SFTP Verbindung 
Passwort                        | Passwort für die SFTP Verbindung 
Modus                           | Modus was für ein Backup erstellt werden soll 
Wechsel Ordner nach             | Zum Anfang der Periode wird ein neuer Ordner erstellt
Zielordner                      | Ordner auf dem Server, in dem das Backup erstellt werden soll
Suche Zielordner                | Durch die Ordner browsen und ein valider Pfad kann erstellt werden
Automatische Backups aktivieren | Aktiviert ein tägliches Update
Täglich um                      | Zeit, wann das Update täglich startet  
Expertenoptionen                | -
Größen Limit                    | Ist eine Datei Größer als dieses Limit wird sie ignoriert
Gefilterte Ordner               | Filter um bestimmte Ordner nicht zu übertragen
Backup erstellen                | Button, welcher sofort ein Update startet. 
Verbindung testen               | Testet, ob eine Verbindung hergestellt werden kann

__Modus__: 
Vollständiges Backup: Erstellt eine vollständige Kopie in einem separaten Ordner nach dem Pattern: symcon-backup-{Jahr}-{Monat}-{Tag}-{Stunde}-{Minute}-{Sekunde}  
Inkrementelles Backup: Updatet ein bestehendes Backup.  
Steht die Option 'Wechsel nach Ordner' nicht auf 'Niemals', so wird der Ordner nach der eingestellten Zeit gewechselt.  
Die Ordner, welche beim inkrementellen Backup erstellt werden, folgen folgenden Pattern:  
- Niemals: symcon-backup
- Woche: symcon-backup-{Jahr}-0{Kalenderwoche}  
- Monat: symcon-backup-{Jahr}-{Monat}  
- Jahr: symcon-backup-{Jahr}  

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name                           | Typ     | Beschreibung
------------------------------ | ------- | ------------
Zuletzt abgeschlossenes Backup | Integer | Zeitpunkt, zudem das letzte Backup abgeschlossen wurde
Übertragene Megabytes          | Float   | Megabytes, welche beim letzten Update übertragen wurde 

### 6. PHP-Befehlsreferenz

`boolean SB_CreateBackup(integer $InstanzID);`
Erstellt oder updatet ein Backup nach den Einstellungen der Instanz

Beispiel:
`SB_CreateBackup(12345);`