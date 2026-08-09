<?php

namespace App\Integrations\Gemini;

use InvalidArgumentException;

/**
 * Shared structured drink analysis payload (schema_version 1).
 *
 * @phpstan-type Identity array{
 *   name: ?string, producer: ?string, brand: ?string, category: ?string, style: ?string,
 *   vintage_or_age: ?string, region: ?string, country: ?string, abv: ?float, volume_ml: ?int
 * }
 * @phpstan-type Sensory array{
 *   appearance: ?string, aroma_notes: list<string>, taste_notes: list<string>, finish: ?string,
 *   body: ?string, sweetness: ?string, acidity: ?string, bitterness: ?string, bitterness_ibu: ?int,
 *   tannin: ?string, carbonation: ?string, mouthfeel: ?string, smoke_peat: ?string
 * }
 * @phpstan-type Narrative array{
 *   tasting_notes: ?string, characteristics: list<string>, interesting_facts: list<string>,
 *   serving_suggestions: ?string, food_pairings: list<string>, glassware: ?string,
 *   serving_temp_c: array{min: ?float, max: ?float}
 * }
 * @phpstan-type Meta array{confidence: float, uncertainties: list<string>, labels_detected: list<string>}
 */
readonly class DrinkAnalysis
{
    public const VERSION = 1;

    public const DOMAINS = ['wine', 'beer', 'spirit'];

    /**
     * @param  Identity  $identity
     * @param  Sensory  $sensory
     * @param  Narrative  $narrative
     * @param  Meta  $meta
     */
    public function __construct(
        public int $schemaVersion,
        public string $domain,
        public array $identity,
        public array $sensory,
        public array $narrative,
        public array $meta,
        public ?string $modelId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $forcedDomain, ?string $modelId = null): self
    {
        if (! in_array($forcedDomain, self::DOMAINS, true)) {
            throw new InvalidArgumentException("Invalid drink domain [{$forcedDomain}].");
        }

        $identity = is_array($raw['identity'] ?? null) ? $raw['identity'] : [];
        $sensory = is_array($raw['sensory'] ?? null) ? $raw['sensory'] : [];
        $narrative = is_array($raw['narrative'] ?? null) ? $raw['narrative'] : [];
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $temp = is_array($narrative['serving_temp_c'] ?? null) ? $narrative['serving_temp_c'] : [];

        return new self(
            schemaVersion: self::VERSION,
            domain: $forcedDomain,
            identity: [
                'name' => self::nullableString($identity['name'] ?? null),
                'producer' => self::nullableString($identity['producer'] ?? null),
                'brand' => self::nullableString($identity['brand'] ?? null),
                'category' => self::nullableString($identity['category'] ?? null),
                'style' => self::nullableString($identity['style'] ?? null),
                'vintage_or_age' => self::nullableString($identity['vintage_or_age'] ?? null),
                'region' => self::nullableString($identity['region'] ?? null),
                'country' => self::nullableString($identity['country'] ?? null),
                'abv' => self::nullableFloat($identity['abv'] ?? null),
                'volume_ml' => self::nullableInt($identity['volume_ml'] ?? null),
            ],
            sensory: [
                'appearance' => self::nullableString($sensory['appearance'] ?? null),
                'aroma_notes' => self::stringList($sensory['aroma_notes'] ?? []),
                'taste_notes' => self::stringList($sensory['taste_notes'] ?? []),
                'finish' => self::nullableString($sensory['finish'] ?? null),
                'body' => self::nullableString($sensory['body'] ?? null),
                'sweetness' => self::nullableString($sensory['sweetness'] ?? null),
                'acidity' => self::nullableString($sensory['acidity'] ?? null),
                'bitterness' => self::nullableString($sensory['bitterness'] ?? null),
                'bitterness_ibu' => self::nullableInt($sensory['bitterness_ibu'] ?? null),
                'tannin' => self::nullableString($sensory['tannin'] ?? null),
                'carbonation' => self::nullableString($sensory['carbonation'] ?? null),
                'mouthfeel' => self::nullableString($sensory['mouthfeel'] ?? null),
                'smoke_peat' => self::nullableString($sensory['smoke_peat'] ?? null),
            ],
            narrative: [
                'tasting_notes' => self::nullableString($narrative['tasting_notes'] ?? null),
                'characteristics' => self::stringList($narrative['characteristics'] ?? []),
                'interesting_facts' => self::stringList($narrative['interesting_facts'] ?? []),
                'serving_suggestions' => self::nullableString($narrative['serving_suggestions'] ?? null),
                'food_pairings' => self::stringList($narrative['food_pairings'] ?? []),
                'glassware' => self::nullableString($narrative['glassware'] ?? null),
                'serving_temp_c' => [
                    'min' => self::nullableFloat($temp['min'] ?? null),
                    'max' => self::nullableFloat($temp['max'] ?? null),
                ],
            ],
            meta: [
                'confidence' => max(0.0, min(1.0, (float) ($meta['confidence'] ?? 0))),
                'uncertainties' => self::stringList($meta['uncertainties'] ?? []),
                'labels_detected' => self::stringList($meta['labels_detected'] ?? []),
            ],
            modelId: $modelId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'domain' => $this->domain,
            'identity' => $this->identity,
            'sensory' => $this->sensory,
            'narrative' => $this->narrative,
            'meta' => $this->meta,
        ];
    }

    public static function schemaInstruction(): string
    {
        return <<<'JSON'
Respond with a single JSON object only (no markdown) matching this shape exactly.
All keys must be present. Use null for unknown scalars and [] for unknown arrays.

QUALITY RULES (critical — do not be vague):
1. sensory.aroma_notes: 5–10 concrete aroma descriptors the drinker would notice on the nose
   (e.g. "blackcurrant", "ripe plum", "smoke", "vanilla oak", "wet slate", "citrus zest").
   Prefer specific food/plant/earth words over abstract adjectives like "fruity" or "complex".
2. sensory.taste_notes: 5–10 prominent flavour descriptors on the palate
   (e.g. "black cherry", "dark chocolate", "pepper spice", "toasted oak"). Same concreteness rules.
3. sensory.appearance / finish / body / sweetness / acidity / tannin / mouthfeel: fill when relevant
   with short tasting-sheet language (e.g. body "medium-full", acidity "bright medium+", finish "long, spicy").
4. narrative.tasting_notes: 2–4 sentences of a rich tasting note that weaves aromas and flavours together.
   Not a single empty marketing line.
5. narrative.characteristics: 3–6 short bullets about how the drink behaves (structure, style, blend feel, aging notes).
   Do NOT dump grape/variety names alone here — put varieties in identity.style if known.
6. narrative.interesting_facts: 2–4 non-obvious facts (producer practice, history, typical vineyard character,
   winemaking, culture around this style). NEVER restate the region or country that is already on the label
   (e.g. ban "this wine is from Paarl / South Africa / WO X"). Prefer why that origin matters or something less obvious.
7. narrative.food_pairings: 3–6 specific dishes (not just "meat").
8. Prefer richness and usefulness over brevity. When style/region known, draw on typical sensory profile of that style
   and say so gently via uncertainties if you are inferring rather than reading the glass.

Schema:
{
  "schema_version": 1,
  "domain": "wine|beer|spirit",
  "identity": {
    "name": null, "producer": null, "brand": null, "category": null, "style": null,
    "vintage_or_age": null, "region": null, "country": null, "abv": null, "volume_ml": null
  },
  "sensory": {
    "appearance": null, "aroma_notes": [], "taste_notes": [], "finish": null,
    "body": null, "sweetness": null, "acidity": null, "bitterness": null, "bitterness_ibu": null,
    "tannin": null, "carbonation": null, "mouthfeel": null, "smoke_peat": null
  },
  "narrative": {
    "tasting_notes": null, "characteristics": [], "interesting_facts": [],
    "serving_suggestions": null, "food_pairings": [], "glassware": null,
    "serving_temp_c": { "min": null, "max": null }
  },
  "meta": {
    "confidence": 0, "uncertainties": [], "labels_detected": []
  }
}
JSON;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $str = self::nullableString($item);
            if ($str !== null) {
                $out[] = $str;
            }
        }

        return array_values($out);
    }
}
