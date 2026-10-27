# Projekt - Framework JPEnvelope

## Projekt

Dokumentationsstand: Version 0.2.15 / 2026-10-27

**JPEnvelope** ist ein datengetriebenes, leichtgewichtiges Full-Stack-Framework, das für die nahtlose und asynchrone Zusammenarbeit zwischen **PHP** im Backend und modernem **Vanilla JavaScript** im Frontend entwickelt wurde.

Das Kernkonzept basiert auf dem Prinzip der namensgebenden **Envelopes (Datenumschläge)**: Anstatt schwere Framework-Architekturen oder relationale SQL-Datenbanken zu bemühen, verpackt JPEnvelope Geschäftslogik, Zustände und Validierungen in standardisierte JSON-Strukturen. Diese dienen als _Single Source of Truth_ für Client und Server gleichermaßen.

---

## CLI & Workflows

Befehlsübersicht

|Aktion|Befehl|Umgebung / Tool|
|---|---|---|
|**PHP Syntaxcheck**|`php -l includes/classes/user.php`|PHP CLI|
|**PHP Test-Suite ausführen**|`php includes/tests/collectorTest.php`|Test-Skript|
|**JS Syntaxcheck**|`node --check includes/js/app.js`|Node CLI|
|**JS Test-Suite ausführen**|`node includes/tests/registryTest.js`|Node.js|

---

## Test-Infrastruktur & Besonderheiten

- Test-Suiten können committet werden.
- Fehlende lokale Produktivdaten werden über `SKIP`-Pfade abgefangen.

> [!info] Bootstrap-Mechanismus (`includes/tests/bootstrap.php`)
> Beim Starten der Tests lädt die `bootstrap.php` alle notwendigen Klassen.
> Um eine vollständige App-Initialisierung und die Authentifizierung zu umgehen, wird der `Controller` als **anonyme Subklasse gemockt**.
>
> - Der Konstruktor (Parent-Konstruktor) wird **nicht** aufgerufen.
> - Für die Testlogik stehen die globalen Helferfunktionen `check()`, `getTemplateVar()` und `mockController()` zur Verfügung.

---

## Architektur & Datenfluss

Das Framework basiert auf einem deklarativen, JSON-konfigurierten **Frontend Controller**-Muster. Geschäftslogik, Parameter-Validierung, Routing und Berechtigungen werden über JSON-Dateien konfiguriert.

### Frontend Controller & View-System

Der vollständige Anwendungszyklus wird über einen Frontend Controller gesteuert.
Der Einstiegspunkt für jeden Request ist die `index.php`, welche `Controller::execute()` aufruft.

#### Die deklarative View-Konfiguration (`views.json`)

Jede darstellbare Seite oder API-Endpunkt ist in `includes/json/data/views.json` registriert.

#### Aufbau eines Views in der `views.json`

|Attribut|Typ|Beschreibung|
|---|---|---|
|`viewName`|_String_|Die auszuführende Methode auf der Zielklasse (`className`).|
|`resultType`|_String_|Ausgabe-Typ: Leer = HTML-Rendering. `"json"` = Sendet JSON-Header + `json_encode`.|
|`roleIds`|_Array_|Liste der Benutzerrollen, die diese View aufrufen dürfen.|
|`templateNames`|_Array_|Die für die View zu ladenden Templates.|
|`actionNames`|_Object-Array_|Registrierte Business-Logik-Aktionen, die **vor** dem Rendern laufen.|

#### Action-Routing & Validierung (`view.php`)

Das Framework trennt Trigger-Namen im HTML-Formular von den PHP-Methodennamen. Das Routing (`View::executeActions`) mappt Requests anhand zweier Action-Typen:

- **`always` (true):** Diese Action läuft bei _jedem_ Aufruf dieser View unkonditioniert mit.
- **`trigger` (String):** Läuft nur, wenn der übergebene `action`-Parameter exakt mit dem Trigger-Wert (z. B. `changeUser`) übereinstimmt.

> [!success] Fail-Loud-Validierung gegen Config-Drift
> Beim Ausführen (`View::validateActions` & `validatePermissions`) prüft das Framework, ob alle konfigurierten Klassen/Methoden existieren und das JSON-Format stimmt.
> Bei Fehlern wird eine `RuntimeException` ausgelöst.
> Es gibt **keine stillen Fehler (Zombies)** im Hintergrund; das System meldet Fehler über den Controller als geloggten Fehler.

### Authentifizierung & Autorisierung (Auth)

Die Auflösung, welcher User die App steuert, folgt einer Prioritäten-Kette:

1. **Explizite ID** (aus dem aktuellen Kontext)
2. **Cookie-Token** (AES-verschlüsselt)
3. **Login-Formular** (SHA256-Passwort-Vergleich)
4. **Fallback:** `defaultUserId` aus der `configs.json` (Gast-Zugang)

### Workflow: Feature Integration

