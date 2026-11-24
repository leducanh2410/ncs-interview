<?php

namespace App\Enums;

/**
 * Canonical prefecture mapping.
 *
 * The key is the lowercase English romanized name used as the canonical form
 * in rule-based group predicates. The value is an array of all known aliases
 * (lowercase) that may appear in the user_infos.prefecture column — Japanese
 * kanji (with and without the 都/道/府/県 suffix), romanized forms with/without
 * hyphens, and other common variants.
 */
enum Prefecture: string
{
    use EnumTrait;

    case HOKKAIDO = 'hokkaido';
    case AOMORI = 'aomori';
    case IWATE = 'iwate';
    case MIYAGI = 'miyagi';
    case AKITA = 'akita';
    case YAMAGATA = 'yamagata';
    case FUKUSHIMA = 'fukushima';
    case IBARAKI = 'ibaraki';
    case TOCHIGI = 'tochigi';
    case GUNMA = 'gunma';
    case SAITAMA = 'saitama';
    case CHIBA = 'chiba';
    case TOKYO = 'tokyo';
    case KANAGAWA = 'kanagawa';
    case NIIGATA = 'niigata';
    case TOYAMA = 'toyama';
    case ISHIKAWA = 'ishikawa';
    case FUKUI = 'fukui';
    case YAMANASHI = 'yamanashi';
    case NAGANO = 'nagano';
    case GIFU = 'gifu';
    case SHIZUOKA = 'shizuoka';
    case AICHI = 'aichi';
    case MIE = 'mie';
    case SHIGA = 'shiga';
    case KYOTO = 'kyoto';
    case OSAKA = 'osaka';
    case HYOGO = 'hyogo';
    case NARA = 'nara';
    case WAKAYAMA = 'wakayama';
    case TOTTORI = 'tottori';
    case SHIMANE = 'shimane';
    case OKAYAMA = 'okayama';
    case HIROSHIMA = 'hiroshima';
    case YAMAGUCHI = 'yamaguchi';
    case TOKUSHIMA = 'tokushima';
    case KAGAWA = 'kagawa';
    case EHIME = 'ehime';
    case KOCHI = 'kochi';
    case FUKUOKA = 'fukuoka';
    case SAGA = 'saga';
    case NAGASAKI = 'nagasaki';
    case KUMAMOTO = 'kumamoto';
    case OITA = 'oita';
    case MIYAZAKI = 'miyazaki';
    case KAGOSHIMA = 'kagoshima';
    case OKINAWA = 'okinawa';

