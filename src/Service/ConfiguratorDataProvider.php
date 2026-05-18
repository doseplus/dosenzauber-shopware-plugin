<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Liefert die statischen Konfigurator-Daten pro DZ-Produkt.
 *
 * STRIKTE WHITELIST: nur die 8 DZ-Produktnummern werden unterstützt.
 * Live-Version: keine WD-Pendant-Produkte mehr — die DZ-Produkte sind selbst
 * Cart-Anker. Das `wd`-Feld bleibt nur als UI-Anzeige-Alias und zeigt die
 * gleiche DZ-Nummer.
 *
 * Datenbasis: Steffen Angers Excel-Tabelle vom 2026-05-12 + Updates 2026-05-13
 */
class ConfiguratorDataProvider
{
    private const STATIC_DATA = [
        'DZ 010' => [
            'name' => 'Lapplandliebe', 'wd' => 'DZ 010', 'wkn' => 'WKN 010', 'wki' => 'WKI 010',
            'kartonSize' => 40, 'maxRiegel' => 24,
            'wdStaffel' => [2.78, 2.58, 2.48, 2.43, 2.33],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSQ 010', 'uvNumber' => 'UV 210', 'uvSize' => '155 × 155 × 55 mm',
        ],
        'DZ 011' => [
            'name' => 'Adventskalender', 'wd' => 'DZ 011', 'wkn' => 'WKN 011', 'wki' => 'WKI 011',
            'kartonSize' => 40, 'maxRiegel' => 24,
            'wdStaffel' => [2.78, 2.58, 2.48, 2.43, 2.33],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSQ 010', 'uvNumber' => 'UV 210', 'uvSize' => '155 × 155 × 55 mm',
        ],
        'DZ 026' => [
            'name' => 'Schneeexpress', 'wd' => 'DZ 026', 'wkn' => 'WKN 026', 'wki' => 'WKI 026',
            'kartonSize' => 30, 'maxRiegel' => 28,
            'wdStaffel' => [2.69, 2.54, 2.44, 2.39, 2.29],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 026', 'uvNumber' => 'UV 220', 'uvSize' => '125 × 110 × 110 mm',
        ],
        'DZ 030' => [
            'name' => 'Weihnachtsschatz', 'wd' => 'DZ 030', 'wkn' => 'WKN 030', 'wki' => 'WKI 030',
            'kartonSize' => 30, 'maxRiegel' => 48,
            'wdStaffel' => [3.58, 3.38, 3.33, 3.28, 3.18],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSE 030', 'uvNumber' => 'UV 230', 'uvSize' => '265 × 140 × 82 mm',
        ],
        'DZ 036' => [
            'name' => 'Weihnachtstraum', 'wd' => 'DZ 036', 'wkn' => 'WKN 036', 'wki' => 'WKI 036',
            'kartonSize' => 20, 'maxRiegel' => 36,
            'wdStaffel' => [3.78, 3.58, 3.48, 3.43, 2.98],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 035', 'uvNumber' => 'UV 240', 'uvSize' => '195 × 195 × 70 mm',
        ],
        'DZ 050' => [
            'name' => 'Tannenbaum', 'wd' => 'DZ 050', 'wkn' => 'WKN 050', 'wki' => 'WKI 050',
            'kartonSize' => 24, 'maxRiegel' => 28,
            'wdStaffel' => [3.78, 3.58, 3.48, 3.43, 3.38],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 035', 'uvNumber' => 'UV 250', 'uvSize' => '225 × 200 × 70 mm',
        ],
        'DZ 051' => [
            'name' => 'Wichtelfreunde', 'wd' => 'DZ 051', 'wkn' => 'WKN 051', 'wki' => 'WKI 051',
            'kartonSize' => 20, 'maxRiegel' => 24,
            'wdStaffel' => [2.69, 2.44, 2.34, 2.29, 2.19],
            'laserStaffel' => [1.55, 1.05, 1.00, 0.87, 0.72, 0.70],
            'gravurCode' => 'DSR 051', 'uvNumber' => 'UV 210', 'uvSize' => '155 × 155 × 55 mm',
        ],
        'DZ 091' => [
            'name' => 'Surprise', 'wd' => 'DZ 091', 'wkn' => 'WKN 091', 'wki' => 'WKI 091',
            'kartonSize' => 20, 'maxRiegel' => 48,
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

    /** @var array<string,string> cache languageId → locale code */
    private array $localeCache = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfiguratorTranslations $translations,
        private readonly Connection $connection,
    ) {}

    public function isDosenzauberProduct(SalesChannelProductEntity $product): bool
    {
        return $this->getDataForProduct($product) !== null;
    }

    public function getDataForProduct(SalesChannelProductEntity $product, ?SalesChannelContext $context = null): ?array
    {
        $number = $product->getProductNumber();
        if (!$number) return null;

        if (isset(self::STATIC_DATA[$number])) {
            return $this->buildData($product, $number, $context);
        }

        return null;
    }

    private function buildData(SalesChannelProductEntity $product, string $dzNumber, ?SalesChannelContext $context = null): array
    {
        // STATIC_DATA ist Single-Source-of-Truth: maxRiegel kommt immer aus dem Plugin-Code,
        // nicht aus dem Live-Custom-Field attr14 (das auf einigen Produkten falsch gepflegt ist).
        $data = self::STATIC_DATA[$dzNumber];

        $locale       = 'de-DE';
        $currencyCode = 'EUR';
        $currencyFactor = 1.0;
        if ($context) {
            $locale = $this->resolveLocale($context->getLanguageId());
            $cur = $context->getCurrency();
            if ($cur) {
                $currencyCode   = $cur->getIsoCode() ?: 'EUR';
                $currencyFactor = (float) ($cur->getFactor() ?: 1.0);
            }
        }

        return [
            'productId'      => $product->getId(),
            'productNumber'  => $dzNumber,
            ...$data,
            'fixedPrices'    => self::FIXED_PRICES,
            'locale'         => $locale,
            'currency'       => $currencyCode,
            'currencyFactor' => $currencyFactor,
            't'              => $this->translations->for($locale),
        ];
    }

    private function resolveLocale(string $languageId): string
    {
        if (isset($this->localeCache[$languageId])) {
            return $this->localeCache[$languageId];
        }
        try {
            $code = $this->connection->fetchOne(
                'SELECT locale.code FROM language INNER JOIN locale ON language.locale_id = locale.id WHERE language.id = :id LIMIT 1',
                ['id' => hex2bin($languageId)]
            );
            $this->localeCache[$languageId] = is_string($code) && $code !== '' ? $code : 'de-DE';
        } catch (\Throwable $e) {
            $this->localeCache[$languageId] = 'de-DE';
        }
        return $this->localeCache[$languageId];
    }
}