1. **Parameter deklarieren (`params.json`):**
    Alle erlaubten GET/POST-Parameter im View-Zweig oder unter `global` eintragen.
    - _Wichtig:_ Wird `Request::param()` für einen nicht deklarierten Parameter aufgerufen, wird der Default-Wert zurückgegeben!
2. **Action-Methode integrieren (PHP-Klasse):**
    Implementierung der Logik in der Zielklasse. Action- / Trigger- Guards werden nicht benötigt. Das übernimmt das Framework.
3. **Berechtigungen & Routing verknüpfen (`roles.json` & `views.json`):**
    - Methode im Format `"Klasse::methode"` in die `roles.json` eintragen.
    - Ergänzung des Action-Eintrags im View-Block der `views.json` via `{ "method": "Klasse::methode", "trigger": "trigger_name" }`.
4. **Frontend anpassen:**
    Die View-Methode oder das zugehörige Template final erstellen bzw. erweitern.

### Validierungssystem (Request & Felder)

Das Framework sichert Datenströme auf zwei Ebenen ab: Eine **deklarative Parameter-Validierung** beim Request-Eintritt und eine **wiederverwendbare Feld-Validierung** für Formulare, die sich Client und Server teilen.

#### Parameter-Validierung (`Request` & `params.json`)

Die `Request`-Klasse kapselt den Zugriff auf globale PHP-Superglobals ab. **Es sollte im Framework nicht, direkt auf `$_GET` oder `$_POST` zugegriffen werden.**

Das System verfolgt ein **"Fail-Closed" / "Fail-Loud"-Prinzip**. Zugriff auf globale Variablen ist tabu.

- **Zentrale Instanz:** Die Klasse `Request` (`includes/classes/request.php`) ist die **einzige** Stelle im Framework, die `$_GET` und `$_POST` ausliest.
- **Datenabruf:** Klassen holen sich Werte über `Request::param('name')`.
- **Internes Verhalten:** `Request` nutzt einen internen Cache pro Parameter (GET überschreibt POST). Leere Strings oder Arrays werden als nicht vorhanden gewertet und liefern `null`.

#### Validierungs-Regeln (`includes/json/data/params.json`)

Die Validierung erfolgt deklarativ über JSON-Strukturen und teilt sich in zwei Bereiche:

|Scope-Ebene|Zweck|Typische Parameter|
|---|---|---|
|**`global`**|Gilt ab App-Start (z. B. für die Vor-Authentifizierung).|`view`, `id`, `class`, `user`, `password`|
|**`[viewId]`**|Nur aktiv, wenn die spezifische View aufgerufen wird.|_View-spezifische Parameter_|

#### Konfigurations-Felder für Parameter

Jeder Parameter in der JSON-Datei kann über folgende Attribute eingeschränkt werden:

- `type`: Datentyp (`string` | `int` | `float`).
- `pattern` _(optional)_: PCRE-Regex inklusive Delimiter zur Formatprüfung.
- `values` _(optional)_: Eine Whitelist erlaubter Werte.
- `default`: Der Standardwert, falls der Parameter fehlt.

> [!danger] Views und Business-Klassen
>
> - **Views:** Jede View _sollte_ einen Zweig in der `params.json` besitzen. Fehlt dieser, wirft das System eine `RuntimeException` (_Fail-Loud_).
> - **Klassen:** Um eine Business-Klasse zu instanziieren (`new $class`), sollte sie in der Whitelist unter `global.class.values` eingetragen sein.

> [!warning] Test-Suiten (`requestTest.php`)
> Da der Request-Cache langlebig ist, überlebt er Änderungen an `$_GET`/`$_POST` während eines Testlaufs. Beim Schreiben von Tests sollte zwischen verschiedenen Szenarien **`Request::reset()`** aufgerufen werden. `Request::setScope()` leert den Cache ebenfalls.

#### Serverseitige Feld-Validierung (`FieldValidator`)

Der `FieldValidator` ist das serverseitige Gegenstück zur clientseitigen `fieldValidator.js`. Beide nutzen **dieselben Definitionsdateien** aus dem Verzeichnis `includes/json/fields/` ( `BaseObject::getFieldsOfClass`).

##### Der Fieldset-Kontrakt

Validierungs Aufruf über `FieldValidator::validate(klasse, fieldset)` mit optionalem Fieldset:

- **Ohne Fieldset (z. B. beim Neuerstellen):** Jedes in der JSON definierte Feld wird einzeln anhand seines eigenen `mandatory`-Status (Pflichtfeld) geprüft.
- **Mit Fieldset (z. B. bei Teil-Updates):** Es werden _nur_ die im Fieldset gelisteten Felder geprüft. Ein Eintrag `'feld' => false` stuft ein Pflichtfeld temporär auf optional herunter. Nicht im Fieldset deklarierte Felder werden ignoriert.

##### Validierungs-Reihenfolge & Kriterien

