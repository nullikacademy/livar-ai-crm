<?php
/**
 * config/countries.php
 *
 * Turns a WhatsApp number into a country.
 *
 * A wa_id is an international number in digits, so the country is
 * already in it -- there is no reason to make an agent type it. This
 * file is the lookup: dialling prefix to ISO 3166-1 alpha-2, alpha-2 to
 * a name, and alpha-2 to the flag emoji.
 *
 * Matching is longest-prefix, which is what makes +1 work: '1' is the
 * United States, but '1876' is Jamaica and '1416' is Canada, and the
 * four-digit entries have to win. The NANP list below covers Canada's
 * area codes and the Caribbean members that have their own; anything
 * else on +1 falls back to the United States.
 *
 * It is a lookup table and nothing else -- no network call, no
 * dependency, and a number it cannot place simply comes back null.
 */

declare(strict_types=1);

/**
 * Dialling prefix (digits, no '+') to ISO 3166-1 alpha-2.
 *
 * Order does not matter; COUNTRY_PREFIX_MAX_LEN below drives the search.
 */
const COUNTRY_DIAL_CODES = [
    // North American Numbering Plan. The bare '1' is last-resort; the
    // four-digit entries are what keep a Canadian from reading as
    // American.
    '1'    => 'US',
    '1204' => 'CA', '1226' => 'CA', '1236' => 'CA', '1249' => 'CA', '1250' => 'CA',
    '1263' => 'CA', '1289' => 'CA', '1306' => 'CA', '1343' => 'CA', '1354' => 'CA',
    '1365' => 'CA', '1367' => 'CA', '1368' => 'CA', '1382' => 'CA', '1387' => 'CA',
    '1403' => 'CA', '1416' => 'CA', '1418' => 'CA', '1428' => 'CA', '1431' => 'CA',
    '1437' => 'CA', '1438' => 'CA', '1450' => 'CA', '1468' => 'CA', '1474' => 'CA',
    '1506' => 'CA', '1514' => 'CA', '1519' => 'CA', '1548' => 'CA', '1579' => 'CA',
    '1581' => 'CA', '1584' => 'CA', '1587' => 'CA', '1604' => 'CA', '1613' => 'CA',
    '1639' => 'CA', '1647' => 'CA', '1672' => 'CA', '1683' => 'CA', '1705' => 'CA',
    '1709' => 'CA', '1742' => 'CA', '1753' => 'CA', '1778' => 'CA', '1780' => 'CA',
    '1782' => 'CA', '1807' => 'CA', '1819' => 'CA', '1825' => 'CA', '1867' => 'CA',
    '1873' => 'CA', '1879' => 'CA', '1902' => 'CA', '1905' => 'CA',
    '1242' => 'BS', '1246' => 'BB', '1264' => 'AI', '1268' => 'AG', '1284' => 'VG',
    '1340' => 'VI', '1345' => 'KY', '1441' => 'BM', '1473' => 'GD', '1649' => 'TC',
    '1658' => 'JM', '1664' => 'MS', '1670' => 'MP', '1671' => 'GU', '1684' => 'AS',
    '1721' => 'SX', '1758' => 'LC', '1767' => 'DM', '1784' => 'VC', '1787' => 'PR',
    '1809' => 'DO', '1829' => 'DO', '1849' => 'DO', '1868' => 'TT', '1869' => 'KN',
    '1876' => 'JM', '1939' => 'PR',

    // Kazakhstan shares +7 with Russia, split on the second digit.
    '7'   => 'RU', '76' => 'KZ', '77' => 'KZ',

    '20'  => 'EG',  '211' => 'SS', '212' => 'MA', '213' => 'DZ', '216' => 'TN',
    '218' => 'LY',  '220' => 'GM', '221' => 'SN', '222' => 'MR', '223' => 'ML',
    '224' => 'GN',  '225' => 'CI', '226' => 'BF', '227' => 'NE', '228' => 'TG',
    '229' => 'BJ',  '230' => 'MU', '231' => 'LR', '232' => 'SL', '233' => 'GH',
    '234' => 'NG',  '235' => 'TD', '236' => 'CF', '237' => 'CM', '238' => 'CV',
    '239' => 'ST',  '240' => 'GQ', '241' => 'GA', '242' => 'CG', '243' => 'CD',
    '244' => 'AO',  '245' => 'GW', '246' => 'IO', '248' => 'SC', '249' => 'SD',
    '250' => 'RW',  '251' => 'ET', '252' => 'SO', '253' => 'DJ', '254' => 'KE',
    '255' => 'TZ',  '256' => 'UG', '257' => 'BI', '258' => 'MZ', '260' => 'ZM',
    '261' => 'MG',  '262' => 'RE', '263' => 'ZW', '264' => 'NA', '265' => 'MW',
    '266' => 'LS',  '267' => 'BW', '268' => 'SZ', '269' => 'KM', '27'  => 'ZA',
    '290' => 'SH',  '291' => 'ER', '297' => 'AW', '298' => 'FO', '299' => 'GL',

    '30'  => 'GR',  '31'  => 'NL', '32'  => 'BE', '33'  => 'FR', '34'  => 'ES',
    '350' => 'GI',  '351' => 'PT', '352' => 'LU', '353' => 'IE', '354' => 'IS',
    '355' => 'AL',  '356' => 'MT', '357' => 'CY', '358' => 'FI', '359' => 'BG',
    '36'  => 'HU',  '370' => 'LT', '371' => 'LV', '372' => 'EE', '373' => 'MD',
    '374' => 'AM',  '375' => 'BY', '376' => 'AD', '377' => 'MC', '378' => 'SM',
    '379' => 'VA',  '380' => 'UA', '381' => 'RS', '382' => 'ME', '383' => 'XK',
    '385' => 'HR',  '386' => 'SI', '387' => 'BA', '389' => 'MK', '39'  => 'IT',
    '40'  => 'RO',  '41'  => 'CH', '420' => 'CZ', '421' => 'SK', '423' => 'LI',
    '43'  => 'AT',  '44'  => 'GB', '45'  => 'DK', '46'  => 'SE', '47'  => 'NO',
    '48'  => 'PL',  '49'  => 'DE',

    '500' => 'FK',  '501' => 'BZ', '502' => 'GT', '503' => 'SV', '504' => 'HN',
    '505' => 'NI',  '506' => 'CR', '507' => 'PA', '508' => 'PM', '509' => 'HT',
    '51'  => 'PE',  '52'  => 'MX', '53'  => 'CU', '54'  => 'AR', '55'  => 'BR',
    '56'  => 'CL',  '57'  => 'CO', '58'  => 'VE', '590' => 'GP', '591' => 'BO',
    '592' => 'GY',  '593' => 'EC', '594' => 'GF', '595' => 'PY', '596' => 'MQ',
    '597' => 'SR',  '598' => 'UY', '599' => 'CW',

    '60'  => 'MY',  '61'  => 'AU', '62'  => 'ID', '63'  => 'PH', '64'  => 'NZ',
    '65'  => 'SG',  '66'  => 'TH', '670' => 'TL', '672' => 'NF', '673' => 'BN',
    '674' => 'NR',  '675' => 'PG', '676' => 'TO', '677' => 'SB', '678' => 'VU',
    '679' => 'FJ',  '680' => 'PW', '681' => 'WF', '682' => 'CK', '683' => 'NU',
    '685' => 'WS',  '686' => 'KI', '687' => 'NC', '688' => 'TV', '689' => 'PF',
    '690' => 'TK',  '691' => 'FM', '692' => 'MH',

    '81'  => 'JP',  '82'  => 'KR', '84'  => 'VN', '850' => 'KP', '852' => 'HK',
    '853' => 'MO',  '855' => 'KH', '856' => 'LA', '86'  => 'CN', '880' => 'BD',
    '886' => 'TW',

    '90'  => 'TR',  '91'  => 'IN', '92'  => 'PK', '93'  => 'AF', '94'  => 'LK',
    '95'  => 'MM',  '960' => 'MV', '961' => 'LB', '962' => 'JO', '963' => 'SY',
    '964' => 'IQ',  '965' => 'KW', '966' => 'SA', '967' => 'YE', '968' => 'OM',
    '970' => 'PS',  '971' => 'AE', '972' => 'IL', '973' => 'BH', '974' => 'QA',
    '975' => 'BT',  '976' => 'MN', '977' => 'NP', '992' => 'TJ', '993' => 'TM',
    '994' => 'AZ',  '995' => 'GE', '996' => 'KG', '998' => 'UZ',
];

