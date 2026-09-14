<img src="icons/shive-color.png" width="96" align="right" alt="Shive">

# Shive

**ZFS-Snapshots, Aufbewahrung und Replikation für Unraid 7.2+**

[![CI](https://github.com/heinrichchristopher/shive/actions/workflows/ci.yml/badge.svg)](https://github.com/heinrichchristopher/shive/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Installation** (Unraid → Plugins → Install Plugin):

```
https://raw.githubusercontent.com/heinrichchristopher/shive/main/shive.plg
```

Shive legt nach Zeitplan ZFS-Snapshots beliebiger Datasets an, räumt sie nach Alter oder
GFS-Regel auf, stoppt auf Wunsch die Docker-Container, deren Daten auf den Datasets liegen,
repliziert auf einen zweiten lokalen Pool und/oder einen SSH-Host und lässt einzelne Dateien
oder ganze Datasets aus der WebGUI zurückholen.

**Entwicklung.** Entstanden in enger Zusammenarbeit mit Claude (Anthropic). Architekturentscheidungen,
sämtliche Tests auf echter Hardware (eine produktive Unraid-7.3.2-Box mit echten ZFS-Pools und
Docker-Containern) sowie jede Design- und Sicherheitsentscheidung lagen bei mir; Claude hat den
Code unter dieser Anleitung geschrieben, eine 109 Prüfungen umfassende Regressionssuite gegen
einen zustandsbehafteten Simulator laufen lassen und sechs eigene QC-Runden durchgeführt.

---

## Inhalt

- [Warum Shive](#warum-shive)
- [Installation](#installation)
- [Funktionen](#funktionen)
  - [Zeitpläne](#zeitpläne)
  - [Snapshot-Benennung und Schedule-ID](#snapshot-benennung-und-schedule-id)
  - [Docker-Awareness](#docker-awareness)
  - [Replikation](#replikation)
  - [Aufbewahrung (Retention)](#aufbewahrung-retention)
  - [Snapshot-Browser und Wiederherstellung](#snapshot-browser-und-wiederherstellung)
  - [Dashboard-Kachel und Benachrichtigungen](#dashboard-kachel-und-benachrichtigungen)
- [Wie ein Lauf abläuft](#wie-ein-lauf-abläuft)
- [Sicherheitsnetze](#sicherheitsnetze)
- [Kommandozeile](#kommandozeile)
- [Dateien und Ablageorte](#dateien-und-ablageorte)
- [Erstes Einrichten – empfohlenes Vorgehen](#erstes-einrichten--empfohlenes-vorgehen)
- [Bekannte Einschränkungen](#bekannte-einschränkungen)
- [Voraussetzungen](#voraussetzungen)

---

## Warum Shive

Unraid bringt ZFS mit, aber keine durchgängige Bedienoberfläche für Snapshots, Aufbewahrung
und Replikation. Shive schließt diese Lücke und legt besonderen Wert auf drei Dinge:

- **Container werden nie durch ein fehlgeschlagenes Backup unten gelassen.** Der Neustart hängt
  an einem `trap` und zusätzlich an einem Wiederherstellungslauf, der auch nach einem
  Stromausfall greift.
- **Aufräumen kann nur die eigenen Snapshots treffen.** Jeder Zeitplan hat eine feste ID, und
  die Aufbewahrung filtert ausschließlich auf das Namensmuster dieser ID.
- **Nichts Zerstörerisches ohne ausdrückliche Bestätigung**, und für jeden Job gibt es einen
  Trockenlauf.

---

## Installation

Über die Plugins-Seite von Unraid mit der oben genannten URL.

Für einen lokalen Test ohne GitHub-Release:

```bash
./build.sh                                   # baut das .txz, prüft und aktualisiert shive.plg,
                                             # erzeugt zusätzlich build/local/shive.plg (file://-URL)
mkdir -p /boot/config/plugins/shive
cp build/shive-*.txz /boot/config/plugins/shive/
cp build/local/shive.plg /boot/config/plugins/
plugin install /boot/config/plugins/shive.plg
```

Die lokale `.plg` **muss** `shive.plg` heißen: Unraids `update_cron` durchsucht nur Verzeichnisse,
die so heißen wie das installierte Plugin (abgeleitet aus `/var/log/plugins`). Ein abweichender
Name wie `shive-local.plg` ließe die Cron-Datei unbeachtet – Shive weicht in dem Fall zwar auf
das dynamix-Verzeichnis aus und warnt im Schedules-Tab, aber sauberer ist der richtige Name.

Danach erreichbar unter **Einstellungen → Shive**. Beim Deinstallieren bleiben Zeitpläne und
Einstellungen unter `/boot/config/plugins/shive/` absichtlich erhalten.

---

## Funktionen

### Zeitpläne

Ein Zeitplan besteht aus einem oder mehreren Ziel-Datasets, einer Frequenz und den
Aufbewahrungsregeln. Mehrere unabhängige Zeitpläne sind möglich.

| Einstellung | Bedeutung |
|---|---|
| **Datasets** | Mehrfachauswahl mit Filterfeld. Optional rekursiv, dann werden Kind-Datasets in denselben, zeitgleichen Snapshot einbezogen. Einzelne Kind-Datasets lassen sich davon ausnehmen (siehe unten). |
| **Frequenz** | stündlich, täglich, wöchentlich, monatlich oder eigener Cron-Ausdruck |
| **Name** | freier Beschreibungstext bis 64 Zeichen, jederzeit änderbar – Schrägstriche, Umlaute und Sonderzeichen sind erlaubt, z. B. `pin/kilderkin – Daten`. Namen müssen nicht eindeutig sein. |
| **Aktiv** | deaktivierte Zeitpläne erzeugen keinen Cron-Eintrag |

Die Cron-Einträge werden bei jedem Speichern komplett neu erzeugt – doppelte Einträge kann es
dadurch nicht geben. Umbenennen verschiebt nur die Zeitplan-Datei; Verlauf, Logs und
Cron-Eintrag bleiben unverändert, weil sie an der ID hängen und nicht am Namen.

### Ausnahmen

Bei rekursiven Zeitplänen lassen sich einzelne Kind-Datasets ausnehmen – nützlich für große,
jederzeit wiederbeschaffbare Daten wie heruntergeladene Modelle oder Caches. Zwei Ebenen:

- **Vom Snapshot ausnehmen** – das Dataset bekommt gar keinen Snapshot. Spart Platz auf der
  Quelle, es gibt dann aber auch keinen lokalen Rückfallpunkt dafür.
- **Zusätzlich beim Senden überspringen**, je Ziel getrennt – der Snapshot wird normal angelegt
  (lokaler Rückfallpunkt bleibt), nur dieses Ziel bekommt ihn nicht. Typischer Fall: auf der
  schnellen lokalen SSD mitnehmen, aber nicht über die Leitung zum Remote schicken.

Die Ziel-Listen sind **additiv** zu den Snapshot-Ausnahmen: Was gar keinen Snapshot hat, kann
ohnehin nirgends hin repliziert werden. Eine Ausnahme schließt immer auch alles darunter mit ein.
Zur Auswahl stehen nur tatsächlich vorhandene Kind-Datasets der gewählten Quellen – ein Tippfehler
würde sonst stillschweigend gar nichts ausschließen.

Technisch: Da `zfs snapshot -r` keine Ausnahmen kennt, übergibt Shive bei Ausnahmen alle
gewünschten Datasets in **einem** `zfs snapshot`-Aufruf – diese Form ist ebenfalls atomar, alle
Datasets behalten also denselben Zeitpunkt. Beim Senden entfällt aus demselben Grund `-R`; jedes
Dataset wird einzeln übertragen (Eltern vor Kindern), inkrementell wie gehabt.

### Snapshot-Benennung und Schedule-ID

Jeder Zeitplan erhält beim Anlegen eine zufällige sechsstellige Hex-ID. Snapshots heißen:

```
kilderkin/archiv@shive-5f946c-09092026-0300
                 └──┬──┘ └──┬─┘ └────┬────┘
                 Präfix    ID     Datum-Uhrzeit
```

Die ID ist **unveränderlich** und wird **nie wiederverwendet** – auch nicht, nachdem ein
Zeitplan gelöscht wurde. Vergebene IDs werden in `used_ids` mitgeschrieben. Zwei Vorteile:

- Umbenennen eines Zeitplans lässt bestehende Snapshots unangetastet.
- Ein neu angelegter Zeitplan kann niemals die Snapshots eines alten erben und wegräumen.

Fällt ein manueller Lauf in dieselbe Minute wie ein vorhandener Snapshot, hängt Shive einmalig
die Sekunden an (`-SS`), statt den Lauf scheitern zu lassen.

### Docker-Awareness

Pro Zeitplan aktivierbar. Ist sie eingeschaltet, ermittelt Shive, welche Container ihre Daten
auf den Ziel-Datasets liegen haben, stoppt **nur die gerade laufenden** davon, macht den
Snapshot und startet **genau dieselben** wieder – unmittelbar nach dem Snapshot, nicht erst
nach der Replikation. Die Ausfallzeit beträgt damit Sekunden, auch wenn die anschließende
Übertragung eine Stunde dauert.

Die Zuordnung Container → Dataset wird automatisch ermittelt:

```
docker inspect  →  Bind-Mount-Pfade
                →  /mnt/user/... wird auf /mnt/<pool>/... umgeschrieben
                →  findmnt -T <pfad>  →  tatsächliches Dataset
```

Im Reiter **Containers** ist das Ergebnis einsehbar. Lässt sich ein Pfad nicht auflösen, kann
das Dataset dort manuell eingetragen werden; Container, die nie gestoppt werden sollen, lassen
sich auf „ignore" setzen.

Ohne Docker-Awareness läuft der Snapshot direkt durch – Container werden nicht angefasst.

### Replikation

Zwei voneinander unabhängige Ziele pro Zeitplan:

- **Lokales Backup-Ziel** – ein Dataset auf einem anderen Pool, typischerweise eine getrennte SSD.
- **Remote-Backup-Ziel** – ein Host per SSH (`benutzer@host:port/pool/dataset`).

Bei beiden wird nur eine **Wurzel** angegeben (z. B. `pool/backups`), kein vollständiger
Zielpfad. Shive legt darunter automatisch für jedes Quell-Dataset ein eigenes Ziel-Dataset an,
benannt nach dessen letztem Pfadteil: Quelle `pin/appdata` + Wurzel `minikeg/backup` ergibt das
tatsächliche Ziel `minikeg/backup/appdata`. Dadurch können mehrere Zeitpläne dieselbe Wurzel
teilen, ohne sich in die Quere zu kommen, und `zfs receive` muss nie in ein bereits vorhandenes
Dataset schreiben (das lehnt ZFS grundsätzlich ab). Existiert die Wurzel selbst noch nicht,
erscheint im Editor ein Hinweis mit einem Ein-Klick-„jetzt anlegen" – legt nur die Wurzel an,
nie das Ziel-Dataset selbst; das entsteht ganz natürlich durch die erste echte Übertragung.

Beide Ziele erhalten die Snapshots per `zfs send -I`. Das `-I` überträgt **alle
Zwischen-Snapshots** seit dem letzten Stand des Ziels – ein wöchentlicher Abgleich täglicher
Snapshots bringt also alle sieben hinüber, nicht nur den neuesten. Auf dem Ziel liegen damit
vollwertige, eigenständige Snapshots mit eigener Aufbewahrung; geht der Quell-Pool verloren, ist
die komplette Historie dort weiterhin vorhanden.

**Eigener Zeitplan pro Ziel** (optional): täglich Snapshots, aber nur sonntags zum Remote-Host
synchronisieren. Shive erzeugt dann zwei Cron-Zeilen – der Snapshot-Job überspringt dieses
Ziel, ein separater Send-Job holt es nach. Der Send-Job macht **keinen** neuen Snapshot und
fasst **keine** Container an, sondern nimmt den neuesten vorhandenen.

Wie die Basis für den inkrementellen Versand bestimmt wird:

1. Abgebrochener Stream auf dem Ziel? → mit `zfs send -t <token>` fortsetzen
2. Neuester gemeinsamer Snapshot → `-I` ab diesem
3. Sonst: Bookmark auf der Quelle → `-i` ab diesem
4. Ziel existiert noch nicht → Vollübertragung
5. Ziel existiert, hat aber nichts gemeinsam → Abbruch mit Hinweis (kein stilles Überschreiben)

Das **Bookmark** ist der Grund, warum Quell-Aufbewahrung und Replikation sich nicht in die
Quere kommen: Es belegt keinen Speicher, überlebt aber das Löschen des Snapshots und dient
weiterhin als Basis.

Empfangen wird mit `-u -s -o readonly=on` und ohne `mountpoint`, `canmount`, `sharenfs`,
`sharesmb`, damit sich das Backup nicht über die Live-Daten mountet und ein späterer Zugriff
den nächsten Abgleich nicht blockiert.

### Aufbewahrung (Retention)

Getrennt einstellbar für **Quelle**, **lokales Ziel** und **Remote-Ziel**. Zwei Modi:

| Modus | Verhalten |
|---|---|
| **Alter** | löscht alles, was älter ist als N Tage |
| **GFS** | behält den neuesten Snapshot je Stunde / Tag / Kalenderwoche / Monat |

Die GFS-Stufen zählen **Kalender-Buckets, die Snapshots enthalten**, nicht verstrichene Zeit.
„7 daily" bedeutet also: der jeweils neueste Snapshot aus den letzten sieben Tagen, *an denen
es Snapshots gibt*. War der Server drei Tage aus, entstehen dadurch keine Lücken – die Stufen
zählen, was vorhanden ist. Weil ein Snapshot dauerhaft zu seinem Kalendertag gehört, fällt die
Entscheidung außerdem immer gleich aus, egal wann geprüft wird, und Sommer-/Winterzeit spielt
keine Rolle.

Der **Hourly-Tier** steht standardmäßig auf 0 und ist nur für stündliche (oder häufigere)
Zeitpläne sinnvoll: Bei einem täglichen Zeitplan würde „24 hourly" schlicht 24 Tages-Snapshots
behalten, weil jeder in einer eigenen Stunde liegt.

Grundsätzlich gilt:

- Es werden **ausschließlich** Snapshots mit dem Präfix des eigenen Zeitplans betrachtet.
- Der neueste Snapshot wird immer behalten.
- **Als wichtig markierte Snapshots (★) werden nie gelöscht** und belegen auch keinen
  GFS-Platz – sie stehen komplett außerhalb der Regel.
- Das Alter kommt aus der ZFS-Eigenschaft `creation`, nie aus dem Namen.
- Der Button **Prune preview** zeigt vor dem Scharfschalten für jeden Snapshot, ob er behalten
  oder gelöscht würde – und welche Stufe ihn hält. Dabei wird nichts gelöscht.

### Snapshot-Browser und Wiederherstellung

Im Reiter **Snapshots & Restore** lassen sich Snapshots von Quelle, lokalem Ziel und
Remote-Ziel auflisten und durchsuchen.

- Bei eingehängten Datasets wird direkt über `.zfs/snapshot/<name>/` gelesen – kein Klon,
  kein Aufräumen nötig.
- Bei nicht eingehängten Datasets (Backup-Ziele) legt Shive einen schreibgeschützten Klon an,
  der beim Schließen oder nach Ablauf der Frist automatisch entfernt wird.
- Kind-Datasets erscheinen im Snapshot des Elternteils als leere Ordner – ihre Daten liegen in
  ihren eigenen Snapshots. Shive kennzeichnet das und verlinkt direkt dorthin.

Einzelne Snapshots lassen sich dort außerdem direkt verwalten:

- **★ Wichtig markieren** – der Snapshot wird von der Aufbewahrung nie angefasst. Gespeichert
  als ZFS-Eigenschaft `shive:important` am Snapshot selbst, also auch außerhalb von Shive
  sichtbar (`zfs get shive:important …`). Die Markierung wird **sofort auf alle Kopien**
  gesetzt – Quelle, lokales Ziel und Remote –, weil jedes Ziel unabhängig aufräumt. Ist ein
  Ziel gerade nicht erreichbar, wird das gemeldet und beim nächsten Replikationslauf
  automatisch nachgezogen (die Quelle gilt dabei als maßgeblich). Der Klick wirkt in beide
  Richtungen: auch auf einer Ziel-Kopie gesetzt, landet er auf der Quelle.
- **Löschen** – einzelner Snapshot, auf Wunsch samt der gleichnamigen Snapshots auf
  Kind-Datasets. Nur nach ausdrücklicher Bestätigung; hat der Snapshot noch einen Klon
  (z. B. eine offene Browse-Ansicht), bricht Shive mit einem Hinweis ab statt einer
  ZFS-Fehlermeldung.

Drei Wiederherstellungswege:

| Weg | Verhalten |
|---|---|
| **Datei / Ordner** | `rsync -aHAX`; standardmäßig **als Kopie** daneben (`<ziel>.shive-restore-<zeit>`), Überschreiben nur nach ausdrücklicher Bestätigung |
| **Ganzes Dataset** | Standard: Rückkopieren per rsync (Snapshot-Historie bleibt erhalten). Erweitert: `zfs rollback -r` – schnell, aber löscht alle neueren Snapshots |
| **Notfall (DR)** | empfängt einen Snapshot vom Backup-Ziel in ein **neues** Dataset; die Live-Daten werden nicht angefasst, der Tausch erfolgt danach bewusst per `zfs rename` |

Vor jeder verändernden Wiederherstellung legt Shive automatisch einen `shive-prerestore-*`
Snapshot an. Hat das Ziel-Dataset verknüpfte Container, werden sie dabei gestoppt und wieder
gestartet – unabhängig davon, ob der Zeitplan Docker-Awareness aktiviert hat.

### Dashboard-Kachel und Benachrichtigungen

Die Kachel auf der Übersichtsseite zeigt je Zeitplan Status, Zeitpunkt des letzten Laufs, den
Zustand der Sendeziele und eine Gesamtampel (OK / Warnung / Fehler). Sie aktualisiert sich alle
30 Sekunden ohne Seitenneuladen.

Benachrichtigungen laufen über Unraids eigenes System – **eine pro Lauf**, nicht eine pro
Dataset oder Container:

| Fall | Stufe |
|---|---|
| Lauf erfolgreich | normal (abschaltbar) |
| Übertragung übersprungen, Aufräumen fehlgeschlagen | Warnung |
| Snapshot fehlgeschlagen | Alarm |
| **Container ließ sich nicht neu starten** | Alarm, eigener Ereignisname zum Filtern |

---

## Wie ein Lauf abläuft

```
VORPRÜFUNG → STOPPEN → SNAPSHOT → STARTEN → SEND LOKAL → SEND REMOTE → AUFRÄUMEN → FERTIG
                 │          │          │           │             │
                 └──────────┴──────────┴───────────┴─────────────┴──► ABSCHLUSS (immer)
```

- **Vorprüfung**: Array gestartet, Pools vorhanden und gesund, Remote erreichbar. Ist ein
  Ziel nicht verfügbar, wird nur dessen Übertragung übersprungen – der Snapshot entsteht
  trotzdem, der Lauf endet als Warnung.
- **Stoppen** vermerkt vor dem ersten `docker stop`, dass gestoppt wurde. Bricht der Lauf
  danach ab, weiß das Sicherheitsnetz, dass Container wieder hochmüssen.
- **Starten** erfolgt direkt nach dem Snapshot.
- **Aufräumen** läuft erst nach dem Starten und nur bei erfolgreichem Snapshot – ein Fehler
  beim Aufräumen kann also nie Container unten lassen.
- **Abschluss** hängt an einem `trap` und läuft in jedem Fall: Container hochfahren, Status
  schreiben, eine Benachrichtigung senden.

---

## Sicherheitsnetze

| Situation | Reaktion |
|---|---|
| Skript stürzt ab, wird abgeschossen, Stromausfall | `shive-recover` findet Läufe ohne lebenden Prozess, deren Container noch gestoppt sind, startet sie und meldet „recovered". Läuft beim Docker-Start, vor jedem Job, bei jedem Aufruf der Shive-Seite und beim Installieren. |
| Array wird gestoppt | Laufende Jobs erhalten SIGTERM, solange Docker noch läuft – der `trap` fährt die Container hoch. |
| Zwei Läufe desselben Zeitplans | `flock` pro Zeitplan; der zweite überspringt. |
| Zwei Übertragungen auf dasselbe Ziel | eigener Lock pro Ziel-Dataset. |
| Ziel-Snapshot ist schon vorhanden | Übertragung wird als „nichts zu tun" übersprungen. |
| Ziel hat einen neueren Snapshot als die Basis | klare Fehlermeldung statt eines abgelehnten Streams. |
| Verpasste Läufe (Server war aus) | optionaler Nachholvorgang beim Docker-Start. |
| Wiederherstellungs-Klone beim Pool-Export | werden beim Aushängen automatisch entfernt. |

---

## Kommandozeile

```bash
shive-run <id|name> [--dry-run] [--force] [--no-prune]
          [--send-only local|remote]              # ohne Snapshot: den neuesten vorhandenen senden
          [--no-send | --no-send-local | --no-send-remote]

shive-send --sched <id> --from pool/ds@snap \
           --to local:pool2/ds | ssh://user@host:22/pool/ds [--recursive] [--dry-run]

shive-prune --sched <id> --location source|local|remote --dataset <ds> \
            [--target ssh://…] [--recursive] [--dry-run] [--json]

shive-restore stage|unstage|file|dataset|dr …
shive-recover [--quiet]
shive-discover [--refresh]
```

`--dry-run` protokolliert jeden verändernden Befehl, ohne ihn auszuführen.

---

## Dateien und Ablageorte

| Ort | Inhalt |
|---|---|
| `/boot/config/plugins/shive/shive.cfg` | globale Einstellungen |
| `/boot/config/plugins/shive/schedules/*.json` | ein Zeitplan je Datei |
| `/boot/config/plugins/shive/mappings.json` | manuelle Container-Zuordnungen |
| `/boot/config/plugins/shive/state/<id>*.last.json` | kompakter Status des letzten Laufs |
| `/boot/config/plugins/shive/used_ids` | vergebene IDs (nie wiederverwendet) |
| `/boot/config/plugins/shive/shive.cron` | erzeugte Cron-Zeilen |
| `/var/local/shive/` | Laufzeitzustand, Locks, Discovery-Cache (flüchtig) |
| `/var/log/shive/<id>/` | Logs je Lauf (flüchtig, Pfad umstellbar), begrenzt auf die neuesten 200 je Zeitplan |

Alles Dauerhafte liegt auf dem USB-Stick und übersteht Neustarts; Laufzeitkram liegt bewusst im
RAM, um Schreibzugriffe auf den Stick gering zu halten. Ausgediente Status-Dateien werden aus
demselben Grund auf die neuesten 50 begrenzt – beide Grenzen setzt `shive-recover`, das ohnehin
regelmäßig läuft.

---

## Erstes Einrichten – empfohlenes Vorgehen

1. **Testobjekte anlegen** statt gleich auf `appdata` zu zielen:
   ```bash
   zfs create pin/shivetest && zfs create pin/shivetest/child
   docker run -d --name shivetest -v /mnt/pin/shivetest/child:/data alpine \
     sh -c 'while true; do date >> /data/log; sleep 2; done'
   ```
2. **Zeitplan anlegen**, Docker-Awareness aktivieren, lokales Ziel setzen.
3. **Trockenlauf** starten und das Log lesen. Die Zeile „linked containers" nennt genau die
   Container, die später gestoppt würden.
4. **Echten Lauf** starten, danach prüfen: Snapshots auf Quelle und Ziel vorhanden, Bookmark
   angelegt, Container wieder gestartet.
5. **Zweiten Lauf** starten – im Log muss „incremental base" stehen.
6. **Fehlerfälle üben**: Zielpool exportieren (Lauf muss als Warnung enden, Snapshot trotzdem
   entstehen), Job mit `kill -9` abschießen und `shive-recover` aufrufen.
7. **Wiederherstellung testen**: eine Datei als Kopie zurückholen, dann Überschreiben.
8. **Prune preview** ansehen und mit der eigenen Erwartung abgleichen.
9. Erst danach den produktiven Zeitplan anlegen und das Remote-Ziel zuschalten.

Ausführlicher in [`docs/TESTING.md`](docs/TESTING.md).

---

## Bekannte Einschränkungen

- **Docker-Compose-Stacks** werden containerweise gestoppt und gestartet, nicht in der
  Abhängigkeitsreihenfolge des Stacks.
- **Bind-Mounts über `/mnt/user`** lassen sich nur auflösen, wenn derselbe Pfad auf einem Pool
  existiert. Andernfalls hilft der manuelle Eintrag im Containers-Reiter.
- **Mehrere Quell-Datasets in einem Zeitplan** landen als `<ziel>/<letzter-pfadteil>` – diese
  letzten Pfadteile müssen eindeutig sein.
- **Der Zeitplan-Name ist reine Beschriftung** – gespeichert wird unter der ID (`<id>.json`).
  Zwei Zeitpläne dürfen denselben Namen tragen; unterschieden werden sie an der ID.
- **Der Remote-Host** braucht SSH-Login per Schlüssel ohne Passwortabfrage sowie `zfs`, `find`
  und `rsync` im Pfad. Die Einrichtung des Schlüssels erfolgt außerhalb des Plugins.

---

## Voraussetzungen

Unraid ab 7.2 (angepasste WebGUI-Struktur, ZFS 2.3). Verwendete Programme:
`zfs`, `zpool`, `jq`, `docker`, `php`, `rsync`, `flock`, `findmnt` – alle in Unraid enthalten.

---

## Drittanbieter-Inhalte

Das Logo ist das „Cask"-Icon von [justicon](https://www.flaticon.com/authors/justicon), bezogen
von [Flaticon](https://www.flaticon.com/free-icon/cask_2617498), verwendet unter deren kostenloser
Lizenz (Namensnennung erforderlich) – siehe [NOTICE.md](NOTICE.md).

## Weitere Dokumentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) – Aufbau, Entwurfsentscheidungen, behobene Fehler
- [`docs/TESTING.md`](docs/TESTING.md) – ausführliche Testanleitung für ein Live-System
- [`test/README.md`](test/README.md) – Simulationsumgebung für Regressionstests