Die Prüfung erfolgt in einer festen, logischen Kette:

1. **Abwesenheit**
2. **Passwort-Companion**
3. **Match / minLength**
4. **Passwort-Security**
5. **Formate**

##### Fehlermeldungen & Teil-Speicher-Semantik

Der `FieldValidator` bricht bei einem Fehler **nicht** ab. Er sammelt alle Fehler in einer Map (`feld => Meldung`) und gibt diese an die aufrufende Action zurück. Die Action entscheidet über `addError($meldung, $trigger)`, wie verfahren wird.

 **Meldungs-Schablonen:** Standardtexte liegen in der `MESSAGES`-Konstante und nutzen Platzhalter (z. B. `{field}`) und im JSON pro Feld und Regel (z. B. `id.mandatory`).

> [!info] Trennung der Zuständigkeiten
> Der `FieldValidator` prüft rein die formale Richtigkeit der Daten. **Domänen-Guards** (z. B. _„Existiert das Objekt bereits?“_, _„Hat der User die Rechte dafür?“_ oder _„Löscht sich der Admin gerade selbst?“_) verbleiben als logischer Schutz in den jeweiligen Action-Methoden.

### Persistenz & Datenspeicherung (`BaseObject`)

Alle Business-Klassen nutzen dateibasierte,  AES-verschlüsselte JSON-Persistenz, die über die zentrale Basisklasse `BaseObject` gesteuert wird.

#### Das Datei- und Speicherkonzept

Jede Business-Klasse (z. B. `User`) erbt von `BaseObject`.

- **Speicherort:** Die Daten werden im Pfad `includes/json/data/{klasseKlein}s.json` abgelegt (Beispiel: `users.json`).
- **Identifikation:** Jedes Objekt wird über eine eindeutige ID (`uniqid()`) als Key im JSON-Objekt identifiziert.
- **Instanziierung:** Der Konstruktor lädt die Daten und befüllt die Klassen-Properties über die  Methode `fillObject()`.

#### CRUD-Operationen auf Instanz-Ebene

- **Speichern & Aktualisieren:** Ein Aufruf von `$instanz->saveObject()` schreibt oder aktualisiert ausschließlich den Eintrag des aktuellen Objekts in der jeweiligen JSON-Datei.
- **Löschen:** Ein Aufruf von `$instanz->deleteObject()` entfernt den Eintrag des Objekts aus der Datei.

#### Filter-Konstanten für Properties

Das System unterscheidet zwischen Speicher- und Anzeige-Filtern:

|Konstante|Wirkungsweise|
|---|---|
|**`NOSAVE`**|Filtert Properties, die **nicht** in die JSON-Datei geschrieben werden sollen (z. B. temporäre Systempfade). Standardmäßig in `BaseObject` gesetzt für `dataJsonFile` und `fields`. Bei der Klasse `User` zusätzlich für `isUserSet`. _Wichtig:_ Sensitive Daten wie Passwort-Hashes bleiben hierin enthalten!|
|**`NOSERIALIZE`**|Filtert Properties exklusiv für die Frontend-Ausgabe (`rawObject`). Verhindert, dass sensible Daten wie Passwort-Hashes an den Client geliefert werden.|

#### Erstellung neuer Objekte

Das Framework unterscheidet zwei Wege, um neue Datensätze in die JSON-Struktur einzupflegen:

1. **Automatische ID (`newId()`-Route):**
   Wird der Konstruktor ohne ID-Parameter aufgerufen, generiert das System eine ID.

   _Ausnahme:_ Bei der Klasse `User` greift dies nicht, da ein nicht-authentifizierter Aufruf von `getUser()` standardmäßig den Benutzer `guest` zurück liefert.

2. **Manuelle / Explizite ID:**
   Für Entitäten, die eine feste oder selbstgewählte ID benötigen (wie die E-Mail-Adresse beim User), existiert die statische Methode:

   `BaseObject::insertObject( $klasse, $id, $eintrag )`

   - **Sicherheit:** Die Methode prüft vor dem Schreiben ob die ID bereits vergeben ist, um Überschreibungen zu verhindern.
   - **Dynamik:** Sie leitet den Zieldateipfad per `strtolower()` aus dem übergebenen Klassennamen ab.

#### Verschlüsselung & Key-Management (`AES-256-CBC`)

Die physikalische Speicherung übernimmt `BaseObject::saveFileEnCrypted()` (Verschlüsselung: **AES-256-CBC**) bzw. `loadFileDeCrypted()`.

- **Hybrides Lesen:** `loadFileDeCrypted()` erkennt, ob es sich um unverschlüsselten Klartext oder um verschlüsselte Daten handelt.
- **Schlüsselverwaltung:**
    - **`.cryptKeys.php`:** Enthält die echten Passphrasen für das Produktiv-System.
    - **`cryptKeys.php`:** Dient als Fallback-Klasse und enthält Platzhalter (z. B. `{{PASSPHRASE1}}`).