/**
 * Longest prefix in the table above, so the search knows where to start.
 */
const COUNTRY_PREFIX_MAX_LEN = 4;

/**
 * ISO 3166-1 alpha-2 to the name shown in the CRM.
 */
const COUNTRY_NAMES = [
    'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan',
    'AG' => 'Antigua and Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania',
    'AM' => 'Armenia', 'AO' => 'Angola', 'AR' => 'Argentina', 'AS' => 'American Samoa',
    'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AZ' => 'Azerbaijan',
    'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh',
    'BE' => 'Belgium', 'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain',
    'BI' => 'Burundi', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BN' => 'Brunei',
    'BO' => 'Bolivia', 'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan',
    'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada',
    'CD' => 'DR Congo', 'CF' => 'Central African Republic', 'CG' => 'Congo',
    'CH' => 'Switzerland', 'CI' => "Côte d'Ivoire", 'CK' => 'Cook Islands',
    'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia',
    'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CV' => 'Cape Verde', 'CW' => 'Curaçao',
    'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany', 'DJ' => 'Djibouti',
    'DK' => 'Denmark', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
    'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt',
    'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland',
    'FJ' => 'Fiji', 'FK' => 'Falkland Islands', 'FM' => 'Micronesia',
    'FO' => 'Faroe Islands', 'FR' => 'France', 'GA' => 'Gabon',
    'GB' => 'United Kingdom', 'GD' => 'Grenada', 'GE' => 'Georgia',
    'GF' => 'French Guiana', 'GH' => 'Ghana', 'GI' => 'Gibraltar',
    'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe',
    'GQ' => 'Equatorial Guinea', 'GR' => 'Greece', 'GT' => 'Guatemala',
    'GU' => 'Guam', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong',
    'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary',
    'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IN' => 'India',
    'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq', 'IS' => 'Iceland',
    'IT' => 'Italy', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan',
    'KE' => 'Kenya', 'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati',
    'KM' => 'Comoros', 'KN' => 'Saint Kitts and Nevis', 'KP' => 'North Korea',
    'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands',
    'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'Saint Lucia',
    'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia',
    'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia',
    'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova',
    'ME' => 'Montenegro', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands',
    'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar', 'MN' => 'Mongolia',
    'MO' => 'Macao', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique',
    'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius',
    'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia',
    'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NC' => 'New Caledonia',
    'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria',
    'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal',
    'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'New Zealand', 'OM' => 'Oman',
    'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia',
    'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan',
    'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon', 'PR' => 'Puerto Rico',
    'PS' => 'Palestine', 'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay',
    'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RS' => 'Serbia',
    'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
    'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan',
    'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena',
    'SI' => 'Slovenia', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone',
    'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Suriname',
    'SS' => 'South Sudan', 'ST' => 'São Tomé and Príncipe', 'SV' => 'El Salvador',
    'SX' => 'Sint Maarten', 'SY' => 'Syria', 'SZ' => 'Eswatini',
    'TC' => 'Turks and Caicos Islands', 'TD' => 'Chad', 'TG' => 'Togo',
    'TH' => 'Thailand', 'TJ' => 'Tajikistan', 'TK' => 'Tokelau',
    'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia',
    'TO' => 'Tonga', 'TR' => 'Türkiye', 'TT' => 'Trinidad and Tobago',
    'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine',
    'UG' => 'Uganda', 'US' => 'United States', 'UY' => 'Uruguay',
    'UZ' => 'Uzbekistan', 'VA' => 'Vatican City',
    'VC' => 'Saint Vincent and the Grenadines', 'VE' => 'Venezuela',
    'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands',
    'VN' => 'Vietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis and Futuna',
    'WS' => 'Samoa', 'XK' => 'Kosovo', 'YE' => 'Yemen', 'ZA' => 'South Africa',
    'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
];

