# Changelog

## v1.0.2 — 2026-05-18

7-Punkte-Feedback von Theilich (Excel + Mail vom 18.05.2026):

1. **UV-Tippfehler-Fix** — DZ 030 hatte `uvNumber: '—'`, jetzt `UV 230`. DZ 036 bleibt `UV 240`.
2. **UV plano ≡ konfektioniert in Lagerhaltung** — UV-SKU ohne K-Suffix. Plano/Konfektionierung sind Konfigurations-Anforderungen an die Produktion, kein eigener Bestand.
3. **Stückliste DZ → WD Mapping** — Formel-Zeile zeigt `DZ 010 = WD 010 + WKN 010 + 24× RSW + UV 210`. Cart-Payload enthält `stockMovements[]` mit WD-SKU für Backend-Bestandsabbuchung.
4. **WKN-Dekrementierung** — neue Stücklisten-Zeile für neutrale Karten (WKN). Beim Cart-Add wird WKN-SKU mit Quantity in `stockMovements[]` mit übergeben.
5. **WKI-Sonderanfertigung** — bei Auswahl "Karte mit persönlichem Text" wechselt Stückliste auf WKI-SKU, Wunschtext wird im Klartext angezeigt. Im Cart-Payload als `sonderanfertigung`-Block (KEIN WKN-Abzug).
6. **WKN-Anfangsbestand** — Stammdaten-Doku unter `STAMMDATEN-UND-BESTAND.md`: 8 WKN-Produkte mit je 1000 Stück Bestand in Pickware anzulegen.
7. **Staffelpreis-Anzeige** — WD-Zeile in Stückliste zeigt jetzt `aktuell X,XX €/Stück (ab YY Stk.)` statt nur Preis-Range, analog zur bestehenden Laser-Zeile.

**Datenmodell-Erweiterung** in `ConfiguratorDataProvider.php`:
- Neue Felder pro DZ-Eintrag: `wkn` (z.B. `WKN 010`), `wki` (z.B. `WKI 010`)

**Cart-Payload** in `addToCart()`:
- Neu: `stockMovements[]` mit `{sku, qty, role}` für Bestandsabbuchung
- Neu: `sonderanfertigung` mit `{sku, qty, text}` für WKI-Produktionsaufträge
- Erweitert: `verpackung.konfektionierung` (boolean) als Produktions-Hinweis

## v1.0.1 — 2026-05-13

Initialer Live-Rollout (siehe LIVE-MIGRATION-PLAN.md):
- 8 DZ-Produkte auf 6 Shops live (DE/COM/AT/CH/PL/FR)
- 15 Helfer-Produkte angelegt, XMAS20-Promo aktiv
- Multi-Lang DE/EN/PL/FR, Multi-Currency EUR/CHF/PLN
- Tailwind precompiliert, Lucide → Inline-SVG

## v1.0.0 — 2026-05-11

Erstinstallation auf Stage (Test mit Anger).