> [!danger] Deployment-Hinweis
> Da die `.cryptKeys.php` nicht im Git-Repository liegt, sollte beim Deployment auf einem Server darauf geachtet werden, dass diese Datei auf dem Server existiert, da die App sonst keine verschlüsselten Produktivdaten lesen kann.

---

## Templates & Frontend-Architektur

Das Frontend nutzt ein minimalistisches, serverseitiges String-Ersetzungs-System gepaart mit einem clientseitigen Komponenten-Konzept über HTML5 `<template>`-Elemente.

### Globaler JS-Namespace & Daten-Kapselung

- **Zentraler Namespace:** `window[appAlias]` (wobei der Name dynamisch aus `configs.json` ausgelesen wird).
- **Initialisierung (`header.start.tmpl`):** Bereitet die Grundstruktur vor: `registry`, `loader`, `config`, `rawObjects` und `debug`.
- **Strikter Datenfluss:** Objektdaten fließen  über `rawObjects` in die clientseitige `Registry` (Struktur: `Klasse ➔ ID ➔ Instanz`).

### Serverseitiges Template-Engine (`Presentation`)

- **Templates:** Liegen unter `includes/templates/*.tmpl`.
- **Ersetzungs-Logik:** Platzhalter im Format `{{klasse::property}}` werden über `Presentation::assignTemplateVar` bzw. `processTemplate` verarbeitet. Objekte und Arrays werden dabei via `json_encode` konvertiert.
- **Sonderfall Übersetzung:** `{{klasse::Translate::key}}` flacht verschachtelte Properties für die Lokalisierung ab.
- **Bereinigung:** Alle Platzhalter, die nach dem Rendern nicht ersetzt wurden, werden aus der finalen HTML-Ausgabe entfernt.

### Dynamisches Multi-Device-Rendering (Dynamic UI)

1. **Die Template-Kette:** Das UI-Template liefert ein natives, serverseitiges `<template>`-Element aus. In der `views.json` **sollte** dieses Template  **VOR** dem Haupt-Template  gelistet sein.
2. **Das Klonen (Client):** Sobald das App-Ready-Event feuert (`registry.onAppReady`), holt sich ein Inline-Skript alle Instanzen (z. B. `registry.getAll('User')`).
3. **ID-Isolierung:** Das `<template>` wird für jede Instanz geklont. Damit IDs im DOM eindeutig bleiben, wird an jedes `id`- und `for`-Attribut ein eindeutiger Suffix angehängt: `-[Suffix]`  (Details zum Escaping siehe Formular-Handling).

> [!warning]  DOM-Queries und Timing
> DOM-Abfragen (wie `document.querySelector`) auf neu geklonte Karten funktionieren erst **nach** dem Aufruf von `appendTemplate`.
> Grund: Der geklonte Template-Inhalt existiert davor in einem isolierten `DocumentFragment` und ist für den globalen DOM-Tree unsichtbar.

### Formular-Handling & Live-Validierung (`baseObject.js`)

Formulardefinitionen und Validierungsregeln teilen sich Client und Server über die JSON-Dateien in `includes/json/fields/{klasseKlein}.json`.

Single-Form-State & Isolierte Validierung

Jede JS-Instanz besitzt ihren eigenen `FieldValidator`. Sobald `set('formId', ...)` aufgerufen wird, bindet sich der Validator dieses Formular im DOM.

> [!warning] Formular-Recycling
> Wenn eine View ein eigenes, standalone Formular benötigt, sollte dafür **keine bestehende Instanz wiederverwendet (recycelt)** werden!
>  - _Warum?_ Der Aufruf von `set('formId', ...)` bindet den Validator der Instanz an das neue Formular. Das ursprüngliche Template würde dadurch ihre Frontend-Validierung verlieren.
> - _Lösung:_ Für On-demand-Formulare (z. B. "Neues Objekt anlegen") sollte die dafür vorgesehene Blaupausen-Instanz (`app.newObject`) verwendet werden.

> [!success] CSS-Selektoren & E-Mail-Escaping (`validateId`)
> IDs und E-Mail-Adressen sind (z. B. `admin@growmatic.de`), würden Zeichen wie `@` oder `.` in einer DOM-ID (z. B. `#form-user-admin@growmatic.de`) macht CSS-Selektoren unbrauchbar.
> Die Funktion `validateId()` bereinigt die IDs für den Suffix wie folgt:
>
> - `@` wird zu `_at_`
> - `.` wird zu `_dot_`
> - Leerzeichen werden zu `-`
> - Alle anderen ungültigen Zeichen werden zu `_`

---

## Dynamisches Klassen-Laden (JS-Loader)

Das Framework nutzt ein **Lazy-Loading-Prinzip**, um die initiale Ladezeit minimal zu halten.

### Statischer Core vs. Dynamisches Nachladen