/**
 * The ISO 3166-1 alpha-2 code a phone number belongs to, or null.
 *
 * Anything but digits is stripped first, so this takes a wa_id, a
 * '+34 600 111 222' typed into the details form, or either one with a
 * leading '00' international prefix.
 */
function country_code_for_phone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    // '0034...' is the same number as '+34...'. A single leading zero is
    // a national trunk prefix and cannot be resolved to a country, so it
    // is left alone to fail the lookup rather than be guessed at.
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    if ($digits === '') {
        return null;
    }

    for ($len = min(COUNTRY_PREFIX_MAX_LEN, strlen($digits)); $len >= 1; $len--) {
        $prefix = substr($digits, 0, $len);
        if (isset(COUNTRY_DIAL_CODES[$prefix])) {
            return COUNTRY_DIAL_CODES[$prefix];
        }
    }

    return null;
}

/**
 * The country name for a phone number, or null.
 */
function country_name_for_phone(?string $phone): ?string
{
    $code = country_code_for_phone($phone);
    return $code !== null ? (COUNTRY_NAMES[$code] ?? null) : null;
}

/**
 * The flag emoji for an alpha-2 code.
 *
 * Regional indicator symbols: 'ES' becomes U+1F1EA U+1F1F8. A platform
 * with no flag glyphs (Windows, mostly) renders the two letters instead,
 * which is a perfectly readable fallback and the reason this is emoji
 * rather than an image sprite.
 */
function country_flag(?string $code): string
{
    if ($code === null || !preg_match('/^[A-Za-z]{2}$/', $code)) {
        return '';
    }

    $code  = strtoupper($code);
    $flag  = '';
    $base  = 0x1F1E6; // REGIONAL INDICATOR SYMBOL LETTER A

    foreach (str_split($code) as $letter) {
        $flag .= mb_chr($base + (ord($letter) - ord('A')), 'UTF-8');
    }

    return $flag;
}

/**
 * Everything the frontend needs to show a country, derived from whatever
 * number the customer row has.
 *
 * wa_id is preferred over `phone`: it is always the full international
 * number as WhatsApp reports it, whereas `phone` is free text an agent
 * may have typed in a local format that has no country in it at all.
 *
 * @param array<string, mixed> $customer
 * @return array{code: ?string, name: ?string, flag: string}
 */
function country_for_customer(array $customer): array
{
    $number = (string) ($customer['wa_id'] ?? '');
    if ($number === '') {
        $number = (string) ($customer['phone'] ?? '');
    }

    $code = country_code_for_phone($number);

    return [
        'code' => $code,
        'name' => $code !== null ? (COUNTRY_NAMES[$code] ?? null) : null,
        'flag' => country_flag($code),
    ];
}