    /**
     * Mapping from canonical English romanized (lowercase) to all known aliases.
     *
     * Each array includes the canonical key itself plus Japanese kanji forms
     * (with and without the suffix) and common romanized variants.
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'hokkaido' => ['hokkaido', '北海道'],
        'aomori' => ['aomori', '青森県', '青森'],
        'iwate' => ['iwate', '岩手県', '岩手'],
        'miyagi' => ['miyagi', '宮城県', '宮城'],
        'akita' => ['akita', '秋田県', '秋田'],
        'yamagata' => ['yamagata', '山形県', '山形'],
        'fukushima' => ['fukushima', '福島県', '福島'],
        'ibaraki' => ['ibaraki', '茨城県', '茨城'],
        'tochigi' => ['tochigi', '栃木県', '栃木'],
        'gunma' => ['gunma', '群馬県', '群馬'],
        'saitama' => ['saitama', '埼玉県', '埼玉'],
        'chiba' => ['chiba', '千葉県', '千葉'],
        'tokyo' => ['tokyo', '東京都', '東京', 'tokyo-to', 'tokyo to'],
        'kanagawa' => ['kanagawa', '神奈川県', '神奈川'],
        'niigata' => ['niigata', '新潟県', '新潟'],
        'toyama' => ['toyama', '富山県', '富山'],
        'ishikawa' => ['ishikawa', '石川県', '石川'],
        'fukui' => ['fukui', '福井県', '福井'],
        'yamanashi' => ['yamanashi', '山梨県', '山梨'],
        'nagano' => ['nagano', '長野県', '長野'],
        'gifu' => ['gifu', '岐阜県', '岐阜'],
        'shizuoka' => ['shizuoka', '静岡県', '静岡'],
        'aichi' => ['aichi', '愛知県', '愛知'],
        'mie' => ['mie', '三重県', '三重'],
        'shiga' => ['shiga', '滋賀県', '滋賀'],
        'kyoto' => ['kyoto', '京都府', '京都', 'kyoto-fu', 'kyoto fu'],
        'osaka' => ['osaka', '大阪府', '大阪', 'osaka-fu', 'osaka fu'],
        'hyogo' => ['hyogo', '兵庫県', '兵庫', 'hyogo-ken', 'hyogo ken'],
        'nara' => ['nara', '奈良県', '奈良'],
        'wakayama' => ['wakayama', '和歌山県', '和歌山'],
        'tottori' => ['tottori', '鳥取県', '鳥取'],
        'shimane' => ['shimane', '島根県', '島根'],
        'okayama' => ['okayama', '岡山県', '岡山'],
        'hiroshima' => ['hiroshima', '広島県', '広島'],
        'yamaguchi' => ['yamaguchi', '山口県', '山口'],
        'tokushima' => ['tokushima', '徳島県', '徳島'],
        'kagawa' => ['kagawa', '香川県', '香川'],
        'ehime' => ['ehime', '愛媛県', '愛媛'],
        'kochi' => ['kochi', '高知県', '高知'],
        'fukuoka' => ['fukuoka', '福岡県', '福岡'],
        'saga' => ['saga', '佐賀県', '佐賀'],
        'nagasaki' => ['nagasaki', '長崎県', '長崎'],
        'kumamoto' => ['kumamoto', '熊本県', '熊本'],
        'oita' => ['oita', '大分県', '大分'],
        'miyazaki' => ['miyazaki', '宮崎県', '宮崎'],
        'kagoshima' => ['kagoshima', '鹿児島県', '鹿児島'],
        'okinawa' => ['okinawa', '沖縄県', '沖縄'],
    ];

    /**
     * @return string[]
     */
    public function aliases(): array
    {
        return self::ALIASES[$this->value] ?? [$this->value];
    }

    /**
     * Official Japanese prefecture name, including its 都/道/府/県 suffix.
     */
    public function japaneseName(): string
    {
        foreach ($this->aliases() as $alias) {
            if (preg_match('/[都道府県]$/u', $alias) === 1) {
                return $alias;
            }
        }

        return $this->value;
    }

    /**
     * @return string[]
     */
    public static function japaneseNames(): array
    {
        return array_map(fn(self $prefecture) => $prefecture->japaneseName(), self::cases());
    }

    /**
     * Expand a set of predicate values into all matching aliases.
     *
     * @param  string[]  $values  Canonical lowercase English romanized values
     * @return string[]  All aliases that should be matched against the DB
     */
    public static function expandValues(array $values): array
    {
        $expanded = [];
        foreach ($values as $value) {
            $lower = mb_strtolower(mb_trim($value));
            // If the value is a known canonical key, expand to all aliases
            if (isset(self::ALIASES[$lower])) {
                $expanded = array_merge($expanded, self::ALIASES[$lower]);
            } else {
                // Unknown value — also try reverse lookup from aliases
                $matched = self::findByAlias($lower);
                if ($matched !== null) {
                    $expanded = array_merge($expanded, self::ALIASES[$matched]);
                } else {
                    // Truly unknown, include as-is
                    $expanded[] = $lower;
                }
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Find the canonical key for a given alias value.
     */
    public static function findByAlias(string $alias): ?string
    {
        $lower = mb_strtolower(mb_trim($alias));

        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($lower, $aliases, true)) {
                return $canonical;
            }
        }

        return null;
    }
}