Beim Aufruf der App lädt die `header.start.tmpl` ausschließlich die Basis-Skripte des Frameworks statisch:

- `utils.js` (Globale statische Hilfsfunktionen)
- `loader.js` (Der dynamische Loader)
- `registry.js` (Die zentrale Client-Registry)
- `fieldValidator.js` (Formular-Validierung)
- `baseObject.js` (Basisklasse für JS-Objekte)

**Dynamisch geladen (on demand über den `Loader`):**
Alle spezifischen View-Klassen sowie externe Ressourcen (wie Chart.js oder CSS-Dateien) werden im Anschluss nachgeladen, wenn diese benötigt werden.

### Die Namenskonvention (PHP-to-JS-Mapping)

Damit das Nachladen fehlerfrei funktioniert, gilt eine Dateinamens-Konvention:

1. **Eine Klasse pro Datei.**
2. **Dateiname = Klassenname**, mit **kleinem** Anfangsbuchstaben, gefolgt von CamelCase.

|PHP-Klasse|JS-Klassendatei|JS-Klasse|Externe Ressource / CSS|
|---|---|---|---|
|`AppError`|`appError.js`|`AppError`|_–_|
|`User`|`user.js`|`User`|`user.css`, `user-dashboard.css` etc. |

### Der PHP zu JS-Kontrakt über Envelopes

Die Kommunikation zwischen Backend und Frontend erfolgt über ein standardisiertes Datenformat, den **Envelope** (Daten-Umschlag):

Jedes Business-Objekt, das für das Frontend bestimmt ist, wird in folgendes JSON-Format verpackt:

```json
{
  "class": "User",
  "id": "max@mustermann.de",
  "data": { ... }
}
```

- **`class`:** Der Klassenname (behält die Großschreibung bei).
- **`id`:** Der eindeutige Schlüssel für die clientseitige Registry.
- **`data`:** Die Nutzdaten. Sensible Daten (wie der Passwort-Hash des Users) werden hierbei  über die PHP-Konstante `NOSERIALIZE` herausgefiltert.

1. **Der Kollektor:** Über `Controller::addRawObject()` werden alle Business-Objekte im Backend eingesammelt.
2. **Deduplizierung:** Wird ein Objekt mit identischer Kombination aus `class` und `id` mehrfach hinzugefügt, gewinnt der letzte Eintrag. Die ursprüngliche Position im Array bleibt erhalten.
3. **Injektion:** Im `header.tmpl` wird das gesammelte Array als `window[appAlias].rawObjects` in den globalen Client-Kontext injiziert.

### Primäre Zufuhrwege für Daten-Envelopes des Kollektors

- **App-Kontext:** Wird als allererstes von `Controller::execute()` erzeugt, um die globale Konfiguration zu übergeben.
- **View-Actions:** Klassen-Methoden, die den `Controller` als Parameter erhalten, fügen ihre Daten den Kollektor hinzu.
- **Der aufgelöste User:** Nach dem Ausführen aller Actions fügt der Controller den aktuell eingeloggten Benutzer hinzu.

###  Der App-Kontext (`App`)

Der globale Anwendungszustand wird ebenfalls über ein spezielles Daten-Envelope mit der Klasse `App` und der ID `appAlias_version` (z. B. `growMatic_0.2.7`) abgebildet. Ein Anheben der Versionsnummer (Version-Bump) in der Config verschiebt die Registry-ID im Frontend.

#### Daten-Payload der App-Instanz

Die PHP-Klasse `App` serialisiert wichtige Kern-Informationen in den `data`-Payload.

- `currentUserId (String)`: Die ID des aktuell aktiven Benutzers.
- `currentUserRoleId (String)`: Die Rollen-ID des Benutzers.
- `currentView (Object)`: Die aktuell gerenderte View.

> [!info] fillObject()
> Die Methode `fillObject()` überschreibt keine bereits im JS-Konstruktor gesetzten Properties.

---

## Blaupausen on Demand (`app.newObject`)

Wenn im Frontend ein Formular zum **Anlegen eines neuen Objekts** geöffnet wird, existiert im Backend noch keine Instanz (und somit kein Daten-Envelope). Hierfür nutzt das System sogenannte **Blaupausen (Blueprints)**.

Ablauf einer Blaupausen-Anforderung (Asynchron)

```
[Template] ➔ app.newObject('Klasse')
                  ↓
[JS-Loader] ➔ Fetcht: index.php?view=viewNewObject&newClass=Klasse
                  ↓
[PHP-Backend] ➔ Erzeugt leere Instanz per Reflection (am Konstruktor vorbei)
                  ↓
[Standard-Pipeline] ➔ createInstance ➔ Registrierung in JS-Registry unter ID 'new'
                  ↓
[Callback] ➔ registry.onReady('Klasse', 'new', (instanz) => { ... })
```

### Technische Besonderheiten im Backend

