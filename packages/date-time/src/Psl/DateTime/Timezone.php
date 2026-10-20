<?php

declare(strict_types=1);

namespace Psl\DateTime;

use DateTimeImmutable;
use DateTimeZone;
use IntlTimeZone;
use Override;
use Psl\Interoperability;

use function abs;
use function intdiv;
use function sprintf;

/**
 * Enumerates all supported time zones, including UTC, all tzdata time zones, and all unique UTC offsets.
 *
 * This enum covers a comprehensive list of time zones used globally, including:
 * - The Coordinated Universal Time (UTC).
 * - All time zones defined in the IANA Time Zone Database (tzdata), such as "America/Los_Angeles".
 * - All unique UTC offsets that are utilized by at least one of the supported time zones during their
 *   standard time or daylight saving time periods.
 *
 * The use of tzdata time zones instead of generic offsets or abbreviations (e.g., PST for Pacific Standard Time)
 * is encouraged to avoid ambiguity, especially considering the dynamic nature of daylight saving time adjustments
 * and the historical changes in time zone definitions. Time zone identifiers like "America/Los_Angeles" automatically
 * account for these variations correctly across different dates.
 *
 * @implements Interoperability\ToStdlib<DateTimeZone>
 * @implements Interoperability\FromStdlib<DateTimeZone>
 * @implements Interoperability\ToIntl<IntlTimeZone>
 * @implements Interoperability\FromIntl<IntlTimeZone>
 *
 * @mago-expect analysis:reference-to-undefined-variable
 *
 * @api
 */
