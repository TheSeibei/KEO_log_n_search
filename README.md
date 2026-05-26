# Helwacht API – Dokumentation

## Überblick

Die Helwacht API liefert alle aktuell als **verfügbar** markierten Betriebe als JSON.

Zusätzlich kann die API mit einer Suchadresse arbeiten. Dafür wird der Parameter `q` verwendet. Die Suchadresse wird über Mapbox geocodiert und anschließend werden alle verfügbaren Betriebe nach Distanz zur Suchadresse sortiert zurückgegeben.

Die Koordinaten der Betriebe werden **serverseitig gecacht** und in WordPress als User-Meta gespeichert. Dadurch muss eine Betriebsadresse nicht bei jedem Request erneut geocodiert werden.

---

## Endpoint

```text
https://keo.at/wp-json/helwacht/v1/availability
```

---

## Methoden

```text
GET
POST
```

Beide Methoden werden unterstützt.

* `GET` eignet sich für einfache Aufrufe per Browser, URL oder externem System.
* `POST` eignet sich für strukturierte Requests mit JSON-Body.

---

## Authentifizierung

Die Authentifizierung erfolgt über einen API-Key.

### Variante 1 – per Header

```bash
-H "X-API-KEY: API_KEY"
```

Beispiel:

```bash
curl -H "X-API-KEY: API_KEY" \
  "https://keo.at/wp-json/helwacht/v1/availability"
```

### Variante 2 – per URL-Parameter

```text
?key=API_KEY
```

Beispiel:

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY
```

Hinweis:
Die Header-Variante ist sicherer, weil API-Keys in URLs leichter in Logs, Browser-History oder Monitoring-Systemen landen.

---

## Grundverhalten der API

Die API gibt **nur Betriebe zurück, deren Verfügbarkeit aktiv ist**.

Das bedeutet:

* Es werden nur Benutzer berücksichtigt, bei denen `helwacht_available = 1` gesetzt ist.
* Nicht verfügbare Benutzer erscheinen niemals in der Ausgabe.

Die Antwort enthält immer ein JSON-Objekt mit Metadaten und einem `data`-Array.

---

## Unterstützte Query-Parameter / Filter

Die API unterstützt zwei Arten von Parametern:

1. **Feldfilter** auf die Betriebe
2. **Adresssuche** über `q`

### Feldfilter

Folgende Felder können als Filter verwendet werden:

```text
innung_id
innung_name
innung_billing_address
phone
first_name
last_name
address
postal_code
city
country
full_address
website
available
last_update
```

### Wichtige Eigenschaften der Filter

* Mehrere Filter werden als **UND-Abfrage** behandelt.
* Filter arbeiten als **Teiltreffer**.
* Die Suche ist **nicht case-sensitiv**.

Beispiel:

```text
city=Wien
```

findet auch Einträge, in denen `Wien` im Feld vorkommt. Z.B `Wiener Neustadt`

---

## Parameter `q` – Adresssuche und Distanzsortierung

Der Parameter `q` ist für eine beliebige Suchadresse gedacht.

Beispiele:

```text
q=Wien
q=Stephansplatz Wien
q=Mariahilfer Straße 1 Wien
q=Straße der Wiener Wirtschaft 1 1020 Wien
q=4020 Linz
```

### Verhalten von `q`

Wenn `q` gesetzt ist:

1. Die Suchadresse wird in Koordinaten umgewandelt.
2. Für jeden verfügbaren Betrieb werden Koordinaten ermittelt.
3. Falls für einen Betrieb bereits gültige Koordinaten gecacht sind, werden diese verwendet.
4. Falls noch keine Koordinaten vorhanden sind oder sich die Adresse geändert hat, wird die Betriebsadresse neu geocodiert.
5. Die Distanz zwischen Suchadresse und Betrieb wird in Kilometern berechnet.
6. Die Ausgabe wird nach `distance_km` aufsteigend sortiert.

### Einschränkungen für `q`

* `q` darf nicht leer sein.
* `q` wird sanitisiert.
* `q` ist auf maximal **200 Zeichen** begrenzt.
* Ist `q` leer oder zu lang, wird es ignoriert und die API verhält sich wie eine normale Verfügbarkeitsabfrage ohne Distanzsuche.

---

## Geocoding und Cache

### Suchadresse

Die Suchadresse aus `q` wird pro Request einmal geocodiert.

### Betriebsadressen

Die Koordinaten eines Betriebs werden dauerhaft in WordPress gespeichert.

Verwendete User-Meta-Felder:

```text
helwacht_latitude
helwacht_longitude
helwacht_geocoded_at
helwacht_geocoded_hash
helwacht_geocoded_source
```

### Wann wird neu geocodiert?

Eine Betriebsadresse wird neu geocodiert, wenn sich eine der folgenden Angaben geändert hat:

* `address`
* `postal_code`
* `city`

In diesem Fall wird der Cache für diesen Benutzer gelöscht und beim nächsten Distanz-Request neu aufgebaut.

---

## Distanzberechnung

Die Distanz wird serverseitig mit der **Haversine-Formel** berechnet.

Ausgegeben wird:

```text
distance_km
```

Die Einheit ist **Kilometer**.

Der Wert ist auf **2 Nachkommastellen** gerundet.

### Sonderfall: keine Koordinaten verfügbar

Wenn für einen Betrieb keine Koordinaten ermittelt werden konnten, wird Folgendes gesetzt:

```json
"distance_km": null
```

Solche Einträge werden bei aktivem `q` **immer ans Ende der Ergebnisliste** sortiert.

---

## Felder in der JSON-Antwort

### Root-Level

Die API liefert folgende Hauptfelder zurück:

```json
{
  "generated_at": "...",
  "count": 0,
  "filters": null,
  "query": null,
  "query_coordinates": null,
  "data": []
}
```

### Beschreibung

* `generated_at` – Zeitpunkt der Generierung
* `count` – Anzahl der zurückgegebenen Betriebe
* `filters` – aktive Feldfilter, sofern gesetzt
* `query` – die Suchadresse aus `q`, sofern gesetzt
* `query_coordinates` – geocodierte Koordinaten der Suchadresse, sofern `q` erfolgreich verarbeitet wurde
* `data` – Liste der gefundenen Betriebe

---

## Felder pro Betrieb

Jeder Betrieb kann folgende Felder enthalten:

```text
innung_id
innung_name
innung_billing_address
phone
first_name
last_name
address
postal_code
city
country
full_address
website
available
last_update
distance_km
```

### Beschreibung

* `innung_id` – WordPress User-ID des Betriebs
* `innung_name` – Name des Betriebs / der Innung
* `innung_billing_address` – Billing-Adresse; manuell oder automatisch erzeugt
* `phone` – Telefonnummer im internationalen Format
* `first_name` – Vorname aus WordPress-Profil
* `last_name` – Nachname aus WordPress-Profil
* `address` – Straßenadresse
* `postal_code` – Postleitzahl
* `city` – Stadt
* `country` – aktuell standardmäßig `Österreich`
* `full_address` – komplette Betriebsadresse als Ein-String
* `website` – Website des Betriebs
* `available` – immer `true`, da nur verfügbare Betriebe ausgegeben werden
* `last_update` – Zeitstempel der letzten Verfügbarkeitsänderung
* `distance_km` – Distanz zur Suchadresse in km, nur wenn `q` gesetzt wurde

Hinweis:
Das Feld `email` wird nicht mehr ausgegeben.

---

## Beispiele

## 1. Einfache Abfrage ohne Filter

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY
```