- **Reflection-Instanziierung:** `BaseObject::createBlueprint($klasse)` erzeugt die Instanz mittels `newInstanceWithoutConstructor`. Dadurch wird der Konstruktor der Subklasse bewusst **umgangen** (der User-Konstruktor würde sonst fälschlicherweise auf den `guest`-User zurückfallen).
- **Initialisierung:** Die Befüllung der Standardwerte und Formularfelder (`fields`) erfolgt über die Methode `initObject()`, welche vom regulären Konstruktor genutzt wird.

> [!info] Informationen zu Blaupausen
>- **Rückgabewert:** Da `app.newObject()` asynchron arbeitet, liefert die Methode keinen synchronen Rückgabewert. Das Template sollte auf den Callback `registry.onReady('Klasse', 'new', (instanz) => { ... })` reagieren.
>- **Sichtbarkeit:** Blaupausen besitzen die feste ID `'new'` (definiert als `Registry::BLUEPRINTID`). Sie werden von der Methode `getAll()` **herausgefiltert**. Zugriff erfolgt per `get(klasse, 'new')` oder via `onReady`.
>- **Timing beim Aufräumen:** Die JS-Funktion `removeTemplates()` sollten **nach** dem erfolgreichen `onReady`-Callback der Blaupause ausgeführt werden, da `appendTemplate` das HTML-Template-Element zu diesem Zeitpunkt benötigt.
>- **Pflegekontrakt bei neuen Klassen:** Wenn eine neue Klasse über das Dashboard anlegbar sein soll, sollten zwei Dinge konfiguriert werden:
>    1. Die Klasse sollte in der `params.json` unter dem Zweig `viewNewObject` in der Whitelist `newClass.values` eingetragen werden.
>    2. Die zugehörige Formular-Definition unter `includes/json/fields/{klasseKlein}.json` sollte existieren.

---

## Fehlermanagement & Ausnahmebehandlung

Das Framework unterscheidet zwischen **weichen Fehlern** (Validierungsfehler, abgewiesene Berechtigungen) und **harten Fehlern** (unbehandelte System-Exceptions).

### Der weiche Fehlerkanal (`AppError`)

Fehler, die während der Ausführung auftreten (z. B. eine fehlgeschlagene Aktion), werden zentral gesammelt und am Ende der Verarbeitung ausgegeben.

- **Erfassung:** Erfolgt über `Controller::addError($message, $source, $severity)`.
    - `$source`: Der Auslöser (z. B. `changeUser`).
    - `$severity`: Erlaubt sind `'error'` oder `'warning'`. Unbekannte Werte werfen eine `RuntimeException`.
- **Ausgabe ans Frontend:** Nach dem Ausführen aller Aktionen wirft `Controller::execute()` die gesammelten Fehler als `AppError`-Daten-Envelopes in den Kollektor (mit fortlaufenden IDs: `error1`, `error2` etc.).
- **Zentrale Anzeige (`app.js`):** Die Klasse `App` ist auf jeder View aktiv. Sie registriert im Konstruktor einen `onAppReady`-Callback und gibt alle Fehler über `showAppErrors()` aus.

> [!success] Fehlerausgabe
> Kein HTML-Template benötigt Code für die Fehlerausgabe. Der JS-Loader bleibt "domänen-blind" und reicht Fehler als Datenstrukturen durch.

> [!info] _Nomenklatur-Hinweis zum Namensraum
> _ Die Klasse heißt explizit `AppError` (und die Datei `appError.js`), da `Error` sowohl in PHP (`Throwable`) als auch in JavaScript (`window.Error`) ein geschütztes, eingebautes Systemobjekt ist.

### Der harte Fehlerkanal (Exception-Fangnetz)

Um eine leere weiße Seite im Client zu verhindern, besitzt das Framework ein **zweistufiges, serverseitiges Fangnetz** gepaart mit einem **Client-Handler**.

#### Serverseitiges Fangnetz (PHP)

|Stufe|Bereich|Was wird abgefangen?|Auswirkung im Ernstfall|
|---|---|---|---|
|**Stufe 1A**|`executeActions`|Absturz während einer Geschäftslogik-Aktion.|Die Exception wird als `addErrorFromThrowable` in einen weichen AppError umgewandelt. Das Rendering läuft mit **HTTP 200** weiter.|
|**Stufe 1B**|View-Zweig|Absturz während des eigentlichen View-Renderings.|Das System bricht ab und sendet **HTTP 500**. HTML-Views zeigen eine statische Not-HTML (`HARD_ERROR_HTML`), JSON-Views liefern einen leeren Body.|
|**Stufe 2**|`index.php`|Boot-Crashes (z. B. `params.json` unlesbar oder View-Zweig fehlt).|Das Notznetz greift im Root-Einstieg, bevor die App initialisiert ist.|

**Stufe 1: Granularer Schutz im Controller**

Innerhalb von `Controller::execute()` fangen zwei separate `try/catch`-Blöcke eventuelle Abstürze ab:

