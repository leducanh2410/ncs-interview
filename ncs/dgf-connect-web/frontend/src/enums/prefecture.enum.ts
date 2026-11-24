export type Prefecture =
    | 'HOKKAIDO'
    | 'AOMORI'
    | 'IWATE'
    | 'MIYAGI'
    | 'AKITA'
    | 'YAMAGATA'
    | 'FUKUSHIMA'
    | 'IBARAKI'
    | 'TOCHIGI'
    | 'GUNMA'
    | 'SAITAMA'
    | 'CHIBA'
    | 'TOKYO'
    | 'KANAGAWA'
    | 'NIIGATA'
    | 'TOYAMA'
    | 'ISHIKAWA'
    | 'FUKUI'
    | 'YAMANASHI'
    | 'NAGANO'
    | 'GIFU'
    | 'SHIZUOKA'
    | 'AICHI'
    | 'MIE'
    | 'SHIGA'
    | 'KYOTO'
    | 'OSAKA'
    | 'HYOGO'
    | 'NARA'
    | 'WAKAYAMA'
    | 'TOTTORI'
    | 'SHIMANE'
    | 'OKAYAMA'
    | 'HIROSHIMA'
    | 'YAMAGUCHI'
    | 'TOKUSHIMA'
    | 'KAGAWA'
    | 'EHIME'
    | 'KOCHI'
    | 'FUKUOKA'
    | 'SAGA'
    | 'NAGASAKI'
    | 'KUMAMOTO'
    | 'OITA'
    | 'MIYAZAKI'
    | 'KAGOSHIMA'
    | 'OKINAWA';

export const PrefectureEnum: { [key in Prefecture]: { value: string; text: string } } = Object.freeze({
    HOKKAIDO: { value: 'hokkaido', text: 'Hokkaido' },
    AOMORI: { value: 'aomori', text: 'Aomori' },
    IWATE: { value: 'iwate', text: 'Iwate' },
    MIYAGI: { value: 'miyagi', text: 'Miyagi' },
    AKITA: { value: 'akita', text: 'Akita' },
    YAMAGATA: { value: 'yamagata', text: 'Yamagata' },
    FUKUSHIMA: { value: 'fukushima', text: 'Fukushima' },
    IBARAKI: { value: 'ibaraki', text: 'Ibaraki' },
    TOCHIGI: { value: 'tochigi', text: 'Tochigi' },
    GUNMA: { value: 'gunma', text: 'Gunma' },
    SAITAMA: { value: 'saitama', text: 'Saitama' },
    CHIBA: { value: 'chiba', text: 'Chiba' },
    TOKYO: { value: 'tokyo', text: 'Tokyo' },
    KANAGAWA: { value: 'kanagawa', text: 'Kanagawa' },
    NIIGATA: { value: 'niigata', text: 'Niigata' },
    TOYAMA: { value: 'toyama', text: 'Toyama' },
    ISHIKAWA: { value: 'ishikawa', text: 'Ishikawa' },
    FUKUI: { value: 'fukui', text: 'Fukui' },
    YAMANASHI: { value: 'yamanashi', text: 'Yamanashi' },
    NAGANO: { value: 'nagano', text: 'Nagano' },
    GIFU: { value: 'gifu', text: 'Gifu' },
    SHIZUOKA: { value: 'shizuoka', text: 'Shizuoka' },
    AICHI: { value: 'aichi', text: 'Aichi' },
    MIE: { value: 'mie', text: 'Mie' },
    SHIGA: { value: 'shiga', text: 'Shiga' },
    KYOTO: { value: 'kyoto', text: 'Kyoto' },
    OSAKA: { value: 'osaka', text: 'Osaka' },
    HYOGO: { value: 'hyogo', text: 'Hyogo' },
    NARA: { value: 'nara', text: 'Nara' },
    WAKAYAMA: { value: 'wakayama', text: 'Wakayama' },
    TOTTORI: { value: 'tottori', text: 'Tottori' },
    SHIMANE: { value: 'shimane', text: 'Shimane' },
    OKAYAMA: { value: 'okayama', text: 'Okayama' },
    HIROSHIMA: { value: 'hiroshima', text: 'Hiroshima' },
    YAMAGUCHI: { value: 'yamaguchi', text: 'Yamaguchi' },
    TOKUSHIMA: { value: 'tokushima', text: 'Tokushima' },
    KAGAWA: { value: 'kagawa', text: 'Kagawa' },
    EHIME: { value: 'ehime', text: 'Ehime' },
    KOCHI: { value: 'kochi', text: 'Kochi' },
    FUKUOKA: { value: 'fukuoka', text: 'Fukuoka' },
    SAGA: { value: 'saga', text: 'Saga' },
    NAGASAKI: { value: 'nagasaki', text: 'Nagasaki' },
    KUMAMOTO: { value: 'kumamoto', text: 'Kumamoto' },
    OITA: { value: 'oita', text: 'Oita' },
    MIYAZAKI: { value: 'miyazaki', text: 'Miyazaki' },
    KAGOSHIMA: { value: 'kagoshima', text: 'Kagoshima' },
    OKINAWA: { value: 'okinawa', text: 'Okinawa' },
});