Beispielantwort:

```json
{
  "generated_at": "2026-05-08T09:54:21+00:00",
  "count": 2,
  "filters": null,
  "query": null,
  "query_coordinates": null,
  "data": [
    {
      "innung_id": "3",
      "innung_name": "ABBUS e.U.",
      "innung_billing_address": "Andersengasse 21 1120 Wien Österreich",
      "phone": "+4369915201520",
      "first_name": "",
      "last_name": "",
      "address": "Andersengasse 21",
      "postal_code": "1120",
      "city": "Wien",
      "country": "Österreich",
      "full_address": "Andersengasse 21 1120 Wien Österreich",
      "website": "https://www.abbus-aufsperrdienst.at",
      "available": true,
      "last_update": "2026-04-26T16:45:33+00:00"
    }
  ]
}
```

---

## 2. Filter nach Postleitzahl

### GET

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&postal_code=1120
```

### POST

```bash
curl -X POST \
  -H "X-API-KEY: API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"postal_code":"1120"}' \
  "https://keo.at/wp-json/helwacht/v1/availability"
```

---

## 3. Mehrere Filter kombiniert

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&postal_code=1120&city=Wien
```

Diese Anfrage liefert nur Einträge, die **beide Bedingungen** erfüllen.

---

## 4. Distanzsuche mit `q`

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&q=Stephansplatz%20Wien
```

Beispielantwort:

```json
{
  "generated_at": "2026-05-08T10:11:21+00:00",
  "count": 2,
  "filters": null,
  "query": "Stephansplatz Wien",
  "query_coordinates": {
    "latitude": 48.20849,
    "longitude": 16.37208
  },
  "data": [
    {
      "innung_id": "3",
      "innung_name": "ABBUS e.U.",
      "innung_billing_address": "Andersengasse 21 1120 Wien Österreich",
      "phone": "+4369915201520",
      "first_name": "",
      "last_name": "",
      "address": "Andersengasse 21",
      "postal_code": "1120",
      "city": "Wien",
      "country": "Österreich",
      "full_address": "Andersengasse 21 1120 Wien Österreich",
      "website": "https://www.abbus-aufsperrdienst.at",
      "available": true,
      "last_update": "2026-04-26T16:45:33+00:00",
      "distance_km": 4.73
    },
    {
      "innung_id": "8",
      "innung_name": "Beispielbetrieb",
      "innung_billing_address": "Musterstraße 1 4020 Linz Österreich",
      "phone": "+43732123456",
      "first_name": "",
      "last_name": "",
      "address": "Musterstraße 1",
      "postal_code": "4020",
      "city": "Linz",
      "country": "Österreich",
      "full_address": "Musterstraße 1 4020 Linz Österreich",
      "website": "https://example.at",
      "available": true,
      "last_update": "2026-05-01T08:12:00+00:00",
      "distance_km": 154.28
    }
  ]
}
```

---

## 5. Filter und Distanzsuche kombiniert

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&city=Wien&q=Stephansplatz%20Wien
```