enum Timezone: string implements
    Interoperability\ToStdlib,
    Interoperability\FromStdlib,
    Interoperability\ToIntl,
    Interoperability\FromIntl
{
    case UTC = 'UTC';
    case Minus1200 = '-12:00';
    case Minus1100 = '-11:00';
    case Minus1000 = '-10:00';
    case Minus0930 = '-09:30';
    case Minus0900 = '-09:00';
    case Minus0800 = '-08:00';
    case Minus0700 = '-07:00';
    case Minus0600 = '-06:00';
    case Minus0500 = '-05:00';
    case Minus0400 = '-04:00';
    case Minus0330 = '-03:30';
    case Minus0300 = '-03:00';
    case Minus0230 = '-02:30';
    case Minus0200 = '-02:00';
    case Minus0100 = '-01:00';
    case Plus0100 = '+01:00';
    case Plus0200 = '+02:00';
    case Plus0300 = '+03:00';
    case Plus0330 = '+03:30';
    case Plus0400 = '+04:00';
    case Plus0430 = '+04:30';
    case Plus0500 = '+05:00';
    case Plus0530 = '+05:30';
    case Plus0545 = '+05:45';
    case Plus0600 = '+06:00';
    case Plus0630 = '+06:30';
    case Plus0700 = '+07:00';
    case Plus0800 = '+08:00';
    case Plus0845 = '+08:45';
    case Plus0900 = '+09:00';
    case Plus0930 = '+09:30';
    case Plus1000 = '+10:00';
    case Plus1030 = '+10:30';
    case Plus1100 = '+11:00';
    case Plus1200 = '+12:00';
    case Plus1245 = '+12:45';
    case Plus1300 = '+13:00';
    case Plus1345 = '+13:45';
    case Plus1400 = '+14:00';
    case AfricaAbidjan = 'Africa/Abidjan';
    case AfricaAccra = 'Africa/Accra';
    case AfricaAddisAbaba = 'Africa/Addis_Ababa';
    case AfricaAlgiers = 'Africa/Algiers';
    case AfricaAsmara = 'Africa/Asmara';
    case AfricaBamako = 'Africa/Bamako';
    case AfricaBangui = 'Africa/Bangui';
    case AfricaBanjul = 'Africa/Banjul';
    case AfricaBissau = 'Africa/Bissau';
    case AfricaBlantyre = 'Africa/Blantyre';
    case AfricaBrazzaville = 'Africa/Brazzaville';
    case AfricaBujumbura = 'Africa/Bujumbura';
    case AfricaCairo = 'Africa/Cairo';
    case AfricaCasablanca = 'Africa/Casablanca';
    case AfricaCeuta = 'Africa/Ceuta';
    case AfricaConakry = 'Africa/Conakry';
    case AfricaDakar = 'Africa/Dakar';
    case AfricaDarEsSalaam = 'Africa/Dar_es_Salaam';
    case AfricaDjibouti = 'Africa/Djibouti';
    case AfricaDouala = 'Africa/Douala';
    case AfricaElAaiun = 'Africa/El_Aaiun';
    case AfricaFreetown = 'Africa/Freetown';
    case AfricaGaborone = 'Africa/Gaborone';
    case AfricaHarare = 'Africa/Harare';
    case AfricaJohannesburg = 'Africa/Johannesburg';
    case AfricaJuba = 'Africa/Juba';
    case AfricaKampala = 'Africa/Kampala';
    case AfricaKhartoum = 'Africa/Khartoum';
    case AfricaKigali = 'Africa/Kigali';
    case AfricaKinshasa = 'Africa/Kinshasa';
    case AfricaLagos = 'Africa/Lagos';
    case AfricaLibreville = 'Africa/Libreville';
    case AfricaLome = 'Africa/Lome';
    case AfricaLuanda = 'Africa/Luanda';
    case AfricaLubumbashi = 'Africa/Lubumbashi';
    case AfricaLusaka = 'Africa/Lusaka';
    case AfricaMalabo = 'Africa/Malabo';
    case AfricaMaputo = 'Africa/Maputo';
    case AfricaMaseru = 'Africa/Maseru';
    case AfricaMbabane = 'Africa/Mbabane';
    case AfricaMogadishu = 'Africa/Mogadishu';
    case AfricaMonrovia = 'Africa/Monrovia';
    case AfricaNairobi = 'Africa/Nairobi';
    case AfricaNdjamena = 'Africa/Ndjamena';
    case AfricaNiamey = 'Africa/Niamey';
    case AfricaNouakchott = 'Africa/Nouakchott';
    case AfricaOuagadougou = 'Africa/Ouagadougou';
    case AfricaPorto = 'Africa/Porto-Novo';
    case AfricaSaoTome = 'Africa/Sao_Tome';
    case AfricaTripoli = 'Africa/Tripoli';
    case AfricaTunis = 'Africa/Tunis';
    case AfricaWindhoek = 'Africa/Windhoek';
    case AmericaAdak = 'America/Adak';
    case AmericaAnchorage = 'America/Anchorage';
    case AmericaAnguilla = 'America/Anguilla';
    case AmericaAntigua = 'America/Antigua';
    case AmericaAraguaina = 'America/Araguaina';
    case AmericaArgentinaBuenosAires = 'America/Argentina/Buenos_Aires';
    case AmericaArgentinaCatamarca = 'America/Argentina/Catamarca';
    case AmericaArgentinaCordoba = 'America/Argentina/Cordoba';
    case AmericaArgentinaJujuy = 'America/Argentina/Jujuy';
    case AmericaArgentinaLaRioja = 'America/Argentina/La_Rioja';
    case AmericaArgentinaMendoza = 'America/Argentina/Mendoza';
    case AmericaArgentinaRioGallegos = 'America/Argentina/Rio_Gallegos';
    case AmericaArgentinaSalta = 'America/Argentina/Salta';
    case AmericaArgentinaSanJuan = 'America/Argentina/San_Juan';
    case AmericaArgentinaSanLuis = 'America/Argentina/San_Luis';
    case AmericaArgentinaTucuman = 'America/Argentina/Tucuman';
    case AmericaArgentinaUshuaia = 'America/Argentina/Ushuaia';
    case AmericaAruba = 'America/Aruba';
    case AmericaAsuncion = 'America/Asuncion';
    case AmericaAtikokan = 'America/Atikokan';
    case AmericaBahia = 'America/Bahia';
    case AmericaBahiaBanderas = 'America/Bahia_Banderas';
    case AmericaBarbados = 'America/Barbados';
    case AmericaBelem = 'America/Belem';
    case AmericaBelize = 'America/Belize';
    case AmericaBlancSablon = 'America/Blanc-Sablon';
    case AmericaBoaVista = 'America/Boa_Vista';
    case AmericaBogota = 'America/Bogota';
    case AmericaBoise = 'America/Boise';
    case AmericaCambridgeBay = 'America/Cambridge_Bay';
    case AmericaCampoGrande = 'America/Campo_Grande';
    case AmericaCancun = 'America/Cancun';
    case AmericaCaracas = 'America/Caracas';
    case AmericaCayenne = 'America/Cayenne';
    case AmericaCayman = 'America/Cayman';
    case AmericaChicago = 'America/Chicago';
    case AmericaChihuahua = 'America/Chihuahua';
    /**
     * Requires ICU time zone data from tzdata 2022g or later (bundled since ICU 73); with older ICU data,
     * using this zone throws an invariant violation.
     */
    case AmericaCiudadJuarez = 'America/Ciudad_Juarez';
    case AmericaCostaRica = 'America/Costa_Rica';
    /**
     * Requires ICU time zone data from tzdata 2025b or later (bundled since ICU 78); with older ICU data,
     * using this zone throws an invariant violation.
     */
    case AmericaCoyhaique = 'America/Coyhaique';
    case AmericaCreston = 'America/Creston';
    case AmericaCuiaba = 'America/Cuiaba';
    case AmericaCuracao = 'America/Curacao';
    case AmericaDanmarkshavn = 'America/Danmarkshavn';
    case AmericaDawson = 'America/Dawson';
    case AmericaDawsonCreek = 'America/Dawson_Creek';
    case AmericaDenver = 'America/Denver';
    case AmericaDetroit = 'America/Detroit';
    case AmericaDominica = 'America/Dominica';
    case AmericaEdmonton = 'America/Edmonton';
    case AmericaEirunepe = 'America/Eirunepe';
    case AmericaElSalvador = 'America/El_Salvador';
    case AmericaFortNelson = 'America/Fort_Nelson';
    case AmericaFortaleza = 'America/Fortaleza';
    case AmericaGlaceBay = 'America/Glace_Bay';
    case AmericaGooseBay = 'America/Goose_Bay';
    case AmericaGrandTurk = 'America/Grand_Turk';
    case AmericaGrenada = 'America/Grenada';
    case AmericaGuadeloupe = 'America/Guadeloupe';
    case AmericaGuatemala = 'America/Guatemala';
    case AmericaGuayaquil = 'America/Guayaquil';
    case AmericaGuyana = 'America/Guyana';
    case AmericaHalifax = 'America/Halifax';
    case AmericaHavana = 'America/Havana';
    case AmericaHermosillo = 'America/Hermosillo';
    case AmericaIndianaIndianapolis = 'America/Indiana/Indianapolis';
    case AmericaIndianaKnox = 'America/Indiana/Knox';
    case AmericaIndianaMarengo = 'America/Indiana/Marengo';
    case AmericaIndianaPetersburg = 'America/Indiana/Petersburg';
    case AmericaIndianaTellCity = 'America/Indiana/Tell_City';
    case AmericaIndianaVevay = 'America/Indiana/Vevay';
    case AmericaIndianaVincennes = 'America/Indiana/Vincennes';
    case AmericaIndianaWinamac = 'America/Indiana/Winamac';
    case AmericaInuvik = 'America/Inuvik';
    case AmericaIqaluit = 'America/Iqaluit';
    case AmericaJamaica = 'America/Jamaica';
    case AmericaJuneau = 'America/Juneau';
    case AmericaKentuckyLouisville = 'America/Kentucky/Louisville';
    case AmericaKentuckyMonticello = 'America/Kentucky/Monticello';
    case AmericaKralendijk = 'America/Kralendijk';
    case AmericaLaPaz = 'America/La_Paz';
    case AmericaLima = 'America/Lima';
    case AmericaLosAngeles = 'America/Los_Angeles';
    case AmericaLowerPrinces = 'America/Lower_Princes';
    case AmericaMaceio = 'America/Maceio';
    case AmericaManagua = 'America/Managua';
    case AmericaManaus = 'America/Manaus';
    case AmericaMarigot = 'America/Marigot';
    case AmericaMartinique = 'America/Martinique';
    case AmericaMatamoros = 'America/Matamoros';
    case AmericaMazatlan = 'America/Mazatlan';
    case AmericaMenominee = 'America/Menominee';
    case AmericaMerida = 'America/Merida';
    case AmericaMetlakatla = 'America/Metlakatla';
    case AmericaMexicoCity = 'America/Mexico_City';
    case AmericaMiquelon = 'America/Miquelon';
    case AmericaMoncton = 'America/Moncton';
    case AmericaMonterrey = 'America/Monterrey';
    case AmericaMontevideo = 'America/Montevideo';
    case AmericaMontserrat = 'America/Montserrat';
    case AmericaNassau = 'America/Nassau';
    case AmericaNewYork = 'America/New_York';
    case AmericaNipigon = 'America/Nipigon';
    case AmericaNome = 'America/Nome';
    case AmericaNoronha = 'America/Noronha';
    case AmericaNorthDakotaBeulah = 'America/North_Dakota/Beulah';
    case AmericaNorthDakotaCenter = 'America/North_Dakota/Center';
    case AmericaNorthDakotaNewSalem = 'America/North_Dakota/New_Salem';
    case AmericaNuuk = 'America/Nuuk';
    case AmericaOjinaga = 'America/Ojinaga';
    case AmericaPanama = 'America/Panama';
    case AmericaPangnirtung = 'America/Pangnirtung';
    case AmericaParamaribo = 'America/Paramaribo';
    case AmericaPhoenix = 'America/Phoenix';
    case AmericaPortAuPrince = 'America/Port-au-Prince';
    case AmericaPortOfSpain = 'America/Port_of_Spain';
    case AmericaPortoVelho = 'America/Porto_Velho';
    case AmericaPuertoRico = 'America/Puerto_Rico';
    case AmericaPuntaArenas = 'America/Punta_Arenas';
    case AmericaRainyRiver = 'America/Rainy_River';
    case AmericaRankinInlet = 'America/Rankin_Inlet';
    case AmericaRecife = 'America/Recife';
    case AmericaRegina = 'America/Regina';
    case AmericaResolute = 'America/Resolute';
    case AmericaRioBranco = 'America/Rio_Branco';
    case AmericaSantarem = 'America/Santarem';
    case AmericaSantiago = 'America/Santiago';
    case AmericaSantoDomingo = 'America/Santo_Domingo';
    case AmericaSaoPaulo = 'America/Sao_Paulo';
    case AmericaScoresbysund = 'America/Scoresbysund';
    case AmericaSitka = 'America/Sitka';
    case AmericaStBarthelemy = 'America/St_Barthelemy';
    case AmericaStJohns = 'America/St_Johns';
    case AmericaStKitts = 'America/St_Kitts';
    case AmericaStLucia = 'America/St_Lucia';
    case AmericaStThomas = 'America/St_Thomas';
    case AmericaStVincent = 'America/St_Vincent';
    case AmericaSwiftCurrent = 'America/Swift_Current';
    case AmericaTegucigalpa = 'America/Tegucigalpa';
    case AmericaThule = 'America/Thule';
    case AmericaThunderBay = 'America/Thunder_Bay';
    case AmericaTijuana = 'America/Tijuana';
    case AmericaToronto = 'America/Toronto';
    case AmericaTortola = 'America/Tortola';
    case AmericaVancouver = 'America/Vancouver';
    case AmericaWhitehorse = 'America/Whitehorse';
    case AmericaWinnipeg = 'America/Winnipeg';
    case AmericaYakutat = 'America/Yakutat';
    case AmericaYellowknife = 'America/Yellowknife';
    case AntarcticaCasey = 'Antarctica/Casey';
    case AntarcticaDavis = 'Antarctica/Davis';
    case AntarcticaDumontDUrville = 'Antarctica/DumontDUrville';
    case AntarcticaMacquarie = 'Antarctica/Macquarie';
    case AntarcticaMawson = 'Antarctica/Mawson';
    case AntarcticaMcMurdo = 'Antarctica/McMurdo';
    case AntarcticaPalmer = 'Antarctica/Palmer';
    case AntarcticaRothera = 'Antarctica/Rothera';
    case AntarcticaSyowa = 'Antarctica/Syowa';
    case AntarcticaTroll = 'Antarctica/Troll';
    case AntarcticaVostok = 'Antarctica/Vostok';
    case ArcticLongyearbyen = 'Arctic/Longyearbyen';
    case AsiaAden = 'Asia/Aden';
    case AsiaAlmaty = 'Asia/Almaty';
    case AsiaAmman = 'Asia/Amman';
    case AsiaAnadyr = 'Asia/Anadyr';
    case AsiaAqtau = 'Asia/Aqtau';
    case AsiaAqtobe = 'Asia/Aqtobe';
    case AsiaAshgabat = 'Asia/Ashgabat';
    case AsiaAtyrau = 'Asia/Atyrau';
    case AsiaBaghdad = 'Asia/Baghdad';
    case AsiaBahrain = 'Asia/Bahrain';
    case AsiaBaku = 'Asia/Baku';
    case AsiaBangkok = 'Asia/Bangkok';
    case AsiaBarnaul = 'Asia/Barnaul';
    case AsiaBeirut = 'Asia/Beirut';
    case AsiaBishkek = 'Asia/Bishkek';
    case AsiaBrunei = 'Asia/Brunei';
    case AsiaChita = 'Asia/Chita';
    case AsiaChoibalsan = 'Asia/Choibalsan';
    case AsiaColombo = 'Asia/Colombo';
    case AsiaDamascus = 'Asia/Damascus';
    case AsiaDhaka = 'Asia/Dhaka';
    case AsiaDili = 'Asia/Dili';
    case AsiaDubai = 'Asia/Dubai';
    case AsiaDushanbe = 'Asia/Dushanbe';
    case AsiaFamagusta = 'Asia/Famagusta';
    case AsiaGaza = 'Asia/Gaza';
    case AsiaHebron = 'Asia/Hebron';
    case AsiaHoChiMinh = 'Asia/Ho_Chi_Minh';
    case AsiaHongKong = 'Asia/Hong_Kong';
    case AsiaHovd = 'Asia/Hovd';
    case AsiaIrkutsk = 'Asia/Irkutsk';
    case AsiaJakarta = 'Asia/Jakarta';
    case AsiaJayapura = 'Asia/Jayapura';
    case AsiaJerusalem = 'Asia/Jerusalem';
    case AsiaKabul = 'Asia/Kabul';
    case AsiaKamchatka = 'Asia/Kamchatka';
    case AsiaKarachi = 'Asia/Karachi';
    case AsiaKathmandu = 'Asia/Kathmandu';
    case AsiaKhandyga = 'Asia/Khandyga';
    case AsiaKolkata = 'Asia/Kolkata';
    case AsiaKrasnoyarsk = 'Asia/Krasnoyarsk';
    case AsiaKualaLumpur = 'Asia/Kuala_Lumpur';
    case AsiaKuching = 'Asia/Kuching';
    case AsiaKuwait = 'Asia/Kuwait';
    case AsiaMacau = 'Asia/Macau';
    case AsiaMagadan = 'Asia/Magadan';
    case AsiaMakassar = 'Asia/Makassar';
    case AsiaManila = 'Asia/Manila';
    case AsiaMuscat = 'Asia/Muscat';
    case AsiaNicosia = 'Asia/Nicosia';
    case AsiaNovokuznetsk = 'Asia/Novokuznetsk';
    case AsiaNovosibirsk = 'Asia/Novosibirsk';
    case AsiaOmsk = 'Asia/Omsk';
    case AsiaOral = 'Asia/Oral';
    case AsiaPhnomPenh = 'Asia/Phnom_Penh';
    case AsiaPontianak = 'Asia/Pontianak';
    case AsiaPyongyang = 'Asia/Pyongyang';
    case AsiaQatar = 'Asia/Qatar';
    case AsiaQostanay = 'Asia/Qostanay';
    case AsiaQyzylorda = 'Asia/Qyzylorda';
    case AsiaRiyadh = 'Asia/Riyadh';
    case AsiaSakhalin = 'Asia/Sakhalin';
    case AsiaSamarkand = 'Asia/Samarkand';
    case AsiaSeoul = 'Asia/Seoul';
    case AsiaShanghai = 'Asia/Shanghai';
    case AsiaSingapore = 'Asia/Singapore';
    case AsiaSrednekolymsk = 'Asia/Srednekolymsk';
    case AsiaTaipei = 'Asia/Taipei';
    case AsiaTashkent = 'Asia/Tashkent';
    case AsiaTbilisi = 'Asia/Tbilisi';
    case AsiaTehran = 'Asia/Tehran';
    case AsiaThimphu = 'Asia/Thimphu';
    case AsiaTokyo = 'Asia/Tokyo';
    case AsiaTomsk = 'Asia/Tomsk';
    case AsiaUlaanbaatar = 'Asia/Ulaanbaatar';
    case AsiaUrumqi = 'Asia/Urumqi';
    case AsiaUstNera = 'Asia/Ust-Nera';
    case AsiaVientiane = 'Asia/Vientiane';
    case AsiaVladivostok = 'Asia/Vladivostok';
    case AsiaYakutsk = 'Asia/Yakutsk';
    case AsiaYangon = 'Asia/Yangon';
    case AsiaYekaterinburg = 'Asia/Yekaterinburg';
    case AsiaYerevan = 'Asia/Yerevan';
    case AtlanticAzores = 'Atlantic/Azores';
    case AtlanticBermuda = 'Atlantic/Bermuda';
    case AtlanticCanary = 'Atlantic/Canary';
    case AtlanticCapeVerde = 'Atlantic/Cape_Verde';
    case AtlanticFaroe = 'Atlantic/Faroe';
    case AtlanticMadeira = 'Atlantic/Madeira';
    case AtlanticReykjavik = 'Atlantic/Reykjavik';
    case AtlanticSouthGeorgia = 'Atlantic/South_Georgia';
    case AtlanticStHelena = 'Atlantic/St_Helena';
    case AtlanticStanley = 'Atlantic/Stanley';
    case AustraliaAdelaide = 'Australia/Adelaide';
    case AustraliaBrisbane = 'Australia/Brisbane';
    case AustraliaBrokenHill = 'Australia/Broken_Hill';
    case AustraliaDarwin = 'Australia/Darwin';
    case AustraliaEucla = 'Australia/Eucla';
    case AustraliaHobart = 'Australia/Hobart';
    case AustraliaLindeman = 'Australia/Lindeman';
    case AustraliaLordHowe = 'Australia/Lord_Howe';
    case AustraliaMelbourne = 'Australia/Melbourne';
    case AustraliaPerth = 'Australia/Perth';
    case AustraliaSydney = 'Australia/Sydney';
    case EuropeAmsterdam = 'Europe/Amsterdam';
    case EuropeAndorra = 'Europe/Andorra';
    case EuropeAstrakhan = 'Europe/Astrakhan';
    case EuropeAthens = 'Europe/Athens';
    case EuropeBelgrade = 'Europe/Belgrade';
    case EuropeBerlin = 'Europe/Berlin';
    case EuropeBratislava = 'Europe/Bratislava';
    case EuropeBrussels = 'Europe/Brussels';
    case EuropeBucharest = 'Europe/Bucharest';
    case EuropeBudapest = 'Europe/Budapest';
    case EuropeBusingen = 'Europe/Busingen';
    case EuropeChisinau = 'Europe/Chisinau';
    case EuropeCopenhagen = 'Europe/Copenhagen';
    case EuropeDublin = 'Europe/Dublin';
    case EuropeGibraltar = 'Europe/Gibraltar';
    case EuropeGuernsey = 'Europe/Guernsey';
    case EuropeHelsinki = 'Europe/Helsinki';
    case EuropeIsleofMan = 'Europe/Isle_of_Man';
    case EuropeIstanbul = 'Europe/Istanbul';
    case EuropeJersey = 'Europe/Jersey';
    case EuropeKaliningrad = 'Europe/Kaliningrad';
    case EuropeKiev = 'Europe/Kiev';
    case EuropeKirov = 'Europe/Kirov';
    /**
     * ICU data older than tzdata 2022b (ICU 72) knows this zone as "Europe/Kiev", which is used there instead.
     */
    case EuropeKyiv = 'Europe/Kyiv';
    case EuropeLisbon = 'Europe/Lisbon';
    case EuropeLjubljana = 'Europe/Ljubljana';
    case EuropeLondon = 'Europe/London';
    case EuropeLuxembourg = 'Europe/Luxembourg';
    case EuropeMadrid = 'Europe/Madrid';
    case EuropeMalta = 'Europe/Malta';
    case EuropeMariehamn = 'Europe/Mariehamn';
    case EuropeMinsk = 'Europe/Minsk';
    case EuropeMonaco = 'Europe/Monaco';
    case EuropeMoscow = 'Europe/Moscow';
    case EuropeOslo = 'Europe/Oslo';
    case EuropeParis = 'Europe/Paris';
    case EuropePodgorica = 'Europe/Podgorica';
    case EuropePrague = 'Europe/Prague';
    case EuropeRiga = 'Europe/Riga';
    case EuropeRome = 'Europe/Rome';
    case EuropeSamara = 'Europe/Samara';
    case EuropeSanMarino = 'Europe/San_Marino';
    case EuropeSarajevo = 'Europe/Sarajevo';
    case EuropeSaratov = 'Europe/Saratov';
    case EuropeSimferopol = 'Europe/Simferopol';
    case EuropeSkopje = 'Europe/Skopje';
    case EuropeSofia = 'Europe/Sofia';
    case EuropeStockholm = 'Europe/Stockholm';
    case EuropeTallinn = 'Europe/Tallinn';
    case EuropeTirane = 'Europe/Tirane';
    case EuropeUlyanovsk = 'Europe/Ulyanovsk';
    case EuropeUzhgorod = 'Europe/Uzhgorod';
    case EuropeVaduz = 'Europe/Vaduz';
    case EuropeVatican = 'Europe/Vatican';
    case EuropeVienna = 'Europe/Vienna';
    case EuropeVilnius = 'Europe/Vilnius';
    case EuropeVolgograd = 'Europe/Volgograd';
    case EuropeWarsaw = 'Europe/Warsaw';
    case EuropeZagreb = 'Europe/Zagreb';
    case EuropeZaporozhye = 'Europe/Zaporozhye';
    case EuropeZurich = 'Europe/Zurich';
    case IndianAntananarivo = 'Indian/Antananarivo';
    case IndianChagos = 'Indian/Chagos';
    case IndianChristmas = 'Indian/Christmas';
    case IndianCocos = 'Indian/Cocos';
    case IndianComoro = 'Indian/Comoro';
    case IndianKerguelen = 'Indian/Kerguelen';
    case IndianMahe = 'Indian/Mahe';
    case IndianMaldives = 'Indian/Maldives';
    case IndianMauritius = 'Indian/Mauritius';
    case IndianMayotte = 'Indian/Mayotte';
    case IndianReunion = 'Indian/Reunion';
    case PacificApia = 'Pacific/Apia';
    case PacificAuckland = 'Pacific/Auckland';
    case PacificBougainville = 'Pacific/Bougainville';
    case PacificChatham = 'Pacific/Chatham';
    case PacificChuuk = 'Pacific/Chuuk';
    case PacificEaster = 'Pacific/Easter';
    case PacificEfate = 'Pacific/Efate';
    case PacificEnderbury = 'Pacific/Enderbury';
    case PacificFakaofo = 'Pacific/Fakaofo';
    case PacificFiji = 'Pacific/Fiji';
    case PacificFunafuti = 'Pacific/Funafuti';
    case PacificGalapagos = 'Pacific/Galapagos';
    case PacificGambier = 'Pacific/Gambier';
    case PacificGuadalcanal = 'Pacific/Guadalcanal';
    case PacificGuam = 'Pacific/Guam';
    case PacificHonolulu = 'Pacific/Honolulu';
    /**
     * ICU data older than tzdata 2021b knows this zone as "Pacific/Enderbury", which is used there instead.
     */
    case PacificKanton = 'Pacific/Kanton';
    case PacificKiritimati = 'Pacific/Kiritimati';
    case PacificKosrae = 'Pacific/Kosrae';
    case PacificKwajalein = 'Pacific/Kwajalein';
    case PacificMajuro = 'Pacific/Majuro';
    case PacificMarquesas = 'Pacific/Marquesas';
    case PacificMidway = 'Pacific/Midway';
    case PacificNauru = 'Pacific/Nauru';
    case PacificNiue = 'Pacific/Niue';
    case PacificNorfolk = 'Pacific/Norfolk';
    case PacificNoumea = 'Pacific/Noumea';
    case PacificPagoPago = 'Pacific/Pago_Pago';
    case PacificPalau = 'Pacific/Palau';
    case PacificPitcairn = 'Pacific/Pitcairn';
    case PacificPohnpei = 'Pacific/Pohnpei';
    case PacificPortMoresby = 'Pacific/Port_Moresby';
    case PacificRarotonga = 'Pacific/Rarotonga';
    case PacificSaipan = 'Pacific/Saipan';
    case PacificTahiti = 'Pacific/Tahiti';
    case PacificTarawa = 'Pacific/Tarawa';
    case PacificTongatapu = 'Pacific/Tongatapu';
    case PacificWake = 'Pacific/Wake';
    case PacificWallis = 'Pacific/Wallis';

    /**
     * Retrieves the default timezone set in the PHP environment.
     *
     * Backward-compatible links (e.g. "US/Eastern") resolve to the zone they point to, and fixed-offset
     * zones (e.g. "Etc/GMT+5") to the matching offset case.
     *
     * @throws Exception\RuntimeException If the default timezone has no equivalent case.
     *
     * @return self The default timezone as an enum instance.
     *
     * @psalm-mutation-free
     */
    public static function default(): self
    {
        return Internal\default_timezone();
    }

    /**
     * Calculates the total time zone offset for a given {@see TemporalInterface} instance.
     *
     * This total offset includes both the raw timezone offset and any daylight saving time (DST) adjustments applicable at the temporal instance's time.
     *
     * @param bool $local Indicates whether the temporal object's time should be treated as local time (`true`) or as UTC time (`false`).
     *
     * @return Duration The total offset from UTC as a Duration instance, including any DST adjustments.
     *
     * @psalm-mutation-free
     */
    public function getOffset(TemporalInterface $temporal, bool $local = false): Duration
    {
        $intlTimezone = Internal\to_intl_timezone($this);
        $timestampMillis = $temporal->getTimestamp()->getSeconds() * namespace\MILLISECONDS_PER_SECOND;
        $intlTimezone->getOffset($timestampMillis, $local, $raw_offset, $dst_offset);

        return Duration::milliseconds($raw_offset + $dst_offset);
    }

    /**
     * Calculates the raw time zone offset for the current timezone, excluding any daylight saving time (DST) adjustments.
     *
     * This method retrieves the fixed offset from UTC for the timezone without considering any seasonal adjustments
     * that might apply due to DST. It's particularly useful for understanding the base offset of a timezone.
     *
     * @psalm-mutation-free
     */
    public function getRawOffset(): Duration
    {
        return Duration::milliseconds(Internal\to_intl_timezone($this)->getRawOffset());
    }

    /**
     * Calculates the daylight saving time (DST) offset for a given {@see TemporalInterface} instance at its specific time.
     *
     * This DST offset is the adjustment added to the raw timezone offset, if DST is in effect at the temporal instance's time.
     *
     * @param bool $local Indicates whether the temporal object's time should be treated as local time (`true`) or as UTC time (`false`).
     *
     * @return Duration The DST offset as a Duration instance. If DST is not in effect, the offset will be zero.
     *
     * @psalm-mutation-free
     */
    public function getDaylightSavingTimeOffset(TemporalInterface $temporal, bool $local = false): Duration
    {
        $intlTimezone = Internal\to_intl_timezone($this);
        $timestampMillis = $temporal->getTimestamp()->getSeconds() * namespace\MILLISECONDS_PER_SECOND;
        $intlTimezone->getOffset($timestampMillis, $local, $_, $dst_offset);

        return Duration::milliseconds($dst_offset);
    }

    /**
     * Determines whether the current timezone observes Daylight Saving Time (DST).
     *
     * This method checks if the timezone has any DST rules and if DST is applied at any point during the year.
     *
     * @return bool True if the timezone uses Daylight Saving Time at any point in the year, false otherwise.
     *
     * @psalm-mutation-free
     */
    public function usesDaylightSavingTime(): bool
    {
        return Internal\to_intl_timezone($this)->useDaylightTime();
    }

    /**
     * Retrieves the amount of time added during Daylight Saving Time for the current timezone.
     *
     * This method returns the typical adjustment made to the local time when DST is in effect.
     *
     * If the timezone does not observe DST or if there is no current DST adjustment (e.g., outside of DST periods),
     * the method will return a Duration of zero.
     *
     * @psalm-mutation-free
     */
    public function getDaylightSavingTimeSavings(): Duration
    {
        return Duration::milliseconds(Internal\to_intl_timezone($this)->getDSTSavings());
    }

    /**
     * Determines whether the current timezone has the same rules as another specified timezone.
     *
     * @psalm-mutation-free
     */
    public function hasTheSameRulesAs(Timezone $other): bool
    {
        return Internal\to_intl_timezone($this)->hasSameRules(Internal\to_intl_timezone($other));
    }

    /**
     * Creates a {@see Timezone} from a PHP {@see DateTimeZone}.
     *
     * Backward-compatible links (e.g. "US/Eastern") resolve to the zone they point to; offset and
     * abbreviation timezones (e.g. "+00:00", "CET", or "CEST") resolve to the case of the fixed offset
     * PHP gives them.
     *
     * @param DateTimeZone $value
     *
     * @throws Exception\InvalidArgumentException If the timezone has no equivalent case.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public static function fromStdlib(mixed $value): static
    {
        $name = $value->getName();
        if (false !== $value->getLocation()) {
            $timezone = Internal\resolve_timezone($name);
        } else {
            // An offset ("+05:00") or abbreviation ("CET", "CEST") timezone has a fixed offset, and only that
            // offset counts: ICU reads some abbreviations as region zones with other offsets or daylight
            // saving time (e.g. "CET" as Europe/Brussels, "IST" as Asia/Kolkata).
            $offset = $value->getOffset(new DateTimeImmutable('@0'));
            $minutes = intdiv(abs($offset), namespace\SECONDS_PER_MINUTE);
            $timezone = match (true) {
                0 === $offset => self::UTC,
                0 !== ($offset % namespace\SECONDS_PER_MINUTE) => null,
                default => self::tryFrom(sprintf(
                    '%s%02d:%02d',
                    $offset < 0 ? '-' : '+',
                    intdiv($minutes, 60),
                    $minutes % 60,
                )),
            };
        }

        return $timezone ?? throw new Exception\InvalidArgumentException(sprintf(
            'The timezone "%s" is not supported.',
            $name,
        ));
    }

    /**
     * Converts this {@see Timezone} to a PHP {@see DateTimeZone}.
     *
     * @return DateTimeZone
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toStdlib(): mixed
    {
        return new DateTimeZone($this->value);
    }

    /**
     * Creates a {@see Timezone} from an {@see IntlTimeZone}.
     *
     * Aliases (e.g. "Asia/Calcutta") resolve to the zone they stand for, and fixed-offset zones
     * (e.g. "GMT+05:00") to the matching offset case.
     *
     * @param IntlTimeZone $value
     *
     * @throws Exception\InvalidArgumentException If the timezone has no equivalent case.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public static function fromIntl(mixed $value): static
    {
        /** @var string $id */
        $id = $value->getID();

        return (
            Internal\resolve_timezone($id) ?? throw new Exception\InvalidArgumentException(sprintf(
                'The timezone "%s" is not supported.',
                $id,
            ))
        );
    }

    /**
     * Converts this {@see Timezone} to an {@see IntlTimeZone}.
     *
     * @return IntlTimeZone
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toIntl(): mixed
    {
        return Internal\to_intl_timezone($this);
    }
}