1. **Um die Actions (`executeActions`):** Crasht eine Action, fängt der Controller dies ab (`addErrorFromThrowable`), wandelt es in einen generischen `AppError` für den Weichkanal um, und das Rendering der Seite läuft mit dem HTTP-Status **200 OK** weiter.
2. **Um die View-Auflösung:** Crasht die View selbst, setzt das System den HTTP-Status **500 Internal Server Error**. Bei HTML-Views wird die Konstante `HARD_ERROR_HTML` ausgegeben. Bei JSON-Views bleibt der Body leer. Es werden hier keine Fehler in den Kollektor geschrieben, da dieser Prozess bereits abgeschlossen ist.

**Stufe 2: Das Notznetz (`index.php`)**

Ein globaler `try/catch`-Block umschließt den Konstruktor und die `execute()`-Methode in der `index.php`. Dieses Netz fängt schwerwiegende Boot-Crashes ab – beispielsweise, wenn die `params.json` unlesbar ist oder ein View-Zweig fehlt (da diese Request-Scope-Exceptions bereits im Konstruktor zünden).

> [!security] Schutz vor Information-Leaks
> Im Frontend wird dem Endnutzer nicht der Stacktrace oder die SQL/Datei-Fehlermeldung angezeigt. Das System gibt eine generische Nachricht aus (`HARD_ERROR_MESSAGE`). Die  sensiblen Details (Klasse, Nachricht, Datei, Zeile und Trace) wandern über `Controller::logHardError` in die Logdatei `includes/logs/messages.log`.

#### Clientseitiges Fangnetz (JavaScript)

- **Reihenfolge:** Beim Laden der Seite zieht die Methode `App::loadResources()` die `appError.js` als erstes vor und registriert erst _danach_ den globalen `window.onerror`-Handler.
- **Rekursion:** `App::reportError()` fängt Fehler im Fehlerkanal ab, ohne sie erneut zu werfen (Catch ohne Re-Throw). Das verhindert eine Rekursion bei Fehlern innerhalb der Anzeige.
- **IDs:** JS-Fehler erhalten eigene IDs im Format `errorjs{N}`.
  Würden sie das PHP-Muster `error{N}` nutzen, könnten sie während der Registrierungsphase bestehende PHP-Fehler im Speicher überschreiben.
- **Idempotenz:** Die Methode `AppError::showError()` prüft vor der Anzeige die DOM-ID. Dadurch wird verhindert, dass Fehler, die vor dem `App-Ready`-Event angezeigt und später im Bereinigungs-Sweep erfasst wurden, doppelt im UI auftauchen.
- **Listener-Isolierung:** `Registry::_dispatchSafely()` kapselt alle vier Listener-Dispatches (`register`, `onReady-Replay`, `setAppReady`, `onAppReady-Replay`). Schlägt ein Callback fehl, bricht er nicht die Ausführung der restlichen, unbeteiligten Listener ab.
- **CLI-Schutz:** `Presentation::getJsonHeader()` sowie die Cookie-Methoden (`writeCookie`, `deleteCookie`) besitzen einen CLI-Guard. Dies verhindert die PHP-Warnung _"headers already sent"_, wenn die Test-Suite auf der Kommandozeile ausgeführt wird.

---

## Dynamisches Klassen-Laden, der asynchrone Bootstrap-Prozess

Das Framework orchestriert den Start im Client über eine eventgetriebene Architektur. Dadurch wird sichergestellt, dass Klassen, Daten und externe Ressourcen (wie externe Bibliotheken)  in der richtigen Reihenfolge bereitstehen.

### Der Bootstrap-Ablauf (Client-Start)

Der Startprozess im Client wird über ein Inline-Skript in der `header.start.tmpl` gestartet und läuft in folgenden Schritten ab:

```
[ Server-Daten injizieren ] ──► Schreibt rawObjects & config in den Namespace
            │
            ▼
[ Kern-Instanzen ] ───────────► `new Registry()` und `new Loader()` werden erstellt
            │
            ▼
[ loader.registerObjects() ] ─► Erstellt parallel alle Objekt-Instanzen via `createInstance`
            │                   (Inkl. automatischem Nachladen der JS-Dateien falls nötig)
            ▼
[ Ressourcen laden ] ─────────► Wartet via `await` auf alle `loadResources()` (z.B. CSS, Chart.js)
            │
            ▼
[ App Ready ] ────────────────► Registriert Objekte in der Registry & feuert `registry.setAppReady()`
```


> [!security] Speicher-Bereinigung im Produktionsmodus
> Wenn `debug == false` konfiguriert ist, löscht das Framework das temporäre Array `rawObjects` nach erfolgreichem Bootstrap aus dem globalen Namespace. Sollte das Laden einer Ressource jedoch fehlschlagen, bleibt das Array für das Debugging erhalten (_Fail-Loud-Prinzip_).

### Ablauf im Inline-Skript