Verhalten:

1. Zuerst werden nur verfügbare Betriebe berücksichtigt.
2. Danach greifen die gesetzten Filter.
3. Anschließend wird die Distanz zur Suchadresse berechnet.
4. Die gefilterten Betriebe werden nach `distance_km` sortiert.

---

## POST-Requests mit JSON

Neben Query-Parametern kann die API auch JSON im Request-Body verarbeiten.

Beispiel:

```bash
curl -X POST \
  -H "X-API-KEY: API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "city": "Wien",
    "postal_code": "1120",
    "q": "Stephansplatz Wien"
  }' \
  "https://keo.at/wp-json/helwacht/v1/availability"
```

Die API unterstützt außerdem einen Fallback für JSON-ähnliche Bodies, wenn kein korrekter `Content-Type: application/json` gesetzt wurde.

---

## Fehlerverhalten

### Ungültige oder nicht geocodierbare Suchadresse

Wenn `q` nicht verarbeitet werden kann, wird ein neutraler Fehler zurückgegeben.

Beispiel:

```json
{
  "code": "helwacht_geocode_failed",
  "message": "Adresse konnte nicht verarbeitet werden."
}
```

Es werden dabei bewusst keine internen Details von Mapbox oder vom Server nach außen gegeben.

### Fehlende Betriebskoordinaten

Wenn ein einzelner Betrieb nicht geocodiert werden konnte:

* bleibt der Betrieb grundsätzlich in der Antwort enthalten
* `distance_km` wird auf `null` gesetzt
* der Eintrag landet am Ende der sortierten Liste

---

## Feldherkunft und Datenlogik

### `innung_id`

`innung_id` ist die WordPress User-ID des jeweiligen Betriebs.

Diese ID ist systemweit innerhalb der WordPress-Installation eindeutig.

### `innung_name`

`innung_name` kommt primär aus dem User-Meta-Feld `innung_name`.

Falls dieses Feld leer ist, wird als Fallback noch `company` verwendet.

### `innung_billing_address`

Wenn `innung_address` befüllt ist, wird dieser Wert direkt verwendet.

Wenn `innung_address` leer ist, wird die Billing-Adresse automatisch erzeugt aus:

* `address`
* `postal_code`
* `city`
* `Österreich`

Format:

```text
Straße PLZ Stadt Österreich
```

Beispiel:

```text
Straße der Wiener Wirtschaft 1 1020 Wien Österreich
```

### `phone`

Telefonnummern werden im API-Output ins internationale Format umgewandelt.

Beispiele:

```text
0664 1234567     -> +436641234567
0043 664 1234567 -> +436641234567
+43 664 1234567  -> +436641234567
```

---

## Hinweise für Integratoren

* Die API liefert nur verfügbare Betriebe.
* Filter und Distanzsuche können kombiniert werden.
* `distance_km` ist nur vorhanden, wenn `q` gesetzt wurde.
* `distance_km = null` bedeutet in der Regel, dass keine gültigen Koordinaten für die Betriebsadresse vorlagen.
* Die erste Distanzsuche kann langsamer sein, weil Betriebskoordinaten erstmals gespeichert werden.
* Folgeanfragen sind in der Regel schneller, weil der Koordinaten-Cache verwendet wird.

---

## Empfohlene Tests

### Ohne Distanzsuche

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY
```

### Mit Distanzsuche

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&q=Wien
```

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&q=Stephansplatz%20Wien
```

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&q=Stra%C3%9Fe%20der%20Wiener%20Wirtschaft%201%201020%20Wien
```

### Mit Filter und Distanzsuche

```text
https://keo.at/wp-json/helwacht/v1/availability?key=API_KEY&city=Wien&q=Stephansplatz%20Wien
```

---

## Zusammenfassung des aktuellen Funktionsumfangs

Die API kann derzeit:

* verfügbare Betriebe liefern
* per Feldfilter einschränken
* per `q` eine Adresse geocodieren
* Betriebe nach Distanz sortieren
* Distanzen in km ausgeben
* Betriebskoordinaten serverseitig cachen
* den Cache bei Adressänderung automatisch invalidieren

Damit eignet sich die API sowohl für einfache Verfügbarkeitsabfragen als auch für standortbezogene Suchen nach dem nächstgelegenen Betrieb.
