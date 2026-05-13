<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/**
 * Liefert die statischen Konfigurator-Daten pro DZ-Produkt.
 *
 * STRIKTE WHITELIST: nur die 8 DZ-Produktnummern werden unterstützt.
 * WD-Produkte triggern nur, wenn ihre Nummer eine entsprechende DZ-Map hat
 * (Mapping WD XXX → DZ XXX).
 *
 * Datenbasis: Steffen Angers Excel-Tabelle vom 2026-05-12 + Updates 2026-05-13
 */
class ConfiguratorDataProvider
{
    private const STATIC_DATA = [
        'DZ 010' => [
            'name' => 'Lapplandliebe', 'wd' => 'WD 010', 'kartonSize' => 40, 'maxRiegel' => 24,
            'wdStaffel' => [2.78, 2.58, 2.48, 2.43, 2.33],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSQ 010', 'uvNumber' => 'UV 210', 'uvSize' => '155 × 155 × 55 mm',
        ],
        'DZ 011' => [
            'name' => 'Adventskalender', 'wd' => 'WD 011', 'kartonSize' => 40, 'maxRiegel' => 24,
            'wdStaffel' => [2.78, 2.58, 2.48, 2.43, 2.33],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSQ 010', 'uvNumber' => 'UV 210', 'uvSize' => '155 × 155 × 55 mm',
        ],
        'DZ 026' => [
            'name' => 'Schneeexpress', 'wd' => 'WD 026', 'kartonSize' => 30, 'maxRiegel' => 28,
            'wdStaffel' => [2.69, 2.54, 2.44, 2.39, 2.29],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 026', 'uvNumber' => 'UV 220', 'uvSize' => '125 × 110 × 110 mm',
        ],
        'DZ 030' => [
            'name' => 'Weihnachtsschatz', 'wd' => 'WD 030', 'kartonSize' => 30, 'maxRiegel' => 48,
            'wdStaffel' => [3.58, 3.38, 3.33, 3.28, 3.18],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSE 030', 'uvNumber' => 'UV 030', 'uvSize' => '265 × 140 × 82 mm',
        ],
        'DZ 036' => [
            'name' => 'Weihnachtstraum', 'wd' => 'WD 036', 'kartonSize' => 20, 'maxRiegel' => 36,
            'wdStaffel' => [3.48, 3.23, 3.13, 3.08, 2.98],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 035', 'uvNumber' => 'UV 240', 'uvSize' => '195 × 195 × 70 mm',
        ],
        'DZ 050' => [
            'name' => 'Tannenbaum', 'wd' => 'WD 050', 'kartonSize' => 24, 'maxRiegel' => 28,
            'wdStaffel' => [3.78, 3.58, 3.48, 3.43, 3.38],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 035', 'uvNumber' => 'UV 250', 'uvSize' => '225 × 200 × 70 mm',
        ],
        'DZ 051' => [
            'name' => 'Wichtelfreunde', 'wd' => 'WD 051', 'kartonSize' => 20, 'maxRiegel' => 24,
            'wdStaffel' => [2.69, 2.44, 2.34, 2.29, 2.19],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 051', 'uvNumber' => 'UV 210', 'uvSize' => '155 × 155 × 55 mm',
        ],
        'DZ 091' => [
            'name' => 'Surprise', 'wd' => 'WD 091', 'kartonSize' => 20, 'maxRiegel' => 48,
            'wdStaffel' => [3.58, 3.23, 3.18, 3.13, 3.03],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.72],
            'gravurCode' => 'DSE 091', 'uvNumber' => 'UV 250', 'uvSize' => '225 × 200 × 70 mm',
        ],
    ];

    private const FIXED_PRICES = [
        'rsw'                => 0.19,   // Ritter Sport Würfel je Stück
        'nkLgMaschine'       => 45.00,  // Maschinenrüstung einmalig
        'nkPersonalisierung' => 90.00,  // NK PERS Personalisierung pauschal
        'vorabmuster'        => 45.00,  // Freigabemuster
        'karteText'          => 125.00, // Karte mit persönlichem Text
        'ukPlano'            => 0.98,   // Umkarton plano beigelegt
        'ukKonfektioniert'   => 1.19,   // Umkarton konfektioniert
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Single source of truth: Produkt ist Dosenzauber-Produkt, wenn es einen Daten-Match gibt.
     * Damit: Whitelist über STATIC_DATA (8 DZ-Nummern) + WD-Mapping zu eben diesen 8.
     */
    public function isDosenzauberProduct(SalesChannelProductEntity $product): bool
    {
        return $this->getDataForProduct($product) !== null;
    }

    public function getDataForProduct(SalesChannelProductEntity $product): ?array
    {
        $number = $product->getProductNumber();
        if (!$number) return null;

        // 1. Direkter DZ-Match (8 erlaubte Nummern)
        if (isset(self::STATIC_DATA[$number])) {
            return $this->buildData($product, $number);
        }

        // 2. WD-Produkt mit explizit zugeordneter DZ-Nummer im Custom Field
        $cf = $product->getCustomFields() ?? [];
        $dzAssigned = $cf['doseplus_dosenzauber_dz'] ?? null;
        if (is_string($dzAssigned) && isset(self::STATIC_DATA[$dzAssigned])) {
            return $this->buildData($product, $dzAssigned);
        }

        // 3. WD-Auto-Mapping: WD XXX → DZ XXX (nur wenn DZ XXX in Whitelist)
        if (str_starts_with($number, 'WD ')) {
            $candidateDz = 'DZ ' . substr($number, 3);
            if (isset(self::STATIC_DATA[$candidateDz])) {
                return $this->buildData($product, $candidateDz);
            }
        }

        return null;
    }

    private function buildData(SalesChannelProductEntity $product, string $dzNumber): array
    {
        $data = self::STATIC_DATA[$dzNumber];

        // Custom-Field-Override: attr14 für maxRiegel
        $cf = $product->getCustomFields() ?? [];
        $cfMax = $cf['migration_shopdoseplusde_product_attr14'] ?? null;
        if ($cfMax !== null && (int)$cfMax > 0) {
            $data['maxRiegel'] = (int)$cfMax;
        }

        return [
            'productId'     => $product->getId(),
            'productNumber' => $dzNumber,
            ...$data,
            'fixedPrices'   => self::FIXED_PRICES,
        ];
    }
}
