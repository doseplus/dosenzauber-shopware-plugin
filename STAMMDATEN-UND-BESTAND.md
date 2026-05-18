# Stammdaten & Bestandslogik – Theilich-Feedback 2026-05-18

Dieser Abschnitt beschreibt die Pickware/Shopware-Stammdaten-Schritte und die noch fehlende Backend-Bestandsbuchung, die zur Frontend-Konfigurator-Logik gehören.

## Punkt 6 — WKN-Anfangsbestand 1000 Stück

Pro Dosenzauber-Konfiguration existiert ein WKN-Produkt (Karte neutral) das mit jeder DZ-Bestellung mit-abgebucht wird.

### Manuell anzulegen (oder zu prüfen) in Pickware/Shopware:

| WKN-SKU  | Bezeichnung                       | Initialbestand | Bezugs-DZ |
|----------|-----------------------------------|----------------|-----------|
| WKN 010  | Karte neutral Lapplandliebe       | 1000           | DZ 010    |
| WKN 011  | Karte neutral Adventskalender     | 1000           | DZ 011    |
| WKN 026  | Karte neutral Schneeexpress       | 1000           | DZ 026    |
| WKN 030  | Karte neutral Weihnachtsschatz    | 1000           | DZ 030    |
| WKN 036  | Karte neutral Weihnachtstraum     | 1000           | DZ 036    |
| WKN 050  | Karte neutral Tannenbaum          | 1000           | DZ 050    |
| WKN 051  | Karte neutral Wichtelfreunde      | 1000           | DZ 051    |
| WKN 091  | Karte neutral Surprise            | 1000           | DZ 091    |

### Schritte im Shopware-Admin:
1. Pickware → Lager → Produktanlage → SKU `WKN xxx`
2. Steuersatz: 19 %, Lagerverwaltung aktiv
3. Bestand: 1000 Stück
4. Verkaufskanal: kein eigener — interner Artikel für Stückliste

## Punkt 2 — UV-Bestand: ein SKU pro Größe

Pro Größe existiert nur **ein** UV-Lagerartikel. „plano" vs „konfektioniert" ist eine Konfigurations-Anforderung in der Bestellung, kein eigener Bestand.

| UV-SKU  | Maße                | Bezugs-DZ                            |
|---------|---------------------|--------------------------------------|
| UV 210  | 155 × 155 × 55 mm   | DZ 010, DZ 011, DZ 051               |
| UV 220  | 125 × 110 × 110 mm  | DZ 026                               |
| UV 230  | 265 × 140 × 82 mm   | DZ 030                               |
| UV 240  | 195 × 195 × 70 mm   | DZ 036                               |
| UV 250  | 225 × 200 × 70 mm   | DZ 050, DZ 091                       |

→ Falls aktuell `UV xxx K` als separates Produkt existiert: zusammenführen oder K-Variante stilllegen.

## Punkte 3 + 4 + 5 — Backend-Bestandsbuchung beim DZ-Cart-Add

**Frontend liefert bereits:** `payload.stockMovements` (Array mit `{sku, qty, role}`) + `payload.sonderanfertigung` (für WKI-Karten).

**Backend-Endpoint fehlt noch:** `POST /dosenzauber/add-to-cart` (geplant in `addToCart()` als TODO markiert).

### Was der Endpoint tun muss:

1. Cart-Line für `DZ xxx` mit übergebener `quantity` anlegen
2. Custom-Field am Cart-Item: gesamte Konfiguration (laser/fuellung/verpackung/karteText/logoFileName)
3. Für jedes `stockMovements`-Item: Lagerbuchung gegen das jeweilige Pickware-Produkt erstellen
   - Achtung: DZ `quantity` × `role`-Faktor (für RSW wäre das `riegelProDose`, hier aber als Cart-Beilage konfiguriert)
4. Wenn `sonderanfertigung != null`: Notiz/Anhang am Auftrag mit WKI-SKU + Wunschtext für Produktion

### Mapping Frontend-Payload → Backend-Aktion

| Frontend                                | Backend-Aktion                                  |
|-----------------------------------------|-------------------------------------------------|
| `stockMovements[].sku = WD xxx`         | Pickware-Lagerabgang WD um `quantity` reduzieren |
| `stockMovements[].sku = WKN xxx`        | Pickware-Lagerabgang WKN um `quantity` reduzieren |
| `stockMovements[].sku = UV xxx`         | Pickware-Lagerabgang UV um `quantity` reduzieren (egal ob plano/konfektioniert) |
| `sonderanfertigung.sku = WKI xxx`       | Produktionsauftrag mit `karteText`, **kein** Lagerabgang |
| `verpackung.konfektionierung = true`    | Notiz an Versand: „UV xxx konfektionieren" |
| `laser.personalisierung = true`         | Produktionsauftrag NK PERS + 90€ Einmalkosten |
| `laser.logoFileName`                    | File-Upload in Order-Anhänge |

### Implementierungs-Skizze (PHP)

```php
// src/Storefront/Controller/AddToCartController.php (neu anzulegen)
public function addToCart(Request $request, SalesChannelContext $context): Response
{
    $payload = json_decode($request->getContent(), true);

    // 1. Hauptprodukt DZ in Cart
    $this->cartService->add($cart, $dzLineItem, $context);

    // 2. Stockmovements (parallele Pickware-Lagerabgänge)
    foreach ($payload['stockMovements'] as $mv) {
        $this->pickwareStockApi->reduce($mv['sku'], $mv['qty'], "DZ-Cart {$payload['productNumber']}");
    }

    // 3. WKI-Sonderanfertigung als Order-Hinweis (kein Lagerabgang)
    if (!empty($payload['sonderanfertigung'])) {
        $cart->addCustomerComment("WKI {$payload['sonderanfertigung']['sku']}: „{$payload['sonderanfertigung']['text']}"");
    }

    return new JsonResponse(['ok' => true]);
}
```

(Pickware-Stock-API: siehe `swag/pickware-erp` SDK — Methode `StockApi::reduceStock(productId, quantity, reason)`.)

## Test-Checkliste vor Stage-Upload

- [ ] WKN-Produkte (8 Stück) in Pickware mit je 1000 Bestand angelegt
- [ ] UV-Produkte konsolidiert (keine `UV xxx K` mehr separat)
- [ ] DZ 030 zeigt im Konfigurator `UV 230` (vorher Tippfehler `—`)
- [ ] Stückliste zeigt für `DZ 010`: `WD 010 + WKN 010 + 24× RSW + UV 210` (Standard-Karte)
- [ ] Bei Klick auf „Karte mit persönlichem Text": Stückliste wechselt zu `+ WKI 010` statt `+ WKN 010`
- [ ] Eingegebener Wunschtext wird in Stückliste angezeigt
- [ ] Staffelpreis: bei 250 Dosen zeigt WD-Zeile aktuell `2,48 €/Stück (ab 100 Stk.)` statt nur Range
- [ ] `addToCart`-Payload (Browser-Console) enthält `stockMovements` und ggf. `sonderanfertigung`
- [ ] Backend-Endpoint `POST /dosenzauber/add-to-cart` implementiert + Pickware-Stock-API-Verbindung getestet
