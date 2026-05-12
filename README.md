# Doseplus Dosenzauber Konfigurator (Shopware 6 Plugin)

Veredelungs-Konfigurator für DZ-Weihnachtsdosen — Lasergravur, Ritter-Sport-Befüllung, Versandverpackung. Ersetzt den bestehenden Neon-Configurator durch eine schlanke, schnell ladende Single-Component.

## Was das Plugin macht

Wenn ein Kunde eine Produktseite mit Artikelnummer beginnend mit `DZ ` (z.B. `DZ 010`, `DZ 050`) öffnet, wird **über dem Standard-"In den Warenkorb"-Button** der Dosenzauber-Konfigurator eingeblendet:

- **Lasergravur**-Toggle mit Logo-Upload + NK PERS (Personalisierung) als Extra
- **Befüllung** mit fester Anzahl Ritter-Sport-Mini pro Dose + Grußkarten-Auswahl (Standard / pers. Text)
- **Versandfertig**-Toggle mit Plano- vs. Konfektioniert-Auswahl
- **Stückzahl-Slider** in Karton-Schritten, mit Live-Staffelpreis-Anzeige
- **Stücklisten-Box**: zeigt aufgeschlüsselt was im DZ-Set steckt (WD + NK LG + NK PERS + RSW + UK)
- **Schwarzer Gesamt-Block** mit großem "In den Warenkorb"-Button

Bei Nicht-DZ-Produkten passiert nichts — die Produktseite zeigt das normale Buy-Widget.

## Datenbasis

Alle Preise und Mengen aus Steffen Angers Excel-Tabelle (Mai 2026):

| | |
|---|---|
| **WD-Staffelpreise** | 5 Stufen pro Dose (`<99`, `100+`, `300+`, `500+`, `1000+`) |
| **Lasergravur** | 6 Stufen (`<99` bis `2000+`) — alle Dosen identisch außer DZ 091 |
| **Ritter Sport Würfel** | 0,19 € fix, Anzahl variabel pro Dose (10-48 Riegel) |
| **Maschinenrüstung** | 45 € einmalig |
| **NK PERS Personalisierung** | 90 € pauschal |
| **Karte mit pers. Text** | 125 € pauschal (max. 450 Zeichen) |
| **Umkarton plano** | 0,98 € pro Stück |
| **Umkarton konfektioniert** | 1,19 € pro Stück |

Daten liegen in `src/Service/ConfiguratorDataProvider.php` als Konstanten. Sobald die Werte als Shopware-Custom-Fields gepflegt werden, einfach in dieser Klasse die Quelle umstellen.

## Trigger-Logik

Der Konfigurator erscheint automatisch auf einer Produktseite, wenn **eines** dieser drei Kriterien erfüllt ist:

1. **Artikelnummer beginnt mit `DZ `** (z.B. `DZ 010`, `DZ 050`) — automatisch
2. **Custom Field `doseplus_dosenzauber_enable` = true** am Produkt — manuelle Aktivierung für beliebige Produkte
3. **Custom Field `doseplus_dosenzauber_dz` = "DZ 050"** — Zuordnung einer WD-Dose zu einer DZ-Konfiguration

Außerdem fällt der Provider automatisch auf das DZ-Äquivalent zurück, wenn die Produktnummer `WD ` ist (z.B. `WD 050` → nutzt `DZ 050`-Daten).

## Stage-Test (von Anger empfohlen)

Da die Dosenzauber-Landingpage in der Stage nicht existiert, Test an einer **Weihnachtsdose (WD-Produkt)**:

1. **Stage-Admin:** https://shop.doseplus.de/dpstage/admin *(Login intern)*
2. Produkt **"Weihnachtsbaum Dose"** (WD 050) im Admin öffnen
3. Custom Field `doseplus_dosenzauber_dz` auf `DZ 050` setzen — ODER `doseplus_dosenzauber_enable` auf true
4. **Storefront:** https://shop.doseplus.de/dpstage — **anmelden** (B2B-Plugin: Preise sind nur sichtbar wenn eingeloggt!)
5. Produktseite öffnen — Konfigurator sollte vor dem Buy-Button erscheinen

## Installation

### Option A: Über das Admin-Backend (empfohlen für Stage)

1. Plugin als ZIP packen — siehe Abschnitt unten
2. Shopware-Admin → **Erweiterungen** → **Meine Erweiterungen** → **Hochladen**
3. ZIP auswählen → installieren → aktivieren
4. **Caches leeren**: `bin/console cache:clear` oder im Admin via "System → Caches & Indizes"

### Option B: Manuell ins Plugin-Verzeichnis

```bash
cp -r dosenzauber-shopware-plugin/  /pfad/zu/shopware/custom/plugins/DoseplusDosenzauberConfigurator/
cd /pfad/zu/shopware/
bin/console plugin:refresh
bin/console plugin:install --activate DoseplusDosenzauberConfigurator
bin/console cache:clear
```

## ZIP-Datei bauen

Das Plugin muss als ZIP mit **einem Root-Ordner** vorliegen, der genau so heißt wie die Plugin-Class:

```bash
cd projects/
zip -r DoseplusDosenzauberConfigurator.zip dosenzauber-shopware-plugin/ \
    -x "*.git*" "*.DS_Store" "node_modules/*"

# Aber: der innere Ordner muss DoseplusDosenzauberConfigurator/ heißen!
# Pragmatisch:
cp -r dosenzauber-shopware-plugin DoseplusDosenzauberConfigurator
zip -r DoseplusDosenzauberConfigurator.zip DoseplusDosenzauberConfigurator/
rm -rf DoseplusDosenzauberConfigurator
```

## Stack

- **PHP** 8.2+ (Shopware 6.6 Standard)
- **Twig** Template-Override des Standard-Buy-Widget
- **Alpine.js** (CDN) für Reaktivität — kein Build-Schritt
- **Tailwind CSS** (CDN) für Styling — kein Build-Schritt
- **Lucide** Icons (CDN)

Vorteile: keine Compile-Schritte, läuft sofort nach Plugin-Aktivierung. Bei Produktiv-Einsatz später Tailwind kompilieren und Alpine bundlen.

## Wo erweitern?

| Feature | Datei |
|---|---|
| Preise/Mengen pro DZ-Artikel | `src/Service/ConfiguratorDataProvider.php` |
| Trigger-Logik (welche Produkte zeigen den Configurator) | `src/Service/ConfiguratorDataProvider.php::isDosenzauberProduct()` |
| UI / Styling | `src/Resources/views/storefront/component/dosenzauber-configurator.html.twig` |
| Wo das Widget rendert | `src/Resources/views/storefront/page/product-detail/buy-widget.html.twig` |
| Warenkorb-Integration | `addToCart()` JS-Funktion (TODO: an Shopware POST `/checkout/line-item/add` anbinden) |

## Bekannte Offene Punkte

- ❌ **Warenkorb-Integration** noch als Demo-Alert (Konfiguration wird gepostet, aber nicht im Cart abgelegt)
- ❌ **Logo-Upload** ist UI-only — Datei wird nicht an Shopware Media übertragen
- ❌ **Stage-Upload** ausstehend (warte auf Stage-API-Zugang)
- ❌ **DZ 030 UV-Nummer** fehlt im Excel (zeigt "—")

## Lizenz

Proprietary · Doseplus