1. Die Server-Daten werden in `rawObjects` und `config` abgelegt.
2. Die Objekte `new Registry()` und `new Loader()` werden instanziiert.
3. `loader.registerObjects()` wird aufgerufen.

Funktionsweise von `loader.registerObjects()`

Die Registrierung verarbeitet alle übergebenen Envelopes parallel:

```
[Envelope empfangen] ➔ createInstance() (Prüft Klasse, lädt JS-Datei ggf. via Promise-Dedupe nach)
                              ↓
                      [Instanziierung] ➔ data-Payload via fillObject() befüllen
                              ↓
                      [loadResources()] ➔ Externe Skripte/CSS via await nachladen
                              ↓
                      [Registry] ➔ Instanz registrieren ➔ registry.setAppReady() feuert
```


### Timing über Promises & Event-Replay

Um asynchrone Race-Conditions zu verhindern, steuert das Framework das Timing über Events und Promises statt über eine Skript-Reihenfolge.

Asynchrones Laden von Assets

Der JS-Konstruktor einer Klasse ruft `loadResources()` niemals selbst auf. Dies übernimmt der Loader _nachdem_ die Instanz erstellt und die Serverdaten via `fillObject()` kopiert wurden.

- Der Konstruktor einer nachgeladenen JavaScript-Klasse ruft `loadResources()` **nicht selbst** auf. Dies übernimmt der Loader  _nach_ der Instanziierung und Datenspeicherung via `await`.
- Die Basisklasse `BaseObject` stellt eine leere Standard-Methode `loadResources()` bereit. Subklassen, die eigene Skripte oder Stylesheets benötigen, überschreiben diese Methode und nutzen `await` auf `loadScriptAsync()` bzw. `loadStylesheetAsync()`.
- **Promise-Deduplizierung:** Ein interner Cache (`promises`) verhindert, dass dieselbe Ressource mehrfach vom Server angefordert wird.

---

## Die Registry

Die clientseitige `Registry` fungiert als Speicher-Zentrale im Frontend und ist als zweistufige Map aufgebaut:

```
Registry ➔ [Klassenname (kleingeschrieben)] ➔ [Objekt-ID] ➔ [JS-Instanz]
```

- `get(klasse)`: Liefert die erste gefundene Instanz dieser Klasse.
- `getAll(klasse)`: Liefert eine Kopie der Map dieser Klasse. **Wichtig:** Blaupausen (mit der ID `Registry::BLUEPRINTID = 'new'`) werden hierbei herausgefiltert. Dadurch können Templates in Schleifen (Karten-Loops) iterieren, ohne Prüfungen einbauen zu müssen.

Sobald eine Instanz registriert wird, feuert die Registry zwei Events ab:

1. `ready:{klasse}:{id}` (Spezifisch für diese eine Instanz)
2. `ready:{klasse}` (Global für alle Instanzen dieser Klasse)

Wenn das Event bereits gefeuert wurde, nutzt das Framework ein **Fired-Events-Replay**: Bereits verpasste Events werden für zu spät registrierte Listener wiederholt!

Die zwei `onReady`-Modi der Registry

|Modus|Aufruf-Syntax|Verhalten|Typischer Einsatzzweck|
|---|---|---|---|
|**Mit ID**|`onReady(klasse, id, callback)`|**Einmalige Zustellung.** Der Callback wird nach der Ausführung sofort gelöscht und nicht gespeichert.|Gezieltes Warten auf eine ganz bestimmte Instanz.|
|**Ohne ID**|`onReady(klasse, null, callback)`|**Dauer-Abonnement.** Der Callback bleibt permanent aktiv. Er feuert sofort für alle bereits existierenden Instanzen und für jede zukünftige Instanz.|Für dynamisch nachgeladene Objekte wie die **Blaupausen-Route**.|

> [!info] Wichtig für den View-Code
> HTML-Template-Code sollte immer auf `registry.onAppReady()` warten. Versuche nicht, Objekte direkt nach dem Instanziieren im DOM zu verarbeiten.

---

## Cache-Busting über Version-Sufix

Damit der Client nach einem Update des Frameworks keine veralteten CSS- oder JS-Dateien aus dem lokalen Cache liest, nutzt das System ein Cache-Busting:

An den Skript- und Stylesheet-Referenzen wird das Suffix `?v=[Version]` angehängt (wobei die Versionsnummer in der `configs.json` definiert wird).

- **In statischen HTML-Templates:** Einbindung über `{{config::Translate::version}}`.
- **In dynamisch nachgeladenen JS-URLs:** Der Loader greift auf `window[appAlias].config.version` zu.

> [!warning] Entwickler-Regel
> Sobald Änderungen an einer `.js`- oder `.css`-Datei vorgenommen wurden, sollte die `version` in der `configs.json` hochgestuft werden, da die Clients sonst alte, fehlerhafte Code-Stände aus ihrem Cache laden!