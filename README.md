# BeersCMS Edit Lock

## Ucel a pozadavky

Balicek zajistuje editacni zamky nad zaznamy aplikace. Jeden zaznam muze
upravovat pouze vlastnik platneho zamku. Nezavisi na frameworku, HTTP ani session.
Vyzaduje PHP 8.1+, rozsireni `ext-json` a Composer autoload.

## Architektura a identita zaznamu

```text
EditLockService -> EditLockStorageInterface -> JsonEditLockStorage
```

- `EditLockService` resi TTL, token a pravidla vlastnictvi.
- `EditLockStorageInterface` definuje persistenci v exkluzivni transakci.
- `JsonEditLockStorage` zajistuje JSON, synchronizaci a uklid podle dodane hranice expirace.

Identitu tvori dvojice `resourceType + resourceId`, napr. `article` a
`documents/42`. Stejne ID v ruznych typech se netlouce.
Aplikace dodava stabilni kanonicke ID a overenou identitu uzivatele. Samotny slug
lze pouzit jen tehdy, pokud je jednoznacny v ramci daneho typu resource.

## Vytvoreni sluzby a acquire

Jedna instance sluzby predstavuje jednu transakci. Pro dalsi request vytvorte
novou instanci. Konstruktor otevre uloziste a provede uklid expirovanych zamku.

```php
use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Storage\JsonEditLockStorage;

$soubor = '/private/state/edit-locks.json';
$typ = 'article';
$id = 'documents/42';
$uzivatel = 'honza'; // V aplikaci pochazi z overeneho prihlaseni.
$locks = new EditLockService(new JsonEditLockStorage($soubor));

try {
    $vysledek = $locks->acquire($typ, $id, $uzivatel);
    if ($vysledek->isSuccess()) {
        $token = $vysledek->getLock()->getToken();
        // Token predejte pouze vlastnikovi pro dalsi requesty.
    } else {
        $chyba = $vysledek->getError();
        // Editor zustane zavreny; aplikace zobrazi informaci o konfliktu.
    }
} finally {
    $locks->close();
}
```

Existujici zamek lze pres `acquire` obnovit pouze se shodnym uzivatelem a tokenem
predanym jako paty argument. Samotna shoda uzivatele nestaci.

## Refresh, assertOwned a release

Nasledujici operace pouzivaji stejne `$typ`, `$id`, overeneho `$uzivatel` a token
vraceny klientem. Kazdou provedte uvnitr otevrene transakce s `close()` ve `finally`.

```php
// Heartbeat obnovi pouze vlastni platny zamek.
$vysledek = $locks->refresh($typ, $id, $uzivatel, $token);

// Samostatne ukonceni editace uvolni pouze vlastni platny zamek.
$vysledek = $locks->release($typ, $id, $uzivatel, $token);
```

Pri save drzte transakci od overeni az do dokonceni chraneneho zapisu:

```php
$locks = new EditLockService(new JsonEditLockStorage($soubor));
try {
    $vysledek = $locks->assertOwned($typ, $id, $uzivatel, $token);
    if (!$vysledek->isSuccess()) {
        throw $vysledek->getError();
    }

    // Zde aplikace ulozi zaznam; pri chybe zapisu se release neprovede.
    $vysledek = $locks->release($typ, $id, $uzivatel, $token);
    if (!$vysledek->isSuccess()) {
        throw $vysledek->getError();
    }
} finally {
    $locks->close();
}
```

Kontrolujte `isSuccess()` u kazde operace. Konflikt a chybejici vlastnictvi sluzba
vraci v `EditLockResultDto` jako `LockConflictException` nebo `LockNotOwnedException`.
Chyby persistence mohou vyhodit `EditLockException` nebo `JsonException`.
`close()` ukoncuje transakci, nikoli editacni zamek.

## Resource a dokonceni zapisu

`BeersCms\EditLock\ValueObject\EditLockResource` je nemenna dvojice typu a ID. `temporary($typ, $prefix)`
vytvori nezavisle ID pro dosud neulozeny zaznam; `restoreTemporary()` overuje
jeho format a prostor. Kanonizace trvale identity zustava aplikaci.

`EditLockResultDto::requireLock()` vrati potvrzeny zamek nebo vyhodi chybu
operace. Umoznuje skladat volani ve stejne transakci bez opakovaneho rozbalovani.

Po uspesnem zapisu `EditLockService::complete($lock)` uvolni zamek a uzavre
transakci. Pro pokracovani editace pouzijte `complete($lock, true, $target)`.
Cilovy `EditLockResource` je volitelny; pri zmene identity se nejprve ziska
cilovy zamek, potom uvolni puvodni a obnovi heartbeat. Konflikt ciloveho zamku
ponecha puvodni zamek. I pri chybe volajici uzavre transakci ve `finally`.
Sluzba neuklada aplikacni zaznam a nezna jeho soubor ani strukturu.

## Typovane prikazy

`BeersCms\EditLock\Command\EditLockOperation` obsahuje `Refresh` a `Release`. `EditLockCommand` je final
trida s private readonly properties a gettery. Prikaz i handler patri do
namespace `BeersCms\EditLock\Command`; `EditLockService` zustava v root namespace `BeersCms\EditLock`. Vyzaduje explicitni typ resource,
ID a overeneho uzivatele; nema vychozi typ clanku ani znalost HTTP.

```php
$prikaz = new \BeersCms\EditLock\Command\EditLockCommand(
    \BeersCms\EditLock\Command\EditLockOperation::Refresh, $typ, $id, $uzivatel, $token,
);
$vysledek = (new \BeersCms\EditLock\Command\EditLockCommandHandler($locks))->handle($prikaz);
```

Handler pouze vybira metodu `EditLockService`, nevytvari vlastni pravidla zamku
ani neuzavira transakci. Priklad patri do otevrene transakce s `close()` ve `finally`.
HTTP adapter overuje request a session, mapuje operaci pres enum a serializuje
vysledek. Balicek nema vazbu na globalni promenne requestu ani HTTP odpovedi.

## TTL a heartbeat

Vychozi TTL je 900 sekund od posledniho heartbeat; lze predat jine kladne TTL
jako druhy argument konstruktoru. Zamek expiruje i presne na hranici TTL.
Klient pravidelne vola `refresh`, napr. po 45 sekundach. Klientsky timer neni
soucasti balicku. Pri nedorucenem release zajisti zanik zamku TTL.

Cas se urci po otevreni transakce a behem ni se nemeni. Treti argument
konstruktoru umoznuje pevny Unix timestamp pro testy. Transakci neudrzujte
otevrenou po celou dobu editace v prohlizeci.

## JSON uloziste a soubeznost

`flock(LOCK_EX)` zamyka trvaly soubor `<soubor>.lock` po celou transakci.
Vsechny procesy musi pouzivat stejny mutex. Overeni a zapis tak tvori jeden
synchronizovany celek a soubezne acquire stejneho zaznamu nema dva viteze.

Zapis vytvori uplny docasny snimek ve stejnem adresari, dokonci zapis a flush,
uzavre soubor a atomickym `rename` nahradi JSON. Mutex se nenahrazuje ani nemaze.
Poskozeny JSON nebo nesoulad klice vyvola chybu misto prepsani uloziste.
Adresar musi existovat, byt zapisovatelny a nesmi byt verejne pristupny.

Transakce nad jednim JSON jsou serializovane, ale ruzni uzivatele mohou mit
soucasne otevrene ruzne zaznamy. Pri prechodu ze zamykani primo JSON souboru
nejprve zastavte stare procesy, aby vsichni pouzivali stejny mutex.

## Immutable DTO a serializace

`EditLockDto` a `EditLockResultDto` jsou final tridy s private readonly properties
kompatibilni s PHP 8.1. Nemaji settery. `withHeartbeat()` vraci novou instanci.
Vysledek obsahuje bud zamek pri uspechu, nebo chybu pri odmitnuti.

`EditLockDto::fromArray()` prijima overenou strukturu; `toArray()` vraci stejny
format vcetne tajneho tokenu. Presny array-shape popisuje PHPDoc. Neduveryhodny
JSON validuje uloziste pred vytvorenim DTO; serializovany zaznam neni verejna
odpoved pro ciziho uzivatele.

## Jina implementace uloziste

Implementujte `EditLockStorageInterface`: `open`, `find`, `put`, `remove`,
`removeExpired` a `close`. Zachovejte exkluzivitu cele transakce mezi `open`
a `close`, nikoli pouze jednotlive zapisy. `removeExpired` dostava hranici
heartbeat od sluzby a nema vlastni TTL ani pravidla vlastnictvi.
Instanci noveho uloziste predejte konstruktoru `EditLockService`.

## Instalace v aplikaci

Pokud aplikace zavisi na balicku pres svuj root `composer.json`, pro jeji beh
staci `composer install` v koreni aplikace. Aplikace nacita pouze root
`vendor/autoload.php`. Instalace uvnitr `packages/edit-lock` neni podminkou
behu aplikace; je urcena vyvoji a QA balicku.

JSON uloziste a soubor mutexu vzniknou automaticky pri prvni operaci.
Adresar predany ulozisti musi existovat a PHP proces v nem musi umet vytvaret,
zapisovat, prejmenovavat a odstranovat soubory. JSON neni nutne vytvaret rucne.
Runtime soubory, docasne snimky a cache nepatri do verzovaciho systemu.

## QA

Vyvojove zavislosti instalujte samostatne pouze pro praci na balicku:

```sh
cd packages/edit-lock
composer install
composer qa
```

Jednotlive kontroly lze v adresari balicku spustit take samostatne:

```sh
composer cs:check
composer stan
composer test
composer qa
```

QA zahrnuje PHP-CS-Fixer, PHPStan level 10 se strict rules bez potlaceni chyb
a PHPUnit. Testy pokryvaji pravidla zamku, DTO a soubeh dvanacti procesu
nad stejnym i ruznymi zaznamy. Cache patri do ignorovaneho adresare `build/`.
